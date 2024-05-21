<?php
/**
 * EmbedVideo
 * FFProbe
 *
 * @author  Alexia E. Smith
 * @license MIT
 * @package EmbedVideo
 * @link    https://www.mediawiki.org/wiki/Extension:EmbedVideo
 **/

namespace EmbedVideo;

use FSFile;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use MediaWiki\MediaWikiServices;

class FFProbe {
	/**
	 * MediaWiki File
	 *
	 * @var \File | \FSFile | string
	 */
	private $file;

	/**
	 * @var string
	 */
	private $filename;

	/**
	 * Meta Data Cache
	 *
	 * @var array
	 */
	private $metadata = null;

	/**
	 * Main Constructor
	 *
	 * @access public
	 * @param  string $filename
	 * @param  \File | \FSFile | string $file
	 * @return void
	 */
	public function __construct($filename, $file) {
		$this->filename = $filename;
		$this->file = $file;
	}

	/**
	 * Get a selected stream.  Follows ffmpeg's stream selection style.
	 *
	 * @access public
	 * @param  string	Stream identifier
	 * Examples:
	 *		"v:0" - Select the first video stream
	 * 		"a:1" - Second audio stream
	 * 		"i:0" - First stream, whatever it is.
	 * 		"s:2" - Third subtitle
	 * 		"d:0" - First generic data stream
	 * 		"t:1" - Second attachment
	 * @return mixed	StreamInfo object or false if does not exist.
	 */
	public function getStream($select) {
		$this->loadMetaData($select);

		$types = [
			'v'	=> 'video',
			'a'	=> 'audio',
			'i'	=> false,
			's'	=> 'subtitle',
			'd'	=> 'data',
			't'	=> 'attachment'
		];

		if (!isset($this->metadata['streams'])) {
			return false;
		}

		list($type, $index) = explode(":", $select);
		$index = intval($index);

		$type = (isset($types[$type]) ? $types[$type] : false);

		$i = 0;
		foreach ($this->metadata['streams'] as $stream) {
			if ($type !== false && isset($stream['codec_type'])) {
				if ($index === $i && $stream['codec_type'] === $type) {
					return new StreamInfo($stream);
				}
			}
			if ($type === false || $stream['codec_type'] === $type) {
				$i++;
			}
		}
		return false;
	}

	/**
	 * Get the FormatInfo object.
	 *
	 * @access public
	 * @return mixed	FormatInfo object or false if does not exist.
	 */
	public function getFormat() {
		$this->loadMetaData();

		if (!isset($this->metadata['format'])) {
			return false;
		}

		return new FormatInfo($this->metadata['format']);
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
	 * @access private
	 * @return array	Meta Data
	 */
	private function invokeFFProbe() {
		global $wgFFprobeLocation;

		if (!file_exists($wgFFprobeLocation)) {
			return [];
		}

		$json = shell_exec(escapeshellcmd($wgFFprobeLocation . ' -v quiet -print_format json -show_format -show_streams ') . escapeshellarg($this->getFilePath()));

		$metadata = @json_decode($json, true);

		if (is_array($metadata)) {
			return $metadata;
		}

		return [];
	}

	public function loadMetaData( string $select = 'v:0' ): bool {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $cache->makeGlobalKey( 'EmbedVideo', 'ffprobe', $this->filename, $select );
		$ttl = ( $this->file instanceof \File || is_string( $this->file ) )
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
	 * Stream Info
	 *
	 * @var array
	 */
	private $info = null;

	/**
	 * Main Constructor
	 *
	 * @access public
	 * @param  array	Stream Info from FFProbe
	 * @return void
	 */
	public function __construct($info) {
		$this->info = $info;
	}

	/**
	 * Simple helper instead of repeating an if statement everything.
	 *
	 * @access private
	 * @param  string	Field Name
	 * @return mixed
	 */
	private function getField($field) {
		return (isset($this->info[$field]) ? $this->info[$field] : false);
	}

	/**
	 * Return the codec type.
	 *
	 * @access public
	 * @return string	Codec type or false if unavailable.
	 */
	public function getType() {
		return $this->getField('codec_type');
	}

	/**
	 * Return the codec name.
	 *
	 * @access public
	 * @return string	Codec name or false if unavailable.
	 */
	public function getCodecName() {
		return $this->getField('codec_name');
	}

	/**
	 * Return the codec long name.
	 *
	 * @access public
	 * @return string	Codec long name or false if unavailable.
	 */
	public function getCodecLongName() {
		return $this->getField('codec_long_name');
	}

	/**
	 * Return the width of the stream.
	 *
	 * @access public
	 * @return integer	Width or false if unavailable.
	 */
	public function getWidth() {
		return $this->getField('width');
	}

	/**
	 * Return the height of the stream.
	 *
	 * @access public
	 * @return integer	Height or false if unavailable.
	 */
	public function getHeight() {
		return $this->getField('height');
	}

	/**
	 * Return bit depth for a video or thumbnail.
	 *
	 * @access public
	 * @return integer	Bit Depth or false if unavailable.
	 */
	public function getBitDepth() {
		return $this->getField('bits_per_raw_sample');
	}

	/**
	 * Get the duration in seconds.
	 *
	 * @access public
	 * @return mixed	Duration in seconds or false if unavailable.
	 */
	public function getDuration() {
		return $this->getField('duration');
	}

	/**
	 * Bit rate in bPS.
	 *
	 * @access public
	 * @return mixed	Bite rate in bPS or false if unavailable.
	 */
	public function getBitRate() {
		return $this->getField('bit_rate');
	}
}

class FormatInfo {
	/**
	 * Format Info
	 *
	 * @var array
	 */
	private $info = null;

	/**
	 * Main Constructor
	 *
	 * @access public
	 * @param  array	Format Info from FFProbe
	 * @return void
	 */
	public function __construct($info) {
		$this->info = $info;
	}

	/**
	 * Simple helper instead of repeating an if statement everything.
	 *
	 * @access private
	 * @param  string	Field Name
	 * @return mixed
	 */
	private function getField($field) {
		return (isset($this->info[$field]) ? $this->info[$field] : false);
	}

	/**
	 * Get the file path.
	 *
	 * @access public
	 * @return mixed	File path or false if unavailable.
	 */
	public function getFilePath() {
		return $this->getField('filename');
	}

	/**
	 * Get the duration in seconds.
	 *
	 * @access public
	 * @return mixed	Duration in seconds or false if unavailable.
	 */
	public function getDuration() {
		return $this->getField('duration');
	}

	/**
	 * Bit rate in bPS.
	 *
	 * @access public
	 * @return mixed	Bite rate in bPS or false if unavailable.
	 */
	public function getBitRate() {
		return $this->getField('bit_rate');
	}
}
