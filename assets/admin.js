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

	/* "Copy original" fills an empty or confirmed field. */
	document.addEventListener( 'click', ( e ) => {
		const fill = e.target.closest( '[data-ovml-fill]' );
		if ( ! fill ) {
			return;
		}
		const field = document.getElementById( fill.dataset.ovmlFill );
		field.value = fill.dataset.source;
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.focus();
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
