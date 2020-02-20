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

if ( !class_exists( "\\Aws\\S3\\S3Client" ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Psr\Log\LogLevel;

/**
 * FileBackend for Amazon S3
 *
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @author Thai Phan <thai@outlook.com>
 * @author Edward Chernenko <edwardspec@gmail.com>
 */
class AmazonS3FileBackend extends FileBackendStore {
	/**
	 * Amazon S3 client object from the SDK
	 * @var Aws\S3\S3Client
	 */
	private $client;

	/**
	 * Whether server side encryption is enabled
	 * @var bool
	 */
	private $encryption;

	/**
	 * Whether to use HTTPS for communicating with Amazon
	 * @var bool
	 */
	private $useHTTPS;

	/**
	 * @var array
	 * Maps names of containers (e.g. mywiki-local-thumb) to "mybucket/some/path", where "mybucket"
	 * is the name of S3 bucket, and "some/path" is the "top directory" prefix of S3 object names.
	 *
	 * @phan-var array<string,string>
	 */
	private $containerPaths;

	/**
	 * Presence of this file in the top of container path
	 * means that this container is used for private zone (e.g. 'deleted'),
	 * meaning ACL=private should be used in putObject() and CopyObject() into this bucket.
	 * See isSecure() below.
	 */
	const RESTRICT_FILE = '.htsecure';

	/**
	 * Maximum length of S3 object name.
	 * See https://docs.aws.amazon.com/AmazonS3/latest/dev/UsingMetadata.html for details.
	 */
	const MAX_S3_OBJECT_NAME_LENGTH = 1024;

	/**
	 * Cache used in isSecure(). Avoids extra requests to doesObjectExist().
	 * @var CachedBagOStuff
	 */
	private $containerSecurityCache = null;

	/**
	 * @var bool If true, then all S3 objects are private.
	 * NOTE: for images to work in private mode, $wgUploadPath should point to img_auth.php.
	 */
	protected $privateWiki = null;

	/**
	 * @var Psr\Log\LoggerInterface
	 * B/C for MediaWiki 1.27 (already defined in FileBackend class)
	 */
	protected $logger = null;

	/**
	 * Construct the backend. Doesn't take any extra config parameters.
	 *
	 * The configuration array may contain the following keys in addition
	 * to the keys accepted by FileBackendStore::__construct:
	 *  * containerPaths (required) - Mapping of container names to Amazon S3 buckets
	 *                                Each container must have its own unique bucket
	 *  * awsKey - An AWS authentication key to override $wgAWSCredentials
	 *  * awsSecret - An AWS authentication secret to override $wgAWSCredentials
	 *  * awsRegion - Region to use in place of $wgAWSRegion
	 *  * awsHttps - Whether to use HTTPS for AWS connections (defaults to $wgAWSUseHTTPS)
	 *  * awsEncryption - Whether to turn on server-side encryption on AWS (implies awsHttps=true)
	 *
	 * @param array $config
	 * @throws AmazonS3MisconfiguredException if no containerPaths is set
	 */
	public function __construct( array $config ) {
		global $wgAWSCredentials, $wgAWSRegion, $wgAWSUseHTTPS;

		parent::__construct( $config );

		$this->encryption = isset( $config['awsEncryption'] ) ? (bool)$config['awsEncryption'] : false;

		if ( $this->encryption ) {
			$this->useHTTPS = true;
		} elseif ( isset( $config['awsHttps'] ) ) {
			$this->useHTTPS = (bool)$config['awsHttps'];
		} else {
			$this->useHTTPS = (bool)$wgAWSUseHTTPS;
		}

		if ( isset( $config['shardViaHashLevels'] ) ) {
			$this->shardViaHashLevels = $config['shardViaHashLevels'];
		}

		// Cache container information to mask latency
		if ( isset( $config['wanCache'] ) && $config['wanCache'] instanceof WANObjectCache ) {
			$this->memCache = $config['wanCache'];
		}

		$params = [
			'version' => '2006-03-01',
			'region' => isset( $config['awsRegion'] ) ? $config['awsRegion'] : $wgAWSRegion,
			'scheme' => $this->useHTTPS ? 'https' : 'http'
		];
		if ( !empty( $wgAWSCredentials['key'] ) ) {
			$params['credentials'] = $wgAWSCredentials;
		} elseif ( isset( $config['awsKey'] ) ) {
			$params['credentials'] = [
				'key' => $config['awsKey'],
				'secret' => $config['awsSecret'],
				'token' => isset( $config['awsToken'] ) ? $config['awsToken'] : false
			];
		}

		if ( isset( $config['endpoint'] ) ) {
			$params['endpoint'] = $config['endpoint'];
		}

		$this->client = new S3Client( $params );

		if ( isset( $config['containerPaths'] ) ) {
			$this->containerPaths = (array)$config['containerPaths'];
		} else {
			throw new AmazonS3MisconfiguredException(
				__METHOD__ . " : containerPaths array must be set for S3." );
		}

		if ( isset( $config['privateWiki'] ) ) {
			/* Explicitly set in LocalSettings.php ($wgLocalFileRepo) */
			$this->privateWiki = $config['privateWiki'];
		} else {
			/* If anonymous users aren't allowed to read articles,
				then we assume that this wiki is private,
				and that we want files to be "for registered users only".
			*/
			$this->privateWiki = !AmazonS3CompatTools::isPublicWiki();
		}

		if ( !$this->logger ) {
			// B/C with MediaWiki 1.27.
			// Modern MediaWiki creates a logger in parent::__construct().
			$this->logger = MediaWiki\Logger\LoggerFactory::getInstance( 'FileOperation' );
		}

		$this->logger->info(
			'S3FileBackend: found backend with S3 buckets: {buckets}.{isPrivateWiki}',
			[
				'buckets' => implode( ', ', array_values( $config['containerPaths'] ) ),
				'isPrivateWiki' => $this->privateWiki ?
					' (private wiki, new S3 objects will be private)' : ''
			]
		);

		$this->containerSecurityCache = new CachedBagOStuff( wfGetMainCache() );
	}

	/**
	 * Returns true if subdirectories are imaginary. This is always the case for Amazon S3.
	 * (path like "a/b.txt" means an object with "a/b.txt" as its name, there is no directory "a")
	 * @return true
	 */
	protected function directoriesAreVirtual() {
		return true;
	}

	/**
	 * Check if an S3 object can be created/modified at this storage path.
	 * @param string $storagePath
	 * @return bool
	 */
	public function isPathUsableInternal( $storagePath ) {
		return true;
	}

	/**
	 * Encode illegal characters (if there are any, e.g. "/") in relative storage path.
	 * Not needed for Amazon S3, because this is done internally by S3Client class.
	 *
	 * Returns null for invalid storage paths (in this case - when S3 object name is too long).
	 *
	 * @param string $container Container name
	 * @param string $relStoragePath Name of S3 object.
	 * @return string|null Name of S3 object (if valid) or null.
	 */
	protected function resolveContainerPath( $container, $relStoragePath ) {
		if ( strlen( $relStoragePath ) <= self::MAX_S3_OBJECT_NAME_LENGTH ) {
			return $relStoragePath;
		} else {
			return null;
		}
	}

	/**
	 * Determine S3 bucket of $container and prefix of S3 objects in $container.
	 * @param string $container Internal container name (e.g. mywiki-local-thumb).
	 * @return array|null Array of two strings: bucket, prefix.
	 * @see getBucketAndObject
	 */
	protected function findContainer( $container ) {
		if ( empty( $this->containerPaths[$container] ) ) {
			return null; // Not configured
		}

		// $containerPath can be either "BucketName" or "BucketName/dir1/dir2".
		// In latter case, "dir1/dir2/" will be prepended to $filename.
		$containerPath = $this->containerPaths[$container];
		$firstSlashPos = strpos( $containerPath, '/' );
		if ( $firstSlashPos === false ) {
			return [ $containerPath, "" ];
		}

		$prefix = substr( $containerPath, $firstSlashPos + 1 );
		$bucket = substr( $containerPath, 0, $firstSlashPos );

		if ( $prefix && substr( $prefix, -1 ) !== '/' ) {
			$prefix .= '/'; # Add trailing slash, e.g. "thumb/".
		}

		return [ $bucket, $prefix ];
	}

	/**
	 * Calculate names of S3 bucket and S3 object of $storagePath.
	 * @param string $storagePath Internal storage URL (mwstore://something/).
	 * @return array|null Array of three strings: bucket, object and internal container.
	 */
	protected function getBucketAndObject( $storagePath ) {
		list( $container, $filename ) = $this->resolveStoragePathReal( $storagePath );
		list( $bucket, $prefix ) = $this->findContainer( $container );
		return [ $bucket, $prefix . $filename, $container ];
	}

	/**
	 * Determine S3 bucket and S3 object name of RESTRICT_FILE in $container.
	 * @param string $container Internal container name (e.g. mywiki-local-thumb).
	 * @return array|null Array of two strings: bucket, object name.
	 */
	protected function getRestrictFilePath( $container ) {
		list( $bucket, $prefix ) = $this->findContainer( $container );
		$restrictFile = $prefix . self::RESTRICT_FILE;

		return [ $bucket, $restrictFile ];
	}

	// phpcs:disable Generic.Files.LineLength.TooLong

	/**
	 * Create a new S3 object.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{content:string|resource,src?:string,dst:string,headers?:array<string,string>} $params
	 */
	protected function doCreateInternal( array $params ) {
		// phpcs:enable Generic.Files.LineLength.TooLong

		list( $bucket, $key, $container ) = $this->getBucketAndObject( $params['dst'] );

		if ( $bucket === null || $key == null ) {
			return Status::newFatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		$contentType = isset( $params['headers']['Content-Type'] ) ?
			$params['headers']['Content-Type'] : null;

		if ( is_resource( $params['content'] ) && isset( $params['src'] ) ) {
			// If we are here, it means that doCreateInternal() was called from doStoreInternal().
			$sha1 = sha1_file( $params['src'] );
			if ( !$contentType ) {
				// Guess the MIME type from filename.
				$contentType = $this->getContentType( $params['dst'], null, $params['src'] );
			}
		} else {
			$sha1 = sha1( $params['content'] );
			if ( !$contentType ) {
				// Guess the MIME type from contents.
				$contentType = $this->getContentType( $params['dst'], $params['content'], null );
			}
		}

		$sha1Hash = Wikimedia\base_convert( $sha1, 16, 36, 31, true, 'auto' );

		$params['headers'] = isset( $params['headers'] ) ? $params['headers'] : [];
		$params['headers'] += array_fill_keys( [
			'Cache-Control',
			'Content-Disposition',
			'Content-Encoding',
			'Content-Language',
			'Expires'
		], null );

		$this->logger->debug(
			'S3FileBackend: doCreateInternal(): saving {key} in S3 bucket {bucket} ' .
			'(sha1 of the original file: {sha1}, Content-Type: {contentType})',
			[
				'bucket' => $bucket,
				'key' => $key,
				'sha1' => $sha1Hash,
				'contentType' => $contentType
			]
		);

		$profiling = new AmazonS3ProfilingAssist( "uploading $key to S3" );

		$ret = $this->runWithExceptionHandling( __FUNCTION__, function ()
			use ( $params, $container, $bucket, $key, $contentType, $sha1Hash ) {
			return $this->client->putObject( array_filter( [
				'ACL' => $this->isSecure( $container ) ? 'private' : 'public-read',
				'Body' => $params['content'],
				'Bucket' => $bucket,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $contentType,
				'Expires' => $params['headers']['Expires'],
				'Key' => $key,
				'Metadata' => [ 'sha1base36' => $sha1Hash ],
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null,
			] ) );
		} );

		$profiling->log();

		if ( $ret instanceof S3Exception ) {
			return Status::newFatal( 'backend-fail-create', $params['dst'] );
		}

		AmazonS3LocalCache::invalidate( $params['dst'] );
		return Status::newGood();
	}

	/**
	 * Same as doCreateInternal(), but the source is a local file, not variable in memory.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{src:string,dst:string,headers?:array<string,string>} $params
	 */
	protected function doStoreInternal( array $params ) {
		// Supply the open file to doCreateInternal() and have it do the rest.
		$params['content'] = fopen( $params['src'], 'r' );
		return $this->doCreateInternal( $params );
	}

	// phpcs:disable Generic.Files.LineLength.TooLong

	/**
	 * Copy an existing S3 object into another S3 object.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{src:string,dst:string,headers?:array<string,string>,ignoreMissingSource?:bool} $params
	 */
	protected function doCopyInternal( array $params ) {
		// phpcs:enable Generic.Files.LineLength.TooLong

		$status = Status::newGood();

		list( $srcBucket, $srcKey, ) = $this->getBucketAndObject( $params['src'] );
		list( $dstBucket, $dstKey, $dstContainer ) = $this->getBucketAndObject( $params['dst'] );

		if ( $srcBucket === null || $srcKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
		}
		if ( $dstBucket === null || $dstKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		if ( !$status->isOK() ) {
			return $status;
		}

		$this->logger->debug(
			'S3FileBackend: doCopyInternal(): copying {srcKey} from S3 bucket {dstBucket} ' .
			'to {dstKey} from S3 bucket {dstBucket}.',
			[
				'dstKey' => $dstKey,
				'dstBucket' => $dstBucket,
				'srcKey' => $srcKey,
				'srcBucket' => $srcBucket
			]
		);

		$params['headers'] = isset( $params['headers'] ) ? $params['headers'] : [];
		$params['headers'] += array_fill_keys( [
			'Cache-Control',
			'Content-Disposition',
			'Content-Encoding',
			'Content-Language',
			'Content-Type',
			'Expires',
			'E-Tag',
			'If-Modified-Since'
		], null );

		$profiling = new AmazonS3ProfilingAssist( "copying S3 object $srcKey to $dstKey" );

		$ret = $this->runWithExceptionHandling( __FUNCTION__, function ()
			use ( $dstContainer, $dstBucket, $params, $srcBucket, $srcKey, $dstKey ) {
			return $this->client->copyObject( array_filter( [
				'ACL' => $this->isSecure( $dstContainer ) ? 'private' : 'public-read',
				'Bucket' => $dstBucket,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $params['headers']['Content-Type'],
				'CopySource' => $srcBucket . '/' . $this->client->encodeKey( $srcKey ),
				'CopySourceIfMatch' => $params['headers']['E-Tag'],
				'CopySourceIfModifiedSince' => $params['headers']['If-Modified-Since'],
				'Expires' => $params['headers']['Expires'],
				'Key' => $dstKey,
				'MetadataDirective' => 'COPY',
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null
			] ) );
		} );

		$profiling->log();

		if ( $ret instanceof S3Exception ) {
			switch ( $ret->getAwsErrorCode() ) {
				// It's possible that source bucket doesn't exist.
				// Only destination bucket is created automatically by runWithExceptionHandling().
				case 'NoSuchBucket':
					return Status::newFatal( 'backend-fail-copy', $params['src'], $params['dst'] );

				case 'NoSuchKey':
					if ( !empty( $params['ignoreMissingSource'] ) ) {
						return Status::newGood();
					}

					return Status::newFatal( 'backend-fail-copy', $params['src'], $params['dst'] );
			}

			return Status::newFatal( 'backend-fail-internal', $this->name );
		}

		AmazonS3LocalCache::invalidate( $params['dst'] );
		return Status::newGood();
	}

	/**
	 * Delete an existing S3 object.
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param array{src:string,ignoreMissingSource?:bool} $params
	 */
	protected function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		list( $bucket, $key, ) = $this->getBucketAndObject( $params['src'] );
		if ( $bucket === null || $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		$this->logger->debug(
			'S3FileBackend: doDeleteInternal(): deleting {key} from S3 bucket {bucket}',
			[
				'key' => $key,
				'bucket' => $bucket
			]
		);

		$ret = $this->runWithExceptionHandling( __FUNCTION__, function () use ( $bucket, $key ) {
			$this->client->deleteObject( [
				'Bucket' => $bucket,
				'Key' => $key
			] );
		} );

		if ( $ret instanceof S3Exception ) {
			if ( $ret->getAwsErrorCode() == 'NoSuchKey' ) {
				if ( !empty( $params['ignoreMissingSource'] ) ) {
					return Status::newGood();
				}

				return Status::newFatal( 'backend-fail-delete', $params['src'] );
			}

			return Status::newFatal( 'backend-fail-internal', $this->name );
		}

		AmazonS3LocalCache::invalidate( $params['src'] );
		return Status::newGood();
	}

	/**
	 * Check if "directory" $dir exists within $container.
	 * Note: in S3, "directories" are imaginary, so existence means that there are S3 objects
	 * that have "$dir/" as the beginning of their name.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return bool
	 */
	protected function doDirectoryExists( $container, $dir, array $params ) {
		// See if at least one file is in the directory.
		if ( $dir && substr( $dir, -1 ) !== '/' ) {
			$dir .= '/';
		}

		list( $bucket, $prefix ) = $this->findContainer( $container );
		$dir = $prefix . $dir;

		$this->logger->debug(
			'S3FileBackend: checking existence of directory {dir} in S3 bucket {bucket}',
			[
				'dir' => $dir,
				'bucket' => $bucket
			]
		);

		return $this->getS3ListPaginator( $bucket, $dir, false, [ 'Limit' => 1 ] )
			->search( 'Contents' )->valid();
	}

	/**
	 * Obtain metadata (e.g. size, SHA1, etc.) of existing S3 object.
	 * @param array $params
	 * @return array|false|null
	 *
	 * @phan-param array{src:string} $params
	 * @phan-return array{mtime:string,size:int,etag:string,sha1:string}|false|null
	 */
	protected function doGetFileStat( array $params ) {
		list( $bucket, $key, ) = $this->getBucketAndObject( $params['src'] );

		$this->logger->debug(
			'S3FileBackend: doGetFileStat(): obtaining information about {key} in S3 bucket {bucket}',
			[
				'key' => $key,
				'bucket' => $bucket
			]
		);

		if ( $bucket === null || $key == null ) {
			return null;
		}

		// Note: we don't use runWithExceptionHandling() here for two reasons:
		// 1) we don't need NotFound errors logged (these are not errors, because doGetFileStat
		// is meant to be used for "does this file exist" checks),
		// 2) if the bucket doesn't exist, there is no point in repeating this operation
		// after creating it, because the result will still be "file not found".
		try {
			$res = $this->client->headObject( [
				'Bucket' => $bucket,
				'Key' => $key
			] );
		} catch ( S3Exception $e ) {
			if ( $e->getAwsErrorCode() != 'NotFound' ) {
				$this->logException( $e, __METHOD__ );
			}

			return false;
		}

		$sha1 = '';
		if ( isset( $res['Metadata']['sha1base36'] ) ) {
			$sha1 = $res['Metadata']['sha1base36'];
		}

		return [
			'mtime' => wfTimestamp( TS_MW, $res['LastModified'] ),
			'size' => (int)$res['ContentLength'],
			'etag' => $res['Etag'],
			'sha1' => $sha1
		];
	}

	/**
	 * Obtain presigned URL of S3 object (from this URL it can be downloaded by HTTP(s) by anyone).
	 * @param array $params
	 * @return string|null
	 *
	 * @phan-param array{src:string} $params
	 */
	public function getFileHttpUrl( array $params ) {
		list( $bucket, $key, ) = $this->getBucketAndObject( $params['src'] );
		if ( $bucket === null ) {
			return null;
		}

		$this->logger->debug(
			'S3FileBackend: getFileHttpUrl(): obtaining presigned S3 URL of {key} in S3 bucket {bucket}',
			[
				'key' => $key,
				'bucket' => $bucket
			]
		);

		// Not using runWithExceptionHandling() for the same reasons as in doGetFileStat().
		try {
			$request = $this->client->getCommand( 'GetObject', [
				'Bucket' => $bucket,
				'Key' => $key
			] );
			$presigned = $this->client->createPresignedRequest( $request, '+1 day' );
			return (string)$presigned->getUri();
		} catch ( S3Exception $e ) {
			return null;
		}
	}

	/**
	 * Obtain Paginator for listing S3 objects with certain prefix.
	 * @param string $bucket Name of S3 bucket.
	 * @param string $prefix If filename doesn't start with $prefix, it won't be listed.
	 * @param bool $topOnly If true, filenames with "/" won't be listed.
	 * @param array $extraParams Additional arguments of ListObjects call (if any).
	 * @return Aws\ResultPaginator
	 */
	private function getS3ListPaginator( $bucket, $prefix, $topOnly, array $extraParams = [] ) {
		return $this->client->getPaginator( 'ListObjects', $extraParams + [
			'Bucket' => $bucket,
			'Prefix' => $prefix,
			'Delimiter' => $topOnly ? '/' : ''
		] );
	}

	/**
	 * Obtain Iterator that lists "subdirectories" in $container under directory $dir.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Iterator
	 *
	 * @phan-param array{topOnly?:bool} $params
	 */
	public function getDirectoryListInternal( $container, $dir, array $params ) {
		$topOnly = !empty( $params['topOnly'] );

		list( $bucket, $prefix ) = $this->findContainer( $container );
		$bucketDir = $prefix . $dir; // Relative to S3 bucket $bucket, not $container

		$this->logger->debug(
			'S3FileBackend: checking DirectoryList(topOnly={topOnly}) ' .
			'of directory {dir} in S3 bucket {bucket}',
			[
				'dir' => $bucketDir,
				'bucket' => $bucket,
				'topOnly' => $topOnly ? 1 : 0
			]
		);

		if ( $topOnly ) {
			if ( $bucketDir && substr( $bucketDir, -1 ) !== '/' ) {
				// Add trailing slash to avoid CommonPrefixes response instead of Contents.
				$bucketDir .= '/';
			}

			$paginator = $this->getS3ListPaginator( $bucket, $bucketDir, true );
			return new TrimStringIterator(
				$paginator->search( 'CommonPrefixes[].Prefix' ),
				strlen( $bucketDir ), // Remove $bucketDir in the beginning
				1 // Remove trailing slash, CommonPrefixes always have it
			);
		}

		return new AmazonS3SubdirectoryIterator(
			$this->getFileListInternal( $container, $dir, [] )
		);
	}

	/**
	 * Obtain Iterator that lists S3 objects in $container under subdirectory $dir.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Iterator
	 *
	 * @phan-param array{topOnly?:bool} $params
	 */
	public function getFileListInternal( $container, $dir, array $params ) {
		$topOnly = !empty( $params['topOnly'] );

		list( $bucket, $prefix ) = $this->findContainer( $container );
		$dir = $prefix . $dir;

		$this->logger->debug(
			'S3FileBackend: checking FileList(topOnly={topOnly}) ' .
			'of directory {dir} in S3 bucket {bucket}',
			[
				'dir' => $dir,
				'bucket' => $bucket,
				'topOnly' => $topOnly ? 1 : 0
			]
		);

		if ( $dir && substr( $dir, -1 ) !== '/' ) {
			// Add trailing slash to avoid CommonPrefixes response instead of Contents.
			$dir .= '/';
		}

		return new TrimStringIterator(
			$this->getS3ListPaginator( $bucket, $dir, $topOnly )->search( 'Contents[].Key' ),
			strlen( $dir ) // Remove $dir from the beginning of listed filenames
		);
	}

	/**
	 * Download S3 object $src. Checks local cache before downloading.
	 * @param string $src
	 * @return FSFile|null Local temporary file that contains downloaded contents.
	 */
	protected function getLocalCopyCached( $src ) {
		// Try the local cache
		$file = AmazonS3LocalCache::get( $src );
		$dstPath = $file->getPath();

		if ( $file->exists() && $file->getSize() > 0 ) { // Found in cache
			$this->logger->debug(
				'S3FileBackend: found {src} in local cache: {dstPath}',
				[
					'src' => $src,
					'dstPath' => $dstPath
				]
			);
			return $file;
		}

		// Not found in the cache. Download from S3.
		$srcPath = $this->getFileHttpUrl( [ 'src' => $src ] );
		if ( !$srcPath ) {
			return null; // Not found: no such object in S3
		}

		$this->logger->debug(
			'S3FileBackend: downloading presigned S3 URL {srcPath} to {dstPath}',
			[
				'srcPath' => $srcPath,
				'dstPath' => $dstPath
			]
		);

		wfMkdirParents( dirname( $dstPath ) );

		$this->s3trapWarnings();

		$profiling = new AmazonS3ProfilingAssist( "downloading $srcPath from S3" );
		$ok = copy( $srcPath, $dstPath );
		$profiling->log();

		$this->s3untrapWarnings();

		// Delayed "remove from cache" if this file doesn't need to be cached (e.g. too small)
		AmazonS3LocalCache::postDownloadLogic( $file );

		if ( !$ok ) {
			return null; // Couldn't download the file from S3 (e.g. network issue)
		}

		return $file;
	}

	/**
	 * Obtain local copies of files from Amazon S3.
	 * @param array $params
	 * @return array
	 *
	 * @phan-return array<FSFile|null>
	 */
	protected function doGetLocalCopyMulti( array $params ) {
		$fsFiles = [];
		$params += [
			'srcs' => $params['src'],
			'concurrency' => isset( $params['srcs'] ) ? count( $params['srcs'] ) : 1
		];
		foreach ( array_chunk( $params['srcs'], $params['concurrency'] ) as $pathBatch ) {
			foreach ( $pathBatch as $src ) {
				// TODO: remove this duplicate check, getFileHttpUrl() already checks this.
				list( $bucket, $key, ) = $this->getBucketAndObject( $src );
				if ( $bucket === null || $key === null ) {
					$fsFiles[$src] = null;
					continue;
				}

				$fsFiles[$src] = $this->getLocalCopyCached( $src );

				$this->logger->log(
					$fsFiles[$src] ? LogLevel::DEBUG : LogLevel::ERROR,
					'S3FileBackend: doGetLocalCopyMulti: {key} from S3 bucket ' .
					'{bucket} {result}: {dst}',
					[
						'result' => $fsFiles[$src] ? 'is stored locally' : 'couldn\'t be copied to',
						'key' => $key,
						'bucket' => $bucket,
						'dst' => $fsFiles[$src] ? $fsFiles[$src]->getPath() : null
					]
				);
			}
		}
		return $fsFiles;
	}

	/**
	 * Ensure that $container is usable. Calls doPublishInternal() and doSecureInternal().
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{noAccess?:bool,noListing?:bool,access?:bool,listing?:bool}
	 */
	protected function doPrepareInternal( $container, $dir, array $params ) {
		list( $bucket, $prefix ) = $this->findContainer( $container );
		$dir = $prefix . $dir;

		$this->logger->debug(
			'S3FileBackend: doPrepareInternal: S3 bucket {bucket}, dir={dir}, params={params}',
			[
				'bucket' => $bucket,
				'dir' => $dir,

				// String, e.g. 'noAccess, noListing'
				'params' => implode( ', ', array_keys( array_filter( $params ) ) )
			]
		);

		$params += [
			'access' => empty( $params['noAccess'] ),
			'listing' => empty( $params['noListing'] )
		];

		$status = Status::newGood();

		$status->merge( $this->doPublishInternal( $container, $dir, $params ) );
		$status->merge( $this->doSecureInternal( $container, $dir, $params ) );

		return $status;
	}

	/**
	 * Does nothing. In other backends - deletes empty subdirectory $dir within the container.
	 * This operation is not applicable to S3, because its "subdirectories" are imaginary.
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 */
	protected function doCleanInternal( $container, $dir, array $params ) {
		return Status::newGood(); /* Nothing to do */
	}

	/**
	 * Mark this container as published if $params['access'] is set.
	 * Being "published" means that new S3 objects here can be downloaded from S3 by anyone.
	 * @note ACL of existing S3 objects is not changed (impractical, not needed for 99,9% wikis).
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{access?:bool}
	 */
	protected function doPublishInternal( $container, $dir, array $params ) {
		if ( !empty( $params['access'] ) && $this->isSecure( $container ) ) {
			// Container is currently secure, but $params say that it should be public.
			list( $bucket, $restrictFile ) = $this->getRestrictFilePath( $container );

			$this->logger->debug(
				'S3FileBackend: doPublishInternal: deleting {file} from S3 bucket {bucket}',
				[
					'bucket' => $bucket,
					'file' => $restrictFile
				]
			);

			$ret = $this->runWithExceptionHandling( __FUNCTION__, function ()
				use ( $bucket, $restrictFile ) {
				return $this->client->deleteObject( [
					'Bucket' => $bucket,
					'Key'    => $restrictFile
				] );
			} );
			if ( $ret instanceof S3Exception ) {
				return Status::newFatal( 'backend-fail-internal', $this->name );
			}

			$this->updateSecurityCache( $container, false );
		}

		return Status::newGood();
	}

	/**
	 * Mark this container as secure if $params['noAccess'] is set.
	 * Being "secure" means that new S3 objects here shouldn't be downloadable by general public.
	 * @note ACL of existing S3 objects is not changed (impractical, not needed for 99,9% wikis).
	 * @param string $container
	 * @param string $dir
	 * @param array $params
	 * @return Status
	 *
	 * @phan-param $params array{noAccess?:bool}
	 */
	protected function doSecureInternal( $container, $dir, array $params ) {
		if ( !empty( $params['noAccess'] ) && !$this->isSecure( $container ) ) {
			// Container is currently public, but $params say that it should be secure.
			list( $bucket, $restrictFile ) = $this->getRestrictFilePath( $container );

			$this->logger->debug(
				'S3FileBackend: doSecureInternal: creating {file} in S3 bucket {bucket}',
				[
					'bucket' => $bucket,
					'file' => $restrictFile
				]
			);

			$ret = $this->runWithExceptionHandling( __FUNCTION__, function ()
				use ( $bucket, $restrictFile ) {
				return $this->client->putObject( [
					'Bucket' => $bucket,
					'Key'    => $restrictFile,
					'Body'   => '' /* Empty file */
				] );
			} );
			if ( $ret instanceof S3Exception ) {
				return Status::newFatal( 'backend-fail-internal', $this->name );
			}

			$this->updateSecurityCache( $container, true );
		}

		return Status::newGood();
	}

	/**
	 * Get cache key used for isSecure() and updateSecurityCache().
	 * @param string $container
	 * @return string
	 */
	private function getCacheKey( $container ) {
		return $this->containerSecurityCache->makeKey(
			'S3FileBackend', 'containerSecurity', $container );
	}

	/**
	 * Store the current security level of $container in containerSecurityCache.
	 * @param string $container
	 * @param bool $isSecure True if secure, false if public.
	 */
	private function updateSecurityCache( $container, $isSecure ) {
		$this->containerSecurityCache->set(
			$this->getCacheKey( $container ),
			$isSecure ? 'private' : 'public-read',
			604800 // 7 days. Changes in container security are exceptionally rare.
		);
	}

	/**
	 * Determine whether S3 objects in $container should be uploaded with "private" ACL.
	 * @param string $container
	 * @return bool
	 */
	private function isSecure( $container ) {
		if ( $this->privateWiki ) {
			// Private wiki: all containers are secure, even in "public" and "thumb" zones.
			return true;
		}

		$security = $this->containerSecurityCache->get( $this->getCacheKey( $container ) );
		if ( $security !== false ) {
			// Found in cache.
			return ( $security !== 'public-read' );
		}

		// Not found in cache. Determine from S3: if ".htsecure" file is present, then secure.
		list( $bucket, $restrictFile ) = $this->getRestrictFilePath( $container );

		$this->logger->debug(
			'S3FileBackend: isSecure: checking the presence of {file} in S3 bucket {bucket}',
			[
				'bucket' => $bucket,
				'file' => $restrictFile
			]
		);

		// No need to use runWithExceptionHandling() for existence checks (see doGetFileStat())
		try {
			$isSecure = $this->client->doesObjectExist( $bucket, $restrictFile );
		} catch ( S3Exception $e ) {
			/* Assume insecure. Don't cache (this may be a temporary problem). */
			return false;
		}

		$this->updateSecurityCache( $container, $isSecure );
		return $isSecure;
	}

	/**
	 * @param string $caller For logging: __FUNCTION__ that called this runWithExceptionHandling().
	 * @param Closure $code Some function that calls S3 API methods.
	 * @return mixed|Aws\S3\Exception\S3Exception Return value of the operation (if successful).
	 */
	private function runWithExceptionHandling( $caller, Closure $code ) {
		$closure = $code->bindTo( $this );

		try {
			return $closure();
		} catch ( S3Exception $e ) {
			$this->logException( $e, $caller );

			$command = $e->getCommand();

			// If the exception was caused by nonexistent S3 bucket,
			// then create this bucket and repeat the operation.
			if ( $e->getAwsErrorCode() == 'NoSuchBucket' &&
				$command->getName() != 'CreateBucket' &&
				$command->hasParam( 'Bucket' )
			) {
				$params = $command->toArray();
				$bucket = $params['Bucket'];

				$this->logger->warning(
					'S3FileBackend: found non-existent S3 bucket {bucket}, going to create it',
					[
						'bucket' => $bucket
					]
				);

				try {
					$this->client->createBucket( [
						'ACL' => 'private', // No listing. Note: this doesn't affect ACL of objects
						'Bucket' => $bucket
					] );

					// @phan-suppress-next-line PhanUndeclaredFunctionInCallable <--- false positive
					$this->client->waitUntil( 'BucketExists', [ 'Bucket' => $bucket ] );
				} catch ( S3Exception $e ) {
					// Failed to create a bucket, so we can't continue.
					$this->logException( $e, $caller );
					return $e;
				}

				// Now that the bucket has been created, redo the code that failed.
				try {
					return $closure();
				} catch ( S3Exception $e ) {
					// Operation still failed for some other reason.
					$this->logException( $e, $caller );
					return $e;
				}
			}

			return $e;
		}
	}

	/**
	 * Record an unknown S3Exception in logs.
	 *
	 * @param Aws\S3\Exception\S3Exception $e Exception that was thrown
	 * @param string $caller Function in which the exception occurred
	 */
	private function logException( S3Exception $e, $caller ) {
		$errorMessage = $e->getMessage();
		$command = $e->getCommand();

		$this->logger->error(
			'S3FileBackend: exception {exception} in {func} from {commandName} ({commandParams})' .
			': {errorMessage}',
			[
				'exception' => $e->getAwsErrorCode(),
				'commandName' => $command->getName(),
				'commandParams' => FormatJson::encode( $command->toArray() ),
				'func' => $caller,
				'errorMessage' => $errorMessage
			]
		);

		// We don't emit "NoSuchBucket" warning in unit tests, because any PHP warning would
		// cause the test to fail, and "NoSuchBucket" is a normal (recoverable) error.
		if ( !( defined( 'MW_PHPUNIT_TEST' ) && $e->getAwsErrorCode() == 'NoSuchBucket' ) ) {
			trigger_error( "$caller: S3Exception: $errorMessage", E_USER_WARNING );
		}
	}

	/**
	 * Listen for E_WARNING errors
	 */
	protected function s3trapWarnings() {
		set_error_handler( [ $this, 's3handleWarning' ], E_WARNING );
	}

	/**
	 * Stop listening for E_WARNING errors
	 */
	protected function s3untrapWarnings() {
		restore_error_handler(); // restore previous handler
	}

	/**
	 * Replacement handler for set_error_handler(). Sends this error to MediaWiki logs.
	 * @param int $errno
	 * @param string $errstr
	 * @return true
	 */
	public function s3handleWarning( $errno, $errstr ) {
		$this->logger->error( $errstr );
		return true; // suppress from PHP handler
	}
}
