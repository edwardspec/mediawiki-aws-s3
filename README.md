Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

# Installation

1\) Download the extension: `git clone --depth 1 https://github.com/edwardspec/mediawiki-aws-s3-stable-fork.git AWS`

2\) Move the AWS directory to the "extensions" directory of your MediaWiki, e.g. `/var/www/html/w/extensions` (assuming MediaWiki is in `/var/www/html/w`).

3\) Run `composer install` from `/var/www/html/w/extensions/AWS` (to download dependencies). If you don't have Composer installed, see https://www.mediawiki.org/wiki/Composer for how to install it.

4\) Choose a unique name (not taken by another AWS user) for your Amazon S3 buckets, e.g. `wonderfulbali234`. Create four S3 buckets: `wonderfulbali234`, `wonderfulbali234-thumb`, `wonderfulbali234-deleted`, `wonderfulbali234-temp`. Note: this name will be seen in URL of images.

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

# Custom S3 domain

You can use a domain name for images (for example, `img.mysite.com`). This is needed when you want a CDN (such as CloudFlare) to cache your images. See [https://docs.aws.amazon.com/AmazonS3/latest/dev/VirtualHosting.html Virtual Hosting of Buckets] for details.

1\) At your DNS provider, add two CNAME entries: one for images, another for thumbnails (for example, `img.mysite.com` and `img-thumb.mysite.com` - point them to `<your-wgAWSBucketPrefix>.s3.amazonaws.com` and `<your-wgAWSBucketPrefix>-thumb.s3.amazonaws.com`).

2\) In LocalSettings.php, set `$wgAWSBucketDomain`. The following values are supported:

```php
// This will use <bucket-name>.cloudfront.net
$wgAWSBucketDomain = '$1.cloudfront.net';

// Default
$wgAWSBucketDomain = '$1.s3.amazonaws.com';

// This will use "media.mysite.com" for "public" zone
// and "media-thumb.mysite.com" for "thumb" zone.
$wgAWSBucketDomain = 'media$2.mysite.com'

// Alternatively, zone URLs can be specified directly:
$wgAWSBucketDomain = [
  'public' => 'media.mysite.com',
  'thumb' => 'thumb.mysite.com'
];
```
