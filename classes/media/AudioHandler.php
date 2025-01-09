<?php
/**
 * EmbedVideo
 * AudioHandler Class
 *
 * @author  Alexia E. Smith
 * @license MIT
 * @package EmbedVideo
 * @link    https://www.mediawiki.org/wiki/Extension:EmbedVideo
 */

namespace EmbedVideo;

use File;
use FSFile;
use MediaHandler;

class AudioHandler extends MediaHandler {
	/**
	 * Get an associative array mapping magic word IDs to parameter names.
	 * Will be used by the parser to identify parameters.
	 */
	public function getParamMap(): array {
		return [
			'img_width'	=> 'width',
			'ev_start'	=> 'start',
			'ev_end'	=> 'end'
		];
	}

	/**
	 * Validate a thumbnail parameter at parse time.
	 * Return true to accept the parameter, and false to reject it.
	 * If you return false, the parser will do something quiet and forgiving.
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function validateParam( $name, $value ): bool {
		if ( $name === 'width' ) {
			return $value > 0;
		}

		if ( $name === 'start' || $name === 'end' ) {
			if ( $this->parseTimeString( $value ) === false ) {
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * Parse a time string into seconds.
	 * strtotime() will not handle this nicely since 1:30 could be one minute and thirty seconds OR one hour and thirty minutes.
	 *
	 * @param string $time Time formatted as one of: ss, :ss, mm:ss, hh:mm:ss, or dd:hh:mm:ss
	 * @return float|int|false Integer seconds or false for a bad format.
	 */
	public function parseTimeString( $time ): float|int|false {
		$parts = explode( ":", $time );
		if ( $parts === false ) {
			return false;
		}
		$parts = array_reverse( $parts );

		$magnitude = [ 1, 60, 3600, 86400 ];
		$seconds = 0;
		foreach ( $parts as $index => $part ) {
			$seconds += (float)$part * $magnitude[$index];
		}
		return $seconds;
	}

	/**
	 * Merge a parameter array into a string appropriate for inclusion in filenames
	 *
	 * @param array $params Array of parameters that have been through normaliseParams.
	 * @return string
	 */
	public function makeParamString( $params ): string {
		return ''; // Width does not matter to video or audio.
	}

	/**
	 * Parse a param string made with makeParamString back into an array
	 *
	 * @param string $str The parameter string without file name (e.g. 122px)
	 * @return array|false Array of parameters or false on failure.
	 */
	public function parseParamString( $str ): array|false {
		return []; // Nothing to parse.  See makeParamString above.
	}

	/**
	 * Changes the parameter array as necessary, ready for transformation.
	 * Should be idempotent.
	 * Returns false if the parameters are unacceptable and the transform should fail
	 *
	 * @param object $file
	 * @param array &$parameters
	 * @return bool Success
	 */
	public function normaliseParams( $file, &$parameters ) {
		global $wgEmbedVideoDefaultWidth;

		if ( isset( $parameters['width'] ) && $parameters['width'] > 0 ) {
			$parameters['width'] = intval( $parameters['width'] );
		} else {
			$parameters['width'] = $wgEmbedVideoDefaultWidth;
		}

		if ( isset( $parameters['start'] ) ) {
			$parameters['start'] = $this->parseTimeString( $parameters['start'] );
			if ( $parameters['start'] === false ) {
				unset( $parameters['start'] );
			}
		}

		if ( isset( $parameters['end'] ) ) {
			$parameters['end'] = $this->parseTimeString( $parameters['end'] );
			if ( $parameters['end'] === false ) {
				unset( $parameters['end'] );
			}
		}

		$parameters['page'] = 1;

		return true;
	}

