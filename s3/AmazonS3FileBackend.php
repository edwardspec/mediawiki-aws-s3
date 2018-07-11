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

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Exception\NoSuchBucketException;
use Aws\S3\Exception\NoSuchKeyException;
use Aws\S3\Exception\S3Exception;
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

	private $containerPaths;

	/**
	 * Presence of this file in the bucket means that this bucket is used
	 * for private zone (e.g. 'deleted'), meaning PRIVATE_ACCESS should be
	 * used in putObject() and CopyObject() into this bucket.
	 * See isSecure() below.
	 */
	const RESTRICT_FILE = '.htsecure';

	/**
	 * Temporary cache used in isSecure()/setSecure().
	 * Avoids extra request to doesObjectExist(), unless MediaWiki core
	 * has forgotten to call prepare() before storing/copying a file.
	 * @var array(bucket_name => true/false, ...)
	 */
	private $isBucketSecure = [];

	/**
	 * @var bool If true, then all S3 objects are private.
	 * NOTE: for images to work in private mode, $wgUploadPath should point to img_auth.php.
	*/
	protected $privateWiki = null;

	/** @var LoggerInterface. B/C for MediaWiki 1.27 (already defined in FileBackend class) */
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
	function __construct( array $config ) {
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

		// Cache container information to mask latency
		if ( isset( $config['wanCache'] ) && $config['wanCache'] instanceof WANObjectCache ) {
			$this->memCache = $config['wanCache'];
		}

		$this->client = S3Client::factory( [
			'key' => isset( $config['awsKey'] ) ? $config['awsKey'] : $wgAWSCredentials['key'],
			'secret' => isset( $config['awsSecret'] ) ? $config['awsSecret'] : $wgAWSCredentials['secret'],
			'token' => isset( $config['awsToken'] ) ? $config['awsToken'] : $wgAWSCredentials['token'],
			'region' => isset( $config['awsRegion'] ) ? $config['awsRegion'] : $wgAWSRegion,
			'scheme' => $this->useHTTPS ? 'https' : 'http',
			'ssl.certificate_authority' => $this->useHTTPS ?: null
		] );

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
			$this->privateWiki = !( User::isEveryoneAllowed( 'read' ) );
		}

		if ( !$this->logger ) {
			// B/C with MediaWiki 1.27.
			// Modern MediaWiki creates a logger in parent::__construct().
			$this->logger = MediaWiki\Logger\LoggerFactory::getInstance( 'FileOperation' );
		}

		$this->logger->info(
			'S3FileBackend: found backend with S3 buckets: {buckets}.{isPrivateWiki}',
			[
				'buckets' => join( ', ', array_values( $config['containerPaths'] ) ),
				'isPrivateWiki' => $this->privateWiki ?
					' (private wiki, new S3 objects will be private)' : ''
			]
		);
	}

	function directoriesAreVirtual() {
		return true;
	}

	function isPathUsableInternal( $storagePath ) {
		list( $container, $rel ) = $this->resolveStoragePathReal( $storagePath );
		return $container !== null && $this->client->doesBucketExist( $container );
	}

	function resolveContainerName( $container ) {
		if (
			isset( $this->containerPaths[$container] ) &&
			$this->client->isValidBucketName( $this->containerPaths[$container] )
		) {
			return $this->containerPaths[$container];
		} else {
			return null;
		}
	}

	function resolveContainerPath( $container, $relStoragePath ) {
		if ( strlen( urlencode( $relStoragePath ) ) <= 1024 ) {
			return $relStoragePath;
		} else {
			return null;
		}
	}

	function doCreateInternal( array $params ) {
		$status = Status::newGood();

		list( $container, $key ) = $this->resolveStoragePathReal( $params['dst'] );
		if ( $container === null || $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
			return $status;
		}

		if ( is_resource( $params['content'] ) ) {
			$sha1Hash = Wikimedia\base_convert( sha1_file( $params['src'] ), 16, 36, 31, true, 'auto' );
		} else {
			$sha1Hash = Wikimedia\base_convert( sha1( $params['content'] ), 16, 36, 31, true, 'auto' );
		}

		$params['headers'] = isset( $params['headers'] ) ? $params['headers'] : [];
		$params['headers'] += array_fill_keys( [
			'Cache-Control',
			'Content-Disposition',
			'Content-Encoding',
			'Content-Language',
			'Expires'
		], null );

		if ( !isset( $params['headers']['Content-Type'] ) ) {
			$params['headers']['Content-Type'] =
				$this->getContentType( $params['dst'], $params['content'], null );
		}

		$this->logger->debug(
			'S3FileBackend: doCreateInternal(): saving {key} in S3 bucket {container} ' .
			'(sha1 of the original file: {sha1})',
			[
				'container' => $container,
				'key' => $key,
				'sha1' => $sha1Hash
			]
		);

		try {
			$res = $this->client->putObject( [
				'ACL' => $this->isSecure( $container ) ? CannedAcl::PRIVATE_ACCESS : CannedAcl::PUBLIC_READ,
				'Body' => $params['content'],
				'Bucket' => $container,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $params['headers']['Content-Type'],
				'Expires' => $params['headers']['Expires'],
				'Key' => $key,
				'Metadata' => [ 'sha1base36' => $sha1Hash ],
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null,
			] );
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-create', $params['dst'] );
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doStoreInternal( array $params ) {
		$params['content'] = fopen( $params['src'], 'r' );
		return $this->doCreateInternal( $params );
	}

	function doCopyInternal( array $params ) {
		$status = Status::newGood();

		list( $srcContainer, $srcKey ) = $this->resolveStoragePathReal( $params['src'] );
		list( $dstContainer, $dstKey ) = $this->resolveStoragePathReal( $params['dst'] );
		if ( $srcContainer === null || $srcKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
		}
		if ( $dstContainer === null || $dstKey == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['dst'] );
		}

		if ( !$status->isOK() ) {
			return $status;
		}

		$this->logger->debug(
			'S3FileBackend: doCopyInternal(): copying {srcKey} from S3 bucket {srcContainer} ' .
			'to {dstKey} from S3 bucket {dstContainer}.',
			[
				'dstKey' => $dstKey,
				'dstContainer' => $dstContainer,
				'srcKey' => $srcKey,
				'srcContainer' => $srcContainer
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

		try {
			$res = $this->client->copyObject( array_filter( [
				'ACL' => $this->isSecure( $dstContainer ) ? CannedAcl::PRIVATE_ACCESS : CannedAcl::PUBLIC_READ,
				'Bucket' => $dstContainer,
				'CacheControl' => $params['headers']['Cache-Control'],
				'ContentDisposition' => $params['headers']['Content-Disposition'],
				'ContentEncoding' => $params['headers']['Content-Encoding'],
				'ContentLanguage' => $params['headers']['Content-Language'],
				'ContentType' => $params['headers']['Content-Type'],
				'CopySource' => $srcContainer . '/' . $this->client->encodeKey( $srcKey ),
				'CopySourceIfMatch' => $params['headers']['E-Tag'],
				'CopySourceIfModifiedSince' => $params['headers']['If-Modified-Since'],
				'Expires' => $params['headers']['Expires'],
				'Key' => $dstKey,
				'MetadataDirective' => 'COPY',
				'ServerSideEncryption' => $this->encryption ? 'AES256' : null
			] ) );
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
		} catch ( NoSuchKeyException $e ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-copy', $params['src'], $params['dst'] );
			}
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doDeleteInternal( array $params ) {
		$status = Status::newGood();

		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );
		if ( $container === null || $key == null ) {
			$status->fatal( 'backend-fail-invalidpath', $params['src'] );
			return $status;
		}

		$this->logger->debug(
			'S3FileBackend: doDeleteInternal(): deleting {key} from S3 bucket {container}',
			[
				'key' => $key,
				'container' => $container
			]
		);

		try {
			$this->client->deleteObject( [
				'Bucket' => $container,
				'Key' => $key
			] );
		} catch ( NoSuchBucketException $e ) {
			$status->fatal( 'backend-fail-delete', $params['src'] );
		} catch ( NoSuchKeyException $e ) {
			if ( empty( $params['ignoreMissingSource'] ) ) {
				$status->fatal( 'backend-fail-delete', $params['src'] );
			}
		} catch ( S3Exception $e ) {
			$this->handleException( $e, $status, __METHOD__, $params );
		}

		return $status;
	}

	function doDirectoryExists( $container, $dir, array $params ) {
		// See if at least one file is in the directory.
		$it = new AmazonS3FileIterator( $this->client, $container, $dir, [], 1 );
		return $it->valid();
	}

	function doGetFileStat( array $params ) {
		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );

		$this->logger->debug(
			'S3FileBackend: doGetFileStat(): obtaining information about {key} in S3 bucket {container}',
			[
				'key' => $key,
				'container' => $container
			]
		);

		if ( $container === null || $key == null ) {
			return null;
		} elseif ( !$this->client->doesBucketExist( $container ) ) {
			return false;
		} elseif ( !$this->client->doesObjectExist( $container, $key ) ) {
			return false;
		}

		try {
			$res = $this->client->headObject( [
				'Bucket' => $container,
				'Key' => $key
			] );
		} catch ( S3Exception $e ) {
			$this->handleException( $e, null, __METHOD__, $params );
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

	function getFileHttpUrl( array $params ) {
		list( $container, $key ) = $this->resolveStoragePathReal( $params['src'] );
		if ( $container === null ) {
			return null;
		}

		$this->logger->debug(
			'S3FileBackend: getFileHttpUrl(): obtaining presigned S3 URL of {key} in S3 bucket {container}',
			[
				'key' => $key,
				'container' => $container
			]
		);

		try {
			$request = $this->client->get( "$container/$key" );
			return $this->client->createPresignedUrl( $request, '+1 day' );
		} catch ( S3Exception $e ) {
			return null;
		}
	}

	function getDirectoryListInternal( $container, $dir, array $params ) {
		return new AmazonS3DirectoryIterator( $this->client, $container, $dir, $params );
	}

	function getFileListInternal( $container, $dir, array $params ) {
		return new AmazonS3FileIterator( $this->client, $container, $dir, $params );
	}

	function doGetLocalCopyMulti( array $params ) {
		$fsFiles = [];
		$params += [
			'srcs' => $params['src'],
			'concurrency' => isset( $params['srcs'] ) ? count( $params['srcs'] ) : 1
		];
		foreach ( array_chunk( $params['srcs'], $params['concurrency'] ) as $pathBatch ) {
			foreach ( $pathBatch as $src ) {
				list( $container, $key ) = $this->resolveStoragePathReal( $src );
				if ( $container === null || $key === null ) {
					$fsFiles[$src] = null;
					continue;
				}

				$ext = self::extensionFromPath( $src );
				$tmpFile = TempFSFile::factory( 'localcopy_', $ext );
				if ( !$tmpFile ) {
					$fsFiles[$src] = null;
					continue;
				}

				$srcPath = $this->getFileHttpUrl( [ 'src' => $src ] );
				$dstPath = $tmpFile->getPath();
				if ( !$srcPath ) {
					$fsFiles[$src] = null;
					continue;
				}

				wfSuppressWarnings();
				$ok = copy( $srcPath, $dstPath );
				wfRestoreWarnings();

				$this->logger->log(
					$ok ? LogLevel::DEBUG : LogLevel::ERROR,
					'S3FileBackend: doGetLocalCopyMulti: {result} {key} from S3 bucket ' .
					'{container} (presigned S3 URL: {src}) to temporary file {dst}',
					[
						'result' => $ok ? 'copied' : 'failed to copy',
						'key' => $key,
						'container' => $container,
						'src' => $srcPath,
						'dst' => $dstPath
					]
				);

				$fsFiles[$src] = $ok ? $tmpFile : null;
			}
		}
		return $fsFiles;
	}

	function doPrepareInternal( $container, $dir, array $params ) {
		$status = Status::newGood();

		$this->logger->debug(
			'S3FileBackend: doPrepareInternal: S3 bucket {container}, dir={dir}, params={params}',
			[
				'container' => $container,
				'dir' => $dir,

				// String, e.g. 'noAccess, noListing'
				'params' => implode( ', ', array_keys( array_filter( $params ) ) )
			]
		);

		if ( !$this->client->doesBucketExist( $container ) ) {
			$this->logger->warning(
				'S3FileBackend: doPrepareInternal: found non-existent S3 bucket {container}, ' .
				'going to create it',
				[
					'container' => $container
				]
			);

			try {
				$res = $this->client->createBucket( [
					'ACL' => isset( $params['noListing'] ) ? CannedAcl::PRIVATE_ACCESS : CannedAcl::PUBLIC_READ,
					'Bucket' => $container
				] );
			} catch ( S3Exception $e ) {
				$this->handleException( $e, $status, __METHOD__, $params );
			}
		}

		if ( !$status->isOK() ) {
			return $status;
		}

		$this->client->waitUntilBucketExists( [ 'Bucket' => $container ] );

		$this->logger->debug(
			'S3FileBackend: doPrepareInternal: S3 bucket {container} exists',
			[
				'container' => $container
			]
		);

		$params += [
			'access' => empty( $params['noAccess'] ),
			'listing' => empty( $params['noListing'] )
		];

		$status->merge( $this->doPublishInternal( $container, $dir, $params ) );
		$status->merge( $this->doSecureInternal( $container, $dir, $params ) );

		return $status;
	}

	function doCleanInternal( $container, $dir, array $params ) {
		return Status::newGood(); /* Nothing to do */
	}

	function doPublishInternal( $container, $dir, array $params ) {
		$status = Status::newGood();

		if ( !empty( $params['access'] ) ) {
			$this->logger->debug(
				'S3FileBackend: doPublishInternal: deleting {file} from S3 bucket {container}',
				[
					'container' => $container,
					'file' => self::RESTRICT_FILE
				]
			);

			try {
				$res = $this->client->deleteObject( [
					'Bucket' => $container,
					'Key'    => self::RESTRICT_FILE
				] );
			} catch ( S3Exception $e ) {
				$this->handleException( $e, $status, __METHOD__, $params );
			}

			$this->isBucketSecure[$container] = false;
		}

		return $status;
	}

	function doSecureInternal( $container, $dir, array $params ) {
		$status = Status::newGood();

		if ( !empty( $params['noAccess'] ) ) {
			$this->logger->debug(
				'S3FileBackend: doSecureInternal: creating {file} in S3 bucket {container}',
				[
					'container' => $container,
					'file' => self::RESTRICT_FILE
				]
			);

			try {
				$res = $this->client->putObject( [
					'Bucket' => $container,
					'Key'    => self::RESTRICT_FILE,
					'Body'   => '' /* Empty file */
				] );
			} catch ( S3Exception $e ) {
				$this->handleException( $e, $status, __METHOD__, $params );
			}

			$this->isBucketSecure[$container] = true;
		}

		return $status;
	}

	private function isSecure( $container ) {
		if ( $this->privateWiki ) {
			return true; /* Private wiki: all buckets are secure, even in "public" and "thumb" zones */
		}

		if ( array_key_exists( $container, $this->isBucketSecure ) ) {
			/* We've just secured/published this very bucket */
			return $this->isBucketSecure[$container];
		}

		$this->logger->debug(
			'S3FileBackend: isSecure: checking the presence of {file} in S3 bucket {container}',
			[
				'container' => $container,
				'file' => self::RESTRICT_FILE
			]
		);

		try {
			$is_secure = $this->client->doesObjectExist( $container,
				self::RESTRICT_FILE );
		} catch ( S3Exception $e ) {
			/* Assume insecure */
			return false;
		}

		$this->isBucketSecure[$container] = $is_secure;
		return $is_secure;
	}

	/**
	 * Handle an unknown S3Exception by adjusting the status and triggering an error.
	 *
	 * @param Aws\S3\Exception\S3Exception $e Exception that was thrown
	 * @param Status $status Status object for the operation
	 * @param string $func Function in which the exception occurred
	 * @param array $params Params passed to the function
	 */
	private function handleException( S3Exception $e, Status $status, $func, array $params ) {
		$status->fatal( 'backend-fail-internal', $this->name );
		if ( $e->getMessage() ) {
			trigger_error( "$func : {$e->getMessage()}", E_USER_WARNING );
		}

		$this->logger->error(
			'S3FileBackend: exception {exception} in {func}({params}): $errorMessage',
			[
				'exception' => get_class( $e ),
				'func' => $func,
				'params' => $params,
				'errorMessage' => $e->getMessage() ?: ""
			]
		);
	}
}
