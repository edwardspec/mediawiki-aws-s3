Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

# Installation

1\) Download the extension: `git clone --depth 1 https://github.com/edwardspec/mediawiki-aws-s3.git AWS`

2\) Move the AWS directory to the "extensions" directory of your MediaWiki, e.g. `/var/www/html/w/extensions` (assuming MediaWiki is in `/var/www/html/w`).

3\) Run `composer install` from `/var/www/html/w/extensions/AWS` (to download dependencies). If you don't have Composer installed, see https://www.mediawiki.org/wiki/Composer for how to install it.

4\) Create an S3 bucket for images, e.g. `wonderfulbali234`. Note: this name will be seen in URL of images.

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

// Replace <something> with the name of your S3 bucket, e.g. wonderfulbali234.
$wgAWSBucketName = "<something>";
```

If you do not specify credentials via $wgAWSCredentials, they are retrieved using the [default credentials chain](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html). This means they are obtained from IAM instance profile (if this EC2 instance has it) or from environmental variables `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_SESSION_TOKEN`.

# Needed IAM permissions

Replace `<something>` with the name of your S3 bucket, e.g. `wonderfulbali234`.

```json
{
        "Effect": "Allow",
        "Action": [
                "s3:*"
        ],
        "Resource": [
                "arn:aws:s3:::<something>/*"
        ]
},
{
        "Effect": "Allow",
        "Action": [
                "s3:Get*",
                "s3:List*"
        ],
        "Resource": [
                "arn:aws:s3:::<something>"
        ]
}
```

# Custom S3 domain

You can use a domain name for images (for example, `img.mysite.com`). This is needed when you want a CDN (such as CloudFlare) to cache your images. See [https://docs.aws.amazon.com/AmazonS3/latest/dev/VirtualHosting.html Virtual Hosting of Buckets] for details.

1\) At your DNS provider, add a CNAME entry. For example, point `img.mysite.com` to `<your-wgAWSBucketName>.s3.amazonaws.com`).

2\) In LocalSettings.php, set `$wgAWSBucketDomain`. The following values are supported:

```php
$wgAWSBucketDomain = 'img.mysite.com';

// This will use <bucket-name>.cloudfront.net
$wgAWSBucketDomain = '$1.cloudfront.net';

// Default
$wgAWSBucketDomain = '$1.s3.amazonaws.com';
```

# Migrating images

By default the extension stores all images in the top-level directory of the bucket.

If you are migrating an existing `images` folder, MediaWiki uses a hashed directory structure. You will need to add this to your `LocalSettings.php` for the image paths to be generated correctly.

```php
$wgAWSRepoHashLevels = '2'; # Default 0
# 2 means that S3 objects will be named a/ab/Filename.png (same as when MediaWiki stores files in local directories)

$wgAWSRepoDeletedHashLevels = '3'; # Default 0
# 3 for naming a/ab/abc/Filename.png (same as when MediaWiki stores deleted files in local directories)
```

If your `images` folder previously was serving multiple wikis split into different subdirectories, you need to set `$wgAWSBucketTopSubdirectory`. This setting is not recommended for new wikis.

```php
$wgAWSBucketTopSubdirectory = '/something';
# images will be in bucketname.s3.amazonaws.com/something/File.png instead of bucketname.s3.amazonaws.com/File.png.
```

# Troubleshooting

## My wiki uses Extension:MultimediaViewer (or shows images as popups), and now they don't work

If you have this issue, attach a [CORS policy](https://docs.aws.amazon.com/AmazonS3/latest/dev/cors.html) to your S3 bucket with images.
This will allow JavaScript (in this case, popup-showing script of Extension:MultimediaViewer) from the domain where your Wiki is hosted to download the images from Amazon S3 URL. For example, if the domain of your wiki is `www.example.com`, you can use the following policy:
```xml
<CORSConfiguration>
 <CORSRule>
   <AllowedOrigin>http://www.example.com</AllowedOrigin>
   <AllowedMethod>GET</AllowedMethod>
 </CORSRule>
</CORSConfiguration>
```

## Local storage is still used, even though the extension is shown to be installed

This can happen if some settings are missing. Make sure you have at least `$wgAWSBucketName` and `$wgAWSRegion` are set.

## I'm getting Exception, even though the extension is shown to be installed

This can happen if some settings are missing. Make sure that `$wgAWSRegion` is set (even if your config doesn't use it, e.g. when using non-Amazon providers).

# Non-standard configuration

## Using another S3-compatible service (not Amazon S3 itself)

You can use non-Amazon software that supports S3 API (such as Apache CloudStack, Digital Ocean, etc.) instead of Amazon S3 itself. To enable this, add the following lines to LocalSettings.php:

```php
//The url used for the API (PutObject, etc.)
$wgFileBackends['s3']['endpoint'] = 'https://my-custom-url';
//The url used for showing images. $1 is translated to the bucket name.
$wgAWSBucketDomain = '$1.my-custom-url';
```

Make sure `$wgAWSBucketName` and `$wgAWSRegion` are set as well.
