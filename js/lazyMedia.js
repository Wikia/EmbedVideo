/**
 * LOREE-237: Chromium allows only a limited number of live media players per
 * page (1000 on desktop, fewer on mobile). Pages with hundreds of audio/video
 * files hit that cap and the remaining players fail to load.
 *
 * Media elements are rendered with data-src instead of src. A source is attached
 * when the element nears the viewport. Only while more than BUDGET players are
 * live are paused offscreen ones released (oldest first), so small pages keep
 * their buffered data and first frames exactly as before.
 * loading="lazy" alone does not help here: it defers creation but never releases.
 */
( function ( mw ) {
	'use strict';

	var SELECTOR = 'audio.embedvideo-lazymedia, video.embedvideo-lazymedia';
	// Well under Chromium's cap, which is lower on mobile than the desktop 1000.
	var BUDGET = 50;
	// Elements with a source attached, in attach order.
	var live = new Set();

	function attach( el ) {
		if ( !el.hasAttribute( 'src' ) && el.dataset.src ) {
			el.src = el.dataset.src;
			live.add( el );
			if ( el.lazyMediaTime ) {
				var time = el.lazyMediaTime;
				el.lazyMediaTime = 0;
				el.addEventListener( 'loadedmetadata', function () {
					el.currentTime = time;
				}, { once: true } );
			}
		}
	}

	function release( el ) {
		if ( el.hasAttribute( 'src' ) && el.paused ) {
			// Keep the position of a paused clip; a finished one starts over.
			el.lazyMediaTime = el.ended ? 0 : el.currentTime;
			el.removeAttribute( 'src' );
			live.delete( el );
			// Required to actually tear down the underlying player.
			el.load();
		}
	}

	// Release paused offscreen players, oldest first, until back within budget.
	function trim() {
		live.forEach( function ( el ) {
			if ( live.size > BUDGET && el.lazyMediaOffscreen ) {
				release( el );
			}
		} );
	}

	var observer = 'IntersectionObserver' in window ?
		new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				entry.target.lazyMediaOffscreen = !entry.isIntersecting;
				if ( entry.isIntersecting ) {
					attach( entry.target );
				}
			} );
			trim();
		}, { rootMargin: '300px 0px' } ) :
		null;

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		var elements = $content[ 0 ] ? $content[ 0 ].querySelectorAll( SELECTOR ) : [];
		Array.prototype.forEach.call( elements, function ( el ) {
			if ( observer ) {
				observer.observe( el );
				// A clip playing offscreen can be released once it stops.
				el.addEventListener( 'pause', trim );
				el.addEventListener( 'ended', trim );
			} else {
				attach( el );
			}
		} );
	} );
}( mediaWiki ) );
