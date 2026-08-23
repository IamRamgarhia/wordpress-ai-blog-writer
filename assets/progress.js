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

	if ( ! steps || ! config.job ) {
		return;
	}

	// Already finished when the page loaded — the server rendered the outcome,
	// so there is nothing to drive and nothing to poll.
	if ( document.querySelector( '.blogcraft-steps .is-now' ) === null ) {
		return;
	}

	var failures = 0;

	function paint( stage ) {
		var items = steps.querySelectorAll( 'li' );
		var seen = false;

		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];
			var isCurrent = item.getAttribute( 'data-step' ) === stage;

			if ( isCurrent ) {
				seen = true;
				item.className = 'is-now';
			} else if ( ! seen ) {
				item.className = 'is-done';
			} else {
				item.className = 'is-todo';
			}
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

				paint( state.stage );
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

	advance();
}() );
