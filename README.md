Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

# Installation

1\) Download the extension: `git clone --depth 1 https://github.com/edwardspec/mediawiki-aws-s3-stable-fork.git AWS`

2\) Move the AWS directory to the "extensions" directory of your MediaWiki, e.g. `/var/www/html/w/extensions` (assuming MediaWiki is in `/var/www/html/w`).

3\) Run `composer install` from `/var/www/html/w/extensions/AWS` (to download dependencies). If you don't have Composer installed, see https://www.mediawiki.org/wiki/Composer for how to install it.

4\) Choose a unique name (not taken by another AWS user) for your Amazon S3 buckets, e.g. `wonderfulbali234`. Create four S3 buckets: `wonderfulbali234`, `wonderfulbali234-thumb`, `wonderfulbali234-deleted`, `wonderfulbali234-temp`. Note: this name will be seen in URL of images.

4a\) If you use a custom S3 domain, such as for a CDN, see the "Custom S3 domain" section below.

5a\) If your EC2 instance has an IAM instance profile (recommended), copy everything from "Needed IAM permissions" (see below) to inline policy of the IAM role. See https://console.aws.amazon.com/iam/home#/roles

5b\) If your EC2 instance doesn't have an IAM profile, obtain key/secret for AWS API. You'll need to write it in LocalSettings.php (see below).

6\) Modify LocalSettings.php (see below).

# Configuration in LocalSettings.php

```php
wfLoadExtension( 'AWS' );

// Configure AWS credentials.
// THIS IS NOT NEEDED if your EC2 instance has an IAM instance profile.
$wgAWSCredentials = [
	'key' => '<something>',
	'secret' => '<something>',
	'token' => false
];

$wgAWSRegion = 'us-east-1'; # Northern Virginia

// Replace <something> with the prefix of your S3 buckets, e.g. wonderfulbali234.
$wgAWSBucketPrefix = "<something>";

// If you have a custom S3 domain, set $wgAWSBucketDomain = "domain.com";
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
                "arn:aws:s3:::<something>/*",
                "arn:aws:s3:::<something>-thumb/*",
                "arn:aws:s3:::<something>-temp/*",
                "arn:aws:s3:::<something>-deleted/*"
        ]
},
{
        "Effect": "Allow",
        "Action": [
                "s3:Get*",
                "s3:List*"
        ],
        "Resource": [
                "arn:aws:s3:::<something>",
                "arn:aws:s3:::<something>-thumb",
                "arn:aws:s3:::<something>-temp",
                "arn:aws:s3:::<something>-deleted"
        ]
}
```

# Custom S3 Domain

You can set a custom S3 domain, which is useful if you use a CDN such as CloudFlare to cache your images.

See https://docs.aws.amazon.com/AmazonS3/latest/dev/VirtualHosting.html for further information

1\) When you create your four S3 buckets, you must include your full domain in their names, eg: `files.example.com`, `files-thumb.example.com`, `files-temp.example.com`, `files-deleted.example.com`

2\) At your DNS provider, set a CNAME for each bucket, eg `files-thumb` points to `files-thumb.example.com.s3.amazonaws.com`

3\) In LocalSettings.php, add the configuration  `$wgAWSBucketDomain = "example.com";`
