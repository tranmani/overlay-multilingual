<?php
/**
 * Rank Math: translated title/description, robots, og:locale, schema language,
 * and the language sitemap in Rank Math's sitemap index.
 *
 * @package OverlayML
 */

namespace OverlayML\Integrations;

use OverlayML\SEO;

defined( 'ABSPATH' ) || exit;

class RankMath {

	public static function init() {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		add_filter( 'rank_math/sitemap/index', [ __CLASS__, 'sitemap_index' ] );
		add_filter( 'ovml_sitemap_include_post', [ __CLASS__, 'include_post' ], 10, 2 );
		add_filter( 'ovml_sitemap_include_term', [ __CLASS__, 'include_term' ], 10, 2 );
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'rank_math/frontend/canonical', [ __CLASS__, 'localize' ], 99 );
		// Rank Math Redirections: keep the target in the visitor's language. Its last filter before wp_redirect() marks
		// the redirect as Rank Math's, so other redirects are left alone.
		add_filter( 'rank_math/redirection/add_query_string', static function ( $add ) {
			self::$redirecting = true;
			return $add;
		}, 99 );
		add_filter( 'wp_redirect', static fn( $location ) => self::$redirecting ? self::localize( $location ) : $location, 99 );
		add_filter( 'rank_math/frontend/title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'rank_math/frontend/description', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'rank_math/frontend/robots', [ __CLASS__, 'robots' ], 99 );
		add_filter( 'rank_math/opengraph/facebook/og_locale', [ __CLASS__, 'og_locale' ], 99 );
		add_filter( 'rank_math/schema/language', static fn( $language ) => ovml_is_default( ovml_lang() ) ? $language : ovml_lang(), 99 );
	}

	/** Rank Math's robots setting for a post type / taxonomy ("Titles & Meta"), used when the item has none of its own. */
	private static function default_noindex( $prefix, $name ) {
		$titles = (array) get_option( 'rank-math-options-titles', [] );
		$own    = 'on' === ( $titles[ "{$prefix}_{$name}_custom_robots" ] ?? '' );
		$robots = (array) ( $own ? ( $titles[ "{$prefix}_{$name}_robots" ] ?? [] ) : ( $titles['robots_global'] ?? [] ) );
		return in_array( 'noindex', $robots, true );
	}

	/** Language sitemaps list only what Rank Math indexes, under its own canonical. */
	public static function include_post( $include, $post_id ) {
		if ( ! $include ) {
			return false;
		}
		$robots = (array) get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = array_filter( $robots );
		if ( $robots ? in_array( 'noindex', $robots, true ) : self::default_noindex( 'pt', get_post_type( $post_id ) ) ) {
			return false;
		}
		return ! SEO::canonical_elsewhere( get_post_meta( $post_id, 'rank_math_canonical_url', true ), get_permalink( $post_id ) );
	}

	public static function include_term( $include, $term ) {
		if ( ! $include ) {
			return false;
		}
		$robots = array_filter( (array) get_term_meta( $term->term_id, 'rank_math_robots', true ) );
		if ( $robots ? in_array( 'noindex', $robots, true ) : self::default_noindex( 'tax', $term->taxonomy ) ) {
			return false;
		}
		$link = get_term_link( $term );
		return ! is_wp_error( $link ) && ! SEO::canonical_elsewhere( get_term_meta( $term->term_id, 'rank_math_canonical_url', true ), $link );
	}

	/** Set while Rank Math is sending one of its redirections. */
	private static $redirecting = false;

	/**
	 * A manual canonical or redirection target inside the site stays in the visitor's
	 * language (/de/brand/x/ -> /de/x-page/ instead of the default-language page),
	 * unless that target has no translation.
	 */
	public static function localize( $url ) {
		$lang = ovml_lang();
		if ( ! $url || ovml_is_default( $lang ) ) {
			return $url;
		}
		$local = ovml_url( $url, $lang );
		if ( $local === $url ) {
			return $url;
		}
		$target = url_to_postid( $url );
		// Posts written per language have their own URLs; a default-language one has no /xx/ twin.
		if ( $target && ( \OverlayML\PolylangData::is_separate( $target ) || null === ovml_post_tr( $target, 'title', $lang ) ) ) {
			return $url;
		}
		return $local;
	}

	public static function robots( $robots ) {
		if ( SEO::noindex_current() ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}

	public static function og_locale( $locale ) {
		return ovml_is_default( ovml_lang() ) ? $locale : ( ovml_languages()[ ovml_lang() ]['og_locale'] ?? $locale );
	}

	public static function sitemap_index( $xml ) {
		if ( 'live' === ovml_settings()['status'] && ! empty( ovml_settings()['seo']['sitemap'] ) && ovml_secondary_languages() ) {
			foreach ( SEO::sitemap_pages() as $url ) {
				$xml .= sprintf( "	<sitemap>\n		<loc>%s</loc>\n		<lastmod>%s</lastmod>\n	</sitemap>\n", esc_url( $url ), gmdate( 'c' ) );
			}
		}
		return $xml;
	}
}
