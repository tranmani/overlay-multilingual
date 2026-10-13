<?php
/**
 * Yoast SEO: translated title/description, robots, og:locale and the language
 * sitemap in Yoast's sitemap index.
 *
 * @package OverlayML
 */

namespace OverlayML\Integrations;

use OverlayML\SEO;

defined( 'ABSPATH' ) || exit;

class Yoast {

	public static function init() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		add_filter( 'wpseo_sitemap_index', [ __CLASS__, 'sitemap_index' ] );
		add_filter( 'ovml_sitemap_include_post', [ __CLASS__, 'include_post' ], 10, 2 );
		add_filter( 'ovml_sitemap_include_term', [ __CLASS__, 'include_term' ], 10, 2 );
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'wpseo_canonical', static fn( $canonical ) => $canonical && ! ovml_is_default( ovml_lang() ) ? ovml_url( $canonical, ovml_lang() ) : $canonical, 99 );
		add_filter( 'wpseo_title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'wpseo_opengraph_title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'wpseo_metadesc', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'wpseo_opengraph_desc', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'wpseo_robots', static fn( $robots ) => SEO::noindex_current() ? 'noindex, follow' : $robots, 99 );
		add_filter( 'wpseo_locale', static fn( $locale ) => ovml_is_default( ovml_lang() ) ? $locale : ( ovml_languages()[ ovml_lang() ]['og_locale'] ?? $locale ), 99 );
	}

	/** Language sitemaps list only what Yoast indexes, under its own canonical. */
	public static function include_post( $include, $post_id ) {
		return $include
			&& '1' !== get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true )
			&& ! SEO::canonical_elsewhere( get_post_meta( $post_id, '_yoast_wpseo_canonical', true ), get_permalink( $post_id ) );
	}

	public static function include_term( $include, $term ) {
		if ( ! $include ) {
			return false;
		}
		$meta = (array) ( get_option( 'wpseo_taxonomy_meta', [] )[ $term->taxonomy ][ $term->term_id ] ?? [] );
		$link = get_term_link( $term );
		return 'noindex' !== ( $meta['wpseo_noindex'] ?? '' ) && ! is_wp_error( $link ) && ! SEO::canonical_elsewhere( $meta['wpseo_canonical'] ?? '', $link );
	}

	public static function sitemap_index( $xml ) {
		if ( 'live' === ovml_settings()['status'] && ! empty( ovml_settings()['seo']['sitemap'] ) && ovml_secondary_languages() ) {
			foreach ( SEO::sitemap_pages() as $url ) {
				$xml .= sprintf( "<sitemap>\n<loc>%s</loc>\n<lastmod>%s</lastmod>\n</sitemap>\n", esc_url( $url ), gmdate( 'c' ) );
			}
		}
		return $xml;
	}
}
