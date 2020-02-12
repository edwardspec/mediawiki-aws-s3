<?php
/**
 * @file
 * Backward compatibility file to support require_once() in LocalSettings.
 *
 * Modern syntax (to enable AWS in LocalSettings.php) is
 * wfLoadExtension( 'AWS' );
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'AWS' );
} else {
	die( 'This version of the AWS extension requires MediaWiki 1.27+' );
}
