/**
 * Blogcraft admin behaviour.
 *
 * Only job: show the custom-endpoint fields when, and only when, the custom
 * provider is selected. Those fields are meaningless for the other three
 * providers and reading them as required setup is the commonest way to get
 * stuck on this screen.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'blogcraft_provider_type' );

	if ( ! select ) {
		return;
	}

	var rows = document.querySelectorAll( '.blogcraft-custom-only' );

	function sync() {
		var isCustom = 'custom' === select.value;

		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].hidden = ! isCustom;
		}
	}

	/**
	 * Point the key and model links at whichever provider is selected.
	 *
	 * Rendered server-side first, so the links are correct before this runs and
	 * stay correct if it never does.
	 */
	var help = document.getElementById( 'blogcraft-provider-help' );
	var providers = window.blogcraftProviders || {};

	function syncHelp() {
		if ( ! help || ! providers.help ) {
			return;
		}

		var entry = providers.help[ select.value ];

		if ( ! entry || ! entry.key_url ) {
			help.hidden = true;
			return;
		}

		var keyLink = help.querySelector( '[data-role="key"]' );
		var docsLink = help.querySelector( '[data-role="docs"]' );

		if ( keyLink ) {
			keyLink.href = entry.key_url;
			keyLink.textContent = ( providers.keyText || 'Get a key from %s' ).replace( '%s', entry.label );
		}

		if ( docsLink ) {
			docsLink.href = entry.docs_url;
			docsLink.hidden = ! entry.docs_url;
		}

		help.hidden = false;
	}

	select.addEventListener( 'change', function () {
		sync();
		syncHelp();
	} );

	sync();
	syncHelp();
}() );

/**
 * Highlight whichever settings section is on screen.
 *
 * The rail already answered "where can I go"; this makes it answer "where am
 * I" too, which is the part that stops a long form feeling like one wall.
 *
 * IntersectionObserver rather than a scroll handler: it does not run on every
 * frame, and it degrades to a rail that still works as plain anchors when the
 * browser lacks it.
 */
( function () {
	'use strict';

	var items = document.querySelectorAll( '.bc-jump-item[data-target]' );

	if ( ! items.length || ! window.IntersectionObserver ) {
		return;
	}

	var byId = {};
	var cards = [];
	var i;

	for ( i = 0; i < items.length; i++ ) {
		var id = items[ i ].getAttribute( 'data-target' );
		var card = document.getElementById( id );

		if ( card ) {
			byId[ id ] = items[ i ];
			cards.push( card );
		}
	}

	function mark( id ) {
		for ( var key in byId ) {
			if ( Object.prototype.hasOwnProperty.call( byId, key ) ) {
				byId[ key ].classList.toggle( 'is-current', key === id );
			}
		}
	}

	var observer = new window.IntersectionObserver(
		function ( entries ) {
			var best = null;

			for ( var e = 0; e < entries.length; e++ ) {
				if ( entries[ e ].isIntersecting ) {
					if ( ! best || entries[ e ].boundingClientRect.top < best.boundingClientRect.top ) {
						best = entries[ e ];
					}
				}
			}

			if ( best ) {
				mark( best.target.id );
			}
		},
		{ rootMargin: '-80px 0px -60% 0px' }
	);

	for ( i = 0; i < cards.length; i++ ) {
		observer.observe( cards[ i ] );
	}
}() );

/**
 * Show a picture service's key and model fields only when it is selected.
 *
 * Same reasoning as the custom-endpoint rows above: a fal.ai key field sitting
 * under a Pollinations selection reads as required setup, and that is the
 * commonest way to get stuck on a settings screen.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'blogcraft_image_provider' );

	if ( ! select ) {
		return;
	}

	var groups = [ 'fal', 'openai' ];

	function sync() {
		for ( var g = 0; g < groups.length; g++ ) {
			var rows = document.querySelectorAll( '.blogcraft-image-' + groups[ g ] );
			var show = select.value === groups[ g ];

			for ( var i = 0; i < rows.length; i++ ) {
				rows[ i ].hidden = ! show;
			}
		}
	}

	select.addEventListener( 'change', sync );
	sync();
}() );
