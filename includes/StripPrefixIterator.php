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
 * Iterator that strips N bytes from the beginning of strings returned by inner Iterator.
 * Used in getFileListInternal() and getDirectoryListInternal().
 */
class StripPrefixIterator extends IteratorIterator {
	/** @var Iterator */
	private $innerIterator;

	/** @var int */
	private $bytesToStrip;

	public function __construct( Iterator $iterator, $bytesToStrip ) {
		parent::__construct( $iterator );

		$this->bytesToStrip = $bytesToStrip;
	}

	public function current() {
		return substr( parent::current(), $this->bytesToStrip );
	}
}
