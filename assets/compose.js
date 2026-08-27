/**
 * Composer behaviour.
 *
 * Switches tabs, keeps slider read-outs honest, and refreshes the outcome
 * panel and the duplicate warning from the server as the brief changes.
 *
 * The outcome is rendered server-side for the same reason the blueprint brief
 * is: it claims to predict what will actually be produced, so a second copy of
 * that arithmetic here would drift from the one the pipeline uses and quietly
 * start lying.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'blogcraft-compose' );

	if ( ! form ) {
		return;
	}

	var config = window.blogcraftCompose || {};
	var outcome = document.getElementById( 'bc-outcome-body' );
	var clash = document.getElementById( 'bc-clash' );

	/* Tabs. */

	var tabs = form.querySelectorAll( '.bc-tab' );
	var panels = form.querySelectorAll( '.bc-tabpanel' );

	function show( name ) {
		var i;

		for ( i = 0; i < panels.length; i++ ) {
			var match = panels[ i ].getAttribute( 'data-tab' ) === name;
			panels[ i ].hidden = ! match;
			panels[ i ].classList.toggle( 'is-active', match );
		}

		for ( i = 0; i < tabs.length; i++ ) {
			var current = tabs[ i ].getAttribute( 'data-tab' ) === name;
			tabs[ i ].classList.toggle( 'is-active', current );
			tabs[ i ].setAttribute( 'aria-selected', current ? 'true' : 'false' );
		}
	}

	for ( var t = 0; t < tabs.length; t++ ) {
		tabs[ t ].addEventListener( 'click', function ( event ) {
			show( event.currentTarget.getAttribute( 'data-tab' ) );
		} );
	}

	/* Slider read-outs. */

	function syncRange( input ) {
		var output = form.querySelector( 'output[for="' + input.id + '"]' );

		if ( output ) {
			output.textContent = input.value + ( input.getAttribute( 'data-unit' ) || '' );
		}
	}

	var ranges = form.querySelectorAll( 'input[type="range"]' );

	for ( var s = 0; s < ranges.length; s++ ) {
		syncRange( ranges[ s ] );
		ranges[ s ].addEventListener( 'input', function ( event ) {
			syncRange( event.currentTarget );
		} );
	}

	/* A minimum above its maximum makes every check impossible, so keep the
	   pair in order as they are dragged rather than repairing it on save. */

	var least = document.getElementById( 'bc_o_sections_min' );
	var most = document.getElementById( 'bc_o_sections_max' );

	if ( least && most ) {
		least.addEventListener( 'input', function () {
			if ( parseInt( least.value, 10 ) > parseInt( most.value, 10 ) ) {
				most.value = least.value;
				syncRange( most );
			}
		} );

		most.addEventListener( 'input', function () {
			if ( parseInt( most.value, 10 ) < parseInt( least.value, 10 ) ) {
				least.value = most.value;
				syncRange( least );
			}
		} );
	}

	/* Outcome and duplicate warning. */

	if ( ! outcome || ! config.ajaxUrl || ! window.FormData || ! window.fetch ) {
		return;
	}

	var timer = null;
	var pending = null;

	function refresh() {
		var data = new FormData( form );
		data.append( 'action', 'blogcraft_preview_post' );

		if ( pending ) {
			pending.abort();
		}

		pending = new AbortController();

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
				signal: pending.signal
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					outcome.classList.remove( 'is-stale' );
					return;
				}

				// Same-origin, nonce-checked, and passed through wp_kses server-side
				// with an allowlist carrying no script, no event attributes and no
				// href, so there is nothing here that can execute.
				outcome.innerHTML = payload.data.outcome;
				outcome.classList.remove( 'is-stale' );

				if ( clash ) {
					clash.textContent = payload.data.clash;
					clash.hidden = ! payload.data.clash;
				}
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				outcome.classList.remove( 'is-stale' );
			} );
	}

	function queue() {
		outcome.classList.add( 'is-stale' );

		if ( timer ) {
			window.clearTimeout( timer );
		}

		timer = window.setTimeout( refresh, 400 );
	}

	form.addEventListener( 'change', queue );
	form.addEventListener( 'input', function ( event ) {
		var tag = event.target.tagName;

		if ( 'INPUT' === tag || 'TEXTAREA' === tag ) {
			queue();
		}
	} );
}() );

