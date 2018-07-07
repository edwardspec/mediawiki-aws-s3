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

/**
 * Iterator to list prefixes (pseudo-directories) of existing Amazon S3 objects.
 */
class AmazonS3DirectoryIterator extends AmazonS3FileIterator {
	private $seenDirectories = [];

	public function rewind() {
		$this->seenDirectories = [];
	}

	/**
	 * @brief Extract directory names (but not files) from $apiResponse.
	 * @param array $apiResponse Return value of successful listObjects() API call.
	 * @param bool $topOnly
	 * @return Array of strings (filenames).
	 */
	protected function extractNamesFromResponse( $apiResponse, $topOnly ) {
		if ( $topOnly ) {
			/* In $topOnly mode, Delimiter=/ was used,
				meaning that subdirectories will be shown as CommonPrefixes,
				not in $apiResponse['Contents']. */
			if ( !isset( $apiResponse['CommonPrefixes'] ) ) {
				return [];
			}

			return array_map( function ( $prefixObj ) {
				$prefix = $this->stripDirectorySuffix( $prefixObj['Prefix'] );

				/* Strip "/" which is always in the end of CommonPrefixes */
				return preg_replace( '/\/$/', '', $prefix );
			}, $apiResponse['CommonPrefixes'] );
		}

		/* No Delimiter was used, so we only have $apiResponse['Contents']
			with the list of all files.
			We need to compile the list of directories ourselves. */
		if ( !isset( $apiResponse['Contents'] ) ) {
			return [];
		}

		$list = [];
		foreach ( $apiResponse['Contents'] as $contentObj ) {
			$dirname = dirname( $this->stripDirectorySuffix( $contentObj['Key'] ) );
			if ( !isset( $seenDirectories[$dirname] ) ) {
				/* New directory found */
				$seenDirectories[$dirname] = true;

				if ( $dirname != '.' ) { // Skip ".", as FSFileBackend does
					$list[] = $dirname;
				}
			}
		}

		return $list;
	}
}
