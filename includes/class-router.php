<?php
/**
 * URLs and routing.
 *
 * The early loader strips the language prefix, so WordPress resolves
 * "/fr/shop/" exactly like "/shop/". Here every URL generated during a
 * secondary-language request gets the prefix back, canonical redirects are
 * kept from looping, separate-mode posts are served only under their own
 * language, and post lists show the current language only.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class Router {

	public static function init() {
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'home_url', [ __CLASS__, 'home_url' ], 20 );
		add_filter( 'post_link', [ __CLASS__, 'separate_post_link' ], 20, 2 );
		add_filter( 'post_type_link', [ __CLASS__, 'separate_post_link' ], 20, 2 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'redirect_canonical' ], 20, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'route_separate_posts' ], 1 );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_lists' ] );
		add_filter( 'widget_posts_args', [ __CLASS__, 'filter_widget_posts' ] );
		add_filter( 'render_block_data', [ __CLASS__, 'scope_post_blocks' ] );
		add_filter( 'render_block', [ __CLASS__, 'unscope_post_blocks' ] );
		add_filter( 'query_loop_block_query_vars', [ __CLASS__, 'filter_query_loop' ] );
		add_filter( 'get_previous_post_where', [ __CLASS__, 'adjacent_where' ], 10, 5 );
		add_filter( 'get_next_post_where', [ __CLASS__, 'adjacent_where' ], 10, 5 );
	}

	/**
	 * Prefix internal links that never went through home_url() — absolute URLs
	 * typed into menus, page builders or theme options. Links that declare a
	 * hreflang (switcher, alternates) deliberately point at another language
	 * and are left alone. Runs on HTML outside <script>/<style>.
	 */
	public static function rewrite_links( $html, $lang ) {
		if ( ovml_is_default( $lang ) ) {
			return $html;
		}
		$root = ovml_root();
		$fix  = static function ( $url ) use ( $lang, $root ) {
			$decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $decoded || '#' === $decoded[0] || 0 === strpos( $decoded, '//' ) && 0 !== strpos( $decoded, '//' . wp_parse_url( $root, PHP_URL_HOST ) ) ) {
				return $url;
			}
			if ( '/' === $decoded[0] && ( ! isset( $decoded[1] ) || '/' !== $decoded[1] ) ) {
				$decoded = $root . $decoded;
			}
			$new = ovml_url( $decoded, $lang );
			return $new === $decoded ? $url : esc_url( $new );
		};
		$html = preg_replace_callback( '#<a\b[^>]*>#i', static function ( $m ) use ( $fix ) {
			if ( false !== stripos( $m[0], 'hreflang=' ) ) {
				return $m[0];
			}
			return preg_replace_callback( '#\shref=(["\'])([^"\']*)\1#i', static fn( $h ) => ' href=' . $h[1] . $fix( $h[2] ) . $h[1], $m[0] );
		}, $html );
		return preg_replace_callback( '#<form\b[^>]*>#i', static function ( $m ) use ( $fix ) {
			return preg_replace_callback( '#\saction=(["\'])([^"\']*)\1#i', static fn( $h ) => ' action=' . $h[1] . $fix( $h[2] ) . $h[1], $m[0] );
		}, $html );
	}

	public static function home_url( $url ) {
		return ovml_is_default( ovml_lang() ) ? $url : ovml_url( $url, ovml_lang() );
	}

	/** Separate-mode posts always live under their own language's prefix. */
	public static function separate_post_link( $url, $post ) {
		if ( ! PolylangData::is_separate( $post ) ) {
			return $url;
		}
		return ovml_url( $url, PolylangData::language_or_default( $post->ID ) );
	}

	/**
	 * WordPress compares the prefix-stripped request with the prefixed permalink;
	 * treat them as equal, and keep the prefix on genuine canonical redirects.
	 */
	public static function redirect_canonical( $redirect, $requested ) {
		if ( ! $redirect || ovml_is_default( ovml_lang() ) ) {
			return $redirect;
		}
		$neutral = ovml_default_language();
		if ( ovml_url( $redirect, $neutral ) === ovml_url( $requested, $neutral ) ) {
			return false;
		}
		return ovml_url( $redirect, ovml_lang() );
	}

	public static function route_separate_posts() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! PolylangData::is_separate( $post ) ) {
			return;
		}
		$own = PolylangData::language_or_default( $post->ID );
		if ( ovml_lang() === $own ) {
			return;
		}
		// An unprefixed URL is the post's old address: move it to its own language.
		// Under another language's prefix, show that language's version if there is one.
		$group = PolylangData::group( $post->ID );
		$want  = ovml_is_default( ovml_lang() ) ? $own : ovml_lang();
		$id    = $group[ $want ] ?? $post->ID;
		$lang  = isset( $group[ $want ] ) ? $want : $own;
		wp_safe_redirect( ovml_url( get_permalink( $id ), $lang ), 301 );
		exit;
	}

	public static function filter_lists( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$types = (array) ovml_settings()['separate_posts'];
		if ( ! $types ) {
			return;
		}
		$listing = $query->is_home() || $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() || $query->is_post_type_archive( $types );
		if ( $listing ) {
			$ids = [];
			foreach ( $types as $type ) {
				$ids = array_merge( $ids, PolylangData::listing_ids( ovml_lang(), $type ) );
			}
			$query->set( 'post__in', $ids );
		}
	}

	public static function filter_widget_posts( $args ) {
		if ( in_array( 'post', (array) ovml_settings()['separate_posts'], true ) ) {
			$args['post__in'] = PolylangData::listing_ids( ovml_lang() );
		}
		return $args;
	}

	/**
	 * The core Latest Posts block queries with get_posts() and offers no args filter,
	 * so its posts are restricted from pre_get_posts while that block renders.
	 */
	private static $in_latest_posts = false;

	public static function scope_post_blocks( $block ) {
		if ( 'core/latest-posts' === ( $block['blockName'] ?? '' ) && in_array( 'post', (array) ovml_settings()['separate_posts'], true ) ) {
			self::$in_latest_posts = true;
			add_action( 'pre_get_posts', [ __CLASS__, 'filter_block_query' ] );
		}
		return $block;
	}

	public static function unscope_post_blocks( $content ) {
		if ( self::$in_latest_posts ) {
			self::$in_latest_posts = false;
			remove_action( 'pre_get_posts', [ __CLASS__, 'filter_block_query' ] );
		}
		return $content;
	}

	/** Query Loop blocks listing a separate-mode post type show the current language only. */
	public static function filter_query_loop( $vars ) {
		$type = $vars['post_type'] ?? 'post';
		if ( is_string( $type ) && in_array( $type, (array) ovml_settings()['separate_posts'], true ) && empty( $vars['post__in'] ) ) {
			$vars['post__in'] = PolylangData::listing_ids( ovml_lang(), $type );
		}
		return $vars;
	}

	public static function filter_block_query( $query ) {
		if ( ! $query->get( 'post__in' ) ) {
			$query->set( 'post__in', PolylangData::listing_ids( ovml_lang() ) );
		}
	}

	public static function adjacent_where( $where, $in_same_term, $excluded, $taxonomy, $post ) {
		if ( ! $post || ! PolylangData::is_separate( $post ) ) {
			return $where;
		}
		$ids = PolylangData::ids_in( PolylangData::language_or_default( $post->ID ), $post->post_type );
		return $where . ' AND p.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}
}
