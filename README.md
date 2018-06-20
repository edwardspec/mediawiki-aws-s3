This is a fork of Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS (only S3-related functionality, no SQS, etc.)

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

The fork was made because:
* We need this extension in production (it is the most correct way to use Amazon S3 as image repository)
* Extension was marked by its author as experimental (not even beta), and we found (and fixed) some bugs in it,
* We must apply bugfixes and improvements ASAP and can't wait for upstream (original extension is unmaintained and they haven't been reviewed for a year).

# Installation

1\) Download the extension: `git clone --depth 1 https://github.com/edwardspec/mediawiki-aws-s3-stable-fork.git AWS`

2\) Move the AWS directory to the "extensions" directory of your MediaWiki, e.g. `/var/www/html/w/extensions` (assuming MediaWiki is in `/var/www/html/w`).

3\) Run `composer install` from `/var/www/html/w/extensions/AWS` (to download dependencies). If you don't have Composer installed, see https://www.mediawiki.org/wiki/Composer for how to install it.

4\) Choose a unique name (not taken by another AWS user) for your Amazon S3 buckets, e.g. `wonderfulbali234`. Create four S3 buckets: `wonderfulbali234-img`, `wonderfulbali234-img-thumb`, `wonderfulbali234-img-deleted`, `wonderfulbali234-img-temp`. Note: this name will be seen in URL of images.

5a\) If your EC2 instance has an IAM instance profile (recommended), copy everything from "Needed IAM permissions" (see below) to inline policy of the IAM role. See https://console.aws.amazon.com/iam/home#/roles

5b\) If your EC2 instance doesn't have an IAM profile, obtain key/secret for AWS API. You'll need to write it in LocalSettings.php (see below).

6\) Modify LocalSettings.php (see below).

# Configuration in LocalSettings.php

```php
require_once("$IP/extensions/AWS/AWS.php");

// Configure AWS credentials.
// THIS IS NOT NEEDED if your EC2 instance has an IAM instance profile.
$wgAWSCredentials = array(
	'key' => '<something>',
	'secret' => '<something>',
	'token' => false
);

$wgAWSRegion = 'us-east-1'; # Northern Virginia

// Replace <something> with the prefix of your S3 buckets, e.g. wonderfulbali234.
$wgFileBackends['s3']['containerPaths'] = array(
	"$wgDBname-local-public" => "<something>-img",
	"$wgDBname-local-thumb" => "<something>-img-thumb",
	"$wgDBname-local-deleted" => "<something>-img-deleted",
	"$wgDBname-local-temp" => "<something>-img-temp"
);

// Make MediaWiki use Amazon S3 for file storage.
// Replace <something> with the prefix of your S3 buckets, e.g. wonderfulbali234.
$wgLocalFileRepo = array (
	'class'             => 'LocalRepo',
	'name'              => 'local',
	'backend'           => 'AmazonS3',
	'scriptDirUrl'      => $wgScriptPath,
	'url'               => $wgScriptPath . '/img_auth.php',
	'hashLevels'        => 0,
	'zones'             => array(
		'public'  => array( 'url' => 'http://<something>-img.s3.amazonaws.com' ),
		'thumb'   => array( 'url' => 'http://<something>-img-thumb.s3.amazonaws.com' ),
		'temp'    => array( 'url' => false ),
		'deleted' => array( 'url' => false )
	)
);
```

# Needed IAM permissions

Replace `<something>` with the prefix of your S3 buckets, e.g. `wonderfulbali234`.
Note: you must create S3 buckets yourself (not wait for MediaWiki to do it).

```json
{
        "Effect": "Allow",
        "Action": [
                "s3:*"
        ],
        "Resource": [
                "arn:aws:s3:::<something>-img/*",
                "arn:aws:s3:::<something>-img-thumb/*",
                "arn:aws:s3:::<something>-img-temp/*",
                "arn:aws:s3:::<something>-img-deleted/*"
        ]
},
{
        "Effect": "Allow",
        "Action": [
                "s3:Get*",
                "s3:List*"
        ],
        "Resource": [
                "arn:aws:s3:::<something>-img",
                "arn:aws:s3:::<something>-img-thumb",
                "arn:aws:s3:::<something>-img-temp",
                "arn:aws:s3:::<something>-img-deleted"
        ]
}
```
