<?php

use EmbedVideo\VideoService;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;

/**
 * EmbedVideo
 * EmbedVideo Hooks
 *
 * @license MIT
 * @package EmbedVideo
 * @link    https://www.mediawiki.org/wiki/Extension:EmbedVideo
 */

class EmbedVideoHooks implements ParserFirstCallInitHook {
	/**
	 * Temporary storage for the current service object.
	 *
	 * @var object
	 */
	private static $service;

	/**
	 * Description Parameter
	 *
	 * @var string|bool
	 */
	private static $description = false;

	/**
	 * Alignment Parameter
	 *
	 * @var string|bool
	 */
	private static $alignment = false;

	/**
	 * Alignment Parameter
	 *
	 * @var string|bool
	 */
	private static $vAlignment = false;

	/**
	 * Container Parameter
	 *
	 * @var string|bool
	 */
	private static $container = false;

	/**
	 * Valid Arguments for the parseEV function hook.
	 *
	 * @var array
	 */
	private static $validArguments = [
		'service'		=> null,
		'id'			=> null,
		'defaultid'		=> null,
		'dimensions'	=> null,
		'alignment'		=> null,
		'description'	=> null,
		'container'		=> null,
		'urlargs'		=> null,
		'autoresize'	=> null,
		'valignment'	=> null
	];

