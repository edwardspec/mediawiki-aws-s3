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

use MWAWS\FSFile;

/**
 * Local cache for images (especially large, like PDF files) that are downloaded from S3
 * to webserver for the purpose of creating a thumbnail.
 */
class AmazonS3LocalCache {

	/**
	 * Find local path that will be used to store S3 object $virtualPath in the local cache.
	 * @param string $virtualPath Pseudo-URL of the image in S3,
	 * e.g. "mwstore://AmazonS3/local-public/Something_something.png".
	 * @return string|false Path to the local file (whether it exists or not)
	 * or false (if the cache is disabled).
	 */
	protected static function findLocalPath( $virtualPath ) {
		global $wgAWSLocalCacheDirectory;
		if ( !$wgAWSLocalCacheDirectory ) {
			return false; // Cache is disabled.
		}

		return $wgAWSLocalCacheDirectory . "/" . str_replace( "mwstore://", "", $virtualPath );
	}

	/**
	 * Look for S3 object $virtualPath in the local cache.
	 * @param string $virtualPath Pseudo-URL of the image in S3,
	 * e.g. "mwstore://AmazonS3/local-public/Something_something.png".
	 * @return FSFile
	 */
	public static function get( $virtualPath ) {
		global $wgAWSLocalCacheDirectory;

		$ext = FSFile::extensionFromPath( $virtualPath );
		$file = null;

		if ( $wgAWSLocalCacheDirectory ) {
			// Cache is enabled.
			// Target file is a non-temporary file inside the cache directory.
			$localPath = self::findLocalPath( $virtualPath );
			$file = new FSFile( $localPath );

			if ( !$file->exists() ) {
				// New cache entry.
				// We don't yet know if this file needs to be persistently stored in cache,
				// because it depends on its size, and we haven't downloaded it yet.
				// So we name it <neededName>.<something>
				// and then will rename it into <neededName> in scheduleAutodeleteIfNeeded().
				$localPath .= ".S3LocalCache." . wfRandomString( 32 ) . "." . $ext;
				$file = new FSFile( $localPath );
			}
		} else {
			// Cache is disabled.
			// Target file is temporary and will be deleted automatically.
			$file = TempFSFile::factory( 'localcopy_', $ext );
		}

		if ( !$file ) {
			throw new MWException( "Failed to make the file for $virtualPath" );
		}

		return $file;
	}

	/**
	 * Automatically delete the file from cache if it is smaller than $wgAWSLocalCacheMinSize.
	 * @param FSFile &$file
	 */
	public static function postDownloadLogic( &$file ) {
		global $wgAWSLocalCacheMinSize;
		if ( $file instanceof TempFSFile ) {
			return; // Already scheduled to autodelete
		}

		$path = $file->getPath();
		$size = $file->getSize();
		$logger = MediaWiki\Logger\LoggerFactory::getInstance( 'FileOperation' );

		if ( $size < $wgAWSLocalCacheMinSize ) {
			$logger->debug( "LocalCache: File {path} is too small for cache: " .
				"{size} is less than wgAWSLocalCacheMinSize={minsize}. Setting to autodelete.",
				[
					'path' => $path,
					'size' => $size,
					'minsize' => $wgAWSLocalCacheMinSize
				]
			);

			$file = new TempFSFile( $path );
			$file->autocollect(); // Will be deleted when this PHP script ends
		} else {
			// Need to rename the file (stripping ".S3LocalCache." suffix)
			$newPath = preg_replace( '/\.S3LocalCache\..*$/', '', $path );
			rename( $path, $newPath );

			$file = new FSFile( $newPath );

			$logger->debug( "LocalCache: File $path is large enough to be cached " .
				"({size} bytes): renaming {path} to {newPath}.",
				[
					'path' => $path,
					'newPath' => $newPath,
					'size' => $size
				]
			);
		}
	}

	/**
	 * Remove the cached file from local cache.
	 * This is used when image is deleted, or when new version of same-name image is uploaded.
	 * @param string $virtualPath Pseudo-URL of the image in S3,
	 * e.g. "mwstore://AmazonS3/local-public/Something_something.png".
	 */
	public static function invalidate( $virtualPath ) {
		$localPath = self::findLocalPath( $virtualPath );

		if ( $localPath ) {
			// TODO: graceful "mark as expired, delete later".
			// TODO: delete on all webservers (not just this one).
			$file = new TempFSFile( $localPath );
			$file->purge();
		}
	}
}
