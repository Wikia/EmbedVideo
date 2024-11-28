(function(mw, $, window) {
	$(function() {
		const evSelector = '.embedvideo';
		const $twitchEmbeds = $(`${evSelector} [src*="twitch.tv"]`);
		const errorOverlay = '<div class="ev-fandom-error-overlay"></div>';
		const errorMessage = `<p class="ev-fandom-error-message">${mw
			.message('ev-fandom-twitch-error-message')
			.escaped()}</p>`;

		if (!$twitchEmbeds.length) {
			return;
		}

		$twitchEmbeds.addClass('wds-is-hidden').each((index, element) => {
			$(element).parents(evSelector).first().wrap(errorOverlay).append(errorMessage);
		});
	});
}(mediaWiki, jQuery, window));
