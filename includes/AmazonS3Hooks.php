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
			self::replaceLocalRepo();
		}

		return true;
	}

	/**
	 * Replace $wgLocalRepo with Amazon S3.
	 */
	protected static function replaceLocalRepo() {
		global $wgFileBackends, $wgLocalFileRepo, $wgDBname;

		/* Needed zones */
		$zones = [ 'public', 'thumb', 'deleted', 'temp' ];
		$publicZones = [ 'public', 'thumb' ];

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
				$wgLocalFileRepo['zones'][$zone] = [
					'url' => self::getBucketUrl( $zone )
				];
			}
		}

		$containerPaths = [];
		foreach ( $zones as $zone ) {
			$containerPaths["$wgDBname-local-$zone"] = self::getBucketName( $zone );
		}
		$wgFileBackends['s3']['containerPaths'] = $containerPaths;
	}

	/**
	 * Returns S3 bucket name for $zone, based on $wgAWSBucketPrefix.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string Name of S3 bucket, e.g. "mysite-media-thumb".
	 */
	protected static function getBucketName( $zone ) {
		global $wgAWSBucketPrefix;
		return $wgAWSBucketPrefix . self::getZoneSuffix( $zone );
	}

	/**
	 * Returns zone suffix (string which is appended to S3 bucket names) of $zone.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string
	 */
	protected static function getZoneSuffix( $zone ) {
		return ( $zone == 'public' ? '' : "-$zone" );
	}

	/**
	 * Returns external URL of the bucket.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string URL, e.g. "https://something.s3.amazonaws.com".
	 */
	protected static function getBucketUrl( $zone ) {
		global $wgAWSBucketDomain;

		if ( is_array( $wgAWSBucketDomain ) ) {
			if ( !isset( $wgAWSBucketDomain[$zone] ) ) {
				throw new AmazonS3MisconfiguredException(
					"\$wgAWSBucketDomain is an array without the required key \"$zone\"" );
			}

			$domain = $wgAWSBucketDomain[$zone];
		} else {
			$domain = $wgAWSBucketDomain;

			// Sanity check to avoid the same domain being used for both 'public' and 'thumb'
			if ( !$domain || !preg_match( '/\$[12]/', $domain ) ) {
				throw new AmazonS3MisconfiguredException(
					'$wgAWSBucketDomain string must contain either $1 or $2.' );
			}
		}

		// Apply replacements:
		// $1 - full S3 bucket name (e.g. mysite-media-thumb)
		// $2 - zone suffix (e.g. "-thumb" for "thumb" zone, "" for public zone)
		$domain = str_replace(
			[ '$1', '$2' ],
			[ self::getBucketName( $zone ), self::getZoneSuffix( $zone ) ],
			$domain
		);

		return 'https://' . $domain;
	}
}
