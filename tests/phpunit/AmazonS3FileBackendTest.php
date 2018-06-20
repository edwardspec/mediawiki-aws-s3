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
	@file
	@brief Integration test for AmazonS3FileBackend.
*/

use Wikimedia\TestingAccessWrapper;

/**
 * @group FileRepo
 * @group FileBackend
 * @group medium
 */
class AmazonS3FileBackendTest extends MediaWikiTestCase {
	/** @var TestingAccessWrapper Proxy to AmazonS3FileBackend */
	private $backend;

	/** @var FileRepo */
	private $repo;

	/**
		@brief Test directory (e.g. 'testdir123').
		All files created during this testrun will be placed in this directory.
	*/
	private $topDirectory;

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );

		$this->backend = TestingAccessWrapper::newFromObject(
			FileBackendGroup::singleton()->get( 'AmazonS3' )
		);
		$this->repo = RepoGroup::singleton()->getLocalRepo();
		$this->topDirectory = getenv( 'AWS_S3_TEST_TOP_DIRECTORY' ) ?:
			( 'Testdir_' . time() . '_' . rand() );
	}

	/**
		@brief Translate "Hello/world.txt" to mw:// pseudo-URL.
		@param $prependTopDir If true, filename is prefixed with $topDirectory.
	*/
	private function getVirtualPath( $filename, $prependTopDirectory = false ) {
		if ( $prependTopDirectory ) {
			$filename = $this->topDirectory . '/' . $filename;
		}

		return $this->repo->newFile( $filename )->getPath();
	}

	/**
	 * @brief Check that doCreateInternal() succeeds.
	 * @covers AmazonS3FileBackend::doCreateInternal
	 */
	public function testCreate() {
		$params = [
			'content' => 'hello',
			'headers' => [],
			'directory' => $this->topDirectory . '/Hello',
			'filename' => 'world.txt',
		];
		$params['fullfilename'] = $params['directory'] . '/' . $params['filename'];
		$params['dst'] = $this->getVirtualPath( $params['fullfilename'] );
		list( $params['container'], ) = $this->backend->resolveStoragePathReal( $params['dst'] );

		$status = $this->backend->doCreateInternal( [
			'content' => $params['content'],
			'headers' => $params['headers'],
			'dst' => $params['dst']
		] );
		$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );

		/* Pass $params to dependent test */
		return $params;
	}

	/**
	 * @brief Check that doGetFileStat() returns correct information about the file.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doGetFileStat
	 */
	public function testGetFileStat( array $params ) {
		$info = $this->backend->doGetFileStat( [ 'src' => $params['dst'] ] );

		$this->assertEquals( $info['size'], strlen( $params['content'] ),
			'GetFileStat(): incorrect filesize after doCreateInternal()' );

		$expectedSHA1 = Wikimedia\base_convert( sha1( $params['content'] ), 16, 36, 31 );
		$this->assertEquals( $expectedSHA1, $info['sha1'],
			'GetFileStat(): incorrect SHA1 after doCreateInternal()' );
	}

	/**
	 * @brief Check that the file can be downloaded via getFileHttpUrl().
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::getFileHttpUrl
	 */
	public function testFileHttpUrl( array $params ) {
		$url = $this->backend->getFileHttpUrl( [ 'src' => $params['dst'] ] );
		$this->assertNotNull( $url, 'No URL returned by getFileHttpUrl()' );

		$content = Http::get( $url );
		$this->assertEquals( $params['content'], $content,
			'Content downloaded from FileHttpUrl is different from expected' );
	}

	protected function getTestContent( $filename ) {
		return 'Content of [' . $filename . '].';
	}

	/**
		@brief Create test pages for testLists().
		@returns [ 'parentDirectory' => 'dirname', 'container' => 'container-name' ]
	*/
	protected function prepareListTest() {
		static $testinfo = null;
		if ( !is_null( $testinfo ) ) {
			return $testinfo;
		}

		$parentDirectory = $this->topDirectory . '/ListTest';
		$filenames = $this->getFilenamesForListTest();

		foreach ( $filenames as $filename ) {
			$status = $this->backend->doCreateInternal( [
				'dst' => $this->getVirtualPath( $parentDirectory . '/' . $filename ),
				'content' => $this->getTestContent( $filename )
			] );
			$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );
		}

		list( $container, ) = $this->backend->resolveStoragePathReal(
			$this->getVirtualPath( $parentDirectory . '/' . $filenames[0] )
		);

		$testinfo = [
			'container' => $container,
			'parentDirectory' => $parentDirectory
		];
		return $testinfo;
	}

	/**
		@brief List of files that must be created before testLists().
		@see listingTestsDataProvider
		@see testLists
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
		@brief Provides datasets for testList().
	*/
	public function listingTestsDataProvider() {
		return [
			[ 'doDirectoryExists', '', [], true ],
			[ 'doDirectoryExists', 'dir1', [], true ],
			[ 'doDirectoryExists', 'dir2', [], true ],
			[ 'doDirectoryExists', 'dir1/subdir1', [], true ],
			[ 'doDirectoryExists', 'dir2/subdir1', [], true ],
			[ 'doDirectoryExists', 'dir1/file2.txt', [], false ],
			[ 'doDirectoryExists', 'WeNeverCreatedFilesWithThisPrefix', [], false ],
			[ 'getDirectoryListInternal', '', [], [ 'dir1', 'dir1/subdir1', 'dir1/subdir2', 'dir2', 'dir2/subdir1' ] ],
			[ 'getDirectoryListInternal', '', [ 'topOnly' => true ], [ 'dir1', 'dir2' ] ],
			[ 'getDirectoryListInternal', 'dir1', [], [ 'subdir1', 'subdir2' ] ],
			[ 'getDirectoryListInternal', 'dir2', [], [ 'subdir1' ] ],
			[ 'getDirectoryListInternal', 'dir1/file2.txt', [], [] ],
			[ 'getFileListInternal', '', [], $this->getFilenamesForListTest() ],
			[ 'getFileListInternal', '', [ 'topOnly' => true ], [ 'file1_in_topdir.txt', 'file2_in_topdir.txt' ] ],
			[ 'getFileListInternal', 'dir1', [],
				[ 'file1.txt', 'file2.txt', 'file3.txt', 'subdir1/file1-1-1.txt', 'subdir1/file1-1-2.txt', 'subdir2/file1-2-1.txt' ] ],
			[ 'getFileListInternal', 'dir1', [ 'topOnly' => true ], [ 'file1.txt', 'file2.txt', 'file3.txt' ] ],
			[ 'getFileListInternal', 'dir1/subdir1', [], [ 'file1-1-1.txt', 'file1-1-2.txt', 'file1-1-3.txt' ] ],
			[ 'getFileListInternal', 'dir1/subdir1', [ 'topOnly' => true ], [ 'file1-1-1.txt', 'file1-1-2.txt', 'file1-1-3.txt' ] ],
			[ 'getFileListInternal', 'dir2', [], [ 'file1.txt', 'file2.txt', 'subdir1/file2-1-1.txt', 'file3.txt' ] ],
			[ 'getFileListInternal', 'dir2', [ 'topOnly' => true ], [ 'file1.txt', 'file2.txt', 'file3.txt' ] ]
		];
	}

	/**
	 * @brief Check that get*ListInternal() works as expected
	 * @dataProvider listingTestsDataProvider
	 * @covers AmazonS3FileBackend::getDirectoryListInternal
	 * @covers AmazonS3FileBackend::getFileListInternal
	 */
	public function testList( $method, $directory, $params, $expectedResult ) {
		$testinfo = $this->prepareListTest();

		$result = $this->backend->$method(
			$testinfo['container'],
			$testinfo['parentDirectory'] . ( $directory == '' ? '' : "/$directory" ),
			$params
		);
		if ( $method == 'doDirectoryExists' ) {
			$this->assertEquals( $result, $expectedResult );
			return;
		}

		$foundFilenames = [];
		foreach ( $result as $dir ) {
			$foundFilenames[] = $dir;
		}

		$this->assertEquals( sort( $expectedResult ), sort( $foundFilenames ),
			"Directory listing doesn't match expected."
		);
	}

	/**
	 * @brief Check that doGetLocalCopyMulti() provides correct content.
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

		$result = $this->backend->doGetLocalCopyMulti( [
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
	 * @brief Check that doCopyInternal() succeeds.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doCopyInternal
	 */
	public function testCopyInternal( array $params ) {
		$params['copy-filename'] = $params['fullfilename'] . '_new_' . rand();
		$params['copy-dst'] = $this->getVirtualPath( $params['copy-filename'] );

		$status = $this->backend->doCopyInternal( [
			'src' => $params['dst'],
			'dst' => $params['copy-dst']
		] );
		$this->assertTrue( $status->isGood(), 'doCopyInternal() failed' );

		/* Pass $params to dependent test */
		return $params;
	}

	/**
	 * @brief Check that doDeleteInternal() succeeds.
	 * @depends testCopyInternal
	 * @covers AmazonS3FileBackend::doDeleteInternal
	 */
	public function testDeleteInternal( array $params ) {
		$status = $this->backend->doDeleteInternal( [
			'src' => $params['copy-dst']
		] );
		$this->assertTrue( $status->isGood(), 'doDeleteInternal() failed' );

		$info = $this->backend->doGetFileStat( [ 'src' => $params['copy-dst'] ] );
		$this->assertFalse( $info, 'doGetFileStat() says the file still exists after doDeleteInternal()' );
	}
}
