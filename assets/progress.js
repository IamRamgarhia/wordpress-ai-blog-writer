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

	// Anchored to when the job started, not when this page loaded.
	// Refreshing a post that had been writing for four minutes used to
	// report nine seconds, which made a long run look like a fresh one
	// and a stuck one look healthy.
	var startedAt = Date.now() - ( ( config.elapsedAt || 0 ) * 1000 );
	var stepsDone = config.stepsAt || 0;

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
			var left = ( config.total || 10 ) - stepsDone;

			// On the last step there is nothing left to divide by, and the
			// honest answer is not "about 0s left" — which is what a job
			// that had genuinely stopped was cheerfully reporting.
			remaining = ( left > 0 ) ? Math.round( perStep * left ) : null;
		}

		clock.textContent = clockText( elapsed, remaining );
	}

	function live( state ) {
		var box = document.getElementById( 'blogcraft-live' );
		var title = document.getElementById( 'blogcraft-live-title' );
		var heads = document.getElementById( 'blogcraft-live-heads' );

		if ( ! box || ! title || ! heads ) {
			return;
		}

		var wait = document.getElementById( 'blogcraft-live-wait' );

		if ( state.title ) {
			title.textContent = state.title;

			// The placeholder has done its job the moment there is something
			// real to show.
			if ( wait ) {
				wait.remove();
			}
		}

		var planned = state.heads || [];

		// Rebuilt rather than patched: the list is a handful of items and
		// diffing it would be more code than redrawing it.
		if ( planned.length && heads.childElementCount !== planned.length ) {
			heads.innerHTML = '';

			for ( var i = 0; i < planned.length; i++ ) {
				var li = document.createElement( 'li' );
				li.textContent = planned[ i ].text;
				heads.appendChild( li );
			}
		}

		for ( var j = 0; j < planned.length && j < heads.children.length; j++ ) {
			heads.children[ j ].className = planned[ j ].done ? 'is-written' : '';
		}
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

		// fetch() waits for ever by default. A stage that hangs — a picture
		// service that accepts the connection and never answers, a provider
		// gone quiet — left this page showing a half-filled bar with no way
		// to tell it apart from one still working. Long enough that a slow
		// step is not cut off, short enough that a dead one is admitted to.
		var stop = ( 'undefined' === typeof AbortController ) ? null : new AbortController();
		var giveUp = stop ? window.setTimeout( function () {
			stop.abort();
		}, 180000 ) : 0;

		var options = { method: 'POST', credentials: 'same-origin', body: body };

		if ( stop ) {
			options.signal = stop.signal;
		}

		fetch( config.ajaxUrl, options )
			.then( function ( response ) {
				window.clearTimeout( giveUp );

				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					throw new Error( 'unusable response' );
				}

				var state = payload.data;

				// Paused by the provider: it resumes on its own, so the page
				// stops asking and reloads to explain itself rather than
				// polling against a job it cannot claim.
				if ( state.done || state.waiting ) {
					// The server renders the draft, the score and the buttons,
					// so a reload is both the simplest and the most honest way
					// to show them — no second copy of that markup in here.
					window.location.reload();

					return;
				}

				stepsDone++;
				paint( state );
				live( state );
				tick();
				failures = 0;
				window.setTimeout( advance, 400 );
			} )
			.catch( function () {
				window.clearTimeout( giveUp );
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

	// A job that has already stopped is not being watched, it is being read.
	// Saying "Working" over a post written days ago, and then asking the
	// server about it every two seconds, is two kinds of wrong.
	if ( config.settled ) {
		if ( note ) {
			note.textContent = config.done || 'Finished.';
		}

		return;
	}

	if ( note ) {
		note.textContent = config.working || 'Working...';
	}

	window.setInterval( tick, 1000 );
	tick();
	advance();
}() );
