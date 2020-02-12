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

namespace MWAWS;

/**
 * Helper class that disguises non-deletable FSFile as deletable TempFSFile.
 * This allows us to avoid unnecessary copying in AmazonS3LocalCache::get()
 * and return FSFile (instead of TempFSFile) in doGetLocalCopyMulti().
 */
class FSFile extends \FSFile {
	/**
	 * Handle methods that don't exist in \FSFile class.
	 * @param string $name
	 * @param array $arguments
	 */
	public function __call( $name, array $arguments ) {
		$class = new \ReflectionClass( 'TempFSFile' );
		if ( !$class->hasMethod( $name ) ) {
			throw new \BadMethodCallException( "Call to undefined method MWAWS\FSFile::$name()" );
		}

		/* Do nothing. All those TempFSFile methods are related to automatic deletion,
			however this particular file shouldn't be deleted.
		*/
	}
}
