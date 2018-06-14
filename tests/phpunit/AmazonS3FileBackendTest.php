<?php

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
	 * @dataProvider createDataProvider
	 * @covers AmazonS3FileBackend::doCreateInternal
	 */
	public function testCreate( array $createParams ) {
		$file = $this->repo->newFile( $createParams['dstTitle'] );
		$createParams['dst'] = $file->getPath();

		$status = $this->backend->doCreateInternal( $createParams );
		$this->assertTrue( $status->isGood(), 'doCreateInternal() failed' );

		/* Now double-check that the file has indeed been created */
		$info = $this->backend->doGetFileStat( [ 'src' => $file->getPath() ] );

		$this->assertEquals( $info['size'], strlen( $createParams['content'] ),
			'Incorrect filesize after doCreateInternal()' );

		$expectedSHA1 = Wikimedia\base_convert( sha1( $createParams['content'] ), 16, 36, 31 );
		$this->assertEquals( $expectedSHA1, $info['sha1'],
			'Incorrect SHA1 after doCreateInternal()' );

		/* Now check the URL and download it */
		$url = $this->backend->getFileHttpUrl( [ 'src' => $file->getPath() ] );
		$this->assertNotNull( $url, 'No URL returned by getFileHttpUrl()' );

		$content = Http::get( $url );
		$this->assertEquals( $createParams['content'], $content,
			'Content downloaded from FileHttpUrl is different from expected' );

		/* TODO: test other operations: Copy, Delete, etc. */
	}

	public static function createDataProvider() {
		return [
			[ [
				'content' => 'hello',
				'headers' => [],

				// Not passed to doCreateInternal(): translated into 'dst' in testCreate()
				'dstTitle' => 'hello.txt',
			] ]
		];
	}
}
