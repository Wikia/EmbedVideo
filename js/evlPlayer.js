(function(mw, $, window) {
	$(function() {
		api = new mw.Api();
		/**
		 * CATS-1635 - resize EmbedVideo's on collapse/expand button click
		 */
		window?.autoResizer?.();
		window.addEventListener('FandomDesktopContentSize', () => window?.autoResizer?.());
		$('a.embedvideo-evl').on('click', function(e){
			e.preventDefault();

			// LOREE-196 - copy only known own string keys so wikitext-controlled keys such as
			// __proto__ never reach mw.Api's $.extend and pollute the request prototype.
			var raw = $(this).data('video-json') || {};
			var data = {};
			[
				'service', 'id', 'dimensions', 'alignment', 'description', 'container',
				'urlargs', 'autoresize', 'valignment', 'player', 'notice'
			].forEach(function(key) {
				if (Object.prototype.hasOwnProperty.call(raw, key) && typeof raw[key] === 'string') {
					data[key] = raw[key];
				}
			});
			data['action'] = 'embedvideo';
			if (typeof data['player'] === 'undefined') {
				data['player'] = 'default';
			}
			var player = data['player'];
			var container = $('#vplayerbox-'+player);
			if (!container.length) {
				mw.log.error('No matching vplayer tag found for this link!');
				return;
			}
			// modify dimensions call to match container size if exists.
			if (container.data('size')) {
				data['dimensions'] = container.data('size');
			}
			api.get( data )
			.done( function( res ) {
				if (typeof res.embedvideo.html !== 'undefined') {
					content = res.embedvideo.html;
				} else {
					content = "There was an error while loading this video";
				}
				if (data.notice) {
					const noticeText = $("<small>").text(data.notice);
					content += noticeText.prop('outerHTML');
				}
				container.html(content);
				window.autoResizer();
			} );
		});
    });
}(mediaWiki, jQuery, window));
