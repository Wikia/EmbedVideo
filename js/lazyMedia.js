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
		}
	}

	function release( el ) {
		if ( el.hasAttribute( 'src' ) && el.paused ) {
			el.removeAttribute( 'src' );
			// Required to actually tear down the underlying player.
			el.load();
		}
	}

	var observer = 'IntersectionObserver' in window ?
		new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
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
			} else {
				attach( el );
			}
		} );
	} );
}( mediaWiki ) );
