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

	protected function setUp () {
		parent::setUp();

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
	public function xtestGetFileStat( array $params ) {
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
	public function xtestFileHttpUrl( array $params ) {
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
	 * @brief Check that getDirectoryListInternal() can find the newly created object.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::getDirectoryListInternal
	 */
	public function testDirectoryListInternal( array $params ) {
		$iterator = $this->backend->getDirectoryListInternal(
			$params['container'],
			$params['directory'],
			[]
		);

		$subdirs = [];
		foreach ( $iterator as $dir ) {
			$subdirs[] = $dir;
		}

		$expectedSubdirs = [ '.' ]; // Test directory doesn't have any subdirectories
		$this->assertEquals( $expectedSubdirs, $subdirs );
	}

	/**
	 * @brief Check that getFileListInternal() can find the newly created object.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::getFileListInternal
	 */
	public function testFileListInternal( array $params ) {
		$iterator = $this->backend->getFileListInternal(
			$params['container'],
			$params['directory'],
			[]
		);

		$files = [];
		foreach ( $iterator as $file ) {
			$files[] = $file;
		}

		$this->assertContains( $params['filename'], $files );
	}

	/**
	 * @brief Check that doDirectoryExists() returns false on non-existant directory.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doDirectoryExists
	 */
	public function testDirectoryExists_emptyDir( array $params ) {
		$fakeDir = 'WeNeverCreatedFilesWithThisPrefix';
		$this->assertFalse( $this->backend->doDirectoryExists( $params['container'], $fakeDir, [] ),
			"doDirectoryExists() says that [$fakeDir] exists, but we never created it." );
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
