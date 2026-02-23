( function () {
	'use strict';

	var config = window.wpThemeGuardAgent;
	var conversation = [];
	var lastStyles = null;
	var lastStylesReset = false;

	var els = {
		conversation: document.getElementById( 'agent-conversation' ),
		input: document.getElementById( 'agent-input' ),
		send: document.getElementById( 'agent-send' ),
		actions: document.getElementById( 'agent-actions' ),
		save: document.getElementById( 'agent-save' ),
		saveStatus: document.getElementById( 'agent-save-status' ),
	};

	if ( config.hasApiKey ) {
		addMessage(
			'assistant',
			'Welcome! Describe the styles you want and I\u2019ll generate them for your theme.\n\n' +
			'Examples:\n' +
			'\u2022 "Make my headings use the primary color"\n' +
			'\u2022 "Set body text to 18px with comfortable line height"\n' +
			'\u2022 "Add a subtle border to all blocks"'
		);
	}

	els.send.addEventListener( 'click', sendMessage );
	els.input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			sendMessage();
		}
	} );
	els.save.addEventListener( 'click', saveStyles );

	function sendMessage() {
		var message = els.input.value.trim();
		if ( ! message ) {
			return;
		}

		els.input.value = '';
		setInputEnabled( false );
		addMessage( 'user', message );
		var loadingEl = addMessage( 'assistant', 'Thinking\u2026' );
		loadingEl.classList.add( 'agent-loading' );

		wp.apiFetch( {
			path: 'wp-theme-guard/v1/agent/chat',
			method: 'POST',
			data: {
				message: message,
				conversation: conversation,
			},
		} )
			.then( function ( data ) {
				conversation = data.conversation;

				// Handle styles reset (empty styles) or updated styles.
				if ( data.styles_reset ) {
					lastStyles = {};
					lastStylesReset = true;
				} else if ( data.styles ) {
					lastStyles = deepMerge( lastStyles || {}, data.styles );
					lastStylesReset = false;
				}

				loadingEl.remove();
				renderConversation();

				if ( lastStyles ) {
					els.actions.style.display = '';
				}

				if ( data.rounds ) {
					var roundsEl = document.createElement( 'div' );
					roundsEl.className = 'agent-rounds';
					roundsEl.textContent = data.rounds + ' round' + ( data.rounds > 1 ? 's' : '' );
					els.conversation.appendChild( roundsEl );
				}
			} )
			.catch( function ( err ) {
				loadingEl.textContent = 'Error: ' + err.message;
				loadingEl.classList.remove( 'agent-loading' );
				loadingEl.classList.add( 'agent-error' );
			} )
			.finally( function () {
				setInputEnabled( true );
				els.input.focus();
			} );
	}

	function renderConversation() {
		els.conversation.innerHTML = '';

		for ( var i = 0; i < conversation.length; i++ ) {
			var msg = conversation[ i ];

			if ( msg.role === 'user' && typeof msg.content === 'string' ) {
				addMessage( 'user', msg.content );
			} else if ( msg.role === 'assistant' && Array.isArray( msg.content ) ) {
				for ( var j = 0; j < msg.content.length; j++ ) {
					var block = msg.content[ j ];
					if ( block.type === 'text' ) {
						addMessage( 'assistant', block.text );
					} else if ( block.type === 'tool_use' ) {
						addToolCall( block );
					}
				}
			}
			// Skip tool_result messages (rendered inside tool calls).
		}
	}

	function addMessage( role, text ) {
		var el = document.createElement( 'div' );
		el.className = 'agent-message agent-message-' + role;
		el.textContent = text;
		els.conversation.appendChild( el );
		els.conversation.scrollTop = els.conversation.scrollHeight;
		return el;
	}

	function addToolCall( block ) {
		var el = document.createElement( 'details' );
		el.className = 'agent-tool-call';

		var summary = document.createElement( 'summary' );
		summary.textContent = block.name + '()';
		el.appendChild( summary );

		var inputPre = document.createElement( 'pre' );
		inputPre.textContent = JSON.stringify( block.input, null, 2 );
		el.appendChild( inputPre );

		// Find matching tool_result in conversation.
		for ( var i = 0; i < conversation.length; i++ ) {
			var msg = conversation[ i ];
			if ( msg.role !== 'user' || ! Array.isArray( msg.content ) ) {
				continue;
			}
			for ( var j = 0; j < msg.content.length; j++ ) {
				var result = msg.content[ j ];
				if ( result.type === 'tool_result' && result.tool_use_id === block.id ) {
					var resultPre = document.createElement( 'pre' );
					resultPre.className = 'agent-tool-result';
					try {
						resultPre.textContent = JSON.stringify(
							JSON.parse( result.content ),
							null,
							2
						);
					} catch ( e ) {
						resultPre.textContent = result.content;
					}
					el.appendChild( resultPre );
				}
			}
		}

		els.conversation.appendChild( el );
		els.conversation.scrollTop = els.conversation.scrollHeight;
	}

	function deepMerge( target, source ) {
		var result = Object.assign( {}, target );
		for ( var key in source ) {
			if ( ! source.hasOwnProperty( key ) ) {
				continue;
			}
			if (
				typeof source[ key ] === 'object' && source[ key ] !== null && ! Array.isArray( source[ key ] ) &&
				typeof result[ key ] === 'object' && result[ key ] !== null && ! Array.isArray( result[ key ] )
			) {
				result[ key ] = deepMerge( result[ key ], source[ key ] );
			} else {
				result[ key ] = source[ key ];
			}
		}
		return result;
	}

	function saveStyles() {
		if ( ! lastStyles && ! lastStylesReset ) {
			return;
		}

		els.save.disabled = true;
		els.saveStatus.textContent = 'Saving\u2026';

		var gsPath = '/wp/v2/global-styles/' + config.globalStylesId;
		var savePromise;

		if ( lastStylesReset ) {
			// Hard reset: replace the entire global styles CPT with the base theme.json.
			savePromise = wp.apiFetch( {
				path: 'wp-theme-guard/v1/agent/reset-styles',
				method: 'POST',
			} );
		} else {
			// Normal: GET current styles, deep-merge, POST back.
			savePromise = wp.apiFetch( { path: gsPath } )
				.then( function ( current ) {
					var merged = deepMerge( current.styles || {}, lastStyles );
					return wp.apiFetch( {
						path: gsPath,
						method: 'POST',
						data: { styles: merged },
					} );
				} );
		}

		savePromise
			.then( function () {
				return wp.apiFetch( {
					path: gsPath + '/revisions?per_page=1',
				} );
			} )
			.then( function ( revisions ) {
				els.saveStatus.textContent = '';
				if ( revisions.length ) {
					var savedText = document.createTextNode( 'Saved! ' );
					var link = document.createElement( 'a' );
					link.href = config.adminUrl + 'revision.php?revision=' + revisions[ 0 ].id;
					link.textContent = 'View revision';
					els.saveStatus.appendChild( savedText );
					els.saveStatus.appendChild( link );
				} else {
					els.saveStatus.textContent = 'Saved!';
				}
			} )
			.catch( function ( err ) {
				els.saveStatus.textContent = 'Error: ' + ( err.message || 'Save failed' );
			} )
			.finally( function () {
				els.save.disabled = false;
			} );
	}

	function setInputEnabled( enabled ) {
		els.send.disabled = ! enabled;
		els.input.disabled = ! enabled;
	}
} )();
