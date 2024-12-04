(function(mw, $, window) {
	$(function() {
		$('.embedvideo-evlbox').css('display', 'flex');
		window?.autoResizer?.();
		window.addEventListener('FandomDesktopContentSize', () => window?.autoResizer?.());
	});

}(mediaWiki, jQuery, window));
