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
	@brief Additional LocalSettings.php for Travis testing of Extension:AWS.

	Expects the following environment variables:
		AWS_KEY=
		AWS_SECRET=
		AWS_BUCKET_PREFIX=

	Note: Amazon S3 buckets (all 4 of them) must be pre-created before the test.
	Note: IAM user (who owns key/secret) must have no other permissions
	except the access to affected S3 buckets. See README.md for details.

	See also: [OldStyleAWSSettings.php] (for customized $wgLocalFileRepo).
*/

wfLoadExtension( 'AWS' );
$wgAWSRegion = 'us-east-1'; # Northern Virginia

$wgAWSCredentials['key'] = getenv( 'AWS_KEY' );
$wgAWSCredentials['secret'] = getenv( 'AWS_SECRET' );
$wgAWSBucketPrefix = getenv( 'AWS_BUCKET_PREFIX' );

$wgDebugLogGroups['FileOperation'] = getenv( 'TRAVIS_BUILD_DIR' ) . '/s3.log';
