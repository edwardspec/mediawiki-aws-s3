<?php

/*
	AWS extension for MediaWiki.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.
*/

use Aws\Command;
use Aws\S3\Exception\S3Exception;

/**
 * @file
 * Fake implementation (mock) of S3Client class (for offline unit testing).
 *
 * NOTE: we only need methods/features that are used by AmazonS3FileBackend, nothing else.
 */
class AmazonS3ClientMock {
	const FAKE_HTTP403_URL = 'http.403';

	/**
	 * @var array
	 * Format: [ 'bucketName1' => [ 'objectName1' => Data1, ... ], ... ]
	 */
	public $fakeStorage = [];

	/**
	 * @param string $bucket
	 * @return bool
	 */
	public function doesBucketExist( $bucket ) {
		return isset( $this->fakeStorage[$bucket] );
	}

	/**
	 * @param array $opt
	 * @phan-param array{Bucket:string} $opt
	 */
	public function createBucket( array $opt ) {
		$bucket = $opt['Bucket'];
		$this->fakeStorage[$bucket] = [];
	}

	/**
	 * @param string $bucket
	 * @param string $key
	 * @return bool
	 */
	public function doesObjectExist( $bucket, $key ) {
		return isset( $this->fakeStorage[$bucket][$key] );
	}

	/**
	 * @param array $opt
	 * @phan-param array{Bucket:string,Key:string} $opt
	 */
	public function deleteObject( array $opt ) {
		$bucket = $opt['Bucket'];
		$key = $opt['Key'];

		unset( $this->fakeStorage[$bucket][$key] );
	}

	/**
	 * @param array $opt
	 * @phan-param array{Bucket:string,Key:string,Body:string|resource} $opt
	 */
	public function putObject( array $opt ) {
		$bucket = $opt['Bucket'];
		$key = $opt['Key'];
		$body = $opt['Body'];

		if ( is_resource( $body ) ) {
			$body = stream_get_contents( $body );
		}

		$this->fakeStorage[$bucket][$key] = array_filter( [
			'ACL' => isset( $opt['ACL'] ) ? $opt['ACL'] : 'private',
			'Body' => $body,
			'ContentType' => isset( $opt['ContentType'] ) ? $opt['ContentType'] : null,
			'Metadata' => isset( $opt['Metadata'] ) ? $opt['Metadata'] : null,
			'LastModified' => wfTimestamp( TS_RFC2822 )
		] );
	}

	// phpcs:disable Generic.Files.LineLength.TooLong

	/**
	 * @param array $opt
	 * @phan-param array{CopySource:string,ACL:string,Bucket:string,Key:string,MetadataDirective:string} $opt
	 */
	public function copyObject( array $opt ) {
		// phpcs:enable Generic.Files.LineLength.TooLong

		// Obtain the original object
		$srcParts = explode( '/', $opt['CopySource'] );
		$srcBucket = array_shift( $srcParts );
		$srcKey = implode( '/', $srcParts );

		$data = $this->fakeStorage[$srcBucket][$srcKey];
		$data['ACL'] = $opt['ACL']; // ACL of the original object is ignored

		// Create a new object
		$bucket = $opt['Bucket'];
		$key = $opt['Key'];
		$this->fakeStorage[$bucket][$key] = $data;

		// Assert that AmazonS3FileBackend uses this method correctly
		if ( $opt['MetadataDirective'] != 'COPY' ) {
			throw new MWException( 'copyObject() must use MetadataDirective=COPY.' );
		}
	}

	/**
	 * @param array $opt
	 * @return array
	 *
	 * @phan-param array{Bucket:string,Key:string} $opt
	 */
	public function headObject( array $opt ) {
		$bucket = $opt['Bucket'];
		$key = $opt['Key'];

		if ( !isset( $this->fakeStorage[$bucket][$key] ) ) {
			throw new S3Exception( '', new Command( 'mockCommand' ), [ 'error' => 'NotFound' ] );
		}

		$data = $this->fakeStorage[$bucket][$key];

		return $data + [
			'ContentLength' => strlen( $data['Body'] ),
			'Etag' => ''
		];
	}

