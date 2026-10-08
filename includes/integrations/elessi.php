<?php
/**
 * Elessi theme (NasaTheme): native top-bar switcher and translated archive H1.
 *
 * Elessi prints its language/currency block on `nasa_topbar_menu` and
 * `nasa_mobile_topbar_menu` (priority 10) using WPML markup. With the switcher
 * placed on that hook at priority 9 and style "theme", the same markup is
 * printed so it sits beside the currency switcher with the theme's styling.
 *
 * @package OverlayML
 */

namespace OverlayML\Integrations;

defined( 'ABSPATH' ) || exit;

class Elessi {

	public static function active() {
		return 'elessi-theme' === get_template();
	}

	public static function init() {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'ovml_switcher_presets', [ __CLASS__, 'preset' ] );
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'ovml_switcher_theme_html', [ __CLASS__, 'markup' ], 10, 3 );
		// Mirror the desktop placement in the mobile top bar.
		$s = ovml_settings()['switcher'];
		if ( 'hook' === $s['placement'] && 'nasa_topbar_menu' === $s['hook'] ) {
			add_action( 'nasa_mobile_topbar_menu', [ \OverlayML\Switcher::class, 'print' ], (int) $s['priority'] );
		}
		add_filter( 'nasa_title_first_breadcrumb', [ __CLASS__, 'archive_heading' ], 50 );
	}

	public static function preset( $presets ) {
		$presets['elessi'] = [
			'label'    => __( 'Elessi top bar, next to the currency switcher', 'overlay-multilingual' ),
			'hook'     => 'nasa_topbar_menu',
			'priority' => 9,
			'style'    => 'theme',
		];
		return $presets;
	}

	public static function markup( $html, $links, $current ) {
		$arrow = '<svg class="nasa-open-child" width="20" height="20" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M15.233 19.175l0.754 0.754 6.035-6.035-0.754-0.754-5.281 5.281-5.256-5.256-0.754 0.754 3.013 3.013z"></path></svg>';
		$items = '';
		foreach ( $links as $lang => $link ) {
			if ( ! $link['current'] ) {
				$items .= sprintf( '<li class="nasa-item-lang"><a href="%s" hreflang="%s" lang="%s" translate="no">%s</a></li>', esc_url( $link['url'] ), esc_attr( $lang ), esc_attr( $lang ), esc_html( $link['label'] ) );
			}
		}
		return sprintf(
			'<ul class="header-multi-languages ovml-switcher-elessi left rtl-right"><li class="nasa-select-languages left rtl-right desktop-margin-right-30 rtl-desktop-margin-right-0 rtl-desktop-margin-left-30 menu-item-has-children root-item li_accordion"><a href="javascript:void(0);" class="nasa-current-lang" rel="nofollow" translate="no">%s</a><ul class="nasa-list-languages sub-menu">%s</ul>%s</li></ul>',
			esc_html( $links[ $current ]['label'] ?? strtoupper( $current ) ),
			$items,
			$arrow
		);
	}

	/** Elessi prints archive titles as the first breadcrumb item. */
	public static function archive_heading( $title ) {
		if ( ovml_is_default() ) {
			return $title;
		}
		$object = get_queried_object();
		if ( $object instanceof \WP_Term ) {
			$tr = ovml_term_tr( $object->term_id, 'h1' ) ?? ovml_term_tr( $object->term_id, 'name' );
			return null !== $tr ? esc_html( $tr ) : $title;
		}
		return esc_html( ovml_t( wp_specialchars_decode( wp_strip_all_tags( $title ) ) ) );
	}
}
