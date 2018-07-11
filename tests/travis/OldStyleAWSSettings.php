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
	@brief Old-style version of [AWSSettings.php]. Doesn't use $wgAWSBucketPrefix.
*/

$KEY = getenv( 'AWS_KEY' );
$SECRET = getenv( 'AWS_SECRET' );
$BUCKET_PREFIX = getenv( 'AWS_BUCKET_PREFIX' );

/*---------------------------------------------------------------------------*/

require_once "$IP/extensions/AWS/AWS.php";

$wgAWSCredentials['key'] = $KEY;
$wgAWSCredentials['secret'] = $SECRET;

$wgAWSRegion = 'us-east-1'; # Northern Virginia

$wgFileBackends['s3']['containerPaths'] = [
	"$wgDBname-local-public" => "${BUCKET_PREFIX}",
	"$wgDBname-local-thumb" => "${BUCKET_PREFIX}-thumb",
	"$wgDBname-local-deleted" => "${BUCKET_PREFIX}-deleted",
	"$wgDBname-local-temp" => "${BUCKET_PREFIX}-temp"
];

$wgLocalFileRepo = [
	'class'             => 'LocalRepo',
	'name'              => 'local',
	'backend'           => 'AmazonS3',
	'url'               => $wgScriptPath . '/img_auth.php',
	'hashLevels'        => 0,
	'zones'             => [
		'public'  => [ 'url' => "https://${BUCKET_PREFIX}.s3.amazonaws.com" ],
		'thumb'   => [ 'url' => "https://${BUCKET_PREFIX}-thumb.s3.amazonaws.com" ],
		'temp'    => [ 'url' => false ],
		'deleted' => [ 'url' => false ]
	]
];

$wgDebugLogGroups['FileOperation'] = getenv( 'TRAVIS_BUILD_DIR' ) . '/oldstyle-s3.log';
