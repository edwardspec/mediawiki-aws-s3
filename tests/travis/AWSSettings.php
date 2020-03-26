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
 * Additional LocalSettings.php for Travis testing of Extension:AWS.
 *
 * Expects the following environment variables:
 * 	AWS_KEY=
 * 	AWS_SECRET=
 * 	BUCKET=
 * 	TRAVIS_BUILD_DIR=
 *
 * Note: Amazon S3 bucket must be pre-created before the test.
 * Note: IAM user (who owns key/secret) must have no other permissions
 * except the access to affected S3 bucket. See README.md for details.
 */

wfLoadExtension( 'AWS' );
$wgAWSRegion = 'us-east-1'; # Northern Virginia

$wgAWSCredentials['key'] = getenv( 'AWS_KEY' ) ?: 'no valid key';
$wgAWSCredentials['secret'] = getenv( 'AWS_SECRET' ) ?: 'no valid secret';
$wgAWSBucketName = getenv( 'BUCKET' );

$wgDebugLogGroups['FileOperation'] = getenv( 'TRAVIS_BUILD_DIR' ) . '/s3.log';

# Should be tested with and without local cache enabled
if ( getenv( 'WITH_CACHE' ) ) {
	$wgAWSLocalCacheDirectory = getenv( 'TRAVIS_BUILD_DIR' ) . '/aws.localcache';
	$wgAWSLocalCacheMinSize = 0; // Make all files cached, regardless of their size.
}
