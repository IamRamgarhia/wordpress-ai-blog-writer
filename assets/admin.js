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

	/**
	 * Keep the base URL hint and placeholder honest.
	 *
	 * These were rendered once from the saved provider and never touched again,
	 * so choosing Anthropic and reading "Leave blank to use api.openai.com" was
	 * the normal experience of this screen until you pressed save.
	 */
	var baseField = document.getElementById( 'blogcraft_provider_base_url' );
	var baseHint = document.getElementById( 'blogcraft_provider_base_url_hint' );

	function syncBase() {
		if ( ! baseField || ! providers.bases ) {
			return;
		}

		var address = providers.bases[ select.value ] || '';

		baseField.placeholder = address;

		if ( ! baseHint ) {
			return;
		}

		baseHint.textContent = ( '' === address
			? providers.baseNone
			: ( providers.baseText || '%s' ).replace( '%s', address )
		) + ' ' + ( providers.baseTail || '' );
	}

	select.addEventListener( 'change', function () {
		sync();
		syncHelp();
		syncBase();
	} );

	sync();
	syncHelp();
	syncBase();
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

	var groups = [ 'fal', 'openai', 'gemini', 'xai' ];

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

/**
 * Fold each card's explanation open and shut.
 *
 * A settings screen that explains everything up front is unreadable, and one
 * that explains nothing sends people to a search engine. Opening it in place
 * is the only arrangement that serves both.
 */
( function () {
	'use strict';

	var toggles = document.querySelectorAll( '.bc-help-toggle' );

	for ( var i = 0; i < toggles.length; i++ ) {
		toggles[ i ].addEventListener( 'click', function ( event ) {
			var button = event.currentTarget;
			var panel = document.getElementById( button.getAttribute( 'aria-controls' ) );

			if ( ! panel ) {
				return;
			}

			var open = 'true' === button.getAttribute( 'aria-expanded' );

			button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			panel.hidden = open;
		} );
	}
}() );

/**
 * Fill the voice fields in from what the site has already published.
 *
 * The values land in the form rather than being saved. A settings screen that
 * rewrites itself without asking is one nobody trusts twice, and a guess in a
 * field you can still correct is help; the same guess saved silently is not.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'blogcraft-learn' );
	var config = window.blogcraftProviders || {};

	if ( ! button || ! config.ajaxUrl || ! window.fetch || ! window.FormData ) {
		return;
	}

	var notes = document.getElementById( 'blogcraft-learn-notes' );

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

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.textContent = config.learning || 'Reading...';

		var data = new FormData();
		data.append( 'action', 'blogcraft_learn_voice' );
		data.append( '_blogcraft_nonce', config.nonce || '' );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				button.disabled = false;
				button.textContent = config.learned || 'Learn from my posts';

				if ( ! payload || ! payload.success || ! payload.data ) {
					say( [ ( payload && payload.data && payload.data.message ) || config.failed ], true );
					return;
				}

				var fields = payload.data.fields || {};

				for ( var name in fields ) {
					if ( ! Object.prototype.hasOwnProperty.call( fields, name ) ) {
						continue;
					}

					var input = document.getElementById( 'blogcraft_' + name );

					// Never overwrite something the person already wrote.
					if ( input && '' === input.value.trim() ) {
						input.value = fields[ name ];
					}
				}

				say( payload.data.notes || [] );
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = config.learned || 'Learn from my posts';
				say( [ config.failed ], true );
			} );
	} );
}() );

/**
 * Fill the model box from the provider's own list.
 *
 * The model field asks for an id "exactly as your provider writes it", which
 * is a reasonable instruction and still gets the name of the API key typed
 * into it — the two things sit side by side in every provider's console. That
 * mistake is invisible until generation runs and the provider rejects it.
 *
 * Every adapter has always been able to list models; nothing called it.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'blogcraft-fetch-models' );
	var choices = document.getElementById( 'blogcraft-model-choices' );
	var status = document.getElementById( 'blogcraft-model-status' );
	var field = document.getElementById( 'blogcraft_provider_model' );
	var config = window.blogcraftProviders || {};

	if ( ! button || ! choices || ! field ) {
		return;
	}

	function value( id ) {
		var el = document.getElementById( id );

		return el ? el.value : '';
	}

	choices.addEventListener( 'change', function () {
		if ( choices.value ) {
			field.value = choices.value;
		}
	} );

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.textContent = config.asking || 'Asking your provider...';

		var body = new FormData();
		body.append( 'action', 'blogcraft_list_models' );
		body.append( '_blogcraft_nonce', config.nonce || '' );
		body.append( 'provider_type', value( 'blogcraft_provider_type' ) );
		body.append( 'base_url', value( 'blogcraft_provider_base_url' ) );

		// Sent only when freshly typed; blank means "use the saved one", which
		// the handler resolves server-side. The key never round-trips back.
		body.append( 'api_key', value( 'blogcraft_provider_api_key' ) );

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				button.disabled = false;
				button.textContent = config.askModel || 'Show the models on my account';

				var models = payload && payload.data && payload.data.models;

				if ( ! payload || ! payload.success || ! models || ! models.length ) {
					if ( status ) {
						status.textContent = ( payload && payload.data && payload.data.message ) || '';
					}

					return;
				}

				while ( choices.options.length > 1 ) {
					choices.remove( 1 );
				}

				for ( var i = 0; i < models.length; i++ ) {
					var option = document.createElement( 'option' );
					option.value = models[ i ];
					option.textContent = models[ i ];
					choices.appendChild( option );
				}

				choices.hidden = false;

				if ( status ) {
					status.textContent = ( config.gotModels || '%d models on your account.' ).replace( '%d', models.length );
				}
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = config.askModel || 'Show the models on my account';
			} );
	} );
}() );