	/**
	 * Get an image size array like that returned by getimagesize(), or false if it
	 * can't be determined.
	 *
	 * This function is used for determining the width, height and bitdepth directly
	 * from an image. The results are stored in the database in the img_width,
	 * img_height, img_bits fields.
	 *
	 * @note If this is a multipage file, return the width and height of the
	 *  first page.
	 *
	 * @param File $file The file object, or false if there isn't one
	 * @param string $path The filename
	 * @return array|false An array following the format of PHP getimagesize() internal function or false if not supported.
	 */
	public function getImageSize( $file, $path ): array|false {
		return false;
	}

	/**
	 * Get a MediaTransformOutput object representing the transformed output. Does the
	 * transform unless $flags contains self::TRANSFORM_LATER.
	 *
	 * @param File $file The file object
	 * @param string $dstPath Filesystem destination path
	 * @param string $dstUrl Destination URL to use in output HTML
	 * @param array $params Arbitrary set of parameters validated by $this->validateParam()
	 *                          Note: These parameters have *not* gone through
	 *                          $this->normaliseParams()
	 * @param int $flags A bitfield, may contain self::TRANSFORM_LATER
	 * @return VideoTransformOutput|AudioTransformOutput All media transform outputs as it might be overridden
	 */
	public function doTransform( $file, $dstPath, $dstUrl, $params, $flags = 0 ): VideoTransformOutput|AudioTransformOutput {
		$this->normaliseParams( $file, $params );

		return new AudioTransformOutput( $file, $params );
	}

	/**
	 * Shown in file history box on image description page.
	 *
	 * @param File $file
	 * @return string Dimensions
	 */
	public function getDimensionsString( $file ): string {
		global $wgLang;

		[
			'stream' => $stream,
			'format' => $format,
		] = $this->getFFProbeResult( $file, "a:0" );

		if ( $format === false || $stream === false ) {
			return parent::getDimensionsString( $file );
		}

		return wfMessage( 'ev_audio_short_desc', $wgLang->formatTimePeriod( $format->getDuration() ) )->text();
	}

	/**
	 * Short description. Shown on Special:Search results.
	 *
	 * @param File $file
	 * @return string
	 */
	public function getShortDesc( $file ): string {
		global $wgLang;

		[
			'stream' => $stream,
			'format' => $format,
		] = $this->getFFProbeResult( $file, "a:0" );

		if ( $format === false || $stream === false ) {
			return parent::getGeneralShortDesc( $file );
		}

		return wfMessage(
			'ev_audio_short_desc',
			$wgLang->formatTimePeriod( $format->getDuration() ),
			$wgLang->formatSize( $file->getSize() )
		)->text();
	}

	/**
	 * Long description. Shown under image on image description page surounded by ().
	 *
	 * @param File $file
	 * @return string
	 */
	public function getLongDesc( $file ): string {
		global $wgLang;

		[
			'stream' => $stream,
			'format' => $format,
		] = $this->getFFProbeResult( $file, "a:0" );

		if ( $format === false || $stream === false ) {
			return parent::getGeneralLongDesc( $file );
		}

		$extension = pathinfo( $file->getLocalRefPath(), PATHINFO_EXTENSION );

		return wfMessage(
			'ev_audio_long_desc',
			strtoupper( $extension ),
			$stream->getCodecName(),
			$wgLang->formatTimePeriod( $format->getDuration() ),
			$wgLang->formatBitrate( $format->getBitRate() )
		)->text();
	}

	/**
	 * Runs FFProbe and caches results in the Main WAN Object cache
	 *
	 * @param string|FSFile|File|bool $file The file to work on
	 * @param string $select Video / Audio track to select
	 * @return array
	 */
	protected function getFFProbeResult( $file, string $select = 'v:0' ): array {
		$path = $file;

		if ( $file === false ) {
			return [
				'stream' => false,
				'format' => false,
			];
		}

		if ( $file instanceof File || $file instanceof FSFile ) {
			$path = $file->getPath();
		}

		$probe = new FFProbe( $path, $file );

		return [
			'stream' => $probe->getStream( $select ),
			'format' => $probe->getFormat()
		];
	}
}
