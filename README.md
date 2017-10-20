This is a fork of Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS (only S3-related functionality, no SQS, etc.)

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

The fork was made because:
* We need this extension in production (it is the most correct way to use Amazon S3 as image repository)
* Extension was marked by its author as experimental (not even beta), and we found (and fixed) some bugs in it,
* We must apply bugfixes and improvements ASAP and can't wait for upstream (original extension is unmaintained and they haven't been reviewed for a year).

# Usage

```php
require_once("$IP/extensions/AWS/AWS.php");

// Configure AWS credentials.
// If your EC2 instance has an IAM instance profile, you don't need to set $wgAWSCredentials.
$wgAWSCredentials = array(
	'key' => '<something>',
	'secret' => '<something>',
	'token' => false
);

$wgAWSRegion = 'us-east-1'; # Northern Virginia

$wgFileBackends['s3']['containerPaths'] = array(
	"$wgDBname-local-public" => "<something>-img",
	"$wgDBname-local-thumb" => "<something>-img-thumb",
	"$wgDBname-local-deleted" => "<something>-img-deleted",
	"$wgDBname-local-temp" => "<something>-img-temp"
);

// Make MediaWiki use Amazon S3 for file storage.
$wgLocalFileRepo = array (
	'class'             => 'LocalRepo',
	'name'              => 'local',
	'backend'           => 'AmazonS3',
	'scriptDirUrl'      => $wgScriptPath,
	'scriptExtension'   => $wgScriptExtension,
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
