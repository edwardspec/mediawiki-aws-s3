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
 * Hooks of Extension:AWS
 */
class AmazonS3Hooks {
	/**
	 * Let MediaWiki know that AmazonS3Backend is available.
	 *
	 * Note: we call this from $wgExtensionFunctions, not from SetupAfterCache hook,
	 * because replaceLocalRepo() needs User::isEveryoneAllowed(),
	 * which (for some reason) needs $wgContLang,
	 * and $wgContLang only gets defined after SetupAfterCache.
	 */
	public static function installBackend() {
		global $wgFileBackends, $wgAWSBucketPrefix;

		if ( !isset( $wgFileBackends['s3'] ) ) {
			$wgFileBackends['s3'] = [];
		}
		$wgFileBackends['s3']['name'] = 'AmazonS3';
		$wgFileBackends['s3']['class'] = 'AmazonS3FileBackend';
		$wgFileBackends['s3']['lockManager'] = 'nullLockManager';

		/* When $wgAWSBucketPrefix is not set, it can mean:
			1) the extension is not configured in LocalSettings.php,
			2) administrator didn't set it on purpose
			(to customize $wgLocalFileRepo in LocalSettings.php).

			In this case we'll still provide AmazonS3FileBackend,
			but MediaWiki won't use it for storing uploads.
		*/
		if ( $wgAWSBucketPrefix ) {
			self::replaceLocalRepo( $wgAWSBucketPrefix );
		}

		return true;
	}

	/**
	 * Replace $wfLocalRepo with Amazon S3.
	 */
	protected static function replaceLocalRepo( $prefix ) {
		global $wgFileBackends, $wgLocalFileRepo, $wgDBname;

		/* Needed zones */
		$zones = [ 'public', 'thumb', 'deleted', 'temp' ];
		$publicZones = [ 'public', 'thumb' ];

		/*
			Determine S3 buckets that contain the images.
		*/
		$bucketNames = [];
		foreach ( $zones as $zone ) {
			$bucketNames[$zone] = $prefix . ( $zone == 'public' ? '' : "-$zone" );
		}

		$wgLocalFileRepo = [
			'class'             => 'LocalRepo',
			'name'              => 'local',
			'backend'           => 'AmazonS3',
			'url'               => wfScript( 'img_auth' ),
			'hashLevels'        => 0,
			'zones'             => array_fill_keys( $zones, [ 'url' => false ] )
		];

		if ( User::isEveryoneAllowed( 'read' ) ) {
			/* Not a private wiki: $publicZones must have an URL */
			foreach ( $publicZones as $zone ) {
				$bucket = $prefix .
					( $zone == 'public' ? '' : "-$zone" );
				$wgLocalFileRepo['zones'][$zone] = [
					'url' => "https://${bucket}.s3.amazonaws.com"
				];
			}
		}

		$containerPaths = [];
		foreach ( $zones as $zone ) {
			$containerPaths["$wgDBname-local-$zone"] = $bucketNames[$zone];
		}
		$wgFileBackends['s3']['containerPaths'] = $containerPaths;
	}
}

