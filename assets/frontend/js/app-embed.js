/**
 * The page around the inventory frame.
 *
 * This file owns two things and deliberately nothing else: keeping the browser's address bar in step
 * with the route inside the frame, and expanding the frame for browsers that cannot go fullscreen.
 * Sizing is the stylesheet's job and the app is the frame's job; neither is touched from here.
 *
 * NO BUILD STEP. It is plain script, enqueued as a classic script and shipped exactly as written --
 * which is also what wordpress.org's readable-source rule asks for. There is nothing here that a
 * bundler would improve.
 *
 * THE MESSAGE PAYLOAD IS ALWAYS `{ source, type, value }`, in both directions. An earlier draft used
 * `hash` in one direction and `value` in the other, which reads fine and silently does nothing.
 */
( function () {
	'use strict';

	var CHANNEL = 'plugixa-inventory';
	var EXPANDED_CLASS = 'is-expanded';
	var LOCK_CLASS = 'plugixa-inventory-embed-locked';

	/**
	 * An app route, as opposed to any other fragment on the page.
	 *
	 * The app routes with a HashRouter, so every one of its routes begins `#/`. A theme's own
	 * "#main" skip target or an anchor to a heading must not be mistaken for one and pushed into the
	 * frame, which would navigate the app somewhere that does not exist.
	 */
	function isAppRoute( hash ) {
		return typeof hash === 'string' && hash.indexOf( '#/' ) === 0;
	}

	/**
	 * @param {Element} wrapper  The `.plugixa-inventory-embed` container.
	 * @param {boolean} syncsUrl Whether this frame owns the address bar. Only one on a page can.
	 */
	function setUp( wrapper, syncsUrl ) {
		var frame = wrapper.querySelector( '.plugixa-inventory-embed__frame' );

		if ( ! frame ) {
			return;
		}

		/*
		 * SEED THE FRAME FROM THE ADDRESS BAR, BUT ONLY WHEN THERE IS SOMETHING TO SEED WITH.
		 *
		 * A fragment is never sent to the server, so PHP cannot put the route into the `src` and this
		 * is the only place it can happen. Reassigning `src` costs a second request for the frame,
		 * which is why it is guarded: an ordinary visit has no fragment and pays nothing.
		 */
		if ( syncsUrl && isAppRoute( window.location.hash ) ) {
			frame.src = frame.src.split( '#' )[ 0 ] + window.location.hash;
		}

		var expanded = false;

		function send( type, value ) {
			if ( frame.contentWindow ) {
				frame.contentWindow.postMessage(
					{ source: CHANNEL, type: type, value: value },
					window.location.origin
				);
			}
		}

		function setExpanded( next ) {
			expanded = !! next;

			wrapper.classList.toggle( EXPANDED_CLASS, expanded );
			document.body.classList.toggle( LOCK_CLASS, expanded );

			send( 'expanded', expanded );
		}

		window.addEventListener( 'message', function ( event ) {
			/*
			 * The frame is same-origin by construction -- its src is this very page. Anything from
			 * anywhere else is another plugin's frame, an advert, or an attempt, and none of those
			 * get to move this page or read its state.
			 */
			if ( event.origin !== window.location.origin ) {
				return;
			}

			/*
			 * AND IT HAS TO BE *THIS* FRAME. `message` fires on the window, so with two embeds on a
			 * page both listeners see both frames' messages; without this check, expanding one would
			 * expand the other as well.
			 */
			if ( event.source !== frame.contentWindow ) {
				return;
			}

			var data = event.data;

			if ( ! data || data.source !== CHANNEL ) {
				return;
			}

			if (
				syncsUrl &&
				data.type === 'route' &&
				isAppRoute( data.value ) &&
				data.value !== window.location.hash
			) {
				/*
				 * replaceState, NOT pushState. The frame keeps its own history, so the back button
				 * already walks the app's routes; pushing here as well would put two entries in the
				 * stack for one navigation and make Back appear to do nothing every other press.
				 */
				window.history.replaceState( null, '', data.value );
			}

			if ( data.type === 'expand' ) {
				setExpanded( data.value === undefined ? ! expanded : data.value );
			}
		} );

		/*
		 * The other direction: somebody used the back button, edited the address bar, or followed a
		 * link to a specific screen. `replaceState` above does not fire this event, so there is no
		 * loop to guard against beyond the value check.
		 */
		if ( syncsUrl ) {
			window.addEventListener( 'hashchange', function () {
				if ( isAppRoute( window.location.hash ) ) {
					send( 'route', window.location.hash );
				}
			} );
		}

		/*
		 * Escape leaves the expanded state, matching what the real Fullscreen API does for browsers
		 * that have it. Bound on the parent because that is where the class lives; the frame binds
		 * its own copy for when focus is inside it.
		 */
		document.addEventListener( 'keydown', function ( event ) {
			if ( expanded && ( event.key === 'Escape' || event.key === 'Esc' ) ) {
				setExpanded( false );
			}
		} );
	}

	function init() {
		var wrappers = document.querySelectorAll( '.plugixa-inventory-embed' );

		/*
		 * Each frame is its own document, so several on one page genuinely work -- but they would all
		 * write to one address bar, so only the first takes part in the URL bridge. Every frame still
		 * gets the expand behaviour, which is per-frame and has no such conflict.
		 */
		Array.prototype.forEach.call( wrappers, function ( wrapper, index ) {
			setUp( wrapper, index === 0 );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}() );
