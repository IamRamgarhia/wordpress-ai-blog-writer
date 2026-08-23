/**
 * Drive one post's pipeline from the browser, and show it happening.
 *
 * The queue is normally drained by WP-Cron every five minutes. That is fine
 * for unattended writing and useless for somebody who just pressed a button:
 * on a staging site cron frequently never fires at all, and even when it does,
 * a five-minute wait with no feedback is indistinguishable from a stuck job.
 *
 * So this asks the server to advance one stage, waits for the answer, and asks
 * again — until the job is finished, held for review, or has failed. One stage
 * per request, because a whole pipeline in one request would exceed PHP's
 * execution limit on most shared hosting.
 */
( function () {
	'use strict';

	var config = window.blogcraftProgress || {};
	var steps = document.getElementById( 'blogcraft-progress-steps' );
	var note = document.getElementById( 'blogcraft-progress-note' );
	var fill = document.getElementById( 'blogcraft-progress-fill' );
	var count = document.getElementById( 'blogcraft-progress-count' );
	var clock = document.getElementById( 'blogcraft-progress-clock' );

	if ( ! steps || ! config.job ) {
		return;
	}

	// Already finished when the page loaded — the server rendered the outcome,
	// so there is nothing to drive and nothing to poll.
	if ( null === document.querySelector( '.blogcraft-steps .is-now' ) ) {
		return;
	}

	var failures = 0;
	var startedAt = Date.now();
	var stepsDone = 0;

	function seconds( ms ) {
		return Math.max( 0, Math.round( ms / 1000 ) );
	}

	function clockText( elapsed, remaining ) {
		var text = ( config.elapsed || '%s elapsed' ).replace( '%s', human( elapsed ) );

		// Only once a couple of steps have actually been timed. An estimate
		// drawn from a single sample is a guess wearing a number, and this
		// plugin has a standing rule against those.
		if ( null !== remaining && stepsDone >= 2 ) {
			text += ' · ' + ( config.remaining || 'about %s left' ).replace( '%s', human( remaining ) );
		}

		return text;
	}

	function human( secs ) {
		if ( secs < 60 ) {
			return secs + 's';
		}

		var mins = Math.floor( secs / 60 );
		var rest = secs % 60;

		return rest ? mins + 'm ' + rest + 's' : mins + 'm';
	}

	function tick() {
		if ( ! clock ) {
			return;
		}

		var elapsed = seconds( Date.now() - startedAt );
		var remaining = null;

		if ( stepsDone > 0 ) {
			var perStep = elapsed / stepsDone;
			var left = Math.max( 0, ( config.total || 10 ) - stepsDone );
			remaining = Math.round( perStep * left );
		}

		clock.textContent = clockText( elapsed, remaining );
	}

	function paint( state ) {
		var items = steps.querySelectorAll( 'li' );
		var seen = false;

		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];

			if ( item.getAttribute( 'data-step' ) === state.stage ) {
				seen = true;
				item.className = 'is-now';
			} else if ( ! seen ) {
				item.className = 'is-done';
			} else {
				item.className = 'is-todo';
			}
		}

		var total = state.total || 10;
		var at = state.step || 0;

		if ( fill ) {
			fill.style.width = Math.round( ( at / total ) * 100 ) + '%';
		}

		if ( count ) {
			count.textContent = ( config.stepOf || 'Step %1$d of %2$d' )
				.replace( '%1$d', Math.min( at + 1, total ) )
				.replace( '%2$d', total );
		}

		if ( note && state.label ) {
			note.textContent = state.label;
		}
	}

	function advance() {
		var body = new FormData();
		body.append( 'action', 'blogcraft_advance' );
		body.append( '_blogcraft_nonce', config.nonce || '' );
		body.append( 'job', config.job );

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					throw new Error( 'unusable response' );
				}

				var state = payload.data;

				if ( state.done ) {
					// The server renders the draft, the score and the buttons,
					// so a reload is both the simplest and the most honest way
					// to show them — no second copy of that markup in here.
					window.location.reload();

					return;
				}

				stepsDone++;
				paint( state );
				tick();
				failures = 0;
				window.setTimeout( advance, 400 );
			} )
			.catch( function () {
				failures++;

				// A provider hiccup or a dropped request should not abandon a
				// half-written post. Back off, try a few times, then say so
				// rather than spinning forever.
				if ( failures > 3 ) {
					if ( note ) {
						note.textContent = config.failed || 'Something went wrong.';
					}

					return;
				}

				window.setTimeout( advance, 2000 * failures );
			} );
	}

	if ( note ) {
		note.textContent = config.working || 'Working...';
	}

	window.setInterval( tick, 1000 );
	tick();
	advance();
}() );
