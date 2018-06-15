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
	 * @brief Check that doCreateInternal() succeeds.
	 * @covers AmazonS3FileBackend::doCreateInternal
	 */
	public function testCreate() {
		$params = [
			'content' => 'hello',
			'headers' => [],
			'directory' => 'Hello',
			'filename' => 'world.txt'
		];
		$params['dst'] = $this->repo->newFile( $params['directory'] . '/' . $params['filename'] )->getPath();

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
	 * @brief Double-check that doGetFileStat() returns correct information about the file.
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
	 * @brief Double-check that the file can be downloaded via getFileHttpUrl().
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::getFileHttpUrl
	 */
	public function xtestFileHttpUrl( array $params ) {
		/* Now check the URL and download it */
		$url = $this->backend->getFileHttpUrl( [ 'src' => $params['dst'] ] );
		$this->assertNotNull( $url, 'No URL returned by getFileHttpUrl()' );

		$content = Http::get( $url );
		$this->assertEquals( $params['content'], $content,
			'Content downloaded from FileHttpUrl is different from expected' );
	}

	/**
	 * @brief Double-check that doDirectoryExists() work correctly.
	 * @depends testCreate
	 * @covers AmazonS3FileBackend::doDirectoryExists
	 */
	public function testDirectoryExists_afterCreate( array $params ) {
		// Amazon S3 doesn't really have directories.
		// Method doDirectoryExists() checks if there are files with the certain prefix.
		list( $container, ) = $this->backend->resolveStoragePathReal( $params['dst'] );
		$fakeDir = $params['directory'];

		$this->assertTrue( $this->backend->doDirectoryExists( $container, $fakeDir, [] ),
			"Directory [$fakeDir] doesn't exist after creating [{$params['dst']}]" );
	}


		/* TODO: test other operations: Copy, Delete, etc. */

		//doCopyInternal
}
