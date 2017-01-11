This is a fork of Extension:AWS - https://www.mediawiki.org/wiki/Extension:AWS (only S3-related functionality, no SQS, etc.)

What it does: it stores images in Amazon S3 instead of the local directory.

Why is this needed: when images are in S3, Amazon EC2 instance which runs MediaWiki doesn't contain any important data and can be created/destroyed by Autoscaling.

The fork was made because:
* We need this extension in production (it is the most correct way to use Amazon S3 as image repository)
* Extension was marked by its author as experimental (not even beta), and we found (and fixed) some bugs in it,
* We must apply bugfixes and improvements ASAP and can't wait for upstream (original extension is unmaintained and they haven't been reviewed for a year).
