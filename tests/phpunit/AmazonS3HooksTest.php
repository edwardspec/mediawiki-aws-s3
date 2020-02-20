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
 * Checks calculation of $wgLocalRepo from $wgAWSBucketPrefix/$wgAWSBucketDomain.
 */

/**
 * @group FileRepo
 * @group FileBackend
 * @group medium
 * @group TestsWithNoNeedForAwsCredentials
 */
class AmazonS3HooksTest extends MediaWikiTestCase {

	protected $untouchedFakeLocalRepo = [ 'untouched' => 'marker' ];

	public function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgLocalFileRepo' => $this->untouchedFakeLocalRepo,
			'wgFileBackends' => [],
			'wgAWSBucketName' => null,
			'wgAWSBucketPrefix' => null,
			'wgAWSRepoHashLevels' => 0,
			'wgAWSRepoDeletedHashLevels' => 0
		] );
	}

	/**
	 * Verify that installBackend() is called during initialization of MediaWiki.
	 * @covers AmazonS3Hooks::setup
	 */
	public function testConfigIsLoaded() {
		global $IP;

		$tmpFilename = tempnam( sys_get_temp_dir(), '.configtest' );
		file_put_contents( $tmpFilename,
			'echo ( isset( $wgFileBackends["s3"] ) && ' .
			'isset( $wgFileBackends["s3"]["class"] ) && ' .
			'$wgFileBackends["s3"]["class"] == "AmazonS3FileBackend" ) ? "LOADED" : "FAILED";' );

		// Spawn a new PHP process with eval.php.
		// This way we can be sure that $wgFileBackends wasn't set by our previous tests,
		// but was indeed initialized by installBackend().
		$cmd = wfEscapeShellArg(
			PHP_BINARY,
			"$IP/maintenance/runScript.php",
			"$IP/maintenance/eval.php"
		) . ' <' . wfEscapeShellArg( $tmpFilename );

		$retval = false;
		$output = wfShellExecWithStderr( $cmd, $retval, [], [ 'memory' => -1 ] );

		unlink( $tmpFilename ); // Cleanup

		$this->assertContains( 'LOADED', $output,
			'testConfigIsLoaded(): $wgFileBackends["s3"] is not defined, ' .
			'which means installBackend() hasn\'t been called on initialization.' );
	}

	/**
	 * Check that $wgAWSBucketName and $wgAWSBucketDomain work as expected.
	 * @covers AmazonS3Hooks::installBackend
	 * @dataProvider configDataProvider
	 * @param array $inputConfigs [ 'wgAWSBucketPrefix' => 'value', ... ]
	 * @param array|null $expectedZoneUrl [ 'public' => URL1, 'thumb' => URL2 ]
	 * @param string|null $expectedExceptionText Exception that should be thrown, if any.
	 */
	public function testConfig(
		array $inputConfigs,
		array $expectedZoneUrl = null,
		$expectedExceptionText = null
	) {
		global $wgLocalFileRepo, $wgFileBackends, $wgAWSBucketName, $wgAWSBucketPrefix;

		$this->setMwGlobals( $inputConfigs );

		try {
			$hooks = new AmazonS3Hooks;
			$hooks->installBackend();
		} catch ( AmazonS3MisconfiguredException $e ) {
			$this->assertContains( $expectedExceptionText, $e->getText(),
				"Unexpected exception from installBackend()" );
			return;
		}

		// Step 1. Check $wgFileBackends
		$expectedBackend = [
			'name' => 'AmazonS3',
			'class' => 'AmazonS3FileBackend',
			'lockManager' => 'nullLockManager',

		];
		$wikiId = wfWikiID();
		if ( $wgAWSBucketName ) {
			// 1 bucket (modern configuration)
			$expectedBackend['containerPaths'] = [
				"$wikiId-local-public" => "$wgAWSBucketName",
				"$wikiId-local-thumb" => "$wgAWSBucketName/thumb",
				"$wikiId-local-deleted" => "$wgAWSBucketName/deleted",
				"$wikiId-local-temp" => "$wgAWSBucketName/temp",
			];
		} elseif ( $wgAWSBucketPrefix ) {
			// 4 buckets (deprecated configuration)
			$expectedBackend['containerPaths'] = [
				"$wikiId-local-public" => "$wgAWSBucketPrefix",
				"$wikiId-local-thumb" => "$wgAWSBucketPrefix-thumb",
				"$wikiId-local-deleted" => "$wgAWSBucketPrefix-deleted",
				"$wikiId-local-temp" => "$wgAWSBucketPrefix-temp",
			];
		}

		$this->assertArrayHasKey( 's3', $wgFileBackends,
			"\$wgFileBackends array doesn't have 's3' key" );
		$this->assertEquals( $expectedBackend, $wgFileBackends['s3'],
			"Unexpected value of \$wgFileBackends['s3']" );

		// Step 2. Check $wgLocalFileRepo.
		$expectedHashLevels = isset( $inputConfigs['wgAWSRepoHashLevels'] ) ?
			$inputConfigs['wgAWSRepoHashLevels'] : 0;
		$expectedDeletedHashLevels = isset( $inputConfigs['wgAWSRepoDeletedHashLevels'] ) ?
			$inputConfigs['wgAWSRepoDeletedHashLevels'] : 0;

		$expectedRepo = $expectedZoneUrl ? [
			'class' => 'LocalRepo',
			'name' => 'local',
			'backend' => 'AmazonS3',
			'url' => wfScript( 'img_auth' ),
			'hashLevels' => $expectedHashLevels,
			'deletedHashLevels' => $expectedDeletedHashLevels,
			'zones' => [
				'public' => [ 'url' => $expectedZoneUrl['public'] ],
				'thumb' => [ 'url' => $expectedZoneUrl['thumb'] ],
				'deleted' => [ 'url' => false ],
				'temp' => [ 'url' => false ]
			]
		] : $this->untouchedFakeLocalRepo;
		$this->assertEquals( $expectedRepo, $wgLocalFileRepo, "Unexpected \$wgLocalFileRepo" );
	}

	/**
	 *
	 * Provides datasets for testConfig().
	 */
	public function configDataProvider() {
		return [
			// Part 1. Configuration without $wgAWSBucketName - shouldn't modify $wgLocalFileRepo
			// or populate 'containerPaths' under $wgFileBackends['s3']
			[ [] ],

			// Part 2a. Correct configurations with $wgAWSBucketName.
			'Modern configuration with $wgAWSBucketName (one S3 bucket)' => [
				[ 'wgAWSBucketName' => 'mysite-images' ],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images.s3.amazonaws.com/thumb'
				]
			],
			'Modern configuration with $wgAWSBucketName and $wgAWSRepoHashLevels=0' => [
				[
					'wgAWSBucketName' => 'mysite-images',
					'wgAWSRepoHashLevels' => 0
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images.s3.amazonaws.com/thumb'
				]
			],
			'Modern configuration with $wgAWSBucketName and $wgAWSRepoHashLevels=2' => [
				[
					'wgAWSBucketName' => 'mysite-images',
					'wgAWSRepoHashLevels' => 2
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images.s3.amazonaws.com/thumb'
				]
			],
			'Modern configuration with $wgAWSBucketName and $wgAWSRepoDeletedHashLevels=0' => [
				[
					'wgAWSBucketName' => 'mysite-images',
					'wgAWSRepoDeletedHashLevels' => 0
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images.s3.amazonaws.com/thumb'
				]
			],
			'Modern configuration with $wgAWSBucketName and $wgAWSRepoDeletedHashLevels=2' => [
				[
					'wgAWSBucketName' => 'mysite-images',
					'wgAWSRepoDeletedHashLevels' => 2
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images.s3.amazonaws.com/thumb'
				]
			],
			'Modern configuration with $wgAWSBucketName and fixed $wgAWSBucketDomain' => [
				[
					'wgAWSBucketName' => 'anothersite-images',
					'wgAWSBucketDomain' => 'myimages.example.com',
				],
				[
					'public' => 'https://myimages.example.com',
					'thumb' => 'https://myimages.example.com/thumb'
				]
			],
			'Configuration with $wgAWSBucketName, but $wgAWSBucketDomain uses "$1" syntax' => [
				[
					'wgAWSBucketName' => 'anothersite-images',
					'wgAWSBucketDomain' => '$1.example.com',
				],
				[
					'public' => 'https://anothersite-images.example.com',
					'thumb' => 'https://anothersite-images.example.com/thumb'
				]
			],
			'Configuration with $wgAWSBucketName: semi-obsolete "$2" in $wgAWSBucketDomain' => [
				[
					'wgAWSBucketName' => 'thirdsite',
					'wgAWSBucketDomain' => 'media$2.example.com',
				],
				[
					'public' => 'https://media.example.com',
					'thumb' => 'https://media-thumb.example.com/thumb'
				]
			],
			'Weird configuration with different custom domains pointing to the same S3 bucket' => [
				[
					'wgAWSBucketName' => 'site-number-four',
					'wgAWSBucketDomain' => [
						'public' => 'img.example.com',
						'thumb' => 'thumb.example.com'
					]
				],
				[
					'public' => 'https://img.example.com',
					'thumb' => 'https://thumb.example.com/thumb'
				]
			],

			// Part 2b. Correct configurations with deprecated $wgAWSBucketPrefix.
			'B/C configuration with $wgAWSBucketPrefix (four S3 buckets, no $wgAWSBucketName)' => [
				[ 'wgAWSBucketPrefix' => 'mysite-images' ],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix and $wgAWSRepoHashLevels=0' => [
				[
					'wgAWSBucketPrefix' => 'mysite-images',
					'wgAWSRepoHashLevels' => 0
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix and $wgAWSRepoHashLevels=2' => [
				[
					'wgAWSBucketPrefix' => 'mysite-images',
					'wgAWSRepoHashLevels' => 2
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix and $wgAWSRepoDeletedHashLevels=0' => [
				[
					'wgAWSBucketPrefix' => 'mysite-images',
					'wgAWSRepoDeletedHashLevels' => 0
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix and $wgAWSRepoDeletedHashLevels=2' => [
				[
					'wgAWSBucketPrefix' => 'mysite-images',
					'wgAWSRepoDeletedHashLevels' => 2
				],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix. $wgAWSBucketDomain uses "$1" syntax' => [
				[
					'wgAWSBucketPrefix' => 'anothersite-images',
					'wgAWSBucketDomain' => '$1.example.com',
				],
				[
					'public' => 'https://anothersite-images.example.com',
					'thumb' => 'https://anothersite-images-thumb.example.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix. $wgAWSBucketDomain uses "$2" syntax' => [
				[
					'wgAWSBucketPrefix' => 'thirdsite',
					'wgAWSBucketDomain' => 'media$2.example.com',
				],
				[
					'public' => 'https://media.example.com',
					'thumb' => 'https://media-thumb.example.com'
				]
			],
			'B/C configuration with $wgAWSBucketPrefix. $wgAWSBucketDomain is an array' => [
				[
					'wgAWSBucketPrefix' => 'site-number-four',
					'wgAWSBucketDomain' => [
						'public' => 'img.example.com',
						'thumb' => 'thumb.example.com'
					]
				],
				[
					'public' => 'https://img.example.com',
					'thumb' => 'https://thumb.example.com'
				]
			],

			// Part 3. Incorrect configurations that should throw AmazonS3MisconfiguredException.
			'Incorrect configuration with $wgAWSBucketPrefix ' .
			'where $wgAWSBucketDomain is a fixed string' => [
				[
					'wgAWSBucketPrefix' => 'mysite',
					'wgAWSBucketDomain' => 'same-for-public-and-thumb.example.com'
				],
				null,
				'If $wgAWSBucketPrefix is used, $wgAWSBucketDomain must contain either $1 or $2.'
			],
			'Incorrect configuration with $wgAWSBucketPrefix ' .
			'where $wgAWSBucketDomain is an array without required key "thumb"' => [
				[
					'wgAWSBucketPrefix' => 'mysite',
					'wgAWSBucketDomain' => [ 'public' => 'thumb-zone-was-forgotten.example.com' ]
				],
				null,
				'$wgAWSBucketDomain is an array without the required key "thumb"'
			]
		];
	}
}
