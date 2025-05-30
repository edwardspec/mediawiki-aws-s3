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

if ( !class_exists( 'WikiMap' ) ) {
	// MediaWiki 1.44+
	class_alias( 'MediaWiki\\WikiMap\\WikiMap', 'WikiMap' );
}

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
	 * Replace $wgLocalFileRepo with Amazon S3.
	 */
	protected function replaceLocalRepo() {
		global $wgFileBackends, $wgLocalFileRepo, $wgAWSRepoHashLevels,
			$wgAWSRepoDeletedHashLevels, $wgImgAuthPath,
			$wgAWSRepoZones, $wgScriptPath;

		$wgLocalFileRepo = [
			'class'             => 'LocalRepo',
			'name'              => 'local',
			'backend'           => 'AmazonS3',
			'url'               => $wgImgAuthPath ?: wfScript( 'img_auth' ),
			'scriptDirUrl'      => $wgScriptPath,
			'hashLevels'        => $wgAWSRepoHashLevels,
			'deletedHashLevels' => $wgAWSRepoDeletedHashLevels,
			'zones'             => []
		];

		// Container names are prefixed by WikiId string, which depends on $wgDBPrefix and $wgDBname.
		$wikiId = WikiMap::getCurrentWikiId();
		$isPublicWiki = $this->earlyIsPublicWiki();

		// Configure zones (public, thumb, deleted, etc.).
		$containerPaths = [];
		foreach ( $wgAWSRepoZones as $zone => $info ) {
			$containerPaths["$wikiId-" . $info['container']] = $this->getContainerPath( $zone );

			$zoneConf = [];
			if ( empty( $info['isPublic'] ) ) {
				// Private zones don't have an URL.
				$zoneConf['url'] = false;
			} elseif ( $isPublicWiki ) {
				// Not a private wiki: public zones must have an URL.
				$zoneConf['url'] = $this->getZoneUrl( $zone );
			}
			$wgLocalFileRepo['zones'][$zone] = $zoneConf;
		}

		$wgFileBackends['s3']['containerPaths'] = $containerPaths;
	}

	/**
	 * Returns true if everyone (even anonymous users) can see pages in this wiki, false otherwise.
	 * Unlike AmazonS3CompatTools::isPublicWiki(), this method can be used during early initialization,
	 * when services like PermissionManager are not available yet.
	 * @return bool
	 */
	protected function earlyIsPublicWiki() {
		global $wgGroupPermissions, $wgRevokePermissions;

		$allowed = $wgGroupPermissions['*']['read'] ?? true;
		$revoked = $wgRevokePermissions['*']['read'] ?? false;

		return $allowed && !$revoked;
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
		global $wgAWSBucketName, $wgAWSRepoZones;
		if ( !$wgAWSBucketName ) {
			// Backward compatibility mode (4 S3 buckets): when we use more than one bucket,
			// there is no need for extra subdirectories within the bucket.
			return "";
		}

		// Modern config, one S3 bucket for all zones.
		$zoneConf = $wgAWSRepoZones[$zone] ?? [];
		return $zoneConf['path'] ?? "/$zone"; # Fallback value for unknown zone
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

		if ( !preg_match( '@^https?://@', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		return $domain . $this->getS3RootDir( $zone );
	}
}
