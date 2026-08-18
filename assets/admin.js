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

	select.addEventListener( 'change', sync );
	sync();
}() );
