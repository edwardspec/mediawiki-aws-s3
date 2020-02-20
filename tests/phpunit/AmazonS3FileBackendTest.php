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

/**
 * @file
 * Integration test for AmazonS3FileBackend.
 */

use Wikimedia\TestingAccessWrapper;

/**
 * @group FileRepo
 * @group FileBackend
 * @group medium
 */
class AmazonS3FileBackendTest extends MediaWikiTestCase {
	/** @var TestingAccessWrapper Proxy to AmazonS3FileBackend */
	private static $backend;

	public static function setUpBeforeClass() {
		global $wgFileBackends;
		if ( getenv( 'USE_MOCK' ) ) {
			// Point to a local Moto server (AWS-mocking daemon)
			$wgFileBackends['s3']['endpoint'] = 'http://127.0.0.1:3000';
			FileBackendGroup::destroySingleton();
		}

		self::$backend = TestingAccessWrapper::newFromObject(
			FileBackendGroup::singleton()->get( 'AmazonS3' )
		);
	}

	/**
	 * Get AmazonS3FileBackend object.
	 * @return TestingAccessWrapper
	 */
	public function getBackend() {
		return self::$backend;
	}

	/**
	 * Get S3 client object.
	 * @return Aws\S3\S3Client
	 */
	public function getClient() {
		return $this->getBackend()->client;
	}

	/**
	 * Translate "Hello/world.txt" to mw:// pseudo-URL.
	 * @param string $filename
	 */
	private function getVirtualPath( $filename ) {
		$repo = RepoGroup::singleton()->getLocalRepo();
		return $repo->getZonePath( getenv( 'AWS_S3_TEST_ZONE' ) ?: 'public' ) . '/' .
			$repo->newFile( $filename )->getRel();
	}

	/**
	 * Check that doPrepareInternal() successfully creates an S3 bucket (unless it already exists).
	 * @covers AmazonS3FileBackend::doPrepareInternal
	 */
	public function testPrepareInternal() {
		list( $container, ) = $this->getBackend()->resolveStoragePathReal(
			$this->getVirtualPath( 'Hello/World.png' ) );
		list( , $prefix ) = $this->getBackend()->findContainer( $container );

		$status = $this->getBackend()->doPrepareInternal( $container, $prefix, [] );
		$this->assertTrue( $status->isGood(), 'doPrepareInternal() failed' );
	}

	/**
	 * Check that doCreateInternal() succeeds.
	 * @covers AmazonS3FileBackend::doCreateInternal
	 */
	public function testCreate() {
		$params = [
			'content' => 'hello',
			'headers' => [],
			'directory' => 'Hello',
			'filename' => 'world.txt',
		];
		$params['fullfilename'] = $params['directory'] . '/' . $params['filename'];
		$params['dst'] = $this->getVirtualPath( $params['fullfilename'] );
		list( $params['container'], ) = $this->getBackend()->resolveStoragePathReal( $params['dst'] );

		$status = $this->getBackend()->doCreateInternal( [
			'content' => $params['content'],
			'headers' => $params['headers'],
			'dst' => $params['dst']
		] );
		$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );

