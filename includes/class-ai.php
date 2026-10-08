<?php
/**
 * AI translation through Anthropic (Claude) or OpenAI, with the site owner's
 * own API key.
 *
 * Calls go straight to each provider's HTTP API with wp_remote_post — a
 * WordPress plugin cannot ship Composer SDKs, and two providers share one code
 * path this way. Every request asks for JSON matching a schema, so responses
 * parse reliably; HTML fields are checked to keep the original's tag sequence
 * and are rejected (never saved half-broken) when they do not.
 *
 * The key is stored encrypted with the site's AUTH_KEY, or can be defined in
 * wp-config.php as OVML_AI_KEY so it never touches the database.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class AI {

	const OPTION = 'ovml_ai';

	/** Suggested models per provider; any other model ID can be entered as a custom model. */
	public static function models() {
		return [
			'anthropic' => [
				'claude-opus-5-5'   => 'Claude Opus 5.5 — best quality (default)',
				'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — faster, lower cost',
				'claude-haiku-4-5'  => 'Claude Haiku 4.5 — fastest, lowest cost',
				'claude-fable-5-1'  => 'Claude Fable 5.1 — most capable, premium price',
			],
			'openai'    => [
				'gpt-5'      => 'GPT-5',
				'gpt-5-mini' => 'GPT-5 mini — lower cost',
				'gpt-4.1'    => 'GPT-4.1',
			],
		];
	}

	public static function defaults() {
		return [
			'provider'     => 'anthropic',
			'key'          => '', // encrypted
			'model'        => 'claude-opus-5-5',
			'custom_model' => '',
			'quality'      => 'balanced', // fast | balanced | thorough
			'instructions' => '',
		];
	}

	public static function settings() {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function save_settings( array $changes ) {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
	}

	/* --------------------------------------------------------------- key -- */

	private static function cipher_key() {
		return hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) . 'ovml-ai', true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv ) );
	}

	private static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ) );
		return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	}

	public static function key() {
		return defined( 'OVML_AI_KEY' ) && OVML_AI_KEY ? (string) OVML_AI_KEY : self::decrypt( self::settings()['key'] );
	}

	public static function key_from_config() {
		return defined( 'OVML_AI_KEY' ) && OVML_AI_KEY;
	}

	/** Last four characters only — for "key saved (…abcd)" displays. */
	public static function key_hint() {
		$key = self::key();
		return '' === $key ? '' : '…' . substr( $key, -4 );
	}

	public static function configured() {
		return '' !== self::key() && '' !== self::model();
	}

	public static function model() {
		$s = self::settings();
		return 'custom' === $s['model'] ? trim( (string) $s['custom_model'] ) : (string) $s['model'];
	}

	/* ------------------------------------------------------------ request -- */

	/**
	 * One JSON-schema-constrained completion. Returns the decoded object or a
	 * WP_Error. Retries once on rate limits / overload, honouring retry-after.
	 */
	public static function complete( $system, $user, array $schema, $max_tokens = 16000 ) {
		$s        = self::settings();
		$provider = $s['provider'];
		$model    = self::model();
		$attempt  = 0;

		do {
			$response = 'openai' === $provider
				? self::request_openai( $model, $system, $user, $schema, $max_tokens )
				: self::request_anthropic( $model, $system, $user, $schema, $max_tokens, $s['quality'] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, [ 429, 500, 502, 503, 529 ], true ) && 0 === $attempt ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 2, min( 20, $wait ?: 5 ) ) );
				++$attempt;
				continue;
			}
			break;
		} while ( true );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = $body['error']['message'] ?? wp_remote_retrieve_response_message( $response );
			return new \WP_Error( 'ovml_ai_http', sprintf( '%s (HTTP %d)', $message, $code ) );
		}

		$text = 'openai' === $provider ? self::text_openai( $body ) : self::text_anthropic( $body );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : new \WP_Error( 'ovml_ai_json', __( 'The AI response was not valid JSON.', 'overlay-multilingual' ) );
	}

	/** Claude: adaptive thinking is the default on current models; quality maps to effort. */
	private static function request_anthropic( $model, $system, $user, $schema, $max_tokens, $quality ) {
		$body = [
			'model'         => $model,
			'max_tokens'    => $max_tokens,
			'system'        => $system,
			'messages'      => [ [ 'role' => 'user', 'content' => $user ] ],
			'output_config' => [ 'format' => [ 'type' => 'json_schema', 'schema' => $schema ] ],
		];
		$headers = [
			'content-type'      => 'application/json',
			'x-api-key'         => self::key(),
			'anthropic-version' => '2023-06-01',
		];
		// Effort is not accepted by Haiku models.
		if ( false === strpos( $model, 'haiku' ) ) {
			$body['output_config']['effort'] = [ 'fast' => 'low', 'balanced' => 'medium', 'thorough' => 'high' ][ $quality ] ?? 'medium';
		}
		// Current models can decline on safety grounds; let the API re-run on a fallback model.
		if ( in_array( $model, [ 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5' ], true ) ) {
			$body['fallbacks']         = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		return wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 240,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		] );
	}

	private static function text_anthropic( $body ) {
		if ( 'refusal' === ( $body['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'ovml_ai_refusal', __( 'The model declined to translate this text.', 'overlay-multilingual' ) );
		}
		if ( 'max_tokens' === ( $body['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'ovml_ai_length', __( 'The text was too long for one request.', 'overlay-multilingual' ) );
		}
		foreach ( (array) ( $body['content'] ?? [] ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				return (string) $block['text'];
			}
		}
		return new \WP_Error( 'ovml_ai_empty', __( 'The AI returned no text.', 'overlay-multilingual' ) );
	}

	private static function request_openai( $model, $system, $user, $schema, $max_tokens ) {
		return wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 240,
			'headers' => [
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . self::key(),
			],
			'body'    => wp_json_encode( [
				'model'                 => $model,
				'max_completion_tokens' => $max_tokens,
				'messages'              => [
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user', 'content' => $user ],
				],
				'response_format'       => [
					'type'        => 'json_schema',
					'json_schema' => [ 'name' => 'translation', 'strict' => true, 'schema' => $schema ],
				],
			] ),
		] );
	}

	private static function text_openai( $body ) {
		$choice = $body['choices'][0] ?? [];
		if ( ! empty( $choice['message']['refusal'] ) ) {
			return new \WP_Error( 'ovml_ai_refusal', (string) $choice['message']['refusal'] );
		}
		if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
			return new \WP_Error( 'ovml_ai_length', __( 'The text was too long for one request.', 'overlay-multilingual' ) );
		}
		return isset( $choice['message']['content'] ) ? (string) $choice['message']['content'] : new \WP_Error( 'ovml_ai_empty', __( 'The AI returned no text.', 'overlay-multilingual' ) );
	}

	/* --------------------------------------------------------- translate -- */

	private static function system_prompt( $lang ) {
		$language = ovml_languages()[ $lang ] ?? [ 'name' => $lang, 'locale' => $lang ];
		$source   = ovml_languages()[ ovml_default_language() ] ?? [ 'name' => 'English', 'locale' => 'en_US' ];
		$site     = get_bloginfo( 'name' );
		$extra    = trim( (string) self::settings()['instructions'] );
		$prompt   = "You translate website content for \"{$site}\" from {$source['name']} ({$source['locale']}) into {$language['name']} ({$language['locale']}).\n\n"
			. "Write the way a native {$language['name']}-speaking copywriter would: natural, idiomatic and consistent across the site — not word-for-word. "
			. "Keep the meaning, tone and level of formality of the original. Keep brand names, product names, model numbers, units, prices, email addresses and URLs exactly as they are.\n\n"
			. "When a value contains HTML, translate only the human-readable text and the alt/title attribute text. Keep every tag, attribute, link and its order exactly as in the original — the markup is checked and a translation that changes it is discarded.\n\n"
			. "Some values are fragments of a sentence that a link or bold text splits apart; translate them so they still read correctly when the pieces are joined in order. A value that is only a name, code or number is returned unchanged.";
		if ( ! empty( $language['replace'] ) ) {
			$pairs   = array_map( static fn( $f, $t ) => "\"$f\" → \"$t\"", array_keys( $language['replace'] ), $language['replace'] );
			$prompt .= "\n\nSpelling rule for this language: always write " . implode( ', ', $pairs ) . '.';
		}
		if ( '' !== $extra ) {
			$prompt .= "\n\nInstructions from the site owner:\n" . $extra;
		}
		return $prompt;
	}

	/** Tag-name sequence: a translation must keep the original's markup. */
	public static function same_markup( $original, $translation ) {
		preg_match_all( '#</?([a-zA-Z][a-zA-Z0-9]*)#', (string) $original, $a );
		preg_match_all( '#</?([a-zA-Z][a-zA-Z0-9]*)#', (string) $translation, $b );
		return array_map( 'strtolower', $a[1] ) === array_map( 'strtolower', $b[1] );
	}

	/**
	 * Translate a list of [id => text] into $lang. Returns [id => translation]
	 * for the items that came back valid, or WP_Error when the request failed.
	 */
	public static function translate_items( array $items, $lang ) {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'translations' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => [ 'id' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ] ],
						'required'             => [ 'id', 'text' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'translations' ],
			'additionalProperties' => false,
		];
		$list = [];
		foreach ( $items as $id => $text ) {
			$list[] = [ 'id' => (string) $id, 'text' => (string) $text ];
		}
		$user = "Translate the \"text\" of each item. Return every item with the same \"id\".\n\n" . wp_json_encode( [ 'items' => $list ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$data = self::complete( self::system_prompt( $lang ), $user, $schema );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = [];
		foreach ( (array) ( $data['translations'] ?? [] ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( isset( $items[ $id ] ) && '' !== trim( (string) ( $row['text'] ?? '' ) ) && self::same_markup( $items[ $id ], $row['text'] ) ) {
				$out[ $id ] = $row['text'];
			}
		}
		return $out;
	}

	/** Fields to translate for a post or term, from its original-language data. */
	public static function source_fields( $type, $id ) {
		if ( 'term' === $type ) {
			$term = get_term( (int) $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return [];
			}
			return array_filter( [
				'name'        => $term->name,
				'description' => $term->description,
				'seo_title'   => self::plain_seo( get_term_meta( $term->term_id, 'rank_math_title', true ) ),
				'seo_desc'    => self::plain_seo( get_term_meta( $term->term_id, 'rank_math_description', true ) ),
			], 'strlen' );
		}
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return [];
		}
		return array_filter( [
			'title'     => $post->post_title,
			'excerpt'   => $post->post_excerpt,
			'content'   => get_post_meta( $post->ID, '_elementor_edit_mode', true ) ? '' : $post->post_content,
			'seo_title' => self::plain_seo( get_post_meta( $post->ID, 'rank_math_title', true ) ?: get_post_meta( $post->ID, '_yoast_wpseo_title', true ) ),
			'seo_desc'  => self::plain_seo( get_post_meta( $post->ID, 'rank_math_description', true ) ?: get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ) ),
		], 'strlen' );
	}

	/** SEO templates with %variables% are not text to translate. */
	private static function plain_seo( $value ) {
		$value = (string) $value;
		return false === strpos( $value, '%' ) ? $value : '';
	}

	/**
	 * Translate one post/term into $lang and (optionally) save it.
	 *
	 * @return array|\WP_Error [field => translation]
	 */
	public static function translate_object( $type, $id, $lang, $save = true ) {
		$fields = self::source_fields( $type, $id );
		if ( ! $fields ) {
			return new \WP_Error( 'ovml_ai_nothing', __( 'Nothing to translate.', 'overlay-multilingual' ) );
		}
		$result = self::translate_items( $fields, $lang );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// One retry for fields whose markup did not survive.
		$missing = array_diff_key( $fields, $result );
		if ( $missing ) {
			$retry = self::translate_items( $missing, $lang );
			if ( ! is_wp_error( $retry ) ) {
				$result += $retry;
			}
		}
		if ( $save && $result ) {
			$name = 'term' === $type ? 'name' : 'title';
			if ( ! isset( $result[ $name ] ) ) {
				return new \WP_Error( 'ovml_ai_partial', __( 'The translation came back incomplete.', 'overlay-multilingual' ) );
			}
			$get    = 'term' === $type ? 'get_term_meta' : 'get_post_meta';
			$update = 'term' === $type ? 'update_term_meta' : 'update_post_meta';
			$all    = (array) $get( (int) $id, OVML_META, true );
			$all[ $lang ] = array_merge( (array) ( $all[ $lang ] ?? [] ), $result );
			$update( (int) $id, OVML_META, $all );
		}
		return $result;
	}

	/** Cheapest possible round-trip to check the key and model. */
	public static function test() {
		$started = microtime( true );
		$result  = self::translate_items( [ 'hello' => 'Hello, welcome to our shop.' ], ovml_secondary_languages()[0] ?? 'fr' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return [
			'model'   => self::model(),
			'sample'  => $result['hello'] ?? '',
			'seconds' => round( microtime( true ) - $started, 1 ),
		];
	}
}
