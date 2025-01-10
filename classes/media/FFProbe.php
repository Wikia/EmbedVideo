<?php
/**
 * EmbedVideo
 * FFProbe
 *
 * @author  Alexia E. Smith
 * @license MIT
 * @package EmbedVideo
 * @link    https://www.mediawiki.org/wiki/Extension:EmbedVideo
 */

namespace EmbedVideo;

use File;
use FSFile;
use MediaWiki\MediaWikiServices;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

class FFProbe {
	/**
	 * Meta Data Cache
	 *
	 * @var array
	 */
	private $metadata = null;

	/**
	 * Main Constructor
	 *
	 * @param string $filename
	 * @param File | FSFile | string $file
	 * @return void
	 */
	public function __construct(
	 private $filename,
	 /**
	  * MediaWiki File
	  */
	 private $file
	) {
	}

	/**
	 * Get a selected stream.  Follows ffmpeg's stream selection style.
	 *
	 * @param  string	Stream identifier
	 * Examples:
	 *		"v:0" - Select the first video stream
	 * 		"a:1" - Second audio stream
	 * 		"i:0" - First stream, whatever it is.
	 * 		"s:2" - Third subtitle
	 * 		"d:0" - First generic data stream
	 * 		"t:1" - Second attachment
	 * @return mixed StreamInfo object or false if does not exist.
	 */
	public function getStream( $select ): false|\EmbedVideo\StreamInfo {
		$this->loadMetaData( $select );

		$types = [
			'v'	=> 'video',
			'a'	=> 'audio',
			'i'	=> false,
			's'	=> 'subtitle',
			'd'	=> 'data',
			't'	=> 'attachment'
		];

		if ( !isset( $this->metadata['streams'] ) ) {
			return false;
		}

		[ $type, $index ] = explode( ":", $select );
		$index = intval( $index );

		$type = ( $types[$type] ?? false );

		$i = 0;
		foreach ( $this->metadata['streams'] as $stream ) {
			if ( $type !== false && isset( $stream['codec_type'] ) ) {
				if ( $index === $i && $stream['codec_type'] === $type ) {
					return new StreamInfo( $stream );
				}
			}
			if ( $type === false || $stream['codec_type'] === $type ) {
				$i++;
			}
		}
		return false;
	}

	/**
	 * Get the FormatInfo object.
	 *
	 * @return false|FormatInfo FormatInfo object or false if does not exist.
	 */
	public function getFormat(): false|FormatInfo {
		$this->loadMetaData();

		if ( !isset( $this->metadata['format'] ) ) {
			return false;
		}

		return new FormatInfo( $this->metadata['format'] );
	}

	private function getFilePath() {
		if ( $this->file instanceof FSFile ) {
			return $this->file->getPath();
		}

		return $this->file->getLocalRefPath();
	}

	/**
	 * Invoke ffprobe on the command line.
	 *
	 * @return array Meta Data
	 */
	private function invokeFFProbe(): array {
		global $wgFFprobeLocation;

		if ( !file_exists( $wgFFprobeLocation ) ) {
			return [];
		}

		$json = shell_exec(
			escapeshellcmd(
				$wgFFprobeLocation . ' -v quiet -print_format json -show_format -show_streams '
			) . escapeshellarg( $this->getFilePath() )
		);

		$metadata = @json_decode( $json, true );

		if ( is_array( $metadata ) ) {
			return $metadata;
		}

		return [];
	}

	public function loadMetaData( string $select = 'v:0' ): bool {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $cache->makeGlobalKey( 'EmbedVideo', 'ffprobe', $this->filename, $select );
		$ttl = ( $this->file instanceof File || is_string( $this->file ) )
			? ExpirationAwareness::TTL_INDEFINITE : ExpirationAwareness::TTL_MINUTE;

		$result = $cache->getWithSetCallback(
			$cacheKey,
			$ttl,
			function ( $old, &$ttl ) {
				$result = $this->invokeFFProbe();

				if ( $result === null ) {
					$ttl = ExpirationAwareness::TTL_UNCACHEABLE;

					return $old;
				}

				return $result;
			}
		);

		if ( is_array( $result ) ) {
			$this->metadata = [
				'streams' => $result['streams'] ?? null,
				'format' => $result['format'] ?? null,
			];

			return true;
		}

		return false;
	}
}

class StreamInfo {
	/**
	 * Main Constructor
	 *
	 * @param array $info Stream Info from FFProbe
	 * @return void
	 */
	public function __construct(
	 /**
	  * Stream Info
	  */
	 private $info
	) {
	}

	/**
	 * Simple helper instead of repeating an if statement everything.
	 *
	 * @param string $field Name
	 * @return mixed
	 */
	private function getField( string $field ): mixed {
		return ( $this->info[$field] ?? false );
	}

	/**
	 * Return the codec type.
	 *
	 * @return string|false Codec type or false if unavailable.
	 */
	public function getType(): string|false {
		return $this->getField( 'codec_type' );
	}

	/**
	 * Return the codec name.
	 *
	 * @return string|false Codec name or false if unavailable.
	 */
	public function getCodecName(): string|false {
		return $this->getField( 'codec_name' );
	}

	/**
	 * Return the codec long name.
	 *
	 * @return string|false Codec long name or false if unavailable.
	 */
	public function getCodecLongName(): string|false {
		return $this->getField( 'codec_long_name' );
	}

	/**
	 * Return the width of the stream.
	 *
	 * @return int|false Width or false if unavailable.
	 */
	public function getWidth(): int|false {
		return $this->getField( 'width' );
	}

	/**
	 * Return the height of the stream.
	 *
	 * @return int|false Height or false if unavailable.
	 */
	public function getHeight(): int|false {
		return $this->getField( 'height' );
	}

	/**
	 * Return bit depth for a video or thumbnail.
	 *
	 * @return int|false Bit Depth or false if unavailable.
	 */
	public function getBitDepth(): int|false {
		return $this->getField( 'bits_per_raw_sample' );
	}

	/**
	 * Get the duration in seconds.
	 *
	 * @return mixed Duration in seconds or false if unavailable.
	 */
	public function getDuration(): mixed {
		return $this->getField( 'duration' );
	}

	/**
	 * Bit rate in bPS.
	 *
	 * @return mixed Bite rate in bPS or false if unavailable.
	 */
	public function getBitRate(): mixed {
		return $this->getField( 'bit_rate' );
	}
}

class FormatInfo {
	/**
	 * Main Constructor
	 *
	 * @param array $info Format Info from FFProbe
	 * @return void
	 */
	public function __construct(
	 /**
	  * Format Info
	  */
	 private $info
	) {
	}

	/**
	 * Simple helper instead of repeating an if statement everything.
	 *
	 * @private
	 * @param string $field Field Name
	 * @return mixed
	 */
	private function getField( string $field ): mixed {
		return ( $this->info[$field] ?? false );
	}

	/**
	 * Get the file path.
	 *
	 * @return mixed File path or false if unavailable.
	 */
	public function getFilePath(): mixed {
		return $this->getField( 'filename' );
	}

	/**
	 * Get the duration in seconds.
	 *
	 * @return mixed Duration in seconds or false if unavailable.
	 */
	public function getDuration(): mixed {
		return $this->getField( 'duration' );
	}

	/**
	 * Bit rate in bPS.
	 *
	 * @return mixed Bite rate in bPS or false if unavailable.
	 */
	public function getBitRate(): mixed {
		return $this->getField( 'bit_rate' );
	}
}
