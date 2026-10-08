/* Overlay Multilingual — admin interactions. No dependencies. */
( function () {
	'use strict';

	const cfg = window.ovmlAdmin || {};
	const t = cfg.i18n || {};

	const post = ( data ) => {
		const body = new FormData();
		body.append( '_ajax_nonce', cfg.nonce );
		Object.entries( data ).forEach( ( [ k, v ] ) => body.append( k, v ) );
		return fetch( cfg.ajax, { method: 'POST', body, credentials: 'same-origin' } ).then( ( r ) => r.json() );
	};

	/* Tabs on edit screens (keyboard: left/right arrows). */
	document.querySelectorAll( '[data-ovml-tabs]' ).forEach( ( root ) => {
		const tabs = [ ...root.querySelectorAll( '[role=tab]' ) ];
		const select = ( tab ) => {
			tabs.forEach( ( other ) => {
				const on = other === tab;
				other.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				other.tabIndex = on ? 0 : -1;
				document.getElementById( other.getAttribute( 'aria-controls' ) ).hidden = ! on;
			} );
		};
		tabs.forEach( ( tab, i ) => {
			tab.tabIndex = tab.getAttribute( 'aria-selected' ) === 'true' ? 0 : -1;
			tab.addEventListener( 'click', () => select( tab ) );
			tab.addEventListener( 'keydown', ( e ) => {
				if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) {
					return;
				}
				const next = tabs[ ( i + ( e.key === 'ArrowRight' ? 1 : tabs.length - 1 ) ) % tabs.length ];
				select( next );
				next.focus();
			} );
		} );
		// Live status pill per language tab.
		root.querySelectorAll( '[data-ovml-main]' ).forEach( ( input ) => {
			const panel = input.closest( '[role=tabpanel]' );
			const pill = document.getElementById( panel.getAttribute( 'aria-labelledby' ) ).querySelector( '[data-ovml-tab-status]' );
			const doneText = pill.dataset.done || 'Translated';
			const missingText = pill.dataset.missing || pill.textContent;
			pill.dataset.done = pill.classList.contains( 'ovml-pill--ok' ) ? pill.textContent : doneText;
			pill.dataset.missing = pill.classList.contains( 'ovml-pill--warn' ) ? pill.textContent : missingText;
			input.addEventListener( 'input', () => {
				const done = input.value.trim() !== '';
				pill.classList.toggle( 'ovml-pill--ok', done );
				pill.classList.toggle( 'ovml-pill--warn', ! done );
				pill.textContent = done ? pill.dataset.done : pill.dataset.missing;
			} );
		} );
	} );

	/* Character counters for SEO fields. */
	document.querySelectorAll( '[data-ovml-count-for]' ).forEach( ( counter ) => {
		const field = document.getElementById( counter.dataset.ovmlCountFor );
		const max = parseInt( counter.dataset.max, 10 );
		const update = () => {
			counter.textContent = field.value.length + ' / ' + max;
			counter.classList.toggle( 'is-over', field.value.length > max );
		};
		field.addEventListener( 'input', update );
		update();
	} );

	/* "Copy original": originals are embedded once per screen as JSON. */
	const originals = ( id ) => {
		const el = document.getElementById( id );
		try {
			return el ? JSON.parse( el.textContent ) : {};
		} catch ( err ) {
			return {};
		}
	};
	const setField = ( field, value ) => {
		field.value = value;
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	};
	document.addEventListener( 'click', ( e ) => {
		const fill = e.target.closest( '[data-ovml-fill]' );
		if ( ! fill ) {
			return;
		}
		const field = document.getElementById( fill.dataset.ovmlFill );
		setField( field, originals( fill.dataset.originals )[ fill.dataset.field ] || '' );
		field.focus();
	} );

	/* ------------------------------------------ automatic translation -- */

	const sprintf = ( text, ...args ) => args.reduce( ( out, arg, i ) => out.replace( '%' + ( i + 1 ) + '$s', arg ).replace( '%' + ( i + 1 ) + '$d', arg ), text || '' );

	// Settings tab: show the chosen service's fields, its saved-key hint and (AI) its models.
	const providerBox = document.querySelector( '[data-ovml-provider]' );
	const modelSelect = document.querySelector( '[data-ovml-model]' );
	const customModel = document.querySelector( '[data-ovml-custom-model]' );
	const keyInput = document.querySelector( '[data-ovml-key]' );
	const removeKey = document.querySelector( '[data-ovml-remove-key]' );
	if ( providerBox ) {
		const sync = () => {
			const radio = providerBox.querySelector( 'input:checked' ) || {};
			const provider = radio.value;
			document.querySelectorAll( '[data-ovml-for]' ).forEach( ( el ) => {
				el.hidden = ! el.dataset.ovmlFor.split( ' ' ).includes( provider );
			} );
			if ( keyInput ) {
				const hint = radio.dataset ? radio.dataset.keyHint : '';
				keyInput.placeholder = hint ? sprintf( keyInput.dataset.saved, hint ) : keyInput.dataset.empty;
				keyInput.value = '';
			}
			if ( removeKey ) {
				removeKey.hidden = ! ( radio.dataset && radio.dataset.keyHint );
			}
			if ( modelSelect ) {
				modelSelect.querySelectorAll( 'optgroup' ).forEach( ( group ) => {
					const on = group.dataset.provider === provider;
					group.hidden = ! on;
					group.disabled = ! on;
				} );
				const current = modelSelect.selectedOptions[ 0 ];
				if ( current && current.parentElement.tagName === 'OPTGROUP' && current.parentElement.disabled ) {
					const first = modelSelect.querySelector( 'optgroup:not([disabled]) option' );
					if ( first ) {
						modelSelect.value = first.value;
					}
				}
				customModel.hidden = modelSelect.value !== 'custom';
			}
		};
		providerBox.addEventListener( 'change', sync );
		if ( modelSelect ) {
			modelSelect.addEventListener( 'change', sync );
		}
		sync();
	}

	const testBtn = document.querySelector( '[data-ovml-ai-test]' );
	if ( testBtn ) {
		const out = document.querySelector( '[data-ovml-ai-test-result]' );
		testBtn.addEventListener( 'click', () => {
			testBtn.disabled = true;
			out.textContent = t.aiTesting;
			post( { action: 'ovml_ai_test' } )
				.then( ( res ) => {
					out.innerHTML = '';
					const pill = document.createElement( 'span' );
					pill.className = 'ovml-pill ' + ( res && res.success ? 'ovml-pill--ok' : 'ovml-pill--warn' );
					pill.textContent = ( res && res.data && res.data.message ) || t.error;
					out.appendChild( pill );
				} )
				.catch( () => ( out.textContent = t.error ) )
				.finally( () => ( testBtn.disabled = false ) );
		} );
	}

	// Shared progress box for bulk AI jobs.
	const aiBox = document.querySelector( '[data-ovml-ai-status]' );
	const aiShow = ( text, pct ) => {
		if ( ! aiBox ) {
			return;
		}
		aiBox.classList.add( 'is-running' );
		aiBox.querySelector( 'span' ).textContent = text;
		if ( pct !== undefined ) {
			aiBox.querySelector( '.ovml-progress span' ).style.width = Math.round( pct ) + '%';
		}
	};

	// Strings: translate every missing phrase, batch by batch, language by language.
	const aiStrings = document.querySelector( '[data-ovml-ai-strings]' );
	if ( aiStrings ) {
		aiStrings.addEventListener( 'click', async () => {
			if ( ! window.confirm( t.aiConfirm ) ) {
				return;
			}
			aiStrings.disabled = true;
			const errors = [];
			for ( const lang of aiStrings.dataset.langs.split( ',' ) ) {
				let first = null;
				for ( let guard = 0; guard < 500; guard++ ) {
					const res = await post( { action: 'ovml_ai_strings', lang } ).catch( () => null );
					if ( ! res || ! res.success ) {
						errors.push( lang.toUpperCase() + ': ' + ( ( res && res.data && res.data.message ) || t.error ) );
						break;
					}
					first = first === null ? res.data.remaining + 1 : first;
					aiShow( sprintf( t.aiStrings, lang.toUpperCase(), res.data.remaining ), 100 * ( 1 - res.data.remaining / Math.max( first, 1 ) ) );
					if ( res.data.done ) {
						break;
					}
				}
			}
			aiShow( errors.length ? sprintf( t.aiFailed, errors.join( '; ' ) ) : t.aiDone, 100 );
			if ( ! errors.length ) {
				setTimeout( () => window.location.reload(), 1200 );
			} else {
				aiStrings.disabled = false;
			}
		} );
	}

	// Content: translate each missing item of the current type, one request per item.
	const aiContent = document.querySelector( '[data-ovml-ai-content]' );
	if ( aiContent ) {
		aiContent.addEventListener( 'click', async () => {
			if ( ! window.confirm( t.aiConfirm ) ) {
				return;
			}
			aiContent.disabled = true;
			const failed = [];
			let did = 0;
			for ( const lang of aiContent.dataset.langs.split( ',' ) ) {
				const queue = await post( { action: 'ovml_ai_queue', lang, source: aiContent.dataset.source } ).catch( () => null );
				if ( ! queue || ! queue.success ) {
					failed.push( lang.toUpperCase() + ': ' + ( ( queue && queue.data && queue.data.message ) || t.error ) );
					continue;
				}
				const ids = queue.data.ids;
				for ( let i = 0; i < ids.length; i++ ) {
					aiShow( sprintf( t.aiItems, lang.toUpperCase(), i + 1, ids.length ), ( 100 * i ) / ids.length );
					const res = await post( { action: 'ovml_ai_object', lang, type: queue.data.type, id: ids[ i ], save: 1 } ).catch( () => null );
					if ( res && res.success ) {
						did++;
					} else {
						failed.push( '#' + ids[ i ] + ' ' + lang.toUpperCase() + ( res && res.data && res.data.message ? ' (' + res.data.message + ')' : '' ) );
					}
				}
			}
			aiShow( failed.length ? sprintf( t.aiFailed, failed.slice( 0, 8 ).join( ', ' ) ) : ( did ? t.aiDone : t.aiNothing ), 100 );
			if ( ! failed.length && did ) {
				setTimeout( () => window.location.reload(), 1200 );
			} else {
				aiContent.disabled = false;
			}
		} );
	}

	// Edit screens: fill one language tab with an AI translation for review.
	document.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '[data-ovml-ai-fill]' );
		if ( ! btn ) {
			return;
		}
		const status = btn.parentElement.querySelector( '[data-ovml-ai-fill-status]' );
		btn.disabled = true;
		status.textContent = t.aiWorking;
		post( { action: 'ovml_ai_object', lang: btn.dataset.lang, type: btn.dataset.type, id: btn.dataset.id, save: 0 } )
			.then( ( res ) => {
				if ( ! res || ! res.success ) {
					status.textContent = ( res && res.data && res.data.message ) || t.error;
					return;
				}
				Object.entries( res.data.fields ).forEach( ( [ field, value ] ) => {
					const input = document.getElementById( btn.dataset.prefix + '-' + btn.dataset.lang + '-' + field );
					if ( input ) {
						setField( input, value );
					}
				} );
				status.textContent = '';
			} )
			.catch( () => ( status.textContent = t.error ) )
			.finally( () => ( btn.disabled = false ) );
	} );

	/* Copy-to-clipboard buttons. */
	document.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '[data-ovml-copy]' );
		if ( ! btn ) {
			return;
		}
		const input = document.querySelector( btn.dataset.ovmlCopy );
		navigator.clipboard.writeText( input.value ).then( () => {
			const label = btn.textContent;
			btn.textContent = t.copied || 'Copied';
			setTimeout( () => ( btn.textContent = label ), 1500 );
		} );
	} );

	/* Updates card: check GitHub in place, no page reload. */
	const updates = document.querySelector( '[data-ovml-updates]' );
	if ( updates ) {
		const btn = updates.querySelector( '[data-ovml-check-updates]' );
		const text = updates.querySelector( '[data-ovml-update-text]' );
		const action = updates.querySelector( '[data-ovml-update-action]' );
		const check = () => {
			const label = btn.textContent;
			btn.disabled = true;
			btn.textContent = t.checking;
			post( { action: 'ovml_check_updates' } )
				.then( ( res ) => {
					if ( res && res.success ) {
						text.innerHTML = res.data.text; // server-escaped markup
						action.innerHTML = res.data.action;
						updates.dataset.checked = '1';
					} else {
						action.innerHTML = '';
						const pill = document.createElement( 'span' );
						pill.className = 'ovml-pill ovml-pill--warn';
						pill.textContent = ( res && res.data && res.data.message ) || t.error;
						action.appendChild( pill );
					}
				} )
				.catch( () => ( action.textContent = t.error ) )
				.finally( () => {
					btn.disabled = false;
					btn.textContent = label;
				} );
		};
		btn.addEventListener( 'click', check );
		if ( updates.dataset.checked !== '1' ) {
			check(); // first visit: fetch the latest release in the background
		}
	}

	/* Confirm before going live. */
	const statusForm = document.querySelector( '[data-ovml-status-form]' );
	if ( statusForm ) {
		statusForm.addEventListener( 'submit', ( e ) => {
			const chosen = statusForm.querySelector( 'input[name=status]:checked' );
			const was = statusForm.querySelector( 'input[name=ovml_was]' ).value;
			if ( chosen && chosen.value === 'live' && was !== 'live' && ! window.confirm( t.confirmLive ) ) {
				e.preventDefault();
			}
		} );
	}

	/* Languages: picking a locale fills in an empty name with its native name. */
	const localeNames = originals( 'ovml-locale-names' );
	document.querySelectorAll( 'input[list="ovml-locales"]' ).forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			const row = input.closest( '.ovml-lang-row' );
			const name = row && row.querySelector( 'input[name$="[name]"]' );
			const social = row && row.querySelector( 'input[name$="[og_locale]"]' );
			const code = row && row.querySelector( 'input[name$="[code]"]' );
			if ( name && ! name.value && localeNames[ input.value ] ) {
				name.value = localeNames[ input.value ];
			}
			if ( social && ! social.value ) {
				social.value = input.value;
			}
			if ( code && ! code.value ) {
				code.value = input.value.split( '_' )[ 0 ].toLowerCase();
			}
		} );
	} );

	/* Languages: reveal the empty row. */
	const addLang = document.querySelector( '[data-ovml-add-lang]' );
	if ( addLang ) {
		addLang.addEventListener( 'click', () => {
			const row = document.querySelector( '[data-ovml-new-lang]' );
			row.hidden = false;
			addLang.hidden = true;
			row.querySelector( 'input' ).focus();
		} );
	}

	/* Settings: show hook fields only for a custom placement. */
	const placement = document.querySelector( '[data-ovml-placement]' );
	if ( placement ) {
		const custom = document.querySelector( '[data-ovml-custom-hook]' );
		placement.addEventListener( 'change', () => ( custom.hidden = placement.value !== 'custom' ) );
	}

	/* Strings: autosave each translation. */
	const timers = new WeakMap();
	const saveString = ( input ) => {
		const row = input.closest( 'tr' );
		input.classList.remove( 'is-saved', 'is-error' );
		input.classList.add( 'is-saving' );
		post( { action: 'ovml_save_string', source: row.dataset.source, lang: input.dataset.lang, value: input.value } )
			.then( ( res ) => {
				input.classList.remove( 'is-saving' );
				input.classList.add( res && res.success ? 'is-saved' : 'is-error' );
				if ( res && res.success ) {
					setTimeout( () => input.classList.remove( 'is-saved' ), 1200 );
				}
			} )
			.catch( () => {
				input.classList.remove( 'is-saving' );
				input.classList.add( 'is-error' );
				input.title = t.error;
			} );
	};
	document.querySelectorAll( '.ovml-string-input' ).forEach( ( input ) => {
		input.dataset.saved = input.value;
		const maybeSave = () => {
			if ( input.value !== input.dataset.saved ) {
				input.dataset.saved = input.value;
				saveString( input );
			}
		};
		input.addEventListener( 'input', () => {
			clearTimeout( timers.get( input ) );
			timers.set( input, setTimeout( maybeSave, 700 ) );
		} );
		input.addEventListener( 'blur', maybeSave );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				maybeSave();
			}
		} );
	} );

	document.addEventListener( 'click', ( e ) => {
		const del = e.target.closest( '[data-ovml-delete]' );
		if ( ! del || ! window.confirm( t.confirmDelete ) ) {
			return;
		}
		const row = del.closest( 'tr' );
		post( { action: 'ovml_delete_string', source: row.dataset.source } ).then( ( res ) => {
			if ( res && res.success ) {
				row.remove();
			}
		} );
	} );

	/* Strings: scan the site in batches. */
	const scanBtn = document.querySelector( '[data-ovml-scan]' );
	const scanBox = document.querySelector( '[data-ovml-scan-status]' );
	if ( scanBtn && scanBox ) {
		const label = scanBox.querySelector( 'span' );
		const bar = scanBox.querySelector( '.ovml-progress span' );
		const step = ( offset ) =>
			post( { action: 'ovml_scan', offset } ).then( ( res ) => {
				if ( ! res || ! res.success ) {
					throw new Error( 'scan' );
				}
				const d = res.data;
				bar.style.width = Math.round( ( 100 * d.next ) / Math.max( d.total, 1 ) ) + '%';
				label.textContent = ( t.scanning || '%1$d / %2$d' ).replace( '%1$d', d.next ).replace( '%2$d', d.total );
				if ( d.done ) {
					label.textContent = ( t.scanDone || '%d' ).replace( '%d', d.found );
					setTimeout( () => window.location.reload(), 1200 );
					return;
				}
				return step( d.next );
			} );
		scanBtn.addEventListener( 'click', () => {
			scanBtn.disabled = true;
			scanBox.classList.add( 'is-running' );
			step( 0 ).catch( () => {
				label.textContent = t.error;
				scanBtn.disabled = false;
			} );
		} );
	}
} )();