/**
 * Ask the model what questions this topic deserves.
 *
 * The evidence field is the heaviest check on a finished post and the only
 * part a model cannot produce — and it is left empty more than any other,
 * because "what do you know that nobody else does" is a hard question asked
 * cold. Asked about a specific topic it becomes easy.
 *
 * The answer is a list of questions, never answers. Filling this field in for
 * somebody would be inventing the one thing the whole quality system leans on
 * being true.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'blogcraft-suggest' );
	var out = document.getElementById( 'blogcraft-suggest-out' );
	var list = document.getElementById( 'blogcraft-suggest-list' );
	var config = window.blogcraftCompose || {};

	if ( ! button || ! out || ! list ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var topic = document.getElementById( 'bc_topic' );
		var angle = document.getElementById( 'bc_instructions' );

		if ( ! topic || '' === topic.value.trim() ) {
			out.hidden = false;
			list.innerHTML = '';
			var warn = document.createElement( 'li' );
			warn.textContent = config.noTopic || 'Write a topic first.';
			list.appendChild( warn );

			return;
		}

		button.disabled = true;
		button.textContent = config.asking || 'Thinking...';

		var body = new FormData();
		body.append( 'action', 'blogcraft_suggest_brief' );
		body.append( '_blogcraft_nonce', config.nonce || '' );
		body.append( 'topic', topic.value );

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				button.disabled = false;
				button.textContent = config.askAgain || 'What should I write about this?';

				list.innerHTML = '';
				out.hidden = false;

				if ( ! payload || ! payload.success || ! payload.data ) {
					var failed = document.createElement( 'li' );
					failed.textContent = ( payload && payload.data && payload.data.message ) || '';
					list.appendChild( failed );

					return;
				}

				// The angle is a suggestion about shape, not a fact, so it is
				// safe to offer directly — and only when the writer has not
				// already said something of their own.
				if ( payload.data.angle && angle && '' === angle.value.trim() ) {
					angle.value = payload.data.angle;
				}

				var questions = payload.data.questions || [];

				for ( var i = 0; i < questions.length; i++ ) {
					var li = document.createElement( 'li' );
					li.textContent = questions[ i ];
					list.appendChild( li );
				}
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = config.askAgain || 'What should I write about this?';
			} );
	} );

	/* The last look before anything is written. */

	var confirm = document.getElementById( 'bc-confirm' );

	if ( confirm ) {
		var sheet = confirm.querySelector( '.bc-confirm-sheet' );
		var back = document.getElementById( 'bc-confirm-back' );
		var armed = false;

		// Rows for a field that already has a switch on the Shape tab carry no
		// name of their own, because two inputs answering one question would
		// mean the form silently keeping whichever rendered last. They drive
		// the real switch instead, and read from it on the way in so the panel
		// never shows a stale answer.
		var proxies = confirm.querySelectorAll( 'input[data-for]' );

		function realFor( box ) {
			return document.getElementById( box.getAttribute( 'data-for' ) );
		}

		function sync() {
			for ( var i = 0; i < proxies.length; i++ ) {
				var real = realFor( proxies[ i ] );

				if ( real ) {
					proxies[ i ].checked = real.checked;
				}
			}
		}

		for ( var p = 0; p < proxies.length; p++ ) {
			proxies[ p ].addEventListener( 'change', function ( event ) {
				var real = realFor( event.target );

				if ( real ) {
					real.checked = event.target.checked;

					// The outcome panel listens on the real control, so it has to
					// hear about a change made through the proxy.
					real.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		}

		function open() {
			sync();
			confirm.hidden = false;

			var first = confirm.querySelector( 'input' );

			if ( first ) {
				first.focus();
			}
		}

		function close() {
			confirm.hidden = true;
		}

		form.addEventListener( 'submit', function ( event ) {
			// Armed once the panel's own button has been pressed. Without this
			// the panel would reopen on top of itself and nothing could ever be
			// written.
			if ( armed ) {
				return;
			}

			// The browser's own required-field check has to run first, or the
			// panel opens over an empty topic and the message about it is
			// hidden behind the panel.
			if ( form.checkValidity && ! form.checkValidity() ) {
				return;
			}

			event.preventDefault();
			open();
		} );

		if ( sheet ) {
			sheet.addEventListener( 'click', function ( event ) {
				if ( 'submit' === event.target.type ) {
					armed = true;
				}
			} );
		}

		if ( back ) {
			back.addEventListener( 'click', close );
		}

		// Clicking the dimmed area behind the panel, and Escape, both mean
		// "not yet" rather than "write it".
		confirm.addEventListener( 'click', function ( event ) {
			if ( event.target === confirm ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! confirm.hidden ) {
				close();
			}
		} );
	}
}() );
