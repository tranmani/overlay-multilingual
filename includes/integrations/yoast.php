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
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'wpseo_title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'wpseo_opengraph_title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'wpseo_metadesc', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'wpseo_opengraph_desc', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'wpseo_robots', static fn( $robots ) => SEO::noindex_current() ? 'noindex, follow' : $robots, 99 );
		add_filter( 'wpseo_locale', static fn( $locale ) => ovml_is_default( ovml_lang() ) ? $locale : ( ovml_languages()[ ovml_lang() ]['og_locale'] ?? $locale ), 99 );
	}

	public static function sitemap_index( $xml ) {
		if ( 'live' === ovml_settings()['status'] && ! empty( ovml_settings()['seo']['sitemap'] ) && ovml_secondary_languages() ) {
			$xml .= sprintf( "<sitemap>\n<loc>%s</loc>\n<lastmod>%s</lastmod>\n</sitemap>\n", esc_url( SEO::sitemap_url() ), gmdate( 'c' ) );
		}
		return $xml;
	}
}
