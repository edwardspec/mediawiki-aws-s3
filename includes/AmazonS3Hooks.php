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
	 * Call installBackend() from $wgExtensionFunctions.
	 */
	public static function setup() {
		$hooks = new self;
		$hooks->installBackend();
	}

	/**
	 * Let MediaWiki know that AmazonS3Backend is available.
	 *
	 * Note: we call this from $wgExtensionFunctions, not from SetupAfterCache hook,
	 * because replaceLocalRepo() needs User::isEveryoneAllowed(),
	 * which (for some reason) needs $wgContLang,
	 * and $wgContLang only gets defined after SetupAfterCache.
	 */
	public function installBackend() {
		global $wgFileBackends, $wgAWSBucketName, $wgAWSBucketPrefix;

		if ( !isset( $wgFileBackends['s3'] ) ) {
			$wgFileBackends['s3'] = [];
		}
		$wgFileBackends['s3']['name'] = 'AmazonS3';
		$wgFileBackends['s3']['class'] = 'AmazonS3FileBackend';
		$wgFileBackends['s3']['lockManager'] = 'nullLockManager';

		/* When $wgAWSBucketName is not set, it can mean:
			1) the extension is not configured in LocalSettings.php,
			2) administrator didn't set it on purpose
			(to customize $wgLocalFileRepo in LocalSettings.php).

			In this case we'll still provide AmazonS3FileBackend,
			but MediaWiki won't use it for storing uploads.
		*/
		if ( $wgAWSBucketName || $wgAWSBucketPrefix ) {
			$this->replaceLocalRepo();
		}
	}

	/**
	 * Replace $wgLocalRepo with Amazon S3.
	 */
	protected function replaceLocalRepo() {
		global $wgFileBackends, $wgLocalFileRepo, $wgAWSRepoHashLevels,
			$wgAWSRepoDeletedHashLevels;

		/* Needed zones */
		$zones = [ 'public', 'thumb', 'deleted', 'temp' ];
		$publicZones = [ 'public', 'thumb' ];

		$wgLocalFileRepo = [
			'class'             => 'LocalRepo',
			'name'              => 'local',
			'backend'           => 'AmazonS3',
			'url'               => wfScript( 'img_auth' ),
			'hashLevels'        => $wgAWSRepoHashLevels,
			'deletedHashLevels' => $wgAWSRepoDeletedHashLevels,
			'zones'             => array_fill_keys( $zones, [ 'url' => false ] )
		];

		if ( AmazonS3CompatTools::isPublicWiki() ) {
			// Not a private wiki: $publicZones must have an URL
			foreach ( $publicZones as $zone ) {
				$wgLocalFileRepo['zones'][$zone] = [
					'url' => $this->getZoneUrl( $zone )
				];
			}
		} else {
			// Private wiki: $publicZones must use img_auth.php
			foreach ( $publicZones as $zone ) {
				// Use default value from $wgLocalFileRepo['url']
				unset( $wgLocalFileRepo['zones'][$zone]['url'] );
			}
		}

		// Container names are prefixed by wfWikiID(), which depends on $wgDBPrefix and $wgDBname.
		$wikiId = wfWikiID();
		$containerPaths = [];
		foreach ( $zones as $zone ) {
			$containerPaths["$wikiId-local-$zone"] = $this->getContainerPath( $zone );
		}
		$wgFileBackends['s3']['containerPaths'] = $containerPaths;
	}

	/**
	 * Returns container path for $zone, based on $wgAWSBucketName or B/C $wgAWSBucketPrefix.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string Container path, e.g. "BucketName" or "BucketName/thumb".
	 */
	protected function getContainerPath( $zone ) {
		return $this->getS3BucketName( $zone ) . $this->getS3RootDir( $zone );
	}

	/**
	 * Returns S3 bucket name for $zone, based on $wgAWSBucketName.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string Container path, e.g. "BucketName" or "BucketName/thumb".
	 */
	protected function getS3BucketName( $zone ) {
		global $wgAWSBucketName, $wgAWSBucketPrefix;

		if ( $wgAWSBucketName ) {
			// Only one S3 bucket
			return $wgAWSBucketName;
		}

		// B/C config, four S3 buckets (one per zone).
		return $wgAWSBucketPrefix . $this->getDashZoneString( $zone );
	}

	/**
	 * Returns root directory within S3 bucket name for $zone.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string Relative path, e.g. "" or "/thumb" (without trailing slash).
	 */
	protected function getS3RootDirInternal( $zone ) {
		global $wgAWSBucketName;
		if ( !$wgAWSBucketName ) {
			// Backward compatibility mode (4 S3 buckets): when we use more than one bucket,
			// there is no need for extra subdirectories within the bucket.
			return "";
		}

		// Modern config, one S3 bucket for all zones.
		switch ( $zone ) {
			case 'public':
				return '';

			case 'thumb':
				return '/thumb';

			case 'deleted':
				return '/deleted';

			case 'temp':
				return '/temp';
		}

		return "/$zone"; # Fallback value for unknown zone (added in recent version of MediaWiki?)
	}

	/**
	 * Same as getS3RootDirInternal(), but with prepended $wgAWSBucketTopSubdirectory.
	 * @param string $zone
	 * @return string
	 */
	protected function getS3RootDir( $zone ) {
		global $wgAWSBucketTopSubdirectory; // Default: empty string
		return $wgAWSBucketTopSubdirectory . $this->getS3RootDirInternal( $zone );
	}

	/**
	 * Returns zone suffix (value of $2 replacement in $wgAWSBucketDomain).
	 * Used for S3 bucket names in configuration with 4 different buckets ($wgAWSBucketPrefix).
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string
	 */
	protected function getDashZoneString( $zone ) {
		return ( $zone == 'public' ? '' : "-$zone" );
	}

	/**
	 * Returns external URL of the zone.
	 * @param string $zone Name of the zone, can be 'public', 'thumb', 'temp' or 'deleted'.
	 * @return string URL, e.g. "https://something.s3.amazonaws.com/thumb".
	 */
	protected function getZoneUrl( $zone ) {
		global $wgAWSBucketDomain;

		if ( is_array( $wgAWSBucketDomain ) ) {
			if ( !isset( $wgAWSBucketDomain[$zone] ) ) {
				throw new AmazonS3MisconfiguredException(
					"\$wgAWSBucketDomain is an array without the required key \"$zone\"" );
			}

			$domain = $wgAWSBucketDomain[$zone];
		} else {
			$domain = $wgAWSBucketDomain;

			// Sanity check: avoid the same domain being used for both 'public' and 'thumb',
			// unless only one S3 bucket is used.
			global $wgAWSBucketPrefix;
			if ( $wgAWSBucketPrefix && !preg_match( '/\$[12]/', $domain ) ) {
				throw new AmazonS3MisconfiguredException(
					'If $wgAWSBucketPrefix is used, $wgAWSBucketDomain must ' .
					'contain either $1 or $2.' );
			}
		}

		// Apply replacements:
		// $1 - full S3 bucket name (e.g. mysite-media-thumb)
		// $2 - zone suffix (e.g. "-thumb" for "thumb" zone, "" for public zone)
		$domain = str_replace(
			[ '$1', '$2' ],
			[ $this->getS3BucketName( $zone ), $this->getDashZoneString( $zone ) ],
			$domain
		);

		return 'https://' . $domain . $this->getS3RootDir( $zone );
	}
}
