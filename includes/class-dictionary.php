<?php
/**
 * Phrase dictionary for text that is neither post/term data nor covered by
 * language packs: theme options, page-builder and slider text, custom menu
 * labels, widgets.
 *
 * Exact matches only. A visible text node (whitespace collapsed) or an alt,
 * title, placeholder or aria-label attribute equal to a source phrase is
 * replaced; <script>, <style>, <textarea> and form values are never touched,
 * so nothing a form submits can change. Stored per language in the options
 * `ovml_dict_{lang}` as [source => translation].
 *
 * While scanning (?ovml_collect=1 on a preview request) untranslated phrases
 * are recorded in `ovml_missing` for the Strings screen.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class Dictionary {

	private static $cache = [];

	public static function init() {
		if ( ! ovml_enabled() ) {
			return;
		}
		add_action( 'template_redirect', [ __CLASS__, 'start_buffer' ], 99 );
		add_action( 'shutdown', [ __CLASS__, 'save_missing' ], 5 ); // after wp_ob_end_flush_all() at priority 1
	}

	public static function get( $lang ) {
		if ( ! isset( self::$cache[ $lang ] ) ) {
			$dict                 = get_option( "ovml_dict_$lang", [] );
			self::$cache[ $lang ] = is_array( $dict ) ? $dict : [];
		}
		return self::$cache[ $lang ];
	}

	public static function save( $lang, array $dict ) {
		self::$cache[ $lang ] = $dict;
		update_option( "ovml_dict_$lang", $dict, false );
	}

	public static function sources() {
		$keys = [];
		foreach ( ovml_secondary_languages() as $lang ) {
			$keys = array_merge( $keys, array_keys( self::get( $lang ) ) );
		}
		$keys = array_merge( $keys, array_keys( (array) get_option( 'ovml_missing', [] ) ) );
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		sort( $keys, SORT_NATURAL | SORT_FLAG_CASE );
		return $keys;
	}

	public static function start_buffer() {
		if ( ovml_is_default( ovml_lang() ) || is_feed() || is_robots() || wp_doing_ajax() ) {
			return;
		}
		$GLOBALS['ovml_collecting'] = ! empty( $_GET['ovml_collect'] ) && ovml_has_preview_cookie();
		// A closure, because PHP passes buffer callbacks a second argument (the
		// buffer phase flags), which must not be mistaken for a language code.
		ob_start( static fn( $html ) => self::translate_html( $html ) );
	}

	/** Strings worth collecting: words, not prices, codes or addresses. */
	private static function collectable( $key ) {
		return mb_strlen( $key ) >= 2 && mb_strlen( $key ) <= 500
			&& preg_match( '/\p{L}{2,}/u', $key )
			&& ! preg_match( '#^(https?://|www\.|[\w.+-]+@[\w-]+\.)#i', $key )
			&& ! preg_match( '/^[\p{Sc}A-Z]{0,3}\s*[\d.,\s\'’]+\s*[\p{Sc}A-Z]{0,3}$/u', $key );
	}

	public static function translate_html( $html, $lang = null ) {
		$lang = $lang ?? ovml_tlang();
		if ( ovml_is_default( $lang ) || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$dict    = self::get( $lang );
		$collect = ! empty( $GLOBALS['ovml_collecting'] );
		$missing = [];

		$swap = static function ( $raw ) use ( $dict, $collect, &$missing ) {
			$key = ovml_normalise( $raw );
			if ( '' === $key ) {
				return null;
			}
			if ( isset( $dict[ $key ] ) && '' !== $dict[ $key ] ) {
				return $dict[ $key ];
			}
			if ( $collect && self::collectable( $key ) ) {
				$missing[ $key ] = true;
			}
			return null;
		};

		$parts = preg_split( '#(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<!--.*?-->)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( $i % 2 ) {
				continue; // protected block
			}
			$part = preg_replace_callback( '#>([^<]+)<#u', static function ( $m ) use ( $swap ) {
				$new = $swap( $m[1] );
				if ( null === $new ) {
					return $m[0];
				}
				preg_match( '/^\s*/u', $m[1], $lead );
				preg_match( '/\s*$/u', $m[1], $trail );
				return '>' . $lead[0] . esc_html( $new ) . $trail[0] . '<';
			}, $part );
			if ( ! empty( ovml_settings()['advanced']['translate_attributes'] ) ) {
				$part = preg_replace_callback( '#\s(alt|title|placeholder|aria-label|data-title)="([^"]+)"#u', static function ( $m ) use ( $swap ) {
					$new = $swap( $m[2] );
					return null === $new ? $m[0] : ' ' . $m[1] . '="' . esc_attr( $new ) . '"';
				}, $part );
			}
			$part = preg_replace_callback( '#<input\b[^>]*\btype=["\'](?:submit|button)["\'][^>]*>#iu', static function ( $m ) use ( $swap ) {
				return preg_replace_callback( '#\svalue="([^"]+)"#u', static function ( $v ) use ( $swap ) {
					$new = $swap( $v[1] );
					return null === $new ? $v[0] : ' value="' . esc_attr( $new ) . '"';
				}, $m[0] );
			}, $part );
			$part        = Router::rewrite_links( $part, $lang );
			$parts[ $i ] = self::replace_text_only( $part, $lang );
		}

		if ( $missing ) {
			$GLOBALS['ovml_missing'] = array_merge( $GLOBALS['ovml_missing'] ?? [], $missing );
		}
		return implode( '', $parts );
	}

	/** Apply a language's character replacements to text nodes, not to markup. */
	private static function replace_text_only( $html, $lang ) {
		$map = ovml_languages()[ $lang ]['replace'] ?? [];
		if ( ! $map ) {
			return $html;
		}
		return preg_replace_callback( '#>([^<]+)<#u', static fn( $m ) => '>' . strtr( $m[1], $map ) . '<', $html );
	}

	public static function save_missing() {
		if ( empty( $GLOBALS['ovml_missing'] ) ) {
			return;
		}
		$all = (array) get_option( 'ovml_missing', [] );
		foreach ( array_keys( $GLOBALS['ovml_missing'] ) as $key ) {
			$all[ $key ] = true;
		}
		update_option( 'ovml_missing', $all, false );
	}
}
