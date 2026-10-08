<?php
/**
 * Language switcher: shortcode [ovml_switcher], template tag ovml_switcher(),
 * or automatic placement on any theme action hook (Settings → Switcher).
 *
 * Styles: "dropdown" and "inline" ship with a small stylesheet; "theme" hands
 * the links to the `ovml_switcher_theme_html` filter so a theme integration
 * can print its own native markup (see integrations/elessi.php).
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class Switcher {

	public static function init() {
		add_shortcode( 'ovml_switcher', static fn( $atts ) => self::render( (array) $atts ) );
		if ( ! ovml_enabled() ) {
			return;
		}
		$s = ovml_settings()['switcher'];
		if ( 'hook' === $s['placement'] && '' !== $s['hook'] ) {
			add_action( $s['hook'], [ __CLASS__, 'print' ], (int) $s['priority'] );
		}
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		if ( ovml_is_preview() && ! empty( ovml_settings()['preview_badge'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'preview_badge' ], 99 );
		}
	}

	/** A small badge so whoever is previewing knows they are, with a one-click exit. */
	public static function preview_badge() {
		printf(
			'<div class="ovml-preview-badge" role="status"><strong>%s</strong> %s <a href="%s">%s</a></div>',
			esc_html__( 'Translation preview', 'overlay-multilingual' ),
			esc_html( ovml_languages()[ ovml_lang() ]['name'] ?? '' ),
			esc_url( add_query_arg( 'ovml_preview', 'off', ovml_url( ovml_current_url(), ovml_default_language() ) ) ),
			esc_html__( 'Exit', 'overlay-multilingual' )
		);
	}

	public static function assets() {
		wp_enqueue_style( 'ovml-switcher', OVML_URL . 'assets/switcher.css', [], OVML_VERSION );
	}

	public static function print() {
		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
	}

	/** [lang => url] for the current page in every language. */
	public static function urls() {
		$here   = ovml_clean_url( ovml_current_url() );
		$object = get_queried_object();
		$urls   = [];
		$group  = ( is_singular() && PolylangData::is_separate( $object ) ) ? PolylangData::group( $object->ID ) : null;
		$blog   = (int) get_option( 'page_for_posts' );

		// Languages the current page has no translation in (only judged where the page has alternates).
		$alts         = SEO::alternates();
		$untranslated = (string) ( ovml_settings()['switcher']['untranslated'] ?? 'show' );

		foreach ( array_keys( ovml_languages() ) as $lang ) {
			if ( null === $group && $alts && ! isset( $alts[ $lang ] ) && $lang !== ovml_lang() && 'show' !== $untranslated ) {
				if ( 'home' === $untranslated ) {
					$urls[ $lang ] = ovml_url( ovml_root() . '/', $lang );
				}
				continue; // 'hide'
			}
			if ( null === $group ) {
				$urls[ $lang ] = ovml_url( $here, $lang );
			} elseif ( isset( $group[ $lang ] ) ) {
				$urls[ $lang ] = ovml_url( get_permalink( $group[ $lang ] ), $lang );
			} else {
				// No version of this article in that language: go to that language's blog.
				$urls[ $lang ] = ovml_url( $blog ? get_permalink( $blog ) : ovml_root() . '/', $lang );
			}
		}
		return (array) apply_filters( 'ovml_switcher_urls', $urls );
	}

	/** Label for a language according to the switcher setting. */
	public static function label( $lang, $mode = null ) {
		$mode = $mode ?? ovml_settings()['switcher']['label'];
		$name = ovml_languages()[ $lang ]['name'] ?? strtoupper( $lang );
		return match ( $mode ) {
			'code' => strtoupper( $lang ),
			'both' => $name . ' (' . strtoupper( $lang ) . ')',
			default => $name,
		};
	}

	public static function render( array $args = [] ) {
		if ( ! ovml_enabled() || count( ovml_languages() ) < 2 ) {
			return '';
		}
		$style   = $args['style'] ?? ovml_settings()['switcher']['style'];
		$current = ovml_lang();
		$links   = [];
		foreach ( self::urls() as $lang => $url ) {
			$links[ $lang ] = [
				'url'     => $url,
				'label'   => self::label( $lang, $args['label'] ?? null ),
				'current' => $lang === $current,
			];
		}

		if ( 'theme' === $style ) {
			/**
			 * Lets a theme integration print native switcher markup.
			 *
			 * @param string $html    Empty string; return markup to use it.
			 * @param array  $links   [lang => [url, label, current]]
			 * @param string $current Current language code.
			 */
			$html = (string) apply_filters( 'ovml_switcher_theme_html', '', $links, $current );
			if ( '' !== $html ) {
				return $html;
			}
			$style = 'dropdown';
		}

		$items       = '';
		$hide_current = ! empty( ovml_settings()['switcher']['hide_current'] ) && 'inline' === $style;
		foreach ( $links as $lang => $link ) {
			if ( $hide_current && $link['current'] ) {
				continue;
			}
			$items .= sprintf(
				'<li class="ovml-switcher__item%s"><a href="%s" hreflang="%s" lang="%s"%s>%s</a></li>',
				$link['current'] ? ' is-current' : '',
				esc_url( $link['url'] ),
				esc_attr( $lang ),
				esc_attr( $lang ),
				$link['current'] ? ' aria-current="true"' : '',
				esc_html( $link['label'] )
			);
		}

		if ( 'inline' === $style ) {
			return '<nav class="ovml-switcher ovml-switcher--inline" aria-label="' . esc_attr__( 'Language', 'overlay-multilingual' ) . '"><ul>' . $items . '</ul></nav>';
		}

		return sprintf(
			'<details class="ovml-switcher ovml-switcher--dropdown"><summary aria-label="%s"><span>%s</span></summary><ul>%s</ul></details>',
			esc_attr__( 'Change language', 'overlay-multilingual' ),
			esc_html( $links[ $current ]['label'] ?? strtoupper( $current ) ),
			$items
		);
	}
}
