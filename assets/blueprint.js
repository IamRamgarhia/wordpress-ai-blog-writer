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

/**
 * Start from a shape, or from an article that already exists.
 *
 * Both fill the controls in and neither saves. A screen that rewrote itself
 * and stored the result would be asking for trust it has not earned; a screen
 * that fills the fields in front of you is just doing the typing.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'blogcraft-blueprint-form' );
	var config = window.blogcraftBlueprint || {};

	if ( ! form || ! config.ajaxUrl || ! window.fetch || ! window.FormData ) {
		return;
	}

	var notes = document.getElementById( 'bc-shape-notes' );

	function say( lines, bad ) {
		if ( ! notes ) {
			return;
		}

		notes.textContent = '';
		notes.hidden = false;
		notes.classList.toggle( 'is-bad', !! bad );

		for ( var i = 0; i < lines.length; i++ ) {
			var line = document.createElement( 'p' );
			line.textContent = lines[ i ];
			notes.appendChild( line );
		}
	}

	/**
	 * Put one field value into whichever kind of control holds it.
	 */
	function put( name, value ) {
		var nodes = form.querySelectorAll( '[name="' + name + '"]' );

		if ( ! nodes.length ) {
			return;
		}

		var first = nodes[ 0 ];

		if ( 'checkbox' === first.type ) {
			first.checked = !! value;
			return;
		}

		if ( 'radio' === first.type ) {
			for ( var i = 0; i < nodes.length; i++ ) {
				nodes[ i ].checked = ( nodes[ i ].value === String( value ) );
			}
			return;
		}

		first.value = value;

		// Sliders carry a read-out that would otherwise still show the old number.
		if ( 'range' === first.type ) {
			var out = form.querySelector( 'output[for="' + first.id + '"]' );

			if ( out ) {
				out.textContent = first.value + ( first.getAttribute( 'data-unit' ) || '' );
			}
		}
	}

	function apply( payload ) {
		var fields = payload.fields || {};

		for ( var name in fields ) {
			if ( Object.prototype.hasOwnProperty.call( fields, name ) ) {
				put( name, fields[ name ] );
			}
		}

		say( ( payload.notes || [] ).concat( [ config.shapeSaved || '' ] ).filter( Boolean ) );

		// The brief panel is now out of date with the controls.
		form.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function ask( body, onDone ) {
		body.append( 'action', 'blogcraft_shape' );
		body.append( '_blogcraft_nonce', ( form.querySelector( '[name="_blogcraft_nonce"]' ) || {} ).value || '' );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin'
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				onDone();

				if ( ! payload || ! payload.success ) {
					say( [ ( payload && payload.data && payload.data.message ) || config.failed ], true );
					return;
				}

				apply( payload.data );
			} )
			.catch( function () {
				onDone();
				say( [ config.failed ], true );
			} );
	}

	var shapes = form.querySelectorAll( '.bc-shape' );

	for ( var s = 0; s < shapes.length; s++ ) {
		shapes[ s ].addEventListener( 'click', function ( event ) {
			var button = event.currentTarget;
			var body = new FormData();

			body.append( 'shape', button.getAttribute( 'data-shape' ) );

			for ( var i = 0; i < shapes.length; i++ ) {
				var on = shapes[ i ] === button;

				shapes[ i ].classList.toggle( 'is-chosen', on );
				shapes[ i ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			}

			// Saved with everything else the shape filled in, so the mark
			// survives the save rather than lasting until the reload.
			var remember = document.getElementById( 'bc_archetype' );

			if ( remember ) {
				remember.value = button.getAttribute( 'data-shape' ) || '';
			}

			ask( body, function () {} );
		} );
	}

	var go = document.getElementById( 'bc-match-go' );
	var field = document.getElementById( 'bc-match-url' );

	if ( ! go || ! field ) {
		return;
	}

	go.addEventListener( 'click', function () {
		if ( '' === field.value.trim() ) {
			say( [ config.shapeNoUrl || '' ].filter( Boolean ), true );
			return;
		}

		var label = go.textContent;

		go.disabled = true;
		go.textContent = config.shapeReading || label;

		var body = new FormData();
		body.append( 'url', field.value.trim() );

		ask( body, function () {
			go.disabled = false;
			go.textContent = label;
		} );
	} );
}() );
