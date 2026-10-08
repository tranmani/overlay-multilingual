<?php
/**
 * Automatic translation with the site owner's own account at a translation
 * service — Google Cloud Translation, DeepL, Microsoft Translator or
 * LibreTranslate — or, as an added service, an AI model (Anthropic Claude,
 * OpenAI).
 *
 * Calls go straight to each provider's HTTP API with wp_remote_post — a
 * WordPress plugin cannot ship Composer SDKs, and one code path keeps every
 * service consistent. HTML is sent in each service's HTML mode, and every
 * translated HTML field must keep the original's tag sequence or it is rejected
 * (never saved half-broken). AI requests ask for JSON matching a schema.
 *
 * Keys are stored encrypted with the site's AUTH_KEY — one per service, so
 * switching services keeps them — or can be defined in wp-config.php as
 * OVML_TRANSLATE_KEY (any service) / OVML_AI_KEY (AI services).
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class AI {

	const OPTION = 'ovml_ai';

	/** Wrapper that protects "keep untranslated" terms inside machine-translation requests. */
	const KEEP_OPEN  = '<span translate="no" class="notranslate">';
	const KEEP_CLOSE = '</span>';

	/**
	 * Services. 'ai' marks the AI providers (model, quality, instructions);
	 * 'batch' and 'chars' are per-request limits of the machine services.
	 */
	public static function providers() {
		return [
			'google'    => [ 'label' => 'Google Translate', 'ai' => false, 'batch' => 100, 'chars' => 25000, 'key_url' => 'https://console.cloud.google.com/apis/library/translate.googleapis.com' ],
			'deepl'     => [ 'label' => 'DeepL', 'ai' => false, 'batch' => 50, 'chars' => 100000, 'key_url' => 'https://www.deepl.com/your-account/keys' ],
			'microsoft' => [ 'label' => 'Microsoft Translator', 'ai' => false, 'batch' => 100, 'chars' => 45000, 'key_url' => 'https://portal.azure.com/#create/Microsoft.CognitiveServicesTextTranslation' ],
			'libre'     => [ 'label' => 'LibreTranslate', 'ai' => false, 'batch' => 50, 'chars' => 20000, 'key_url' => 'https://github.com/LibreTranslate/LibreTranslate' ],
			'anthropic' => [ 'label' => 'Anthropic Claude', 'ai' => true, 'key_url' => 'https://console.anthropic.com/settings/keys' ],
			'openai'    => [ 'label' => 'OpenAI', 'ai' => true, 'key_url' => 'https://platform.openai.com/api-keys' ],
		];
	}

	public static function is_ai( $provider = null ) {
		$provider = $provider ?? self::settings()['provider'];
		return ! empty( self::providers()[ $provider ]['ai'] );
	}

	/** Suggested models per AI provider; any other model ID can be entered as a custom model. */
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
			'provider'     => 'google',
			'keys'         => [], // provider => encrypted key
			'region'       => '', // Microsoft Translator resource region, e.g. westeurope
			'libre_url'    => 'https://libretranslate.com',
			'formality'    => 'default', // DeepL: default | more | less
			'keep'         => '', // terms never translated, one per line
			'model'        => 'claude-opus-5-5',
			'custom_model' => '',
			'quality'      => 'balanced', // fast | balanced | thorough
			'instructions' => '',
		];
	}

	public static function settings() {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		// 1.3.x stored one AI key under 'key' for the provider of the time.
		if ( ! empty( $saved['key'] ) && empty( $saved['keys'] ) ) {
			$saved['keys'] = [ $saved['provider'] ?? 'anthropic' => $saved['key'] ];
		}
		unset( $saved['key'] );
		if ( ! isset( $saved['provider'] ) && ! empty( $saved['keys'] ) ) {
			$saved['provider'] = array_key_first( $saved['keys'] );
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function save_settings( array $changes ) {
		$all = array_merge( self::settings(), $changes );
		update_option( self::OPTION, $all, false );
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

	/** wp-config.php constant that holds the key for a provider, if any is defined. */
	private static function config_constant( $provider ) {
		if ( defined( 'OVML_TRANSLATE_KEY' ) && OVML_TRANSLATE_KEY ) {
			return 'OVML_TRANSLATE_KEY';
		}
		if ( self::is_ai( $provider ) && defined( 'OVML_AI_KEY' ) && OVML_AI_KEY ) {
			return 'OVML_AI_KEY';
		}
		return '';
	}

	public static function key( $provider = null ) {
		$provider = $provider ?? self::settings()['provider'];
		$constant = self::config_constant( $provider );
		return $constant ? (string) constant( $constant ) : self::decrypt( self::settings()['keys'][ $provider ] ?? '' );
	}

	public static function key_from_config( $provider = null ) {
		return '' !== self::config_constant( $provider ?? self::settings()['provider'] );
	}

	/** Last four characters only — for "key saved (…abcd)" displays. */
	public static function key_hint( $provider = null ) {
		$key = self::key( $provider );
		return '' === $key ? '' : '…' . substr( $key, -4 );
	}

	public static function configured() {
		$s = self::settings();
		if ( ! isset( self::providers()[ $s['provider'] ] ) ) {
			return false;
		}
		if ( 'libre' === $s['provider'] ) {
			return '' !== trim( (string) $s['libre_url'] ); // the key is optional on self-hosted servers
		}
		if ( '' === self::key() ) {
			return false;
		}
		return ! self::is_ai() || '' !== self::model();
	}

	public static function model() {
		$s = self::settings();
		return 'custom' === $s['model'] ? trim( (string) $s['custom_model'] ) : (string) $s['model'];
	}

	/** Human name of what is in use, for messages: "DeepL" or "claude-opus-5-5". */
	public static function engine_label() {
		return self::is_ai() ? self::model() : self::providers()[ self::settings()['provider'] ]['label'];
	}

	/* ------------------------------------------------- language codes -- */

	/**
	 * Provider language code for a site language, from its WordPress locale
	 * (pt_BR, zh_TW, en_GB …) so regional variants map correctly.
	 */
	public static function lang_code( $provider, $lang, $target = true ) {
		$locale = (string) ( ovml_languages()[ $lang ]['locale'] ?? $lang );
		$parts  = preg_split( '/[_-]/', strtolower( $locale ) );
		$base   = $parts[0];
		$region = $parts[1] ?? '';
		switch ( $provider ) {
			case 'deepl':
				if ( ! $target ) {
					return strtoupper( $base );
				}
				if ( 'en' === $base ) {
					return 'us' === $region ? 'EN-US' : 'EN-GB';
				}
				if ( 'pt' === $base ) {
					return 'br' === $region ? 'PT-BR' : 'PT-PT';
				}
				if ( 'zh' === $base ) {
					return in_array( $region, [ 'tw', 'hk' ], true ) ? 'ZH-HANT' : 'ZH-HANS';
				}
				return strtoupper( 'no' === $base ? 'nb' : $base );
			case 'google':
				if ( 'zh' === $base ) {
					return in_array( $region, [ 'tw', 'hk' ], true ) ? 'zh-TW' : 'zh-CN';
				}
				if ( 'pt' === $base && 'pt' === $region ) {
					return 'pt-PT';
				}
				return 'nb' === $base ? 'no' : $base;
			case 'microsoft':
				if ( 'zh' === $base ) {
					return in_array( $region, [ 'tw', 'hk' ], true ) ? 'zh-Hant' : 'zh-Hans';
				}
				if ( 'pt' === $base && 'pt' === $region ) {
					return 'pt-pt';
				}
				return 'no' === $base ? 'nb' : $base;
			default:
				return $base;
		}
	}

	/* --------------------------------------------- machine translation -- */

	private static function keep_terms() {
		$terms = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::settings()['keep'] ) ), 'strlen' );
		usort( $terms, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) ); // longest first
		return $terms;
	}

	/** Wrap "keep untranslated" terms (outside tags) so services leave them alone. */
	private static function protect( $text, array $terms ) {
		if ( ! $terms ) {
			return $text;
		}
		$quoted = implode( '|', array_map( static fn( $t ) => preg_quote( $t, '/' ), $terms ) );
		$parts  = preg_split( '/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && '<' !== $part[0] ) {
				$parts[ $i ] = preg_replace( '/(?<![\p{L}\p{N}])(' . $quoted . ')(?![\p{L}\p{N}])/u', self::KEEP_OPEN . '$1' . self::KEEP_CLOSE, $part );
			}
		}
		return implode( '', $parts );
	}

	private static function unprotect( $text ) {
		return preg_replace( '#<span translate="no" class="notranslate">(.*?)</span>#s', '$1', (string) $text );
	}

	/**
	 * Translate [id => text] with a machine-translation service.
	 *
	 * @return array|\WP_Error [id => translation]
	 */
	private static function translate_machine( array $items, $lang ) {
		$provider = self::settings()['provider'];
		$meta     = self::providers()[ $provider ];
		$terms    = self::keep_terms();
		$prepared = [];
		foreach ( $items as $id => $text ) {
			$prepared[ $id ] = self::protect( (string) $text, $terms );
		}

		// Chunks within the service's per-request limits.
		$chunks = [];
		$chunk  = [];
		$size   = 0;
		foreach ( $prepared as $id => $text ) {
			if ( $chunk && ( count( $chunk ) >= $meta['batch'] || $size + strlen( $text ) > $meta['chars'] ) ) {
				$chunks[] = $chunk;
				$chunk    = [];
				$size     = 0;
			}
			$chunk[ $id ] = $text;
			$size        += strlen( $text );
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}

		$replace = (array) ( ovml_languages()[ $lang ]['replace'] ?? [] );
		$out     = [];
		foreach ( $chunks as $chunk ) {
			$texts  = array_values( $chunk );
			$result = self::request_machine( $provider, $texts, $lang );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( array_keys( $chunk ) as $i => $id ) {
				$text = self::unprotect( $result[ $i ] ?? '' );
				if ( false === strpos( (string) $items[ $id ], '<' ) ) {
					// Plain text went through HTML mode: turn entities back into characters.
					$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
				if ( $replace ) {
					$text = strtr( $text, $replace );
				}
				if ( '' !== trim( $text ) && self::same_markup( $items[ $id ], $text ) ) {
					$out[ $id ] = $text;
				}
			}
		}
		return $out;
	}

	/** One request to a machine-translation service. Returns translations in input order. */
	private static function request_machine( $provider, array $texts, $lang ) {
		$s       = self::settings();
		$key     = self::key( $provider );
		$source  = self::lang_code( $provider, ovml_default_language(), false );
		$target  = self::lang_code( $provider, $lang );
		$attempt = 0;

		do {
			switch ( $provider ) {
				case 'google':
					$response = wp_remote_post( 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode( $key ), [
						'timeout' => 60,
						'headers' => [ 'content-type' => 'application/json' ],
						'body'    => wp_json_encode( [ 'q' => $texts, 'source' => $source, 'target' => $target, 'format' => 'html' ] ),
					] );
					break;
				case 'deepl':
					$host = ':fx' === substr( $key, -3 ) ? 'api-free.deepl.com' : 'api.deepl.com';
					$body = [ 'text' => $texts, 'source_lang' => $source, 'target_lang' => $target, 'tag_handling' => 'html', 'preserve_formatting' => true ];
					if ( 'default' !== $s['formality'] ) {
						$body['formality'] = 'more' === $s['formality'] ? 'prefer_more' : 'prefer_less';
					}
					$response = wp_remote_post( "https://$host/v2/translate", [
						'timeout' => 60,
						'headers' => [ 'content-type' => 'application/json', 'authorization' => 'DeepL-Auth-Key ' . $key ],
						'body'    => wp_json_encode( $body ),
					] );
					break;
				case 'microsoft':
					$headers = [ 'content-type' => 'application/json', 'Ocp-Apim-Subscription-Key' => $key ];
					if ( '' !== trim( (string) $s['region'] ) ) {
						$headers['Ocp-Apim-Subscription-Region'] = trim( (string) $s['region'] );
					}
					$response = wp_remote_post( 'https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&textType=html&from=' . rawurlencode( $source ) . '&to=' . rawurlencode( $target ), [
						'timeout' => 60,
						'headers' => $headers,
						'body'    => wp_json_encode( array_map( static fn( $t ) => [ 'Text' => $t ], $texts ) ),
					] );
					break;
				default: // libre
					$body = [ 'q' => $texts, 'source' => $source, 'target' => $target, 'format' => 'html' ];
					if ( '' !== $key ) {
						$body['api_key'] = $key;
					}
					$response = wp_remote_post( untrailingslashit( (string) $s['libre_url'] ) . '/translate', [
						'timeout' => 120,
						'headers' => [ 'content-type' => 'application/json' ],
						'body'    => wp_json_encode( $body ),
					] );
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, [ 429, 500, 502, 503 ], true ) && 0 === $attempt ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 2, min( 20, $wait ?: 5 ) ) );
				++$attempt;
				continue;
			}
			break;
		} while ( true );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = $data['error']['message'] ?? $data['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : '' );
			if ( 456 === $code && 'deepl' === $provider ) {
				$message = __( 'DeepL quota exceeded for this billing period.', 'overlay-multilingual' );
			}
			return new \WP_Error( 'ovml_mt_http', sprintf( '%s (HTTP %d)', $message ?: wp_remote_retrieve_response_message( $response ), $code ) );
		}

		switch ( $provider ) {
			case 'google':
				$list = array_column( (array) ( $data['data']['translations'] ?? [] ), 'translatedText' );
				break;
			case 'deepl':
				$list = array_column( (array) ( $data['translations'] ?? [] ), 'text' );
				break;
			case 'microsoft':
				$list = array_map( static fn( $row ) => $row['translations'][0]['text'] ?? '', (array) $data );
				break;
			default:
				$list = (array) ( $data['translatedText'] ?? [] );
		}
		if ( count( $list ) !== count( $texts ) ) {
			return new \WP_Error( 'ovml_mt_count', __( 'The translation service returned an unexpected response.', 'overlay-multilingual' ) );
		}
		return array_map( 'strval', $list );
	}

	/* ------------------------------------------------------ AI request -- */

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
		if ( self::keep_terms() ) {
			$prompt .= "\n\nNever translate these terms; keep them exactly as written: " . implode( ', ', self::keep_terms() ) . '.';
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
	 * Translate a list of [id => text] into $lang with the configured service.
	 * Returns [id => translation] for the items that came back valid, or
	 * WP_Error when the request failed.
	 */
	public static function translate_items( array $items, $lang ) {
		if ( ! self::is_ai() ) {
			return self::translate_machine( $items, $lang );
		}
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

	/** Cheapest possible round-trip to check the key (and model). */
	public static function test() {
		$started = microtime( true );
		$result  = self::translate_items( [ 'hello' => 'Hello, welcome to our shop.' ], ovml_secondary_languages()[0] ?? 'fr' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['hello'] ) ) {
			return new \WP_Error( 'ovml_mt_test', __( 'The service answered, but returned no translation.', 'overlay-multilingual' ) );
		}
		return [
			'model'   => self::engine_label(),
			'sample'  => $result['hello'],
			'seconds' => round( microtime( true ) - $started, 1 ),
		];
	}
}
