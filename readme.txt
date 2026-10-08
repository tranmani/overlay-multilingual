=== Overlay Multilingual ===
Contributors: overlaymultilingual
Tags: multilingual, translation, woocommerce, hreflang, language switcher
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make a WordPress or WooCommerce site multilingual without duplicating content.

== Description ==

Overlay Multilingual layers translations over your existing posts, pages, products and terms. A product stays one product: the same stock, price, variations and order flow in every language. Nothing is copied, so nothing has to be kept in sync.

* **Language URLs** — your default language keeps its URLs; other languages live under a prefix such as `/fr/` and `/de/`.
* **WordPress, WooCommerce and theme text** switches language through the official language packs.
* **Your content** — titles, descriptions, excerpts, SEO title and meta description for any post type; names, descriptions and headings for any taxonomy, including WooCommerce attributes.
* **Everything else** — theme options, page-builder and slider text, menu labels — through a phrase dictionary with a built-in site scanner.
* **Written per language** — optionally keep some content (e.g. blog articles) as separate posts per language, linked as translations. Uses Polylang's data format, so you can migrate from Polylang without losing links.
* **WooCommerce** — translated cart, checkout and account pages; orders remember their language and customer emails are sent in it; order lines stay in your default language for admin and invoices.
* **SEO** — hreflang with x-default, translated html lang, noindex for untranslated pages, a sitemap of translated URLs; Rank Math and Yoast titles, descriptions, robots and og:locale.
* **Safe rollout** — Off, Preview (only for browsers that opened a secret link) and Live.
* **Language switcher** — shortcode, template tag or automatic placement on any theme hook; dropdown, inline or theme-native style.
* **Popups** — Brave Popup Builder popups are translated through the phrase list.
* **Automatic translation** — bring your own Google Translate, DeepL, Microsoft Translator or LibreTranslate account, or an AI model (Anthropic Claude, OpenAI) as an added service; translate missing phrases, whole content types or a single edit-screen tab, with HTML checked before saving.
* **Language packs** — WordPress, WooCommerce and plugin/theme text in each language is downloaded automatically.
* **Updates** — new GitHub releases appear in WordPress's normal updates with a one-click "Update now".
* **Developer friendly** — WP-CLI import/export in one JSON format, filters for everything, no external services, no tracking, no ads.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Languages → Languages** and add your languages.
3. Translate content on each edit screen, and phrases under **Languages → Strings**.
4. Switch to **Preview**, open the preview link, check the site, then switch to **Live**.

== Frequently Asked Questions ==

= Does it duplicate my products? =
No. Translations are stored as metadata on the original product, so stock and prices are shared.

= What does the early loader do? =
On activation a tiny must-use file is created so language detection runs before other plugins load their translations. You can reinstall it from the Overview screen.

= What happens to untranslated pages in another language? =
They still work for visitors (original text, translated interface) but are marked noindex and left out of hreflang and the sitemap, so they never compete with the original.

== Developer notes ==

Functions: `ovml_lang()`, `ovml_url( $url, $lang )`, `ovml_t( $text )`, `ovml_language_urls()`, `ovml_switcher()`.

Filters: `ovml_alternates`, `ovml_sitemap_urls`, `ovml_scan_urls`, `ovml_skip_path`, `ovml_switcher_urls`, `ovml_switcher_presets`, `ovml_switcher_theme_html`, `ovml_switcher_drop_query_args`.

WP-CLI: `wp ovml status`, `wp ovml import <file>`, `wp ovml export [--file=<file>]`, `wp ovml missing [--lang=<lang>] [--clear]`.

== Changelog ==

= 1.4.0 =
* "AI translation" is now **Automatic translation**: choose Google Translate (Cloud Translation API), DeepL (Free or Pro, with formal/informal address), Microsoft Translator (with region) or LibreTranslate (self-hostable) — or Anthropic Claude / OpenAI as an added AI service.
* One encrypted key per service, so switching services keeps your keys; `OVML_TRANSLATE_KEY` in wp-config.php works for any service.
* "Keep untranslated" list: brand names and terms every service leaves exactly as written.
* Language codes follow each language's WordPress locale (pt_BR, zh_TW, en_GB …) per service; per-language character replacements apply to every service.
* Settings from 1.3 (AI key, model) carry over automatically.

= 1.3.3 =
* The settings screens use the full width of the admin area.

= 1.3.2 =
* Fix: the "Latest Posts" block (e.g. in a blog sidebar) and Query Loop blocks listed posts of every language; they now show the current language only (separate-posts mode).

= 1.3.1 =
* A newly added language no longer shows an empty blog: until it has posts of its own, its blog and category listings show the default language's posts (noindexed and left out of hreflang), and single posts redirect to the version that exists.

= 1.3.0 =
* AI translation with your own Anthropic (Claude) or OpenAI API key: model chooser with custom model IDs, quality setting, instructions/glossary, Test connection, and "Translate with AI" for missing phrases, whole content types, or one edit-screen tab. HTML structure is verified before anything is saved.
* Language packs are installed automatically: adding a language downloads the WordPress core pack plus every available plugin and theme translation; "Install / update language packs" refreshes them.
* Built for many languages: Strings shows one language at a time beyond three, Content uses compact per-language chips, edit-screen tabs wrap and stay light, the switcher dropdown scrolls, and sitemaps are split per language and paginated (cached).
* Locale suggestions for every WordPress locale, with the native name filled in automatically.
* Elements marked translate="no" are never changed by the phrase list (switcher language names stay in their own language).
* Update check runs in place (no page reload) and no longer claims "up to date" before checking.

= 1.2.0 =
* About tab with links to the GitHub project, issues and releases, and copyable system information for bug reports.
* "View on GitHub" and "Report an issue" links on the Plugins screen.

= 1.1.0 =
* One-click updates from GitHub releases (Dashboard → Updates, Plugins screen and the Overview).
* Brave Popup Builder popups are translated.
* Site scan compares default and translated pages, so only untranslated text is listed.
* More settings: detection scope, cookie lifetime, switcher behaviour for untranslated pages, x-default, SEO title format, WooCommerce options, excluded paths, preview badge.

= 1.0.0 =
* First release.
