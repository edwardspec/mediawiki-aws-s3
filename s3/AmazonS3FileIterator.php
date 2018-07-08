<?php

/**
 * Implements the AWS extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\NoSuchBucketException;

/**
 * Iterator to list existing Amazon S3 objects with certain prefix.
 */
class AmazonS3FileIterator implements Iterator {
	private $client, $container, $dir, $topOnly, $limit;
	private $index, $marker, $finished, $suffixStart;

	/** @var array Filenames obtained by the last listObjects() call */
	private $filenamesArray = [];

	public function __construct( S3Client $client, $container, $dir, array $params, $limit = 500 ) {
		/* "Directory" must end with the slash,
			otherwise S3 will return PRE (prefix) suggestion
			instead of the listing itself */
		if ( $dir != '' && substr( $dir, -1, 1 ) != '/' ) {
			$dir .= '/';
		}

		$this->suffixStart = strlen( $dir ); // size of "path/to/dir/"

		$this->client = $client;
		$this->container = $container;
		$this->dir = $dir;
		$this->limit = $limit;
		$this->topOnly = !empty( $params['topOnly'] );

		$this->rewind();
	}

	public function key() {
		$this->init();
		return $this->index;
	}

	public function current() {
		$this->init();
		return $this->filenamesArray[$this->index];
	}

	public function next() {
		$this->index ++;
	}

	public function rewind() {
		$this->filenamesArray = [];
		$this->marker = null;
		$this->index = 0;
		$this->finished = false;
	}

	public function valid() {
		$this->init();
		return !$this->finished || $this->index < count( $this->filenamesArray );
	}

	/**
		@brief If needed, load more filenames from listObjects API.
	*/
	private function init() {
		if ( $this->finished || $this->index < count( $this->filenamesArray ) ) {
			// Either there aren't any objects left
			// or we still have enough objects in filenamesArray.
			return;
		}

		try {
			$apiResponse = $this->client->listObjects( [
				'Bucket' => $this->container,
				'Delimiter' => $this->topOnly ? '/' : '',
				'Marker' => $this->marker,
				'MaxKeys' => $this->limit,
				'Prefix' => $this->dir
			] );
		} catch ( NoSuchBucketException $e ) {
		}

		$this->filenamesArray = $this->extractNamesFromResponse( $apiResponse, $this->topOnly );
		$this->index = 0;
		$this->marker = $this->filenamesArray ? $apiResponse['Marker'] : null;
		$this->finished = $this->filenamesArray ? true : !$apiResponse['IsTruncated'];
	}

	/**
		 @brief Delete first $suffixStart symbols from $path.
		 @return Filename (what remains of $path).
	 */
	protected function stripDirectorySuffix( $path ) {
		return substr( $path, $this->suffixStart );
	}

	/**
	 * @brief Extract filenames (but not directories) from $apiResponse.
	 * @param array $apiResponse Return value of successful listObjects() API call.
	 * @param bool $topOnly
	 * @return Array of strings (filenames).
	 */
	protected function extractNamesFromResponse( $apiResponse, $topOnly ) {
		if ( !isset( $apiResponse['Contents'] ) ) {
			return [];
		}

		return array_map( function ( $contentObj ) {
			return $this->stripDirectorySuffix( $contentObj['Key'] );
		}, $apiResponse['Contents'] );
	}
}
