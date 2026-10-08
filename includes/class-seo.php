<?php
/**
 * Search-engine signals for every language: hreflang alternates, html lang,
 * noindex for pages without a translation, translated SEO title/description
 * (consumed by the Rank Math and Yoast integrations) and a sitemap listing
 * every secondary-language URL.
 *
 * A secondary-language page without a translation still works for visitors
 * (original text, translated interface) but is kept out of the index, out of
 * hreflang and out of the sitemap, so it never competes as a duplicate.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class SEO {

	const SITEMAP_PATH = '/ovml-sitemap.xml';

	public static function init() {
		add_action( 'parse_request', [ __CLASS__, 'serve_sitemap' ], 0 );
		foreach ( [ 'save_post', 'deleted_post', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'flush_sitemaps' ] );
		}
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'added_term_meta', 'updated_term_meta' ] as $hook ) {
			add_action( $hook, static function ( $meta_id, $object_id, $key ) {
				if ( OVML_META === $key ) {
					self::flush_sitemaps();
				}
			}, 10, 3 );
		}
		if ( ! ovml_enabled() ) {
			return;
		}
		add_action( 'wp_head', [ __CLASS__, 'hreflang' ], 2 );
		add_filter( 'language_attributes', [ __CLASS__, 'html_lang' ], 99 );
		add_filter( 'wp_robots', [ __CLASS__, 'wp_robots' ], 99 );
	}

	private static function setting( $key ) {
		return ! empty( ovml_settings()['seo'][ $key ] );
	}

	/** The object the current request is about (the shop page for the product archive). */
	public static function queried_object() {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return get_post( wc_get_page_id( 'shop' ) );
		}
		if ( is_home() && ! is_front_page() ) {
			return get_post( (int) get_option( 'page_for_posts' ) );
		}
		return get_queried_object();
	}

	/** [lang => url] for the current page, only languages with real content. */
	public static function alternates() {
		static $alts = null;
		if ( null !== $alts ) {
			return $alts;
		}
		$alts   = [];
		$here   = strtok( ovml_clean_url( ovml_current_url() ), '?' );
		$object = self::queried_object();

		if ( is_singular() && PolylangData::is_separate( $object ) ) {
			foreach ( PolylangData::group( $object->ID ) as $lang => $id ) {
				$alts[ $lang ] = ovml_url( get_permalink( $id ), $lang );
			}
		} elseif ( is_front_page() || ( is_home() && ovml_settings()['separate_posts'] ) ) {
			foreach ( array_keys( ovml_languages() ) as $lang ) {
				$alts[ $lang ] = ovml_url( $here, $lang );
			}
		} elseif ( $object instanceof \WP_Post || $object instanceof \WP_Term ) {
			foreach ( array_keys( ovml_languages() ) as $lang ) {
				if ( ovml_has_tr( $object, $lang ) ) {
					$alts[ $lang ] = ovml_url( $here, $lang );
				}
			}
		}
		/**
		 * Filters the hreflang alternates of the current page.
		 *
		 * @param array<string,string> $alts [lang => url]
		 */
		return $alts = (array) apply_filters( 'ovml_alternates', $alts );
	}

	/** Whether the current page has real content in the request language. */
	public static function is_translated() {
		return ovml_is_default( ovml_lang() ) || isset( self::alternates()[ ovml_lang() ] );
	}

	public static function noindex_current() {
		return self::setting( 'noindex_untranslated' ) && ! self::is_translated();
	}

	public static function hreflang() {
		if ( ! self::setting( 'hreflang' ) ) {
			return;
		}
		$alts = self::alternates();
		if ( count( $alts ) < 2 ) {
			return;
		}
		foreach ( $alts as $lang => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $lang ), esc_url( $url ) );
		}
		$default = ovml_default_language();
		if ( self::setting( 'x_default' ) && isset( $alts[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $alts[ $default ] ) );
		}
	}

	public static function html_lang( $output ) {
		return ovml_is_default( ovml_lang() ) ? $output : preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( ovml_lang() ) . '"', $output );
	}

	public static function wp_robots( $robots ) {
		if ( self::noindex_current() ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/** Translated SEO title for the current page, or null to keep the original. */
	public static function title( $original ) {
		if ( ovml_is_default() ) {
			return null;
		}
		$object = self::queried_object();
		if ( is_singular() && PolylangData::is_separate( $object ) ) {
			return null; // a real post in its own language
		}
		if ( $object instanceof \WP_Post ) {
			$tr = ovml_post_tr( $object->ID, 'title' );
			return ovml_post_tr( $object->ID, 'seo_title' ) ?? ( null !== $tr ? self::format_title( $tr ) : ovml_t( $original ) );
		}
		if ( $object instanceof \WP_Term ) {
			$tr = ovml_term_tr( $object->term_id, 'name' );
			return ovml_term_tr( $object->term_id, 'seo_title' ) ?? ( null !== $tr ? self::format_title( $tr ) : ovml_t( $original ) );
		}
		return ovml_t( $original );
	}

	/** Title for items with a translated name but no SEO title, from the "title format" setting. */
	private static function format_title( $title ) {
		$format = (string) ( ovml_settings()['seo']['title_format'] ?? '' ) ?: '%title% - %site%';
		return strtr( $format, [ '%title%' => $title, '%site%' => get_bloginfo( 'name' ) ] );
	}

	/** Translated meta description for the current page, or null to keep the original. */
	public static function description( $original ) {
		if ( ovml_is_default() ) {
			return null;
		}
		$object = self::queried_object();
		if ( is_singular() && PolylangData::is_separate( $object ) ) {
			return null;
		}
		$tr = null;
		if ( $object instanceof \WP_Post ) {
			$tr = ovml_post_tr( $object->ID, 'seo_desc' ) ?? ovml_post_tr( $object->ID, 'excerpt' );
		} elseif ( $object instanceof \WP_Term ) {
			$tr = ovml_term_tr( $object->term_id, 'seo_desc' );
		}
		return null !== $tr ? wp_html_excerpt( wp_strip_all_tags( $tr ), 160, '' ) : ovml_t( $original );
	}

	/* ------------------------------------------------------------ sitemap -- */
	/*
	 * /ovml-sitemap.xml is an index of per-language sitemaps,
	 * /ovml-sitemap-{lang}-{page}.xml, each capped at SITEMAP_PAGE URLs so
	 * sites with many languages and products stay far below the 50,000-URL
	 * limit. URL lists are cached and cleared whenever content or
	 * translations change.
	 */

	const SITEMAP_PAGE = 1000;

	public static function sitemap_url() {
		return ovml_root() . self::SITEMAP_PATH;
	}

	public static function sitemap_page_url( $lang, $page ) {
		return ovml_root() . '/ovml-sitemap-' . rawurlencode( $lang ) . '-' . (int) $page . '.xml';
	}

	/** [url, ...] of every per-language sitemap page that has content. */
	public static function sitemap_pages() {
		$pages = [];
		foreach ( ovml_secondary_languages() as $lang ) {
			$count = count( self::sitemap_urls( $lang ) );
			for ( $page = 1; $page <= (int) ceil( $count / self::SITEMAP_PAGE ); $page++ ) {
				$pages[] = self::sitemap_page_url( $lang, $page );
			}
		}
		return $pages;
	}

	private static function is_noindex( $post_id ) {
		$rank_math = (array) get_post_meta( $post_id, 'rank_math_robots', true );
		return in_array( 'noindex', $rank_math, true ) || '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
	}

	public static function flush_sitemaps() {
		foreach ( array_keys( ovml_languages() ) as $lang ) {
			delete_transient( 'ovml_sitemap_' . $lang );
		}
	}

	/** Every URL in one language with real content (cached). */
	public static function sitemap_urls( $lang ) {
		$cached = get_transient( 'ovml_sitemap_' . $lang );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$urls  = [ ovml_url( ovml_root() . '/', $lang ) ];
		$types = array_diff( (array) ovml_settings()['post_types'], (array) ovml_settings()['separate_posts'] );
		$ids   = $types ? get_posts( [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => OVML_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- cached
		] ) : [];
		$front = (int) get_option( 'page_on_front' );
		foreach ( $ids as $id ) {
			if ( $front !== (int) $id && null !== ovml_post_tr( $id, 'title', $lang ) && ! self::is_noindex( $id ) ) {
				$urls[] = ovml_url( get_permalink( $id ), $lang );
			}
		}
		$terms = get_terms( [ 'taxonomy' => ovml_translatable_taxonomies(), 'hide_empty' => true ] );
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( null !== ovml_term_tr( $term->term_id, 'name', $lang ) ) {
				$urls[] = ovml_url( get_term_link( $term ), $lang );
			}
		}
		foreach ( (array) ovml_settings()['separate_posts'] as $type ) {
			foreach ( PolylangData::ids_in( $lang, $type ) as $id ) {
				if ( $id ) {
					$urls[] = get_permalink( $id );
				}
			}
		}
		$urls = array_values( array_unique( (array) apply_filters( 'ovml_sitemap_urls', $urls, $lang ) ) );
		set_transient( 'ovml_sitemap_' . $lang, $urls, 12 * HOUR_IN_SECONDS );
		return $urls;
	}

	public static function serve_sitemap() {
		$path = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
		$page = null;
		if ( self::SITEMAP_PATH !== $path ) {
			if ( ! preg_match( '#^/ovml-sitemap-([a-z]{2,3}(?:-[a-z]{2,4})?)-(\d+)\.xml$#', (string) $path, $m ) || ! in_array( $m[1], ovml_secondary_languages(), true ) ) {
				return;
			}
			$page = [ $m[1], max( 1, (int) $m[2] ) ];
		}
		if ( ! self::setting( 'sitemap' ) || ! ovml_enabled() ) {
			return;
		}
		$urls = null;
		if ( null !== $page ) {
			[ $lang, $number ] = $page;
			$urls = array_slice( self::sitemap_urls( $lang ), ( $number - 1 ) * self::SITEMAP_PAGE, self::SITEMAP_PAGE );
		}
		status_header( null !== $urls && ! $urls ? 404 : 200 ); // decided before any output
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( null === $page ) {
			echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach ( self::sitemap_pages() as $url ) {
				echo "\t<sitemap><loc>" . esc_url( $url ) . "</loc></sitemap>\n";
			}
			echo '</sitemapindex>';
			exit;
		}
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $url ) {
			echo "\t<url><loc>" . esc_url( $url ) . "</loc></url>\n";
		}
		echo '</urlset>';
		exit;
	}
}