		/* Pass $params to dependent test */
		return $params;
	}

	/**
	 * Check that doGetFileStat() returns correct information about the file.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doGetFileStat
	 */
	public function testGetFileStat( array $params ) {
		$info = $this->getBackend()->doGetFileStat( [ 'src' => $params['dst'] ] );

		$this->assertEquals( $info['size'], strlen( $params['content'] ),
			'GetFileStat(): incorrect filesize after doCreateInternal()' );

		$expectedSHA1 = Wikimedia\base_convert( sha1( $params['content'] ), 16, 36, 31 );
		$this->assertEquals( $expectedSHA1, $info['sha1'],
			'GetFileStat(): incorrect SHA1 after doCreateInternal()' );
	}

	/**
	 * Check that the file can be downloaded via getFileHttpUrl().
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::getFileHttpUrl
	 */
	public function testFileHttpUrl( array $params ) {
		$url = $this->getBackend()->getFileHttpUrl( [ 'src' => $params['dst'] ] );
		$this->assertNotNull( $url, 'No URL returned by getFileHttpUrl()' );

		$content = Http::get( $url );
		$this->assertEquals( $params['content'], $content,
			'Content downloaded from FileHttpUrl is different from expected' );
	}

	protected function getTestContent( $filename ) {
		return 'Content of [' . $filename . '].';
	}

	/**
	 * Create test pages for testList().
	 * @return array [ 'parentDirectory' => 'dirname', 'container' => 'container-name' ]
	 */
	protected function prepareListTest() {
		static $testinfo = null;
		if ( $testinfo !== null ) {
			return $testinfo;
		}

		$parentDirectory = 'ListTest';
		$filenames = $this->getFilenamesForListTest();

		foreach ( $filenames as $filename ) {
			$status = $this->getBackend()->doCreateInternal( [
				'dst' => $this->getVirtualPath( $parentDirectory . '/' . $filename ),
				'content' => $this->getTestContent( $filename )
			] );
			$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );
		}

		list( $container, ) = $this->getBackend()->resolveStoragePathReal(
			$this->getVirtualPath( $parentDirectory . '/' . $filenames[0] )
		);

		$testinfo = [
			'container' => $container,
			'parentDirectory' => $parentDirectory
		];
		return $testinfo;
	}

	/**
	 * List of files that must be created before testList().
	 * @see listingTestsDataProvider
	 * @see testList
	 */
	public function getFilenamesForListTest() {
		return [
			'dir1/file1.txt',
			'dir1/file2.txt',
			'dir1/file3.txt',
			'dir1/subdir1/file1-1-1.txt',
			'dir1/subdir1/file1-1-2.txt',
			'dir1/subdir2/file1-2-1.txt',
			'dir2/file1.txt',
			'dir2/file2.txt',
			'dir2/subdir1/file2-1-1.txt',
			'dir2/file3.txt',
			'file1_in_topdir.txt',
			'file2_in_topdir.txt'
		];
	}

	/**
	 * Provides datasets for testList().
	 */
	public function listingTestsDataProvider() {
		return [
			[ 'doDirectoryExists', '', [], true ],
			[ 'doDirectoryExists', 'dir1', [], true ],
			[ 'doDirectoryExists', 'dir1/', [], true ],
			[ 'doDirectoryExists', 'dir2', [], true ],
			[ 'doDirectoryExists', 'dir2/', [], true ],
			[ 'doDirectoryExists', 'dir1/subdir1', [], true ],
			[ 'doDirectoryExists', 'dir2/subdir1', [], true ],
			[ 'doDirectoryExists', 'dir1/file2.txt', [], false ],
			[ 'doDirectoryExists', 'WeNeverCreatedFilesWithThisPrefix', [], false ],
			[ 'getDirectoryListInternal', '', [],
				[ 'dir1', 'dir1/subdir1', 'dir1/subdir2', 'dir2', 'dir2/subdir1' ] ],
			[ 'getDirectoryListInternal', '', [ 'topOnly' => true ], [ 'dir1', 'dir2' ] ],
			[ 'getDirectoryListInternal', 'dir1', [], [ 'subdir1', 'subdir2' ] ],
			[ 'getDirectoryListInternal', 'dir2', [], [ 'subdir1' ] ],
			[ 'getDirectoryListInternal', 'dir1/file2.txt', [], [] ],
			[ 'getFileListInternal', '', [], $this->getFilenamesForListTest() ],
			[ 'getFileListInternal', '', [ 'topOnly' => true ],
				[ 'file1_in_topdir.txt', 'file2_in_topdir.txt' ] ],
			[ 'getFileListInternal', 'dir1', [],
				[ 'file1.txt', 'file2.txt', 'file3.txt', 'subdir1/file1-1-1.txt',
					'subdir1/file1-1-2.txt', 'subdir2/file1-2-1.txt' ] ],
			[ 'getFileListInternal', 'dir1', [ 'topOnly' => true ],
				[ 'file1.txt', 'file2.txt', 'file3.txt' ] ],
			[ 'getFileListInternal', 'dir1/subdir1', [],
				[ 'file1-1-1.txt', 'file1-1-2.txt' ] ],
			[ 'getFileListInternal', 'dir1/subdir1', [ 'topOnly' => true ],
				[ 'file1-1-1.txt', 'file1-1-2.txt' ] ],
			[ 'getFileListInternal', 'dir2', [],
				[ 'file1.txt', 'file2.txt', 'subdir1/file2-1-1.txt', 'file3.txt' ] ],
			[ 'getFileListInternal', 'dir2', [ 'topOnly' => true ],
				[ 'file1.txt', 'file2.txt', 'file3.txt' ] ]
		];
	}

	/**
	 * Check that get*ListInternal() works as expected
	 * @dataProvider listingTestsDataProvider
	 * @covers AmazonS3FileBackend::getDirectoryListInternal
	 * @covers AmazonS3FileBackend::getFileListInternal
	 */
	public function testList( $method, $directory, $params, $expectedResult ) {
		$testinfo = $this->prepareListTest();

		$result = $this->getBackend()->$method(
			$testinfo['container'],
			$testinfo['parentDirectory'] . ( $directory == '' ? '' : "/$directory" ),
			$params
		);
		if ( $method == 'doDirectoryExists' ) {
			$this->assertEquals( $expectedResult, $result );
			return;
		}

		$foundFilenames = iterator_to_array( $result );

		sort( $expectedResult );
		sort( $foundFilenames );
		$this->assertEquals( $expectedResult, $foundFilenames,
			"Directory listing doesn't match expected."
		);
	}

	/**
	 * Check that doGetLocalCopyMulti() provides correct content.
	 * @covers AmazonS3FileBackend::doGetLocalCopyMulti
	 */
	public function testGetLocalCopyMulti() {
		$testinfo = $this->prepareListTest();
		$filenames = $this->getFilenamesForListTest();

		/* Make an array of VirtualPaths for 'src' parameter of doGetLocalCopyMulti() */
		$src = [];
		foreach ( $filenames as $filename ) {
			$src[$filename] = $this->getVirtualPath( $testinfo['parentDirectory'] . '/' . $filename );
		}

		$result = $this->getBackend()->doGetLocalCopyMulti( [
			'src' => array_values( $src )
		] );
		$this->assertCount( count( $filenames ), $result,
			'Incorrect number of elements returned by doGetLocalCopyMulti()' );

		foreach ( $src as $filename => $virtualPath ) {
			$this->assertArrayHasKey( $virtualPath, $result,
				"URL $virtualPath not found() in array returned by doGetLocalCopyMulti()" );
			$this->assertEquals(
				$this->getTestContent( $filename ),
				file_get_contents( $result[$virtualPath]->getPath() ),
				"Incorrect contents of $virtualPath returned by doGetLocalCopyMulti()"
			);
		}
	}

	/**
	 * Check that doCopyInternal() succeeds.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doCopyInternal
	 */
	public function testCopyInternal( array $params ) {
		$params['copy-filename'] = $params['fullfilename'] . '_new_' . rand();
		$params['copy-dst'] = $this->getVirtualPath( $params['copy-filename'] );

		$status = $this->getBackend()->doCopyInternal( [
			'src' => $params['dst'],
			'dst' => $params['copy-dst']
		] );
		$this->assertTrue( $status->isGood(), 'doCopyInternal() failed' );

		/* Pass $params to dependent test */
		return $params;
	}

	/**
	 * Check that doDeleteInternal() succeeds.
	 * @depends testCopyInternal
	 * @covers AmazonS3FileBackend::doDeleteInternal
	 */
	public function testDeleteInternal( array $params ) {
		$status = $this->getBackend()->doDeleteInternal( [
			'src' => $params['copy-dst']
		] );
		$this->assertTrue( $status->isGood(), 'doDeleteInternal() failed' );

		$info = $this->getBackend()->doGetFileStat( [ 'src' => $params['copy-dst'] ] );
		$this->assertFalse( $info,
			'doGetFileStat() says the file still exists after doDeleteInternal()' );
	}

	/**
	 * Check that doStoreInternal() succeeds.
	 * @covers AmazonS3FileBackend::doStoreInternal
	 *
	 * Pretty much the same checks as in testCreate().
	 */
	public function testStoreInternal() {
		$src = tempnam( wfTempDir(), 'testupload' );
		$dst = $this->getVirtualPath( 'Stored/File/1.txt' );

		$expectedContent = '-- whatever --';
		file_put_contents( $src, $expectedContent );

		$status = $this->getBackend()->doStoreInternal( [
			'src' => $src,
			'dst' => $dst
		] );
		$this->assertTrue( $status->isGood(), 'doStoreInternal() failed' );

		$url = $this->getBackend()->getFileHttpUrl( [ 'src' => $dst ] );
		$this->assertNotNull( $url, 'No URL returned by getFileHttpUrl()' );

		$content = Http::get( $url );
		$this->assertEquals( $expectedContent, $content,
			'Content downloaded from FileHttpUrl is different from expected' );
	}

	/**
	 * Check that doSecureInternal() and doPublishInternal() succeed.
	 * @covers AmazonS3FileBackend::doSecureInternal
	 * @covers AmazonS3FileBackend::doPublishInternal
	 */
	public function testSecureAndPublish() {
		$dst = $this->getVirtualPath( 'Stored/File/2.txt' );
		list( $container, $key ) = $this->getBackend()->resolveStoragePathReal( $dst );

		/* Order of these tests will be different, see below */
		$subtests = [
			'noopPublish' => [
				'doPublishInternal', // method
				[], // params. Note: call without 'access => true' does nothing
				true // Expected security after the test: still secure
			],
			'publish' => [ 'doPublishInternal', [ 'access' => true ], false ],
			'noopSecure' => [ 'doSecureInternal', [], false ],
			'secure' => [ 'doSecureInternal', [ 'noAccess' => true ], true ]
		];

		/*
			We don't want to create a new S3 bucket in tests because of "eventual consistency"
			of S3: the fact that CreateBucket and BucketExists returned success doesn't
			guarantee that all of S3 servers already know that this newly created bucket exists.

			With existing buckets, the starting state (is secure? Yes/No) can be different.

			Luckily, we have Publish test for "Yes" and Secure test for "No",
			and they (if successful) switch "Yes" to "No" (and back).
			So we run them in different order, depending on the starting state.
		*/
		$isSecure = $this->getBackend()->isSecure( $container );

		$orderOfTests = [ 'noopSecure', 'secure', 'noopPublish', 'publish' ];
		if ( $isSecure ) {
			$orderOfTests = [ 'noopPublish', 'publish', 'noopSecure', 'secure' ];
		}

		foreach ( $orderOfTests as $subtestName ) {
			// Delete cache, so that it won't affect this subtest
			$this->clearSecurityCache( $container );

			list( $method, $params, $expectedSecurity ) = $subtests[$subtestName];
			$this->getBackend()->$method( $container, 'unused', $params );

			// Delete cache, so that doCreateInternal would actually recheck security,
			// not trust the cache that was just populated by $method().
			$this->clearSecurityCache( $container );

			// DeleteObject (used by doPublishInternal) is an "eventually consistent" S3 operation,
			// and doPublishInternal() is not a blocking operation (doesn't need to be),
			// so let's wait until it actually gets completed before we continue the test.
			if ( $expectedSecurity === false ) {
				$restrictPath = $this->getBackend()->getRestrictFilePath( $container );

				// @phan-suppress-next-line PhanUndeclaredFunctionInCallable <--- false positive
				$this->getClient()->waitUntil( 'ObjectNotExists', [
					'Bucket' => $restrictPath[0],
					'Key' => $restrictPath[1]
				] );
			}

			// Upload a new file (to check its ACL afterwards).
			$status = $this->getBackend()->doCreateInternal( [
				'content' => 'Whatever',
				'dst' => $dst
			] );
			$this->assertTrue( $status->isGood(), "$method() failed" );

			# To check security, we try to download this S3 object via the public URL.
			# Note: getFileHttpUrl() returns presigned URLs and can't be used here.
			# A non-presigned URL will return HTTP 403 Forbidden
			# if the ACL of this object is not PUBLIC_READ.
			list( $bucket, $prefix ) = $this->getBackend()->findContainer( $container );
			$url = $this->getClient()->getObjectUrl( $bucket, $prefix . $key );
			$securityAfterTest = ( Http::get( $url ) === false );

			$this->assertEquals( $expectedSecurity, $securityAfterTest,
				"Incorrect ACL: S3 Object uploaded after $method() is " .
				( $expectedSecurity ? "publicly accessible" : "restricted for reading" ) );
		}
	}

	/**
	 * Helper function for testSecureAndPublish() that invalidates "is secure?" cache for $container.
	 * @param string $container
	 */
	private function clearSecurityCache( $container ) {
		$this->getBackend()->containerSecurityCache->delete(
			$this->getBackend()->getCacheKey( $container )
		);
	}

	/**
	 * Verify that uploaded S3 objects have a correct Content-Type header.
	 * @dataProvider contentTypeDataProvider
	 * @covers AmazonS3FileBackend::doCreateInternal
	 * @covers AmazonS3FileBackend::doStoreInternal
	 */
	public function testContentType( $method, $filename, $expectedContentType ) {
		$src = __DIR__ . "/../resources/$filename";
		if ( !file_exists( $src ) ) {
			throw new MWException( __METHOD__ . ": file $src not found (needed for the test)." );
		}

		// Avoid stale data from previous tests by choosing a unique $dst for each run.
		static $run = 0;
		$dst = $this->getVirtualPath( 'ContentTypeTest/' . ( ++$run ) . '/' . $filename );

		// Remove the extension from $dst, otherwise MIME type will be guessed from $dst alone.
		// MIME type is only guessed based on $filename if $dst is inconclusive.
		$dst = preg_replace( '/\.[^\.]*$/', '', $dst ) . '.fake.ext';

		$params = [ 'dst' => $dst ];
		if ( $method == 'doStoreInternal' ) {
			$params['src'] = $src;
		} elseif ( $method == 'doCreateInternal' ) {
			$params['content'] = file_get_contents( $src );
		} else {
			throw new MWException( __METHOD__ . ": unknown method $method." );
		}

		$status = $this->getBackend()->$method( $params );
		$this->assertTrue( $status->isGood(), "$method() failed" );

		// Now that S3 object has been stored, perform a HEAD request and check its headers.
		list( $bucket, $key, ) = $this->getBackend()->getBucketAndObject( $params['dst'] );
		$response = $this->getClient()->headObject( [
			'Bucket' => $bucket,
			'Key' => $key
		] );
		$this->assertArrayHasKey( 'ContentType', $response );
		$this->assertEquals( $expectedContentType, $response['ContentType'] );
	}

	/**
	 * Provides datasets for testContentType().
	 */
	public function contentTypeDataProvider() {
		return [
			[ 'doCreateInternal', 'blank.png',  'image/png' ],
			[ 'doStoreInternal', 'blank.png',  'image/png' ],
			[ 'doStoreInternal', 'text.txt',  'text/plain' ],
			[ 'doCreateInternal', 'text.txt',  'text/plain' ]
		];
	}
}
