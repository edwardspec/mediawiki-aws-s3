<?php

/**
 * Implements the AWS extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'AWS',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:AWS',
	'version'        => '0.6.0',
	'author'         => array(
		'Tyler Romeo',
		'Daniel Friesen @ Redwerks',
	),
	'descriptionmsg' => 'aws-desc'
);

/**
 * Credentials to use to connect to AWS
 */
$wgAWSCredentials = array(
	'key' => false,
	'secret' => false,
	'token' => false
);

/**
 * Region of AWS to connect to
 */
$wgAWSRegion = false;

/**
 * Use SES as a mailer
 */
$wgAWSSES = null;

/**
 * Whether to use HTTPS with AWS
 */
$wgAWSUseHTTPS = true;

$wgMessagesDirs['AWS'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['AWS'] = __DIR__ . '/AWS.i18n.php';
$wgAutoloadClasses['JobQueueAmazonSqs'] = __DIR__ . '/sqs/JobQueueAmazonSqs.php';
$wgAutoloadClasses['AmazonS3FileBackend'] = __DIR__ . '/s3/AmazonS3FileBackend.php';
$wgAutoloadClasses['AmazonS3FileIterator'] = __DIR__ . '/s3/AmazonS3FileBackend.php';
$wgAutoloadClasses['AmazonS3DirectoryIterator'] = __DIR__ . '/s3/AmazonS3FileBackend.php';
$wgAutoloadClasses['AmazonSesAlternateUserMailer'] = __DIR__ . '/ses/AmazonSesAlternateUserMailer.php';

$wgHooks['AlternateUserMailer'][] = 'AmazonSesAlternateUserMailer::hook';

$wgJobTypeConf['sqs'] = array(
	'class' => 'JobQueueAmazonSqs',
	'order' => 'random'
);
$wgFileBackends['s3'] = array(
	'name' => 'AmazonS3',
	'class' => 'AmazonS3FileBackend',
	'lockManager' => 'nullLockManager',
);

require_once __DIR__ . '/vendor/autoload.php';
