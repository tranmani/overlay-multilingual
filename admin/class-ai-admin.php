<?php
/**
 * AI translation: settings tab and the AJAX endpoints behind the
 * "Translate with AI" buttons (strings, content list, edit screens).
 *
 * Each AJAX call does one small unit of work — one batch of phrases or one
 * post/term — so long jobs never hit PHP's execution time limit; the browser
 * drives the loop and shows progress.
 *
 * @package OverlayML
 */

namespace OverlayML\Admin;

use OverlayML\AI;
use OverlayML\Dictionary;

defined( 'ABSPATH' ) || exit;

class AI_Admin {

	const BATCH = 40;

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'save' ] );
		add_action( 'wp_ajax_ovml_ai_test', [ __CLASS__, 'ajax_test' ] );
		add_action( 'wp_ajax_ovml_ai_strings', [ __CLASS__, 'ajax_strings' ] );
		add_action( 'wp_ajax_ovml_ai_queue', [ __CLASS__, 'ajax_queue' ] );
		add_action( 'wp_ajax_ovml_ai_object', [ __CLASS__, 'ajax_object' ] );
	}

	/* --------------------------------------------------------------- tab -- */

	public static function render() {
		$s      = AI::settings();
		$models = AI::models();
		$known  = isset( $models[ $s['provider'] ][ $s['model'] ] );
		$model  = $known ? $s['model'] : 'custom';
		$custom = $known ? $s['custom_model'] : ( $s['custom_model'] ?: $s['model'] );
		?>
		<form method="post">
			<?php wp_nonce_field( 'ovml_ai_save', 'ovml_ai_nonce' ); ?>
			<div class="ovml-card">
				<div class="ovml-card-row">
					<div>
						<h2><?php esc_html_e( 'AI translation', 'overlay-multilingual' ); ?></h2>
						<p class="ovml-hint" style="margin:0"><?php esc_html_e( 'Translate content and phrases with Claude or OpenAI using your own API key. Requests go directly from your server to the provider; nothing passes through any other service.', 'overlay-multilingual' ); ?></p>
					</div>
					<?php if ( AI::configured() ) : ?>
						<span class="ovml-pill ovml-pill--ok"><?php esc_html_e( 'Ready', 'overlay-multilingual' ); ?></span>
					<?php else : ?>
						<span class="ovml-pill ovml-pill--off"><?php esc_html_e( 'Not set up', 'overlay-multilingual' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="ovml-card">
				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Provider', 'overlay-multilingual' ); ?></span>
					<div class="ovml-segmented" data-ovml-provider>
						<label><input type="radio" name="provider" value="anthropic" <?php checked( $s['provider'], 'anthropic' ); ?>><span>Anthropic Claude</span></label>
						<label><input type="radio" name="provider" value="openai" <?php checked( $s['provider'], 'openai' ); ?>><span>OpenAI</span></label>
					</div>
				</div>

				<div class="ovml-field">
					<label for="ovml-ai-key"><?php esc_html_e( 'API key', 'overlay-multilingual' ); ?></label>
					<?php if ( AI::key_from_config() ) : ?>
						<p class="description"><span class="ovml-pill ovml-pill--ok"><?php esc_html_e( 'Set in wp-config.php', 'overlay-multilingual' ); ?></span> <?php echo esc_html( AI::key_hint() ); ?></p>
					<?php else : ?>
						<input type="password" id="ovml-ai-key" name="api_key" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( AI::key_hint() ? sprintf( /* translators: %s: last characters of the saved key */ __( 'Saved key %s — leave empty to keep it', 'overlay-multilingual' ), AI::key_hint() ) : __( 'Paste your API key', 'overlay-multilingual' ) ); ?>">
						<p class="description"><?php printf( esc_html__( 'Stored encrypted. For extra safety, define %s in wp-config.php instead.', 'overlay-multilingual' ), '<code>OVML_AI_KEY</code>' ); ?>
							<?php if ( AI::key_hint() ) : ?><label style="margin-left:8px"><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e( 'Remove saved key', 'overlay-multilingual' ); ?></label><?php endif; ?>
						</p>
					<?php endif; ?>
				</div>

				<div class="ovml-field">
					<label for="ovml-ai-model"><?php esc_html_e( 'Model', 'overlay-multilingual' ); ?></label>
					<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
						<select id="ovml-ai-model" name="model" data-ovml-model>
							<?php foreach ( $models as $provider => $list ) : ?>
								<optgroup label="<?php echo esc_attr( 'anthropic' === $provider ? 'Anthropic Claude' : 'OpenAI' ); ?>" data-provider="<?php echo esc_attr( $provider ); ?>">
									<?php foreach ( $list as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $model, $id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
							<option value="custom" <?php selected( $model, 'custom' ); ?>><?php esc_html_e( 'Custom model ID…', 'overlay-multilingual' ); ?></option>
						</select>
						<input type="text" name="custom_model" value="<?php echo esc_attr( $custom ); ?>" placeholder="<?php esc_attr_e( 'e.g. claude-sonnet-5-5', 'overlay-multilingual' ); ?>" class="regular-text" data-ovml-custom-model <?php echo 'custom' === $model ? '' : 'hidden'; ?>>
					</div>
					<p class="description"><?php esc_html_e( 'Any model ID your account can use works as a custom model.', 'overlay-multilingual' ); ?></p>
				</div>

				<div class="ovml-field">
					<span class="ovml-label"><?php esc_html_e( 'Quality', 'overlay-multilingual' ); ?></span>
					<div class="ovml-segmented">
						<?php foreach ( [ 'fast' => __( 'Fast', 'overlay-multilingual' ), 'balanced' => __( 'Balanced', 'overlay-multilingual' ), 'thorough' => __( 'Thorough', 'overlay-multilingual' ) ] as $value => $label ) : ?>
							<label><input type="radio" name="quality" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['quality'], $value ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'How much the model thinks before answering (Claude). Thorough costs more and takes longer.', 'overlay-multilingual' ); ?></p>
				</div>

				<div class="ovml-field">
					<label for="ovml-ai-instructions"><?php esc_html_e( 'Instructions and glossary', 'overlay-multilingual' ); ?></label>
					<textarea id="ovml-ai-instructions" name="instructions" rows="5" class="large-text" placeholder="<?php esc_attr_e( "e.g. We sell to professionals — use formal address.\nKeep these terms in English: Lash Lift, Brow Lamination.\nOur brand names: …", 'overlay-multilingual' ); ?>"><?php echo esc_textarea( $s['instructions'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Tone, terminology and words to keep. Sent with every request.', 'overlay-multilingual' ); ?></p>
				</div>

				<div class="ovml-actions" style="align-items:center">
					<button class="button button-primary"><?php esc_html_e( 'Save', 'overlay-multilingual' ); ?></button>
					<button type="button" class="button" data-ovml-ai-test <?php disabled( ! AI::configured() ); ?>><?php esc_html_e( 'Test connection', 'overlay-multilingual' ); ?></button>
					<span data-ovml-ai-test-result class="ovml-hint" style="margin:0"></span>
				</div>
			</div>
		</form>

		<div class="ovml-card">
			<h2><?php esc_html_e( 'Where to use it', 'overlay-multilingual' ); ?></h2>
			<ul class="ovml-list">
				<li><?php printf( esc_html__( '%s — translate every missing phrase in one click.', 'overlay-multilingual' ), '<a href="' . esc_url( Admin::url( 'strings' ) ) . '">' . esc_html__( 'Strings', 'overlay-multilingual' ) . '</a>' ); ?></li>
				<li><?php printf( esc_html__( '%s — translate all missing products, pages or terms of a type.', 'overlay-multilingual' ), '<a href="' . esc_url( Admin::url( 'content' ) ) . '">' . esc_html__( 'Content', 'overlay-multilingual' ) . '</a>' ); ?></li>
				<li><?php esc_html_e( 'Edit screens — "Translate with AI" fills a language tab for you to review before saving.', 'overlay-multilingual' ); ?></li>
			</ul>
			<p class="ovml-hint" style="margin:0"><?php esc_html_e( 'HTML is checked after every translation: if the markup does not match the original, that field is not saved. Language packs already cover WordPress, WooCommerce and most plugin text, so AI is only needed for your own content.', 'overlay-multilingual' ); ?></p>
		</div>
		<?php
	}

	public static function save() {
		if ( ! isset( $_POST['ovml_ai_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( Admin::CAP ) || ! wp_verify_nonce( sanitize_key( $_POST['ovml_ai_nonce'] ), 'ovml_ai_save' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'overlay-multilingual' ) );
		}
		$provider = sanitize_key( $_POST['provider'] ?? 'anthropic' );
		$quality  = sanitize_key( $_POST['quality'] ?? 'balanced' );
		$changes  = [
			'provider'     => in_array( $provider, [ 'anthropic', 'openai' ], true ) ? $provider : 'anthropic',
			'model'        => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
			'custom_model' => preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) wp_unslash( $_POST['custom_model'] ?? '' ) ),
			'quality'      => in_array( $quality, [ 'fast', 'balanced', 'thorough' ], true ) ? $quality : 'balanced',
			'instructions' => sanitize_textarea_field( wp_unslash( $_POST['instructions'] ?? '' ) ),
		];
		$key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- an API key is stored as given, encrypted
		if ( '' !== $key ) {
			$changes['key'] = AI::encrypt( $key );
		} elseif ( ! empty( $_POST['remove_key'] ) ) {
			$changes['key'] = '';
		}
		AI::save_settings( $changes );
		set_transient( 'ovml_notice_' . get_current_user_id(), [ 'text' => __( 'AI settings saved.', 'overlay-multilingual' ), 'type' => 'success' ], 60 );
		wp_safe_redirect( Admin::url( 'ai' ) );
		exit;
	}

	/* -------------------------------------------------------------- ajax -- */

	private static function guard() {
		if ( ! current_user_can( Admin::CAP ) || ! check_ajax_referer( 'ovml_ajax', false, false ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'overlay-multilingual' ) ], 403 );
		}
		if ( ! AI::configured() ) {
			wp_send_json_error( [ 'message' => __( 'Add an API key and model on the AI translation tab first.', 'overlay-multilingual' ) ], 400 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
	}

	private static function lang_param() {
		$lang = sanitize_key( $_POST['lang'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		if ( ! in_array( $lang, ovml_secondary_languages(), true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown language.', 'overlay-multilingual' ) ], 400 );
		}
		return $lang;
	}

	public static function ajax_test() {
		self::guard();
		$result = AI::test();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [
			/* translators: 1: model, 2: seconds, 3: sample translation */
			'message' => sprintf( __( 'Connected to %1$s in %2$ss — “%3$s”', 'overlay-multilingual' ), $result['model'], $result['seconds'], $result['sample'] ),
		] );
	}

	/** One batch of untranslated phrases for a language. */
	public static function ajax_strings() {
		self::guard();
		$lang    = self::lang_param();
		$dict    = Dictionary::get( $lang );
		$skipped = (array) get_transient( 'ovml_ai_skip_' . $lang );
		$todo    = array_values( array_filter( Dictionary::sources(), static fn( $k ) => empty( $dict[ $k ] ) && ! isset( $skipped[ $k ] ) ) );
		if ( ! $todo ) {
			delete_transient( 'ovml_ai_skip_' . $lang );
			wp_send_json_success( [ 'done' => true, 'remaining' => 0, 'translated' => 0 ] );
		}
		$batch = array_slice( $todo, 0, self::BATCH );
		$items = [];
		foreach ( $batch as $i => $phrase ) {
			$items[ 's' . $i ] = $phrase;
		}
		$result = AI::translate_items( $items, $lang );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		foreach ( $items as $id => $phrase ) {
			if ( isset( $result[ $id ] ) ) {
				$dict[ $phrase ] = sanitize_text_field( $result[ $id ] );
			} else {
				$skipped[ $phrase ] = true; // do not loop on a phrase the model keeps failing
			}
		}
		Dictionary::save( $lang, $dict );
		set_transient( 'ovml_ai_skip_' . $lang, $skipped, HOUR_IN_SECONDS );
		$remaining = count( $todo ) - count( $batch );
		wp_send_json_success( [ 'done' => $remaining <= 0, 'remaining' => max( 0, $remaining ), 'translated' => count( $result ) ] );
	}

	/** IDs of posts/terms of one content source that lack a translation in a language. */
	public static function ajax_queue() {
		self::guard();
		$lang   = self::lang_param();
		$source = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		[ $kind, $name ] = array_pad( explode( ':', $source, 2 ), 2, '' );
		$ids = [];
		if ( 'post' === $kind && ovml_translatable_post_type( $name ) ) {
			foreach ( get_posts( [ 'post_type' => $name, 'post_status' => [ 'publish', 'private', 'draft' ], 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $id ) {
				if ( null === ovml_post_tr( $id, 'title', $lang ) ) {
					$ids[] = (int) $id;
				}
			}
		} elseif ( 'tax' === $kind && in_array( $name, ovml_translatable_taxonomies(), true ) ) {
			$terms = get_terms( [ 'taxonomy' => $name, 'hide_empty' => false, 'fields' => 'ids' ] );
			foreach ( is_array( $terms ) ? $terms : [] as $id ) {
				if ( null === ovml_term_tr( $id, 'name', $lang ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		wp_send_json_success( [ 'type' => 'tax' === $kind ? 'term' : 'post', 'ids' => $ids ] );
	}

	/** Translate one post or term; save it (bulk) or return the fields (edit screen). */
	public static function ajax_object() {
		self::guard();
		$lang = self::lang_param();
		$type = 'term' === ( $_POST['type'] ?? '' ) ? 'term' : 'post'; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$save = ! empty( $_POST['save'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'post' === $type ? ! current_user_can( 'edit_post', $id ) : ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'overlay-multilingual' ) ], 403 );
		}
		$result = AI::translate_object( $type, $id, $lang, $save );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ 'fields' => $result ] );
	}
}
