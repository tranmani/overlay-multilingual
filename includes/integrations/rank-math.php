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
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'rank_math/frontend/title', static fn( $title ) => SEO::title( $title ) ?? $title, 99 );
		add_filter( 'rank_math/frontend/description', static fn( $desc ) => SEO::description( $desc ) ?? $desc, 99 );
		add_filter( 'rank_math/frontend/robots', [ __CLASS__, 'robots' ], 99 );
		add_filter( 'rank_math/opengraph/facebook/og_locale', [ __CLASS__, 'og_locale' ], 99 );
		add_filter( 'rank_math/schema/language', static fn( $language ) => ovml_is_default( ovml_lang() ) ? $language : ovml_lang(), 99 );
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
