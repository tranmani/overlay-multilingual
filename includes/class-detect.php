<?php
/**
 * Optional: match the visitor's device language on their first visit.
 *
 * Done in the browser, not on the server, on purpose: a server redirect is
 * invisible to pages served from a CDN cache (or worse, gets cached and sent to
 * everyone), and crawlers must never be redirected by language. A tiny inline
 * script reads navigator.languages; when there is no `ovml_lang` cookie yet and
 * the visitor landed on a default-language page whose translation exists (a
 * hreflang alternate is present), it moves them there once. The cookie then
 * records the language of every page viewed, so a manual switch is remembered
 * and the redirect never happens again.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class Detect {

	public static function init() {
		if ( ! ovml_enabled() || empty( ovml_settings()['detect_language'] ) || count( ovml_languages() ) < 2 ) {
			return;
		}
		// After hreflang links (priority 2) so the script can read them.
		add_action( 'wp_head', [ __CLASS__, 'script' ], 3 );
	}

	public static function script() {
		$s      = ovml_settings();
		$config = [
			'current'   => ovml_lang(),
			'default'   => ovml_default_language(),
			'languages' => array_keys( ovml_languages() ),
			'homeOnly'  => 'home' === $s['detect_scope'],
			'maxAge'    => max( 1, (int) $s['cookie_days'] ) * DAY_IN_SECONDS,
		];
		?>
<script id="ovml-detect">
(function (c) {
	var name = 'ovml_lang', secure = location.protocol === 'https:' ? ';Secure' : '';
	var remember = function (lang) { document.cookie = name + '=' + lang + ';path=/;max-age=' + c.maxAge + ';SameSite=Lax' + secure; };
	var seen = document.cookie.match(/(?:^|; )ovml_lang=([^;]+)/);
	if (seen) { if (seen[1] !== c.current) { remember(c.current); } return; }
	var pick = null;
	(navigator.languages || [navigator.language || '']).some(function (tag) {
		tag = String(tag).toLowerCase();
		if (c.languages.indexOf(tag) > -1) { pick = tag; return true; }
		tag = tag.split('-')[0];
		if (c.languages.indexOf(tag) > -1) { pick = tag; return true; }
		return false;
	});
	var onHome = location.pathname === '/' || location.pathname === '';
	if (pick && pick !== c.current && c.current === c.default && (!c.homeOnly || onHome)) {
		var alt = document.querySelector('link[rel="alternate"][hreflang="' + pick + '"]');
		if (alt) { remember(pick); location.replace(alt.href + location.hash); return; }
	}
	remember(c.current);
})(<?php echo wp_json_encode( $config ); ?>);
</script>
		<?php
	}
}
