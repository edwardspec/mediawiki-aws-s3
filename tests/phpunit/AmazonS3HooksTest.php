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
	@brief Checks calculation of $wgLocalRepo from $wgAWSBucketPrefix/$wgAWSBucketDomain.
*/

/**
 * @group FileRepo
 * @group FileBackend
 * @group medium
 */
class AmazonS3HooksTest extends MediaWikiTestCase {

	protected $untouchedFakeLocalRepo = [ 'untouched' => 'marker' ];

	public function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgLocalFileRepo' => $this->untouchedFakeLocalRepo,
			'wgFileBackends' => [],
			'wgAWSBucketPrefix' => null
		] );
	}

	/**
	 * @brief Check that $wgAWSBucketPrefix and $wgAWSBucketDomain work as expected.
	 * @covers AmazonS3Hooks
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
		global $wgLocalFileRepo, $wgFileBackends, $wgAWSBucketPrefix, $wgDBname;

		$this->setMwGlobals( $inputConfigs );

		try {
			AmazonS3Hooks::installBackend();
		} catch ( AmazonS3MisconfiguredException $e ) {
			$text = MWExceptionHandler::getLogMessage( $e );
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
		if ( $wgAWSBucketPrefix ) {
			$expectedBackend['containerPaths'] = [
				"$wgDBname-local-public" => "$wgAWSBucketPrefix",
				"$wgDBname-local-thumb" => "$wgAWSBucketPrefix-thumb",
				"$wgDBname-local-deleted" => "$wgAWSBucketPrefix-deleted",
				"$wgDBname-local-temp" => "$wgAWSBucketPrefix-temp",
			];
		}

		$this->assertArrayHasKey( 's3', $wgFileBackends,
			"\$wgFileBackends array doesn't have 's3' key" );
		$this->assertEquals( $expectedBackend, $wgFileBackends['s3'],
			"Unexpected value of \$wgFileBackends['s3']" );

		// Step 2. Check $wgLocalFileRepo.
		$expectedRepo = $expectedZoneUrl ? [
			'class' => 'LocalRepo',
			'name' => 'local',
			'backend' => 'AmazonS3',
			'url' => wfScript( 'img_auth' ),
			'hashLevels' => 0,
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
		@brief Provides datasets for testConfig().
	*/
	public function configDataProvider() {
		return [
			// Part 1. Configuration without $wgAWSBucketPrefix - shouldn't modify $wgLocalFileRepo
			// or populate 'containerPaths' under $wgFileBackends['s3']
			[ [] ],

			// Part 2. Correct configurations.
			[
				[ 'wgAWSBucketPrefix' => 'mysite-images' ],
				[
					'public' => 'https://mysite-images.s3.amazonaws.com',
					'thumb' => 'https://mysite-images-thumb.s3.amazonaws.com'
				]
			],
			[
				[
					'wgAWSBucketPrefix' => 'anothersite-images',
					'wgAWSBucketDomain' => '$1.example.com',
				],
				[
					'public' => 'https://anothersite-images.example.com',
					'thumb' => 'https://anothersite-images-thumb.example.com'
				]
			],
			[
				[
					'wgAWSBucketPrefix' => 'thirdsite',
					'wgAWSBucketDomain' => 'media$2.example.com',
				],
				[
					'public' => 'https://media.example.com',
					'thumb' => 'https://media-thumb.example.com'
				]
			],
			[
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
			[
				[
					'wgAWSBucketPrefix' => 'mysite',
					'wgAWSBucketDomain' => 'same-for-public-and-thumb.example.com'
				],
				null,
				'$wgAWSBucketDomain string must contain either $1 or $2.'
			],
			[
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
