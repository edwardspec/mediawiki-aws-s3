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
 * Recursively lists CommonPrefixes based on the Iterator of S3 object names,
 * as returned by getFileListInternal($topOnly=false).
 */
class AmazonS3SubdirectoryIterator extends FilterIterator {
	private $seenDirectories = [];

	public function rewind() {
		$this->seenDirectories = [];
		parent::rewind();
	}

	/**
	 * Ignore the directories which were already listed.
	 * The original iterator can contain keys like "dir1/file1" and "dir1/file2",
	 * but this iterator should return "dir1" only once.
	 * @return bool
	 */
	public function accept() {
		$dirname = $this->current();
		if ( !isset( $this->seenDirectories[$dirname] ) ) {
			/* New directory found */
			$this->seenDirectories[$dirname] = true;

			if ( $dirname !== '.' ) { // Skip ".", as FSFileBackend does
				return true;
			}
		}

		return false;
	}

	public function current() {
		return dirname( $this->getInnerIterator()->current() );
	}
}
