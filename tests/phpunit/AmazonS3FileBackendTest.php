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

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );

		$this->backend = TestingAccessWrapper::newFromObject(
			FileBackendGroup::singleton()->get( 'AmazonS3' )
		);
		$this->repo = RepoGroup::singleton()->getLocalRepo();
	}

	/**
		@brief Translate "Hello/world.txt" to mw:// pseudo-URL.
	*/
	private function getVirtualPath( $filename ) {
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
			'directory' => 'Hello',
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

	/**
	 * @brief Check that doDirectoryExists() can find the newly created object.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doDirectoryExists
	 */
	public function testDirectoryExists_afterCreate( array $params ) {
		// Amazon S3 doesn't really have directories.
		// Method doDirectoryExists() checks if there are files with the certain prefix.
		$fakeDir = $params['directory'];
		$this->assertTrue( $this->backend->doDirectoryExists( $params['container'], $fakeDir, [] ),
			"Directory [$fakeDir] doesn't exist after creating [{$params['dst']}]" );
	}

	/**
	 * @brief Create multiple objects via doCreateInternal().
	 * @coversNothing
	 * @note This test is only needed to pre-create files for dependent tests like testGetLocalCopyMulti().
	 * @returns array(
	 *	'contents' => [ 'dst1' => 'content1', ... ],
	 *	'filenames' => [ 'name1', ... ],
	 *	'directories' => [ 'dirName1', ... ],
	 *	'parentDirectory' => 'dirName',
	 *	'container' => 'containerName'
	 * );
	 */
	public function testCreateMultipleFiles() {
		$info = [
			'contents' => [],
			'filenames' => [],
			'directories' => [],
			'parentDirectory' => 'Testdir_' . time() . '_' . rand()
		];

		foreach ( [ 1, 2, '' ] as $dirSuffix ) {
			$directoryName = $info['parentDirectory'];
			if ( $dirSuffix != '' ) {
				$directoryName .= "/dir${dirSuffix}";
			}

			$info['directories'][] = $directoryName;

			foreach ( [ 'a', 'b', 'c' ] as $fileSuffix ) {
				$filename = "$directoryName/file${fileSuffix}.txt";
				$info['filenames'][] = $filename;

				$dst = $this->getVirtualPath( $filename );
				$info['contents'][$dst] = $this->getTestContent( $filename );

				$status = $this->backend->doCreateInternal( [
					'dst' => $dst,
					'content' => $info['contents'][$dst]
				]);
				$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );
			}
		}

		/* Pass $info to dependent tests */
		list( $info['container'], ) = $this->backend->resolveStoragePathReal(
			array_keys( $info['contents'] )[0]
		);
		return $info;
	}

	/**
	 * @brief Check that doGetLocalCopyMulti() provides correct content.
	 * @covers AmazonS3FileBackend::doGetLocalCopyMulti
	 * @depends testCreateMultipleFiles
	 */
	public function testGetLocalCopyMulti( array $info ) {
		/*
			Test response of doGetLocalCopyMulti.
		*/
		$expectedContents = $info['contents'];
		$result = $this->backend->doGetLocalCopyMulti( [
			'src' => array_keys( $expectedContents )
		] );
		$this->assertInternalType( 'array', $result,
			'doGetLocalCopyMulti() didn\'t return an array' );
		$this->assertCount( count( $expectedContents ), $result,
			'Incorrect number of elements returned by doGetLocalCopyMulti()' );

		foreach ( $expectedContents as $dst => $expectedContent ) {
			$this->assertArrayHasKey( $dst, $result,
				"URL $dst not found() in array returned by doGetLocalCopyMulti()" );
			$this->assertEquals( $expectedContent, file_get_contents( $result[$dst]->getPath() ),
				"Incorrect contents of $dst returned by doGetLocalCopyMulti()" );
		}
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

		$parentDirectory = 'Testdir_' . time() . '_' . rand();
		$filenames = $this->getFilenamesForListTest();

		foreach ( $filenames as $filename ) {
			$status = $this->backend->doCreateInternal( [
				'dst' => $this->getVirtualPath( $parentDirectory . '/' . $filename ),
				'content' => $this->getTestContent( $filename )
			] );
			$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );
		}

		list( $container, ) = $this->backend->resolveStoragePathReal(
			$this->getVirtualPath( $parentDirectory . $filenames[0] )
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
		@brief Provides datasets for testLists().
	*/
	public function listingTestsDataProvider() {
		return [
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
		@brief Calculate expected filenames under $directory in $topOnly mode.
		Used by tests like testDirectoryListInternal_topOnly().

		@param $where Either $info['directories'] or $info['filenames'],
			where $info is the return value of testCreateMultipleFiles().
	*/
	protected function getExpectedFilenames( $directory, $topOnly, array $where ) {
		return array_filter( array_map( function( $filename ) use ( $directory, $topOnly ) {
			$prefix = $directory . '/';
			if ( strpos( $filename, $prefix ) !== 0 ) {
				return null;
			}

			$filename = substr( $filename, strlen( $prefix ) );
			if ( $topOnly && strpos( $filename, '/' ) !== false ) {
				return null; /* Subdirectory in $filename, not expected in topOnly mode */
			}

			return $filename;
		}, $where ) );
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