	/**
	 * @param string $name
	 * @param array $opt
	 * @return object
	 *
	 * @phan-param array{Bucket:string,Prefix:string,Delimiter?:string,Limit?:int} $opt
	 */
	public function getPaginator( $name, array $opt ) {
		if ( $name != 'ListObjects' ) {
			throw new MWException( 'Only the ListObjects paginator is implemented in this mock.' );
		}

		return new class( $this, $opt ) {
			/**
			 * @var AmazonS3ClientMock
			 */
			protected $clientMock;

			/**
			 * @var array
			 */
			protected $params;

			/**
			 * @param AmazonS3ClientMock $clientMock
			 * @param array $params
			 *
			 * @phan-param array{Bucket:string,Prefix:string,Delimiter?:string,Limit?:int} $params
			 */
			public function __construct( AmazonS3ClientMock $clientMock, $params ) {
				$this->clientMock = $clientMock;
				$this->params = $params;
			}

			/**
			 * @param string $query
			 * @return Iterator
			 */
			public function search( $query ) {
				$bucket = $this->params['Bucket'];
				$prefix = $this->params['Prefix'];
				$delim = $this->params['Delimiter'];

				$results = [];
				$seenPrefixes = []; // [ CommonPrefix1 => true, ... ] - to avoid duplicates

				foreach ( $this->clientMock->fakeStorage[$bucket] as $key => $data ) {
					if ( strpos( $key, $prefix ) !== 0 ) {
						continue;
					}

					if ( $delim ) {
						$unprefixedKey = substr( $key, strlen( $prefix ) );
						$keyComponents = explode( $delim, $unprefixedKey );

						// If there is only one key component, then $key is an actual S3 object name.
						// If there are many components, we have something like [ dir1, dir2, file.txt ],
						// where we need to add "dir1/" into the CommonPrefixes (assuming $delim="/").
						if ( count( $keyComponents ) > 1 ) {
							if ( $query == 'CommonPrefixes[].Prefix' ) {
								$commonPrefix = $prefix . $keyComponents[0] . $delim;
								if ( !isset( $seenPrefixes[$commonPrefix] ) ) {
									$results[] = $commonPrefix;
									$seenPrefixes[$commonPrefix] = true;
								}
							}
							continue;
						}
					}

					if ( $query == 'Contents[].Key' ) {
						$results[] = $key;
					} elseif ( $query == 'Contents' ) {
						// This is an incomplet record, but AmazonS3FileBackend only uses it
						// to check "does directory exists?". It doesn't actually inspect the contents.
						$results[] = [ 'Key' => $key ];
					} elseif ( $query != 'CommonPrefixes[].Prefix' ) {
						throw new MWException( 'This paginator query is not implemented in this mock' );
					}
				}

				if ( isset( $this->params['Limit'] ) ) {
					$results = array_slice( $results, 0, $this->params['Limit'] );
				}

				return new ArrayIterator( $results );
			}
		};
	}

	/**
	 * @param string $name
	 * @param array $opt
	 * @return array
	 *
	 * @phan-return array{0:string,1:array}
	 */
	public function getCommand( $name, array $opt ) {
		return [ $name, $opt ];
	}

	/**
	 * @param array $mockedCommand
	 * @param mixed $ttl
	 * @return object
	 */
	public function createPresignedRequest( array $mockedCommand, $ttl ) {
		// NOTE: this method doesn't accept a real Command object,
		// instead it accepts a fake command, as returned by mocked getCommand().
		list( $name, $opt ) = $mockedCommand;

		$bucket = $opt['Bucket'];
		$key = $opt['Key'];
		$data = $this->fakeStorage[$bucket][$key];

		// Create a temporary file with contents of this object
		$ext = FSFile::extensionFromPath( $key );
		$file = TempFSFile::factory( 'clientmock_', $ext );

		$file->preserve(); // FIXME: without this, file is deleted before getUri()

		$path = $file->getPath();
		file_put_contents( $path, $data['Body'] );

		return new class( $path ) {
			/**
			 * @var string
			 */
			protected $uri;

			/**
			 * @param string $uri
			 */
			public function __construct( $uri ) {
				$this->uri = $uri;
			}

			/**
			 * @return string
			 */
			public function getUri() {
				return $this->uri;
			}
		};
	}

	/**
	 * @param string $bucket
	 * @param string $key
	 * @return string
	 */
	public function getObjectUrl( $bucket, $key ) {
		// NOTE: this function is not used by AmazonS3FileBackend itself,
		// but it's needed for AmazonS3FileBackendTest::testSecureAndPublish().
		$data = $this->fakeStorage[$bucket][$key];

		if ( $data['ACL'] != 'public-read' ) {
			// This object is not public, so download of non-presigned URL must fail.
			return self::FAKE_HTTP403_URL;
		}

		return $this->createPresignedRequest( [ 'GetCommand', [
			'Bucket' => $bucket,
			'Key' => $key
		] ], '+1 day' )->getUri();
	}

	/**
	 * @param string $name
	 * @param array $opt
	 * @return object
	 */
	public function getWaiter( $name, array $opt ) {
		return new class {
			public function promise() {
				return new class {
					public function wait() {
						// No need to wait: this mock is synchoronous.
					}
				};
			}
		};
	}

	/**
	 * @param string $name
	 * @param array $opt
	 */
	public function waitUntil( $name, array $opt ) {
		// No need to wait: this mock is synchoronous.
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public function encodeKey( $string ) {
		// Same as in the normal S3Client class
		return str_replace( '%2F', '/', rawurlencode( $string ) );
	}

	/**
	 * Version of Http::get() that supports local file URLs (as provided by AmazonS3ClientMock).
	 * @param string $fakeUrl
	 * @return string|false
	 */
	public function fakeHttpGet( $fakeUrl ) {
		if ( $fakeUrl == self::FAKE_HTTP403_URL ) {
			return false;
		}

		return file_get_contents( $fakeUrl );
	}
}
