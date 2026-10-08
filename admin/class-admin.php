<?php
/**
 * Admin screens: Overview, Languages, Strings, Content, Settings.
 *
 * @package OverlayML
 */

namespace OverlayML\Admin;

use OverlayML\Dictionary;
use OverlayML\PolylangData;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'overlay-multilingual';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
		add_action( 'admin_post_ovml_export_strings', [ __CLASS__, 'export_strings' ] );
		add_action( 'wp_ajax_ovml_save_string', [ __CLASS__, 'ajax_save_string' ] );
		add_action( 'wp_ajax_ovml_delete_string', [ __CLASS__, 'ajax_delete_string' ] );
		add_action( 'wp_ajax_ovml_scan', [ __CLASS__, 'ajax_scan' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( OVML_FILE ), [ __CLASS__, 'action_links' ] );
	}

	public static function url( $tab = 'overview', $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Languages', 'overlay-multilingual' ),
			__( 'Languages', 'overlay-multilingual' ),
			self::CAP,
			self::SLUG,
			[ __CLASS__, 'page' ],
			'dashicons-translation',
			81
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'overlay-multilingual' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$ours    = 'toplevel_page_' . self::SLUG === $hook;
		$editing = $screen && ( ( 'post' === $screen->base && ovml_translatable_post_type( $screen->post_type ) ) || ( 'term' === $screen->base && in_array( $screen->taxonomy, ovml_translatable_taxonomies(), true ) ) || ( 'post' === $screen->base && in_array( $screen->post_type, (array) ovml_settings()['separate_posts'], true ) ) );
		if ( ! $ours && ! $editing ) {
			return;
		}
		wp_enqueue_style( 'ovml-admin', OVML_URL . 'assets/admin.css', [], OVML_VERSION );
		wp_enqueue_script( 'ovml-admin', OVML_URL . 'assets/admin.js', [], OVML_VERSION, true );
		wp_localize_script( 'ovml-admin', 'ovmlAdmin', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ovml_ajax' ),
			'i18n'  => [
				'confirmLive'   => __( 'Go live? Every visitor will see the language switcher and the translated URLs.', 'overlay-multilingual' ),
				'confirmDelete' => __( 'Delete this phrase and its translations?', 'overlay-multilingual' ),
				'scanning'      => __( 'Scanning %1$d of %2$d pages…', 'overlay-multilingual' ),
				'scanDone'      => __( 'Scan complete: %d new phrases found. Reloading…', 'overlay-multilingual' ),
				'copied'        => __( 'Copied', 'overlay-multilingual' ),
				'error'         => __( 'Could not save. Check your connection and try again.', 'overlay-multilingual' ),
			],
		] );
	}

	/* ------------------------------------------------------------- layout -- */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = [
			'overview'  => __( 'Overview', 'overlay-multilingual' ),
			'languages' => __( 'Languages', 'overlay-multilingual' ),
			'strings'   => __( 'Strings', 'overlay-multilingual' ),
			'content'   => __( 'Content', 'overlay-multilingual' ),
			'settings'  => __( 'Settings', 'overlay-multilingual' ),
		];
		$tab  = isset( $_GET['tab'], $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$status = ovml_settings()['status'];
		?>
		<div class="ovml">
			<div class="ovml-header">
				<h1><?php esc_html_e( 'Languages', 'overlay-multilingual' ); ?></h1>
				<?php self::status_pill( $status ); ?>
			</div>
			<p class="ovml-sub"><?php esc_html_e( 'Translations are layered over your existing content: one product, one stock level and one checkout in every language.', 'overlay-multilingual' ); ?></p>
			<?php self::notices(); ?>
			<nav class="ovml-tabs" aria-label="<?php esc_attr_e( 'Sections', 'overlay-multilingual' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( [ __CLASS__, 'tab_' . $tab ] ); ?>
		</div>
		<?php
	}

	private static function status_pill( $status ) {
		$map = [
			'off'     => [ 'off', __( 'Off', 'overlay-multilingual' ) ],
			'preview' => [ 'warn', __( 'Preview', 'overlay-multilingual' ) ],
			'live'    => [ 'live', __( 'Live', 'overlay-multilingual' ) ],
		];
		[ $class, $label ] = $map[ $status ] ?? $map['off'];
		printf( '<span class="ovml-pill ovml-pill--%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	private static function notices() {
		$notice = get_transient( 'ovml_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'ovml_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
		}
	}

	private static function flash( $text, $type = 'success' ) {
		set_transient( 'ovml_notice_' . get_current_user_id(), [ 'text' => $text, 'type' => $type ], 60 );
	}

	/* ----------------------------------------------------------- coverage -- */

	/** [lang => [posts_done, posts_total, terms_done, terms_total, strings_done, strings_total]] */
	private static function coverage() {
		global $wpdb;
		$types = array_values( array_diff( (array) ovml_settings()['post_types'], (array) ovml_settings()['separate_posts'] ) );
		$posts = $types ? get_posts( [ 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] ) : [];
		$terms = get_terms( [ 'taxonomy' => ovml_translatable_taxonomies(), 'hide_empty' => false, 'fields' => 'ids' ] );
		$terms = is_array( $terms ) ? $terms : [];
		$strings = Dictionary::sources();

		$post_meta = $posts ? $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( OVML_META ) . "' AND post_id IN (" . implode( ',', array_map( 'intval', $posts ) ) . ')', OBJECT_K ) : [];
		$term_meta = $terms ? $wpdb->get_results( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = '" . esc_sql( OVML_META ) . "' AND term_id IN (" . implode( ',', array_map( 'intval', $terms ) ) . ')', OBJECT_K ) : [];

		$out = [];
		foreach ( ovml_secondary_languages() as $lang ) {
			$p = 0;
			foreach ( $post_meta as $row ) {
				$tr = maybe_unserialize( $row->meta_value );
				$p += ! empty( $tr[ $lang ]['title'] ) ? 1 : 0;
			}
			$t = 0;
			foreach ( $term_meta as $row ) {
				$tr = maybe_unserialize( $row->meta_value );
				$t += ! empty( $tr[ $lang ]['name'] ) ? 1 : 0;
			}
			$dict = Dictionary::get( $lang );
			$s    = count( array_filter( $strings, static fn( $k ) => isset( $dict[ $k ] ) && '' !== $dict[ $k ] ) );
			$out[ $lang ] = [ $p, count( $posts ), $t, count( $terms ), $s, count( $strings ) ];
		}
		return $out;
	}

	private static function bar( $label, $done, $total ) {
		$pct = $total ? round( 100 * $done / $total ) : 0;
		printf(
			'<span>%s</span><div class="ovml-progress" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100"><span style="width:%d%%"></span></div><span class="num">%d / %d</span>',
			esc_html( $label ),
			(int) $pct,
			(int) $pct,
			(int) $done,
			(int) $total
		);
	}

	/* ----------------------------------------------------------- overview -- */

	public static function tab_overview() {
		$s        = ovml_settings();
		$preview  = add_query_arg( 'ovml_preview', $s['preview_key'], home_url( '/' ) );
		$early    = file_exists( ovml_early_loader_path() );
		$coverage = ovml_secondary_languages() ? self::coverage() : [];
		$pct      = 0;
		if ( $coverage ) {
			$done  = array_sum( array_map( static fn( $c ) => $c[0] + $c[2] + $c[4], $coverage ) );
			$total = array_sum( array_map( static fn( $c ) => $c[1] + $c[3] + $c[5], $coverage ) );
			$pct   = $total ? (int) round( 100 * $done / $total ) : 0;
		}
		$steps = [
			[ __( 'Add a language', 'overlay-multilingual' ), (bool) ovml_secondary_languages(), self::url( 'languages' ) ],
			[ __( 'Choose what gets translated', 'overlay-multilingual' ), $s['post_types'] || $s['separate_posts'] || $s['taxonomies'], self::url( 'settings' ) ],
			[ __( 'Place the language switcher', 'overlay-multilingual' ), 'hook' === $s['switcher']['placement'], self::url( 'settings' ) ],
			/* translators: %d: percentage translated */
			[ sprintf( __( 'Translate your content (%d%% done)', 'overlay-multilingual' ), $pct ), $pct >= 90, self::url( 'content' ) ],
			[ __( 'Preview the site in each language', 'overlay-multilingual' ), 'off' !== $s['status'], '#ovml-status' ],
			[ __( 'Go live', 'overlay-multilingual' ), 'live' === $s['status'], '#ovml-status' ],
		];
		$all_done = ! in_array( false, array_column( $steps, 1 ), true );
		?>
		<?php if ( ! $all_done ) : ?>
			<div class="ovml-card">
				<h2><?php esc_html_e( 'Setup', 'overlay-multilingual' ); ?></h2>
				<ol class="ovml-steps">
					<?php foreach ( $steps as [ $label, $done, $url ] ) : ?>
						<li class="<?php echo $done ? 'is-done' : ''; ?>"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a><?php echo $done ? '<span class="screen-reader-text">' . esc_html__( '(done)', 'overlay-multilingual' ) . '</span>' : ''; ?></li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php endif; ?>
		<div class="ovml-card" id="ovml-status">
			<form method="post" class="ovml-card-row" data-ovml-status-form>
				<?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?>
				<input type="hidden" name="ovml_action" value="status">
				<input type="hidden" name="ovml_was" value="<?php echo esc_attr( $s['status'] ); ?>">
				<div>
					<h2><?php esc_html_e( 'Status', 'overlay-multilingual' ); ?></h2>
					<p class="ovml-hint" style="margin:0"><?php esc_html_e( 'Preview shows translations only in browsers that opened the preview link. Live shows them to everyone.', 'overlay-multilingual' ); ?></p>
				</div>
				<div style="display:flex;gap:10px;align-items:center">
					<div class="ovml-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Status', 'overlay-multilingual' ); ?>">
						<?php foreach ( [ 'off' => __( 'Off', 'overlay-multilingual' ), 'preview' => __( 'Preview', 'overlay-multilingual' ), 'live' => __( 'Live', 'overlay-multilingual' ) ] as $value => $label ) : ?>
							<label><input type="radio" name="status" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['status'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<button class="button button-primary"><?php esc_html_e( 'Save', 'overlay-multilingual' ); ?></button>
				</div>
			</form>
		</div>

		<?php if ( 'live' !== $s['status'] ) : ?>
			<div class="ovml-card">
				<h2><?php esc_html_e( 'Preview link', 'overlay-multilingual' ); ?></h2>
				<p class="ovml-hint"><?php esc_html_e( 'Open this link once in a browser to see the site with translations while it is in Preview. Add ?ovml_preview=off to any URL to leave preview.', 'overlay-multilingual' ); ?></p>
				<div class="ovml-copy">
					<input type="text" readonly value="<?php echo esc_attr( $preview ); ?>" id="ovml-preview-link" aria-label="<?php esc_attr_e( 'Preview link', 'overlay-multilingual' ); ?>">
					<button type="button" class="button" data-ovml-copy="#ovml-preview-link"><?php esc_html_e( 'Copy', 'overlay-multilingual' ); ?></button>
					<a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'overlay-multilingual' ); ?></a>
					<form method="post" style="display:inline">
						<?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?>
						<input type="hidden" name="ovml_action" value="regenerate_key">
						<button class="button button-link-delete"><?php esc_html_e( 'New link', 'overlay-multilingual' ); ?></button>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! ovml_secondary_languages() ) : ?>
			<div class="ovml-card ovml-empty">
				<strong><?php esc_html_e( 'Add your first language', 'overlay-multilingual' ); ?></strong>
				<p><?php esc_html_e( 'Your site has one language so far.', 'overlay-multilingual' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( self::url( 'languages' ) ); ?>"><?php esc_html_e( 'Add a language', 'overlay-multilingual' ); ?></a>
			</div>
		<?php else : ?>
			<div class="ovml-grid">
				<?php foreach ( self::coverage() as $lang => $c ) : ?>
					<?php $total = $c[1] + $c[3] + $c[5]; $done = $c[0] + $c[2] + $c[4]; ?>
					<div class="ovml-card">
						<div class="ovml-stat">
							<span class="ovml-stat__label"><?php echo esc_html( ovml_languages()[ $lang ]['name'] ); ?> · <?php echo esc_html( strtoupper( $lang ) ); ?></span>
							<span class="ovml-stat__value"><?php echo esc_html( $total ? round( 100 * $done / $total ) : 0 ); ?>%</span>
							<span class="ovml-stat__meta"><?php esc_html_e( 'translated overall', 'overlay-multilingual' ); ?></span>
						</div>
						<div class="ovml-coverage" style="margin-top:16px">
							<?php
							self::bar( __( 'Content', 'overlay-multilingual' ), $c[0], $c[1] );
							self::bar( __( 'Terms', 'overlay-multilingual' ), $c[2], $c[3] );
							self::bar( __( 'Strings', 'overlay-multilingual' ), $c[4], $c[5] );
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="ovml-card" style="margin-top:16px">
			<h2><?php esc_html_e( 'Early loading', 'overlay-multilingual' ); ?></h2>
			<?php if ( $early ) : ?>
				<p class="ovml-hint" style="margin:0"><span class="ovml-pill ovml-pill--ok"><?php esc_html_e( 'Active', 'overlay-multilingual' ); ?></span> <?php esc_html_e( 'Language detection runs before other plugins, so every plugin loads its translations.', 'overlay-multilingual' ); ?></p>
			<?php else : ?>
				<p class="ovml-hint"><span class="ovml-pill ovml-pill--warn"><?php esc_html_e( 'Not installed', 'overlay-multilingual' ); ?></span> <?php esc_html_e( 'A tiny must-use file lets language detection run before other plugins. Without it, a plugin that loads before this one may stay in the default language.', 'overlay-multilingual' ); ?></p>
				<form method="post"><?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?><input type="hidden" name="ovml_action" value="install_early"><button class="button"><?php esc_html_e( 'Install', 'overlay-multilingual' ); ?></button></form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- languages -- */

	public static function tab_languages() {
		$s         = ovml_settings();
		$installed = array_merge( [ 'en_US' ], get_available_languages() );
		$rows      = $s['languages'];
		$rows['']  = [ 'locale' => '', 'name' => '', 'og_locale' => '', 'replace' => [] ]; // empty row to add one
		?>
		<form method="post" class="ovml-card">
			<?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?>
			<input type="hidden" name="ovml_action" value="languages">
			<h2><?php esc_html_e( 'Languages', 'overlay-multilingual' ); ?></h2>
			<p class="ovml-hint"><?php esc_html_e( 'The default language keeps your current URLs. Every other language is served under its code, e.g. /fr/. To remove a language, clear its code and save.', 'overlay-multilingual' ); ?></p>

			<div class="ovml-lang-row ovml-lang-head">
				<span><?php esc_html_e( 'Code', 'overlay-multilingual' ); ?></span>
				<span><?php esc_html_e( 'Name', 'overlay-multilingual' ); ?></span>
				<span><?php esc_html_e( 'WordPress locale', 'overlay-multilingual' ); ?></span>
				<span><?php esc_html_e( 'Social locale', 'overlay-multilingual' ); ?></span>
				<span><?php esc_html_e( 'Replace characters', 'overlay-multilingual' ); ?></span>
				<span><?php esc_html_e( 'Default', 'overlay-multilingual' ); ?></span>
			</div>
			<?php
			$i = 0;
			foreach ( $rows as $code => $l ) :
				$replace = [];
				foreach ( (array) ( $l['replace'] ?? [] ) as $from => $to ) {
					$replace[] = "$from=$to";
				}
				$new  = '' === $code;
				$pack = $new || in_array( $l['locale'], $installed, true );
				$hint = static fn( $text ) => $new ? ' placeholder="' . esc_attr( $text ) . '"' : '';
				?>
				<div class="ovml-lang-row"<?php echo $new ? ' data-ovml-new-lang hidden' : ''; ?>>
					<input type="text" name="lang[<?php echo (int) $i; ?>][code]" value="<?php echo esc_attr( $code ); ?>"<?php echo $hint( __( 'e.g. it', 'overlay-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $hint ?> aria-label="<?php esc_attr_e( 'Code', 'overlay-multilingual' ); ?>" pattern="[a-z]{2,3}(-[a-z]{2,4})?">
					<input type="text" name="lang[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $l['name'] ); ?>"<?php echo $hint( __( 'e.g. Italiano', 'overlay-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php esc_attr_e( 'Name', 'overlay-multilingual' ); ?>">
					<div>
						<input type="text" name="lang[<?php echo (int) $i; ?>][locale]" value="<?php echo esc_attr( $l['locale'] ); ?>"<?php echo $hint( 'it_IT' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> list="ovml-locales" aria-label="<?php esc_attr_e( 'WordPress locale', 'overlay-multilingual' ); ?>" style="width:100%">
						<?php if ( ! $pack ) : ?>
							<button class="button-link" name="install_pack" value="<?php echo esc_attr( $l['locale'] ); ?>"><?php esc_html_e( 'Install language pack', 'overlay-multilingual' ); ?></button>
						<?php endif; ?>
					</div>
					<input type="text" name="lang[<?php echo (int) $i; ?>][og_locale]" value="<?php echo esc_attr( $l['og_locale'] ?? '' ); ?>"<?php echo $hint( 'it_IT' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php esc_attr_e( 'Social locale', 'overlay-multilingual' ); ?>">
					<textarea name="lang[<?php echo (int) $i; ?>][replace]"<?php echo $hint( __( 'optional', 'overlay-multilingual' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php esc_attr_e( 'Replace characters', 'overlay-multilingual' ); ?>"><?php echo esc_textarea( implode( "\n", $replace ) ); ?></textarea>
					<input type="radio" name="default" value="<?php echo (int) $i; ?>" <?php checked( ! $new && $code === $s['default_language'] ); ?> aria-label="<?php esc_attr_e( 'Default language', 'overlay-multilingual' ); ?>" style="margin-top:8px">
				</div>
				<?php
				$i++;
			endforeach;
			?>
			<button type="button" class="button ovml-add-lang" data-ovml-add-lang>+ <?php esc_html_e( 'Add language', 'overlay-multilingual' ); ?></button>
			<datalist id="ovml-locales"><?php foreach ( $installed as $locale ) : ?><option value="<?php echo esc_attr( $locale ); ?>"><?php endforeach; ?></datalist>
			<p class="description" style="margin-top:14px"><?php esc_html_e( 'Replace characters: one pair per line, e.g. ß=ss for Swiss German. Applied to all translated text in that language.', 'overlay-multilingual' ); ?></p>
			<div class="ovml-actions"><button class="button button-primary"><?php esc_html_e( 'Save languages', 'overlay-multilingual' ); ?></button></div>
		</form>
		<?php
	}

	/* ------------------------------------------------------------ strings -- */

	public static function tab_strings() {
		$langs  = ovml_secondary_languages();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$dicts  = [];
		foreach ( $langs as $lang ) {
			$dicts[ $lang ] = Dictionary::get( $lang );
		}
		$keys = Dictionary::sources();
		if ( '' !== $search ) {
			$keys = array_values( array_filter( $keys, static function ( $k ) use ( $search, $dicts ) {
				$hay = $k;
				foreach ( $dicts as $d ) {
					$hay .= ' ' . ( $d[ $k ] ?? '' );
				}
				return false !== mb_stripos( $hay, $search );
			} ) );
		}
		if ( isset( $dicts[ $filter ] ) ) {
			$keys = array_values( array_filter( $keys, static fn( $k ) => empty( $dicts[ $filter ][ $k ] ) ) );
		}
		$per   = 30;
		$total = count( $keys );
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$slice = array_slice( $keys, ( $paged - 1 ) * $per, $per );
		?>
		<div class="ovml-toolbar">
			<form method="get" style="display:flex;gap:8px;align-items:center">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="strings">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search phrases…', 'overlay-multilingual' ); ?>">
				<select name="filter" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All phrases', 'overlay-multilingual' ); ?></option>
					<?php foreach ( $langs as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $filter, $lang ); ?>><?php echo esc_html( sprintf( /* translators: %s: language name */ __( 'Untranslated in %s', 'overlay-multilingual' ), ovml_languages()[ $lang ]['name'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'overlay-multilingual' ); ?></button>
			</form>
			<span class="spacer"></span>
			<button type="button" class="button" data-ovml-scan <?php disabled( ! $langs ); ?>><?php esc_html_e( 'Scan site for text', 'overlay-multilingual' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ovml_export_strings' ), 'ovml_export' ) ); ?>"><?php esc_html_e( 'Export', 'overlay-multilingual' ); ?></a>
			<form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px">
				<?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?>
				<input type="hidden" name="ovml_action" value="import_strings">
				<label class="button"><input type="file" name="strings_file" accept="application/json,.json" hidden onchange="this.form.submit()"><?php esc_html_e( 'Import', 'overlay-multilingual' ); ?></label>
			</form>
		</div>
		<div class="ovml-card ovml-scan" data-ovml-scan-status><span></span><div class="ovml-progress"><span style="width:0"></span></div></div>

		<?php if ( ! $langs ) : ?>
			<div class="ovml-card ovml-empty"><strong><?php esc_html_e( 'No languages to translate into yet', 'overlay-multilingual' ); ?></strong><a class="button" href="<?php echo esc_url( self::url( 'languages' ) ); ?>"><?php esc_html_e( 'Add a language', 'overlay-multilingual' ); ?></a></div>
		<?php elseif ( ! $slice ) : ?>
			<div class="ovml-card ovml-empty">
				<strong><?php echo '' !== $search || $filter ? esc_html__( 'Nothing matches', 'overlay-multilingual' ) : esc_html__( 'No phrases yet', 'overlay-multilingual' ); ?></strong>
				<p><?php esc_html_e( 'Phrases are text from your theme, page builder and menus. Run a scan (in Preview or Live) to collect the ones that are not translated yet.', 'overlay-multilingual' ); ?></p>
			</div>
		<?php else : ?>
			<table class="ovml-table">
				<thead><tr>
					<th style="width:34%"><?php echo esc_html( ovml_languages()[ ovml_default_language() ]['name'] ); ?></th>
					<?php foreach ( $langs as $lang ) : ?><th><?php echo esc_html( ovml_languages()[ $lang ]['name'] ); ?></th><?php endforeach; ?>
					<th style="width:40px"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'overlay-multilingual' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $slice as $key ) : ?>
					<tr data-source="<?php echo esc_attr( $key ); ?>">
						<td class="ovml-source"><?php echo esc_html( $key ); ?></td>
						<?php foreach ( $langs as $lang ) : ?>
							<td><input type="text" class="ovml-string-input" data-lang="<?php echo esc_attr( $lang ); ?>" value="<?php echo esc_attr( $dicts[ $lang ][ $key ] ?? '' ); ?>" lang="<?php echo esc_attr( $lang ); ?>" aria-label="<?php echo esc_attr( ovml_languages()[ $lang ]['name'] ); ?>"></td>
						<?php endforeach; ?>
						<td class="row-actions-ovml"><button type="button" class="button-link button-link-delete" data-ovml-delete aria-label="<?php esc_attr_e( 'Delete phrase', 'overlay-multilingual' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::pagination( $total, $per, $paged ); ?>
		<?php endif; ?>
		<?php
	}

	private static function pagination( $total, $per, $paged ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0">';
		echo wp_kses_post( paginate_links( [
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => $pages,
		] ) );
		echo '</div></div>';
	}

	/* ------------------------------------------------------------ content -- */

	public static function tab_content() {
		$s       = ovml_settings();
		$langs   = ovml_secondary_languages();
		$sources = [];
		foreach ( array_diff( (array) $s['post_types'], (array) $s['separate_posts'] ) as $type ) {
			$obj = get_post_type_object( $type );
			if ( $obj ) {
				$sources[ "post:$type" ] = $obj->labels->name;
			}
		}
		foreach ( ovml_translatable_taxonomies() as $tax ) {
			$obj = get_taxonomy( $tax );
			if ( $obj ) {
				$sources[ "tax:$tax" ] = $obj->labels->name;
			}
		}
		if ( ! $sources || ! $langs ) {
			echo '<div class="ovml-card ovml-empty"><strong>' . esc_html__( 'Nothing to translate yet', 'overlay-multilingual' ) . '</strong><p>' . esc_html__( 'Add a language and choose content types in Settings.', 'overlay-multilingual' ) . '</p></div>';
			return;
		}
		$current = isset( $_GET['source'], $sources[ $_GET['source'] ] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : array_key_first( $sources ); // phpcs:ignore WordPress.Security.NonceVerification
		$missing = isset( $_GET['missing'] ) ? sanitize_key( $_GET['missing'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per     = 40;
		[ $kind, $name ] = explode( ':', $current, 2 );

		$rows = [];
		if ( 'post' === $kind ) {
			foreach ( get_posts( [ 'post_type' => $name, 'post_status' => [ 'publish', 'private', 'draft' ], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
				$tr     = (array) get_post_meta( $post->ID, OVML_META, true );
				$rows[] = [ 'title' => $post->post_title ?: __( '(no title)', 'overlay-multilingual' ), 'edit' => get_edit_post_link( $post->ID, 'raw' ), 'done' => array_map( static fn( $l ) => ! empty( $tr[ $l ]['title'] ), array_combine( $langs, $langs ) ), 'status' => $post->post_status ];
			}
		} else {
			$terms = get_terms( [ 'taxonomy' => $name, 'hide_empty' => false ] );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$tr     = (array) get_term_meta( $term->term_id, OVML_META, true );
				$rows[] = [ 'title' => $term->name, 'edit' => get_edit_term_link( $term->term_id, $name ), 'done' => array_map( static fn( $l ) => ! empty( $tr[ $l ]['name'] ), array_combine( $langs, $langs ) ), 'status' => '' ];
			}
		}
		if ( isset( array_flip( $langs )[ $missing ] ) ) {
			$rows = array_values( array_filter( $rows, static fn( $r ) => ! $r['done'][ $missing ] ) );
		}
		$total = count( $rows );
		$rows  = array_slice( $rows, ( $paged - 1 ) * $per, $per );
		?>
		<div class="ovml-toolbar">
			<form method="get" style="display:flex;gap:8px">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="content">
				<select name="source" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Content type', 'overlay-multilingual' ); ?>">
					<?php foreach ( $sources as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<select name="missing" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Filter', 'overlay-multilingual' ); ?>">
					<option value=""><?php esc_html_e( 'All items', 'overlay-multilingual' ); ?></option>
					<?php foreach ( $langs as $lang ) : ?><option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $missing, $lang ); ?>><?php echo esc_html( sprintf( /* translators: %s: language name */ __( 'Missing %s', 'overlay-multilingual' ), ovml_languages()[ $lang ]['name'] ) ); ?></option><?php endforeach; ?>
				</select>
			</form>
			<span class="spacer"></span>
			<span class="ovml-sub" style="margin:0"><?php echo esc_html( sprintf( /* translators: %d: number of items */ _n( '%d item', '%d items', $total, 'overlay-multilingual' ), $total ) ); ?></span>
		</div>
		<?php if ( ! $rows ) : ?>
			<div class="ovml-card ovml-empty"><strong><?php esc_html_e( 'All caught up', 'overlay-multilingual' ); ?></strong></div>
		<?php else : ?>
			<table class="ovml-table">
				<thead><tr><th><?php esc_html_e( 'Title', 'overlay-multilingual' ); ?></th><?php foreach ( $langs as $lang ) : ?><th style="width:140px"><?php echo esc_html( ovml_languages()[ $lang ]['name'] ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['edit'] ); ?>"><strong><?php echo esc_html( $row['title'] ); ?></strong></a><?php echo $row['status'] && 'publish' !== $row['status'] ? ' <span class="ovml-pill ovml-pill--off">' . esc_html( $row['status'] ) . '</span>' : ''; ?></td>
						<?php foreach ( $langs as $lang ) : ?>
							<td><?php echo $row['done'][ $lang ] ? '<span class="ovml-pill ovml-pill--ok">' . esc_html__( 'Translated', 'overlay-multilingual' ) . '</span>' : '<span class="ovml-pill ovml-pill--warn">' . esc_html__( 'Missing', 'overlay-multilingual' ) . '</span>'; ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::pagination( $total, $per, $paged ); ?>
		<?php endif; ?>
		<?php self::separate_summary(); ?>
		<?php
	}

	private static function separate_summary() {
		$types = (array) ovml_settings()['separate_posts'];
		if ( ! $types ) {
			return;
		}
		echo '<div class="ovml-card" style="margin-top:16px"><h2>' . esc_html__( 'Written per language', 'overlay-multilingual' ) . '</h2><p class="ovml-hint">' . esc_html__( 'These are separate posts in each language, linked as translations of each other.', 'overlay-multilingual' ) . '</p>';
		foreach ( $types as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				continue;
			}
			$parts = [];
			foreach ( array_keys( ovml_languages() ) as $lang ) {
				$ids     = array_filter( PolylangData::ids_in( $lang, $type ) );
				$parts[] = esc_html( ovml_languages()[ $lang ]['name'] . ': ' . count( $ids ) );
			}
			echo '<p><strong>' . esc_html( $obj->labels->name ) . '</strong> — ' . implode( ' · ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped above
		}
		echo '</div>';
	}

	/* ----------------------------------------------------------- settings -- */

	public static function tab_settings() {
		$s       = ovml_settings();
		$types   = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		$taxes   = get_taxonomies( [ 'public' => true ], 'objects' );
		$presets = (array) apply_filters( 'ovml_switcher_presets', [] );
		$sw      = $s['switcher'];
		$placement = 'none';
		if ( 'hook' === $sw['placement'] ) {
			$placement = 'custom';
			foreach ( $presets as $key => $p ) {
				if ( $p['hook'] === $sw['hook'] && (int) $p['priority'] === (int) $sw['priority'] ) {
					$placement = "preset:$key";
				}
			}
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'ovml_save', 'ovml_nonce' ); ?>
			<input type="hidden" name="ovml_action" value="settings">

			<div class="ovml-card">
				<h2><?php esc_html_e( 'What gets translated', 'overlay-multilingual' ); ?></h2>
				<p class="ovml-hint"><?php esc_html_e( 'Translated in place: one item, with translations stored alongside it. Written per language: separate posts per language linked as translations (Polylang-compatible), best for blog articles.', 'overlay-multilingual' ); ?></p>
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Translated in place', 'overlay-multilingual' ); ?></span>
					<div class="ovml-checks"><?php foreach ( $types as $type ) : ?><label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><?php endforeach; ?></div>
				</div>
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Written per language', 'overlay-multilingual' ); ?></span>
					<div class="ovml-checks"><?php foreach ( $types as $type ) : ?><label><input type="checkbox" name="separate_posts[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['separate_posts'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><?php endforeach; ?></div>
				</div>
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Taxonomies', 'overlay-multilingual' ); ?></span>
					<div class="ovml-checks"><?php foreach ( $taxes as $tax ) : ?><?php if ( 0 === strpos( $tax->name, 'pa_' ) ) { continue; } ?><label><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( in_array( $tax->name, (array) $s['taxonomies'], true ) ); ?>> <?php echo esc_html( $tax->labels->name ); ?></label><?php endforeach; ?></div>
					<p class="description"><?php esc_html_e( 'Product attributes are included automatically when products are translated.', 'overlay-multilingual' ); ?></p>
				</div>
			</div>

			<div class="ovml-card">
				<h2><?php esc_html_e( 'Language switcher', 'overlay-multilingual' ); ?></h2>
				<p class="ovml-hint"><?php printf( esc_html__( 'Place it automatically, or anywhere with the shortcode %1$s or the template tag %2$s.', 'overlay-multilingual' ), '<code>[ovml_switcher]</code>', '<code>&lt;?php ovml_switcher(); ?&gt;</code>' ); ?></p>
				<div class="ovml-field">
					<label for="ovml-placement"><?php esc_html_e( 'Placement', 'overlay-multilingual' ); ?></label>
					<select id="ovml-placement" name="switcher_placement" data-ovml-placement>
						<option value="none" <?php selected( $placement, 'none' ); ?>><?php esc_html_e( 'Only where I add the shortcode or template tag', 'overlay-multilingual' ); ?></option>
						<?php foreach ( $presets as $key => $p ) : ?><option value="preset:<?php echo esc_attr( $key ); ?>" <?php selected( $placement, "preset:$key" ); ?>><?php echo esc_html( $p['label'] ); ?></option><?php endforeach; ?>
						<option value="custom" <?php selected( $placement, 'custom' ); ?>><?php esc_html_e( 'On a theme action hook…', 'overlay-multilingual' ); ?></option>
					</select>
				</div>
				<div class="ovml-field" data-ovml-custom-hook <?php echo 'custom' === $placement ? '' : 'hidden'; ?>>
					<label for="ovml-hook"><?php esc_html_e( 'Action hook and priority', 'overlay-multilingual' ); ?></label>
					<div style="display:flex;gap:8px"><input type="text" id="ovml-hook" name="switcher_hook" value="<?php echo esc_attr( $sw['hook'] ); ?>" placeholder="wp_body_open" class="regular-text"><input type="number" name="switcher_priority" value="<?php echo esc_attr( $sw['priority'] ); ?>" style="width:90px" aria-label="<?php esc_attr_e( 'Priority', 'overlay-multilingual' ); ?>"></div>
				</div>
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Style', 'overlay-multilingual' ); ?></span>
					<div class="ovml-segmented">
						<?php foreach ( [ 'dropdown' => __( 'Dropdown', 'overlay-multilingual' ), 'inline' => __( 'Inline list', 'overlay-multilingual' ), 'theme' => __( 'Theme native', 'overlay-multilingual' ) ] as $value => $label ) : ?>
							<label><input type="radio" name="switcher_style" value="<?php echo esc_attr( $value ); ?>" <?php checked( $sw['style'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Show', 'overlay-multilingual' ); ?></span>
					<div class="ovml-segmented">
						<?php foreach ( [ 'name' => __( 'Name', 'overlay-multilingual' ), 'code' => __( 'Code', 'overlay-multilingual' ), 'both' => __( 'Both', 'overlay-multilingual' ) ] as $value => $label ) : ?>
							<label><input type="radio" name="switcher_label" value="<?php echo esc_attr( $value ); ?>" <?php checked( $sw['label'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="ovml-field">
					<label for="ovml-untranslated"><?php esc_html_e( 'When the current page is not translated into a language', 'overlay-multilingual' ); ?></label>
					<select id="ovml-untranslated" name="switcher_untranslated">
						<option value="show" <?php selected( $sw['untranslated'], 'show' ); ?>><?php esc_html_e( 'Link to the page anyway (interface translated, content not)', 'overlay-multilingual' ); ?></option>
						<option value="home" <?php selected( $sw['untranslated'], 'home' ); ?>><?php esc_html_e( "Link to that language's homepage", 'overlay-multilingual' ); ?></option>
						<option value="hide" <?php selected( $sw['untranslated'], 'hide' ); ?>><?php esc_html_e( 'Hide that language in the switcher', 'overlay-multilingual' ); ?></option>
					</select>
				</div>
				<div class="ovml-field ovml-checks">
					<label><input type="checkbox" name="switcher_hide_current" value="1" <?php checked( ! empty( $sw['hide_current'] ) ); ?>> <?php esc_html_e( 'Hide the current language (inline list style)', 'overlay-multilingual' ); ?></label>
				</div>
			</div>

			<div class="ovml-card">
				<h2><?php esc_html_e( 'Visitors', 'overlay-multilingual' ); ?></h2>
				<div class="ovml-field ovml-checks" style="flex-direction:column;align-items:flex-start">
					<label><input type="checkbox" name="detect_language" value="1" <?php checked( ! empty( $s['detect_language'] ) ); ?>> <?php esc_html_e( "Match the visitor's device language on their first visit", 'overlay-multilingual' ); ?></label>
					<p class="description"><?php esc_html_e( 'Only on a first visit, only from a page in the default language, and only when that page has a translation. Their choice is remembered afterwards, including when they use the switcher. Search engines are never redirected.', 'overlay-multilingual' ); ?></p>
				</div>
				<div style="display:flex;gap:24px;flex-wrap:wrap">
					<div class="ovml-field">
						<label for="ovml-detect-scope"><?php esc_html_e( 'Apply on', 'overlay-multilingual' ); ?></label>
						<select id="ovml-detect-scope" name="detect_scope">
							<option value="any" <?php selected( $s['detect_scope'], 'any' ); ?>><?php esc_html_e( 'Any page they land on', 'overlay-multilingual' ); ?></option>
							<option value="home" <?php selected( $s['detect_scope'], 'home' ); ?>><?php esc_html_e( 'The homepage only', 'overlay-multilingual' ); ?></option>
						</select>
					</div>
					<div class="ovml-field">
						<label for="ovml-cookie-days"><?php esc_html_e( 'Remember their language for (days)', 'overlay-multilingual' ); ?></label>
						<input type="number" id="ovml-cookie-days" name="cookie_days" min="1" max="3650" value="<?php echo esc_attr( $s['cookie_days'] ); ?>" style="width:110px">
					</div>
				</div>
			</div>

			<div class="ovml-card">
				<h2><?php esc_html_e( 'Search engines', 'overlay-multilingual' ); ?></h2>
				<div class="ovml-field ovml-checks" style="flex-direction:column;align-items:flex-start">
					<label><input type="checkbox" name="seo[hreflang]" value="1" <?php checked( ! empty( $s['seo']['hreflang'] ) ); ?>> <?php esc_html_e( 'Add hreflang links between language versions', 'overlay-multilingual' ); ?></label>
					<label><input type="checkbox" name="seo[x_default]" value="1" <?php checked( ! empty( $s['seo']['x_default'] ) ); ?>> <?php esc_html_e( 'Mark the default language as x-default', 'overlay-multilingual' ); ?></label>
					<label><input type="checkbox" name="seo[noindex_untranslated]" value="1" <?php checked( ! empty( $s['seo']['noindex_untranslated'] ) ); ?>> <?php esc_html_e( 'Keep pages without a translation out of search results', 'overlay-multilingual' ); ?></label>
					<label><input type="checkbox" name="seo[sitemap]" value="1" <?php checked( ! empty( $s['seo']['sitemap'] ) ); ?>> <?php esc_html_e( 'Publish a sitemap of translated URLs', 'overlay-multilingual' ); ?> <code><?php echo esc_html( \OverlayML\SEO::SITEMAP_PATH ); ?></code></label>
				</div>
				<div class="ovml-field">
					<label for="ovml-title-format"><?php esc_html_e( 'SEO title when a translation has no SEO title of its own', 'overlay-multilingual' ); ?></label>
					<input type="text" id="ovml-title-format" name="seo_title_format" class="regular-text" value="<?php echo esc_attr( $s['seo']['title_format'] ); ?>">
					<p class="description"><?php printf( esc_html__( 'Use %1$s for the translated title and %2$s for the site name.', 'overlay-multilingual' ), '<code>%title%</code>', '<code>%site%</code>' ); ?></p>
				</div>
			</div>

			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<div class="ovml-card">
					<h2><?php esc_html_e( 'WooCommerce', 'overlay-multilingual' ); ?></h2>
					<div class="ovml-field ovml-checks" style="flex-direction:column;align-items:flex-start">
						<label><input type="checkbox" name="wc[emails]" value="1" <?php checked( ! empty( $s['woocommerce']['emails'] ) ); ?>> <?php esc_html_e( 'Send customer emails in the language the order was placed in', 'overlay-multilingual' ); ?></label>
						<label><input type="checkbox" name="wc[default_items]" value="1" <?php checked( ! empty( $s['woocommerce']['default_items'] ) ); ?>> <?php esc_html_e( 'Store order lines in the default language (admin, invoices and reports stay in one language)', 'overlay-multilingual' ); ?></label>
					</div>
				</div>
			<?php endif; ?>

			<details class="ovml-card">
				<summary style="cursor:pointer"><h2 style="display:inline"><?php esc_html_e( 'Advanced', 'overlay-multilingual' ); ?></h2></summary>
				<div style="margin-top:16px">
					<div class="ovml-field ovml-checks" style="flex-direction:column;align-items:flex-start">
						<label><input type="checkbox" name="adv[translate_attributes]" value="1" <?php checked( ! empty( $s['advanced']['translate_attributes'] ) ); ?>> <?php esc_html_e( 'Translate image alt text, tooltips, placeholders and ARIA labels through the phrase list', 'overlay-multilingual' ); ?></label>
						<label><input type="checkbox" name="preview_badge" value="1" <?php checked( ! empty( $s['preview_badge'] ) ); ?>> <?php esc_html_e( 'Show a "Translation preview" badge with an exit link while previewing', 'overlay-multilingual' ); ?></label>
					</div>
					<div class="ovml-field">
						<label for="ovml-excluded"><?php esc_html_e( 'Never translate these paths', 'overlay-multilingual' ); ?></label>
						<textarea id="ovml-excluded" name="adv[excluded_paths]" rows="3" class="large-text code" placeholder="/members/&#10;/api/"><?php echo esc_textarea( $s['advanced']['excluded_paths'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One path prefix per line. Links to them keep the default-language URL.', 'overlay-multilingual' ); ?></p>
					</div>
				</div>
			</details>

			<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'overlay-multilingual' ); ?></button></p>
		</form>
		<?php
	}

	/* -------------------------------------------------------------- saves -- */

	private static function update( array $changes ) {
		$settings = array_replace( get_option( 'ovml_settings', [] ) ?: ovml_default_settings(), $changes );
		update_option( 'ovml_settings', $settings, true );
		ovml_settings( true );
	}

	public static function handle_post() {
		if ( empty( $_POST['ovml_action'] ) || ! isset( $_POST['ovml_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( sanitize_key( $_POST['ovml_nonce'] ), 'ovml_save' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'overlay-multilingual' ) );
		}
		$action = sanitize_key( $_POST['ovml_action'] );
		$tab    = 'overview';

		switch ( $action ) {
			case 'status':
				$status = sanitize_key( $_POST['status'] ?? 'off' );
				if ( in_array( $status, [ 'off', 'preview', 'live' ], true ) ) {
					$changes = [ 'status' => $status ];
					if ( '' === (string) ovml_settings()['preview_key'] ) {
						$changes['preview_key'] = wp_generate_password( 24, false );
					}
					self::update( $changes );
					flush_rewrite_rules( false );
					self::flash( __( 'Status saved.', 'overlay-multilingual' ) );
				}
				break;

			case 'regenerate_key':
				self::update( [ 'preview_key' => wp_generate_password( 24, false ) ] );
				self::flash( __( 'New preview link created. The old one no longer works.', 'overlay-multilingual' ) );
				break;

			case 'install_early':
				$installed = ovml_install_early_loader();
				self::flash( $installed ? __( 'Early loading installed.', 'overlay-multilingual' ) : __( 'Could not write to the must-use plugins folder. Check its permissions.', 'overlay-multilingual' ), $installed ? 'success' : 'error' );
				break;

			case 'languages':
				$tab = 'languages';
				self::save_languages();
				break;

			case 'settings':
				$tab = 'settings';
				self::save_settings();
				break;

			case 'import_strings':
				$tab = 'strings';
				self::import_strings();
				break;
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function save_languages() {
		if ( ! empty( $_POST['install_pack'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			$locale = sanitize_text_field( wp_unslash( $_POST['install_pack'] ) );
			$ok     = wp_can_install_language_pack() && wp_download_language_pack( $locale );
			self::flash( $ok ? sprintf( /* translators: %s: locale */ __( 'Language pack %s installed.', 'overlay-multilingual' ), $locale ) : __( 'The language pack could not be installed automatically. Install it from Settings → General.', 'overlay-multilingual' ), $ok ? 'success' : 'error' );
		}
		$rows    = (array) wp_unslash( $_POST['lang'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below
		$default = (int) ( $_POST['default'] ?? -1 );
		$langs   = [];
		$default_code = '';
		foreach ( $rows as $i => $row ) {
			$code = strtolower( sanitize_text_field( $row['code'] ?? '' ) );
			if ( ! preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code ) || isset( $langs[ $code ] ) ) {
				continue;
			}
			$replace = [];
			foreach ( preg_split( '/\r\n|\n/', (string) ( $row['replace'] ?? '' ) ) as $line ) {
				if ( false !== strpos( $line, '=' ) ) {
					[ $from, $to ] = explode( '=', $line, 2 );
					if ( '' !== $from ) {
						$replace[ sanitize_text_field( $from ) ] = sanitize_text_field( $to );
					}
				}
			}
			$locale         = preg_replace( '/[^A-Za-z_]/', '', (string) ( $row['locale'] ?? '' ) ) ?: 'en_US';
			$langs[ $code ] = [
				'locale'    => $locale,
				'name'      => sanitize_text_field( $row['name'] ?? '' ) ?: strtoupper( $code ),
				'og_locale' => preg_replace( '/[^A-Za-z_]/', '', (string) ( $row['og_locale'] ?? '' ) ) ?: $locale,
				'replace'   => $replace,
			];
			if ( (int) $i === $default ) {
				$default_code = $code;
			}
		}
		if ( ! $langs ) {
			self::flash( __( 'At least one language is required.', 'overlay-multilingual' ), 'error' );
			return;
		}
		$default_code = $default_code ?: array_key_first( $langs );
		$langs        = [ $default_code => $langs[ $default_code ] ] + $langs; // default first
		self::update( [ 'languages' => $langs, 'default_language' => $default_code ] );
		if ( empty( $_POST['install_pack'] ) ) {
			self::flash( __( 'Languages saved.', 'overlay-multilingual' ) );
		}
	}

	private static function save_settings() {
		$keys      = static fn( $name ) => array_values( array_map( 'sanitize_key', (array) ( $_POST[ $name ] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in handle_post()
		$placement = sanitize_text_field( wp_unslash( $_POST['switcher_placement'] ?? 'none' ) );
		$presets   = (array) apply_filters( 'ovml_switcher_presets', [] );
		$switcher  = ovml_settings()['switcher'];
		$style     = sanitize_key( $_POST['switcher_style'] ?? 'dropdown' );
		$label     = sanitize_key( $_POST['switcher_label'] ?? 'name' );
		$switcher['style'] = in_array( $style, [ 'dropdown', 'inline', 'theme' ], true ) ? $style : 'dropdown';
		$switcher['label'] = in_array( $label, [ 'name', 'code', 'both' ], true ) ? $label : 'name';
		$untranslated      = sanitize_key( $_POST['switcher_untranslated'] ?? 'show' );
		$switcher['untranslated'] = in_array( $untranslated, [ 'show', 'home', 'hide' ], true ) ? $untranslated : 'show';
		$switcher['hide_current'] = ! empty( $_POST['switcher_hide_current'] );

		if ( 0 === strpos( $placement, 'preset:' ) && isset( $presets[ substr( $placement, 7 ) ] ) ) {
			$p                     = $presets[ substr( $placement, 7 ) ];
			$switcher['placement'] = 'hook';
			$switcher['hook']      = $p['hook'];
			$switcher['priority']  = (int) $p['priority'];
			if ( ! empty( $p['style'] ) ) {
				$switcher['style'] = $p['style'];
			}
		} elseif ( 'custom' === $placement ) {
			$switcher['placement'] = 'hook';
			$switcher['hook']      = preg_replace( '/[^A-Za-z0-9_\-\/]/', '', (string) wp_unslash( $_POST['switcher_hook'] ?? '' ) );
			$switcher['priority']  = (int) ( $_POST['switcher_priority'] ?? 10 );
		} else {
			$switcher['placement'] = 'none';
		}

		$seo = [];
		foreach ( [ 'hreflang', 'x_default', 'noindex_untranslated', 'sitemap' ] as $key ) {
			$seo[ $key ] = ! empty( $_POST['seo'][ $key ] );
		}
		$seo['title_format'] = sanitize_text_field( wp_unslash( $_POST['seo_title_format'] ?? '' ) ) ?: '%title% - %site%';
		$scope = sanitize_key( $_POST['detect_scope'] ?? 'any' );
		$paths = implode( "\n", array_filter( array_map( static fn( $p ) => sanitize_text_field( trim( $p ) ), preg_split( '/\r\n|\n/', (string) wp_unslash( $_POST['adv']['excluded_paths'] ?? '' ) ) ) ) );

		self::update( [
			'post_types'      => $keys( 'post_types' ),
			'separate_posts'  => $keys( 'separate_posts' ),
			'taxonomies'      => $keys( 'taxonomies' ),
			'detect_language' => ! empty( $_POST['detect_language'] ),
			'detect_scope'    => in_array( $scope, [ 'any', 'home' ], true ) ? $scope : 'any',
			'cookie_days'     => min( 3650, max( 1, (int) ( $_POST['cookie_days'] ?? 365 ) ) ),
			'preview_badge'   => ! empty( $_POST['preview_badge'] ),
			'switcher'        => $switcher,
			'seo'             => $seo,
			'woocommerce'     => [
				'emails'        => ! empty( $_POST['wc']['emails'] ),
				'default_items' => ! empty( $_POST['wc']['default_items'] ),
			],
			'advanced'        => [
				'translate_attributes' => ! empty( $_POST['adv']['translate_attributes'] ),
				'excluded_paths'       => $paths,
			],
		] );
		self::flash( __( 'Settings saved.', 'overlay-multilingual' ) );
	}

	/* ------------------------------------------------- strings: ajax / io -- */

	private static function ajax_guard() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'ovml_ajax', false, false ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	public static function ajax_save_string() {
		self::ajax_guard();
		$source = ovml_normalise( wp_unslash( $_POST['source'] ?? '' ) );
		$lang   = sanitize_key( $_POST['lang'] ?? '' );
		$value  = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
		if ( '' === $source || ! in_array( $lang, ovml_secondary_languages(), true ) ) {
			wp_send_json_error( null, 400 );
		}
		$dict = Dictionary::get( $lang );
		if ( '' === $value ) {
			unset( $dict[ $source ] );
		} else {
			$dict[ $source ] = $value;
		}
		Dictionary::save( $lang, $dict );
		wp_send_json_success();
	}

	public static function ajax_delete_string() {
		self::ajax_guard();
		$source = ovml_normalise( wp_unslash( $_POST['source'] ?? '' ) );
		foreach ( ovml_secondary_languages() as $lang ) {
			$dict = Dictionary::get( $lang );
			unset( $dict[ $source ] );
			Dictionary::save( $lang, $dict );
		}
		$missing = (array) get_option( 'ovml_missing', [] );
		unset( $missing[ $source ] );
		update_option( 'ovml_missing', $missing, false );
		wp_send_json_success();
	}

	/**
	 * Scan: fetch every translatable page in the default language and in the
	 * first secondary language. A phrase that appears identically in both is
	 * untranslated text; anything a language pack or a translation already
	 * changed is ignored, so the list holds only what really needs work.
	 */
	public static function ajax_scan() {
		self::ajax_guard();
		$lang = ovml_secondary_languages()[0] ?? '';
		$step = (int) ( $_POST['offset'] ?? 0 );
		$urls = get_transient( 'ovml_scan_urls' );
		if ( 0 === $step || ! is_array( $urls ) ) {
			$urls = self::scan_urls( $lang );
			set_transient( 'ovml_scan_urls', $urls, HOUR_IN_SECONDS );
			update_option( 'ovml_scan_before', count( (array) get_option( 'ovml_missing', [] ) ), false );
		}
		$batch   = array_slice( $urls, $step, 3 );
		$host    = (string) wp_parse_url( ovml_root(), PHP_URL_HOST );
		$args    = [
			'timeout'     => 30,
			'redirection' => 2,
			'cookies'     => [ new \WP_Http_Cookie( [ 'name' => 'ovml_preview', 'value' => ovml_settings()['preview_key'], 'domain' => $host ] ) ],
		];
		$missing = (array) get_option( 'ovml_missing', [] );
		$dict    = Dictionary::get( $lang );
		foreach ( $batch as $url ) {
			$original   = Dictionary::extract_phrases( wp_remote_retrieve_body( wp_remote_get( ovml_url( $url, ovml_default_language() ), $args ) ) );
			$translated = Dictionary::extract_phrases( wp_remote_retrieve_body( wp_remote_get( $url, $args ) ) );
			foreach ( array_keys( array_intersect_key( $original, $translated ) ) as $phrase ) {
				if ( empty( $dict[ $phrase ] ) ) {
					$missing[ $phrase ] = true;
				}
			}
		}
		update_option( 'ovml_missing', $missing, false );
		$next  = $step + count( $batch );
		$found = count( $missing ) - (int) get_option( 'ovml_scan_before', 0 );
		wp_send_json_success( [ 'next' => $next, 'total' => count( $urls ), 'done' => $next >= count( $urls ), 'found' => max( 0, $found ) ] );
	}

	private static function scan_urls( $lang ) {
		$urls  = [ ovml_url( ovml_root() . '/', $lang ) ];
		$types = array_diff( (array) ovml_settings()['post_types'], (array) ovml_settings()['separate_posts'] );
		foreach ( $types ? get_posts( [ 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 400, 'fields' => 'ids' ] ) : [] as $id ) {
			$urls[] = ovml_url( get_permalink( $id ), $lang );
		}
		$terms = get_terms( [ 'taxonomy' => (array) ovml_settings()['taxonomies'], 'hide_empty' => true ] );
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$urls[] = ovml_url( get_term_link( $term ), $lang );
		}
		return array_values( array_unique( (array) apply_filters( 'ovml_scan_urls', $urls, $lang ) ) );
	}

	public static function export_strings() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'ovml_export' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'overlay-multilingual' ) );
		}
		$out = [ 'sources' => Dictionary::sources(), 'translations' => [] ];
		foreach ( ovml_secondary_languages() as $lang ) {
			$out['translations'][ $lang ] = Dictionary::get( $lang );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=strings-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function import_strings() {
		$file = $_FILES['strings_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path from PHP upload handling
		$data = $file && is_uploaded_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $data ) ) {
			self::flash( __( 'That file is not a strings export.', 'overlay-multilingual' ), 'error' );
			return;
		}
		$count = 0;
		foreach ( (array) ( $data['translations'] ?? [] ) as $lang => $pairs ) {
			if ( ! in_array( $lang, ovml_secondary_languages(), true ) ) {
				continue;
			}
			$dict = Dictionary::get( $lang );
			foreach ( (array) $pairs as $source => $value ) {
				$source = ovml_normalise( $source );
				$value  = sanitize_text_field( (string) $value );
				if ( '' !== $source && '' !== $value ) {
					$dict[ $source ] = $value;
					++$count;
				}
			}
			Dictionary::save( $lang, $dict );
		}
		self::flash( sprintf( /* translators: %d: number of translations */ _n( 'Imported %d translation.', 'Imported %d translations.', $count, 'overlay-multilingual' ), $count ) );
	}
}