	/**
	 * Hook to set up defaults.
	 *
	 * @return void
	 */
	public static function onExtension(): void {
		global $wgEmbedVideoDefaultWidth, $wgMediaHandlers, $wgFileExtensions,
			   $wgEmbedVideoEnableAudioHandler, $wgEmbedVideoEnableVideoHandler, $wgEmbedVideoAddFileExtensions;

		if ( !isset( $wgEmbedVideoDefaultWidth ) && ( isset( $_SERVER['HTTP_X_MOBILE'] )
				&& $_SERVER['HTTP_X_MOBILE'] == 'true' ) && $_COOKIE['stopMobileRedirect'] != 1 ) {
			// Set a smaller default width when in mobile view.
			$wgEmbedVideoDefaultWidth = 320;
		}

		if ( $wgEmbedVideoEnableAudioHandler ) {
			$wgMediaHandlers['application/ogg'] 	= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/flac']			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/ogg'] 			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/mpeg']			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/mp4']			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/wav']			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/webm']			= 'EmbedVideo\AudioHandler';
			$wgMediaHandlers['audio/x-flac']		= 'EmbedVideo\AudioHandler';
		}
		if ( $wgEmbedVideoEnableVideoHandler ) {
			$wgMediaHandlers['video/mp4']			= 'EmbedVideo\VideoHandler';
			$wgMediaHandlers['video/quicktime']		= 'EmbedVideo\VideoHandler';
			$wgMediaHandlers['video/webm']			= 'EmbedVideo\VideoHandler';
			$wgMediaHandlers['video/x-matroska']	= 'EmbedVideo\VideoHandler';
		}

		if ( $wgEmbedVideoAddFileExtensions ) {
			$wgFileExtensions[] = 'flac';
			$wgFileExtensions[] = 'mkv';
			$wgFileExtensions[] = 'mov';
			$wgFileExtensions[] = 'mp3';
			$wgFileExtensions[] = 'mp4';
			$wgFileExtensions[] = 'oga';
			$wgFileExtensions[] = 'ogv';
			$wgFileExtensions[] = 'wav';
			$wgFileExtensions[] = 'webm';
		}
	}

	/**
	 * Sets up this extension's parser functions.
	 *
	 * @param Parser $parser instance.
	 * @return bool true
	 */
	public function onParserFirstCallInit( $parser ): bool {
		$parser->setFunctionHook( "ev", "EmbedVideoHooks::parseEV" );
		$parser->setFunctionHook( "evt", "EmbedVideoHooks::parseEVT" );
		$parser->setFunctionHook( "evp", "EmbedVideoHooks::parseEVP" );
		$parser->setFunctionHook( "evu", "EmbedVideoHooks::parseEVU" );

		$parser->setHook( "embedvideo", "EmbedVideoHooks::parseEVTag" );
		$parser->setHook( 'evlplayer', "EmbedVideoHooks::parseEVLPlayer" );
		$parser->setFunctionHook( 'evl', "EmbedVideoHooks::parseEVL" );

		// don't step on VideoLink's toes.
		if ( !class_exists( 'FXVideoLink' ) ) {
			$parser->setHook( 'vplayer', "EmbedVideoHooks::parseEVLPlayer" );
			$parser->setFunctionHook( 'vlink', "EmbedVideoHooks::parseEVL" );
		}

		// smart handling of service name tags (if they aren't already implamented)
		$tags = $parser->getTags();
		$services = VideoService::getAvailableServices();
		$create = array_diff( $services, $tags );
		// We now have a list of services we can create tags for that aren't already implamented
		foreach ( $create as $service ) {
			$parser->setHook( $service, "EmbedVideoHooks::parseServiceTag{$service}" );
		}

		return true;
	}

	/**
	 * Handle passing parseServiceTagSERVICENAME to the parseServiceTag method.
	 *
	 * @param string $name
	 * @param array $args
	 * @return array|null
	 */
	public static function __callStatic( string $name, array $args ): ?array {
		if ( str_starts_with( $name, "parseServiceTag" ) ) {
			$service = str_replace( "parseServiceTag", "", $name );
			return self::parseServiceTag( $service, $args[0], $args[1], $args[2], $args[3] );
		}
		return null;
	}

	/**
	 * Parse tag with service name
	 *
	 * @param string $service
	 * @param string $input Raw User Input
	 * @param array $args Arguments on the tag.
	 * @param Parser $parser Parser object.
	 * @param PPFrame $frame PPFrame object.
	 * @return array Error Message
	 */
	public static function parseServiceTag( $service, $input, array $args, Parser $parser, PPFrame $frame ): array {
		$args = array_merge( self::$validArguments, $args );

		// accept input as default, but also allow url param.
		if ( empty( $input ) && isset( $args['url'] ) ) {
			$input = $args['url'];
		}

		return self::parseEV(
			$parser,
			$service,
			$input,
			$args['dimensions'],
			$args['alignment'],
			$args['description'],
			$args['container'],
			$args['urlargs'],
			$args['autoresize'],
			$args['valignment']
		);
	}

	/**
	 * Parse EVL (and vlink) Tags
	 *
	 * @param Parser &$parser
	 * @return array
	 */
	public static function parseEVL( Parser &$parser ): array {
		$args = func_get_args();
		array_shift( $args );

		// handle comma separated video id list
		$ids = explode( ',', $args[0] );
		$id = isset( $args[2] ) && is_numeric( $args[2] ) ? $args[2] - 1 : false;
		$video = $id !== false && isset( $ids[$id] ) ? $ids[$id] : array_shift( $ids );

		// standardize first 2 arguments into strings that parse_str can handle.
		$args[0] = "id=" . $video;
		$args[1] = "linktitle=" . ( $args[1] ?? '' );

		$options = [];
		parse_str( implode( "&", $args ), $options );

		// default service to youtube for compatibility with vlink
		$options['service'] = $options['service'] ?? "youtube";

		// force to youtubevidelink or youtube if video list is provided
		if ( count( $ids ) > 0 ) {
			if ( $options['service'] != 'youtube' && $options['service'] != 'youtubevideolist' ) {
				$options['notice'] = "The video list feature only works with the youtube service.
				 					Your service is being overridden.";
			}
			$options['service'] = count( $ids ) > 0 && $id === false ? "youtubevideolist" : "youtube";
		}

		$options = array_merge( self::$validArguments, $options );

		// fix for youtube ids that VideoLink would have handled.
		if ( $options['service'] == 'youtube' && strpos( $options['id'], ';' ) !== false ) {
			// transform input like Oh8KRy2WV0o;C5rePhJktn0 into Oh8KRy2WV0o
			$options['notice'] = "Use of semicolon delimited video lists is deprecated.
								Only the first video in this list will play.";
			$options['id'] = strstr( $options['id'], ';', true );
		}

		// force start time on youtube videos from "start".
		if ( $options['service'] == 'youtube' && isset( $options['start'] )
			&& preg_match( '/^([0-9]+:){0,2}[0-9]+(?:\.[0-9]+)?$/', $options['start'] ) ) {
			$te = explode( ':', $options['start'] );
			$tc = count( $te );
			for ( $i = 1, $startTime = floatval( $te[0] ); $i < $tc; $i++ ) {
				$startTime = $startTime * 60 + floatval( $te[$i] );
			}

			if ( empty( $options['urlargs'] ) ) {
				// just set the url args to the start time string
				$options['urlargs'] = "start={$startTime}";
			} else {
				// break down the url args and inject the start time in.
				$urlargs = [];
				parse_str( $options['urlargs'], $urlargs );
				$urlargs['start'] = $startTime;
				$options['urlargs'] = http_build_query( $urlargs );
			}
		}

		// handle adding playlist for video links for a play all link
		if ( $options['service'] == 'youtubevideolist' && count( $ids ) > 0 ) {
			$playlist = implode( ',', $ids );
			if ( empty( $options['urlargs'] ) ) {
				// just set the url args to the playlist
				$options['urlargs'] = "playlist={$playlist}";
			} else {
				// break down the url args and inject the playlist.
				$urlargs = [];
				parse_str( $options['urlargs'], $urlargs );
				$urlargs['playlist'] = $playlist;
				$options['urlargs'] = http_build_query( $urlargs );
			}
		}

		if ( $options['linktitle'] == "" ) {
			$options['linktitle'] = wfMessage( 'ev_default_play_desc' )->text();
		}

		$json = json_encode( $options );

		$link = Xml::element( 'a', [
			'href' => '#',
			'data-video-json' => $json,
			'class' => 'embedvideo-evl vplink'
		], $options['linktitle'] );

		$parser->getOutput()->addModules( [ 'ext.embedVideo-evl', 'ext.embedVideo.styles' ] );

		return [ $link, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Parse EVLPlayer (and vplayer) Tags
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return array
	 */
	public static function parseEVLPlayer( $input, array $args, Parser $parser, PPFrame $frame ): array {
		$args = array_merge( self::$validArguments, $args );

		$pid = $args['id'] ?? 'default';
		$w = min( 2000, max( 240, isset( $args['w'] ) ? (int)$args['w'] : 800 ) );
		$h = min( 1200, max( 80, isset( $args['h'] ) ? (int)$args['h'] : ( 9 * $w / 16 ) ) );
		$style = isset( $args['style'] ) ? ' ' . $args['style'] : '';
		$class = isset( $args['class'] ) ? ' ' . $args['class'] : '';

		if ( $args['defaultid'] && $args['service'] ) {
			// so we don't have to deal with any screwy parsing of tags by the HTML class.
			$input = "DEFAULT PLAYER REPLACEMENT";
		}

		// Parse internal content
		$content = $parser->recursiveTagParse( $input, $frame );

		$div = Html::rawElement( 'div', [
			'id' => 'vplayerbox-' . $pid,
			'class' => 'embedvideo-evlbox vplayerbox' . $class,
			'data-size' => $w . 'x' . $h,
			'style' => 'display:flex;' . $style,
		], $content );

		if ( $args['defaultid'] && $args['service'] ) {
			$new = self::parseEV(
				$parser,
				$args['service'],
				$args['defaultid'],
				"{$w}x{$h}",
				$args['alignment'],
				$args['description'],
				$args['container'],
				$args['urlargs'],
				$args['autoresize'],
				$args['valignment']
			)[0];

			// replace the default content with the new content
			$div = str_replace( $content, $new, $div );
		}

		return [ $div, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Embeds a video based on the URL
	 *
	 * @param Parser $parser
	 * @param string|null $url
	 * @return array Error Message
	 */
	public static function parseEVU( $parser, ?string $url = null ): array {
		if ( !$url ) {
			return self::error( 'missingparams', $url );
		}
		$host = parse_url( $url, PHP_URL_HOST );
		$host = strtolower( $host );
		$host = str_ireplace( 'www.', '', $host ); // strip www from any hostname.

		$map = VideoService::getServiceHostMap();

		$service = false;

		if ( isset( $map[$host] ) ) {
			if ( !is_array( $map[$host] ) ) {
				// only one possible answer. Set it.
				$service = $map[$host];
			} else {
				// map by array.
				foreach ( $map[$host] as $possibleService ) {
					$evs = VideoService::newFromName( $possibleService );
					if ( $evs ) {
						$test = $evs->parseVideoID( $url );

						if ( $test !== false && $test !== $url ) {
							// successful parse - safe assumption that this is correct.
							$service = $possibleService;
							break;
						}
					}
				}
			}
		} else {
			return self::error( 'cantdecode_evu', $url );
		}

		if ( !$service ) {
			return self::error( 'cantdecode_evu', $url );
		}

		$arguments = func_get_args();
		array_shift( $arguments );

		$args = [];
		foreach ( $arguments as $argumentPair ) {
			$argumentPair = trim( $argumentPair );
			if ( !strpos( $argumentPair, '=' ) ) {
				continue;
			}

			[ $key, $value ] = explode( '=', $argumentPair, 2 );

			if ( !array_key_exists( $key, self::$validArguments ) ) {
				continue;
			}
			$args[$key] = $value;
		}

		$args = array_merge( self::$validArguments, $args );

		return self::parseEV(
			$parser,
			$service,
			$url,
			$args['dimensions'],
			$args['alignment'],
			$args['description'],
			$args['container'],
			$args['urlargs'],
			$args['autoresize'],
			$args['valignment']
		);
	}

	/**
	 * Adapter to call the new style tag.
	 *
	 * @param object $parser Parser
	 * @return array Error Message
	 */
	public static function parseEVP( $parser ): array {
		wfDeprecated( __METHOD__, '2.0', 'EmbedVideo' );
		return self::error( 'evp_deprecated' );
	}

	/**
	 * Adapter to call the EV parser tag with template like calls.
	 *
	 * @param object $parser Parser
	 * @return array Error Message
	 */
	public static function parseEVT( $parser ): array {
		$arguments = func_get_args();
		array_shift( $arguments );

		$args = [];
		foreach ( $arguments as $argumentPair ) {
			$argumentPair = trim( $argumentPair );
			if ( !strpos( $argumentPair, '=' ) ) {
				continue;
			}

			[ $key, $value ] = explode( '=', $argumentPair, 2 );

			if ( !array_key_exists( $key, self::$validArguments ) ) {
				continue;
			}
			$args[$key] = $value;
		}

		$args = array_merge( self::$validArguments, $args );

		return self::parseEV(
			$parser,
			$args['service'],
			$args['id'],
			$args['dimensions'],
			$args['alignment'],
			$args['description'],
			$args['container'],
			$args['urlargs'],
			$args['autoresize'],
			$args['valignment']
		);
	}

	/**
	 * Adapter to call the parser hook.
	 *
	 * @param string $input Raw User Input
	 * @param array $args Arguments on the tag.
	 * @param Parser $parser Parser object.
	 * @param PPFrame $frame PPFrame object.
	 * @return array Error Message
	 */
	public static function parseEVTag( $input, array $args, Parser $parser, PPFrame $frame ): array {
		$args = array_merge( self::$validArguments, $args );

		return self::parseEV(
			$parser,
			$args['service'],
			$input,
			$args['dimensions'],
			$args['alignment'],
			$args['description'],
			$args['container'],
			$args['urlargs'],
			$args['autoresize'],
			$args['valignment']
		);
	}

	/**
	 * Embeds a video of the chosen service.
	 *
	 * @param Parser $parser Parser
	 * @param string|null $service [Optional] Which online service has the video.
	 * @param string|null $id [Optional] Identifier Code or URL for the video on the service.
	 * @param string|null $dimensions [Optional] Dimensions of video
	 * @param string|null $alignment [Optional] Horizontal Alignment of the embed container.
	 * @param string|null $description [Optional] Description to show
	 * @param string|null $container [Optional] Container to use.(Frame is currently the only option.)
	 * @param string|null $urlArgs [Optional] Extra URL Arguments
	 * @param string|null $autoResize [Optional] Automatically Resize video that will break its parent container.
	 * @param string|null $vAlignment [Optional] Vertical Alignment of the embed container.
	 * @return array Encoded representation of input params (to be processed later)
	 */
	public static function parseEV( $parser, ?string $service = null, ?string $id = null, ?string $dimensions = null,
								   ?string $alignment = null, ?string $description = null, ?string $container = null,
								   ?string $urlArgs = null, ?string $autoResize = null, ?string $vAlignment = null ): array {
		self::resetParameters();
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$wgEmbedVideoDisabledServices = $config->get( 'EmbedVideoDisabledServices' );

		$service		= trim( $service ?? '' );
		$id				= trim( $id ?? '' );
		$alignment		= trim( $alignment ?? '' );
		$description	= trim( $description ?? '' );
		$dimensions		= trim( $dimensions ?? '' );
		$urlArgs		= trim( $urlArgs ?? '' );
		$width			= null;
		$height			= null;
		$autoResize		= !( ( isset( $autoResize ) && strtolower( trim( $autoResize ) ) == "false" ) );
		$vAlignment		= trim( $vAlignment ?? '' );

		// I am not using $parser->parseWidthParam() since it can not handle height only.  Example: x100
		if ( stristr( $dimensions, 'x' ) ) {
			$dimensions = strtolower( $dimensions );
			[ $width, $height ] = explode( 'x', $dimensions );
		} elseif ( is_numeric( $dimensions ) ) {
			$width = $dimensions;
		}

		/************************************/
		/* Error Checking                   */
		/************************************/
		if ( !$service || !$id ) {
			return self::error( 'missingparams', $service, $id );
		}

		if ( $wgEmbedVideoDisabledServices && in_array( $service, $wgEmbedVideoDisabledServices ) ) {
			return self::error( 'service_disabled', $service );
		}

		self::$service = VideoService::newFromName( $service );
		if ( !self::$service ) {
			return self::error( 'service', $service );
		}

		// Let the service automatically handle bad dimensional values.
		self::$service->setWidth( $width );

		self::$service->setHeight( $height );

		// If the service has an ID pattern specified, verify the id number.
		if ( !self::$service->setVideoID( $id ) ) {
			return self::error( 'id', $service, $id );
		}

		if ( !self::$service->setUrlArgs( $urlArgs ) ) {
			return self::error( 'urlargs', $service, $urlArgs );
		}

		if ( $parser !== null ) {
			self::setDescription( $description, $parser );
		} else {
			self::setDescriptionNoParse( $description );
		}

		if ( !self::setContainer( $container ) ) {
			  return self::error( 'container', $container );
		}

		if ( !self::setAlignment( $alignment ) ) {
			  return self::error( 'alignment', $alignment );
		}

		if ( !self::setVerticalAlignment( $vAlignment ) ) {
			  return self::error( 'valignment', $vAlignment );
		}

		/************************************/
		/* HMTL Generation                  */
		/************************************/
		$html = self::$service->getHtml();
		if ( !$html ) {
			  return self::error( 'unknown', $service );
		}

		if ( $autoResize ) {
			  $html = self::generateWrapperHTML( $html, null, "autoResize" );
		} else {
			  $html = self::generateWrapperHTML( $html );
		}

		if ( $parser ) {
			  // don't call this if parser is null (such as in API usage).
			  $out = $parser->getOutput();
			  $out->addModules( [ 'ext.embedVideo' ] );
			  $out->addModuleStyles( [ 'ext.embedVideo.styles' ] );
		}

		return [
			$html,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/**
	 * Generate the HTML necessary to embed the video with the given alignment
	 * and text description
	 *
	 * @private
	 * @param string|null $html [Optional] Horizontal Alignment
	 * @param string|null $description [Optional] Description
	 * @param string|null $addClass [Optional] Additional Classes to add to the wrapper
	 * @return string
	 */
	private static function generateWrapperHTML( $html, ?string $description = null, ?string $addClass = null ): string {
		$classString = "embedvideo";
		$styleString = "";
		$innerClassString = "embedvideowrap";
		$outerClassString = "embedvideo ";

		if ( self::getContainer() == 'frame' ) {
			$classString .= " thumbinner";
		}

		if ( self::getAlignment() !== false ) {
			$outerClassString .= " ev_" . self::getAlignment() . " ";
			$styleString .= " width: " . ( self::$service->getWidth() + 6 ) . "px;";
		}

		if ( self::getVerticalAlignment() !== false ) {
			$outerClassString .= " ev_" . self::getVerticalAlignment() . " ";
		}

		if ( $addClass ) {
			$classString .= " " . $addClass;
			$outerClassString .= $addClass;
		}

		$html = "<div class='thumb $outerClassString' style='width: " . ( self::$service->getWidth() + 8 ) . "px;'>
			<div class='" . $classString . "' style='" . $styleString . "'>
				<div class='" . $innerClassString . "' style='width: " . self::$service->getWidth() . "px;'>
					{$html}
				</div>
				" . ( self::getDescription() !== false
				? "<div class='thumbcaption'>" . self::getDescription() . "</div>"
				: null ) . "
			</div>
		</div>";

		return $html;
	}

	/**
	 * Return the alignment parameter.
	 *
	 * @return bool|string Alignment or false for not set.
	 */
	private static function getAlignment(): bool|string {
		return self::$alignment;
	}

	/**
	 * Set the align parameter.
	 *
	 * @param string $alignment Parameter
	 * @return bool Valid
	 */
	private static function setAlignment( string $alignment ): bool {
		if ( !empty( $alignment )
			&& ( $alignment == 'left' || $alignment == 'right' || $alignment == 'center' || $alignment == 'inline' ) ) {
			self::$alignment = $alignment;
		} elseif ( !empty( $alignment ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Return the valignment parameter.
	 *
	 * @return string|false Vertical Alignment or false for not set.
	 */
	private static function getVerticalAlignment(): string|false {
		return self::$vAlignment;
	}

	/**
	 * Set the align parameter.
	 *
	 * @param string $vAlignment Alignment Parameter
	 * @return bool Valid
	 */
	private static function setVerticalAlignment( string $vAlignment ): bool {
		if ( !empty( $vAlignment )
			&& ( $vAlignment == 'top' || $vAlignment == 'middle' || $vAlignment == 'bottom' || $vAlignment == 'baseline' ) ) {
			if ( $vAlignment != 'baseline' ) {
				self::$alignment = 'inline';
			}
			self::$vAlignment = $vAlignment;
		} elseif ( !empty( $vAlignment ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Return description text.
	 *
	 * @return string|false String description or false for not set.
	 */
	private static function getDescription(): string|false {
		return self::$description;
	}

	/**
	 * Set the description.
	 *
	 * @param string $description Description
	 * @param Parser $parser Mediawiki Parser object
	 * @return void
	 */
	private static function setDescription( string $description, Parser $parser ): void {
		self::$description = ( !$description ? false : $parser->recursiveTagParse( $description ) );
	}

	/**
	 * Set the description without using the parser
	 *
	 * @param string $description
	 * @return void
	 */
	private static function setDescriptionNoParse( string $description ): void {
		self::$description = ( !$description ? false : $description );
	}

	/**
	 * Return container type.
	 *
	 * @return string|false String container type or false for not set.
	 */
	private static function getContainer(): string|false {
		return self::$container;
	}

	/**
	 * Set the container type.
	 *
	 * @param string|null $container
	 * @return bool Success
	 */
	private static function setContainer( ?string $container ): bool {
		if ( !empty( $container ) && ( $container == 'frame' ) ) {
			self::$container = $container;
		} elseif ( !empty( $container ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Reset parameters between parses.
	 *
	 * @return void
	 */
	private static function resetParameters(): void {
		self::$description	= false;
		self::$alignment	= false;
		self::$container	= false;
	}

	/**
	 * Error Handler
	 *
	 * @param string $type [Optional] Error Type
	 * @param mixed ...$arguments [...] Multiple arguments to be retrieved with func_get_args().
	 * @return array Printable Error Message
	 */
	private static function error( string $type = 'unknown', mixed ...$arguments ): array {
		$message = wfMessage( 'error_embedvideo_' . $type, ...$arguments )->escaped();

		return [
			"<div class='errorbox'>{$message}</div>",
			'noparse' => true,
			'isHTML' => true
		];
	}
}
