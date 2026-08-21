/**
 * Blueprint editor behaviour.
 *
 * Three jobs: switch panes, keep the slider read-outs honest, and refresh the
 * brief panel from the server as controls change.
 *
 * The brief is rendered server-side rather than assembled here on purpose. The
 * panel claims to show exactly what the model will be told, so building a
 * second copy of that logic in JavaScript would guarantee the two drift and
 * turn the one honest thing on the screen into a lie.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'blogcraft-blueprint-form' );

	if ( ! form ) {
		return;
	}

	var config = window.blogcraftBlueprint || {};
	var brief = document.getElementById( 'bc-brief-body' );
	var picture = document.getElementById( 'bc-picture-prompt' );

	/* Panes. */

	var rail = form.querySelectorAll( '.bc-rail-item' );
	var panes = form.querySelectorAll( '.bc-pane' );

	function show( name ) {
		var i;

		for ( i = 0; i < panes.length; i++ ) {
			var match = panes[ i ].getAttribute( 'data-pane' ) === name;
			panes[ i ].hidden = ! match;
			panes[ i ].classList.toggle( 'is-active', match );
		}

		for ( i = 0; i < rail.length; i++ ) {
			var current = rail[ i ].getAttribute( 'data-pane' ) === name;
			rail[ i ].classList.toggle( 'is-active', current );
			rail[ i ].setAttribute( 'aria-current', current ? 'true' : 'false' );
		}
	}

	for ( var r = 0; r < rail.length; r++ ) {
		rail[ r ].addEventListener( 'click', function ( event ) {
			show( event.currentTarget.getAttribute( 'data-pane' ) );
		} );
	}

	/* Slider read-outs. */

	function sync( input ) {
		var output = form.querySelector( 'output[for="' + input.id + '"]' );

		if ( output ) {
			output.textContent = input.value + ( input.getAttribute( 'data-unit' ) || '' );
		}
	}

	var ranges = form.querySelectorAll( 'input[type="range"]' );

	for ( var s = 0; s < ranges.length; s++ ) {
		sync( ranges[ s ] );
		ranges[ s ].addEventListener( 'input', function ( event ) {
			sync( event.currentTarget );
		} );
	}

	/* Live brief. */

	if ( ! brief || ! config.ajaxUrl || ! window.FormData || ! window.fetch ) {
		return;
	}

	var timer = null;
	var pending = null;

	function refresh() {
		var data = new FormData( form );
		data.append( 'action', 'blogcraft_preview_brief' );

		// Abandon a request already in flight; only the newest state matters.
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
				if ( payload && payload.success && payload.data ) {
					brief.textContent = payload.data.brief;

					if ( picture && payload.data.picture ) {
						picture.textContent = payload.data.picture;
					}
				}

				brief.classList.remove( 'is-stale' );
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				// A failed refresh must not leave a stale brief looking current.
				brief.classList.remove( 'is-stale' );
				brief.textContent = config.failed || '';
			} );
	}

	function queue() {
		brief.classList.add( 'is-stale' );

		if ( timer ) {
			window.clearTimeout( timer );
		}

		timer = window.setTimeout( refresh, 350 );
	}

	form.addEventListener( 'change', queue );
	form.addEventListener( 'input', function ( event ) {
		var tag = event.target.tagName;

		if ( 'INPUT' === tag || 'TEXTAREA' === tag ) {
			queue();
		}
	} );
}() );
