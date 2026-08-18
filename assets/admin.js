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
