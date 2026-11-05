/**
 * LOREE-237: Chromium allows only a limited number of live media players per
 * page (1000 on desktop, fewer on mobile). Pages with hundreds of audio/video
 * files hit that cap and the remaining players fail to load.
 *
 * Media elements are rendered with data-src instead of src. A source is attached
 * only while the element is near the viewport, and released again (if paused)
 * once it scrolls away, so only a handful of players exist at any time.
 * loading="lazy" alone does not help here: it defers creation but never releases.
 */
( function ( mw ) {
	'use strict';

	var SELECTOR = 'audio.embedvideo-lazymedia, video.embedvideo-lazymedia';

	function attach( el ) {
		if ( !el.hasAttribute( 'src' ) && el.dataset.src ) {
			el.src = el.dataset.src;
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
			// Required to actually tear down the underlying player.
			el.load();
		}
	}

	// A clip that was playing when it left the viewport is released once it stops.
	function releaseIfOffscreen( event ) {
		if ( event.target.lazyMediaOffscreen ) {
			release( event.target );
		}
	}

	var observer = 'IntersectionObserver' in window ?
		new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				entry.target.lazyMediaOffscreen = !entry.isIntersecting;
				if ( entry.isIntersecting ) {
					attach( entry.target );
				} else {
					release( entry.target );
				}
			} );
		}, { rootMargin: '300px 0px' } ) :
		null;

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		var elements = $content[ 0 ] ? $content[ 0 ].querySelectorAll( SELECTOR ) : [];
		Array.prototype.forEach.call( elements, function ( el ) {
			if ( observer ) {
				observer.observe( el );
				el.addEventListener( 'pause', releaseIfOffscreen );
				el.addEventListener( 'ended', releaseIfOffscreen );
			} else {
				attach( el );
			}
		} );
	} );
}( mediaWiki ) );
