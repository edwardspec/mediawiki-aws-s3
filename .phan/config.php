<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

# Some .php files are outside of includes/ directory (something that should probably be fixed),
# so provide the proper paths here.

$cfg['directory_list'][] = 's3';

if ( getenv( 'PHAN_CHECK_TESTSUITE' ) ) {
	$cfg['directory_list'][] = 'tests/phpunit';

	# PHPUnit classes, etc. Should be parsed, but not analyzed.
	$cfg['directory_list'][] = $IP . '/tests';
	$cfg['exclude_analysis_directory_list'][] = $IP . '/tests';
}

# Parse (but not analyze) AWS SDK
$cfg['directory_list'][] = 'vendor';
$cfg['exclude_analysis_directory_list'][] = 'vendor';

$cfg['exclude_file_list'] = array_merge( $cfg['exclude_file_list'], [
	# These interfaces exist both in "extensions/AWS/vendor/" and in "vendor/" of MediaWiki core.
	# They are identical, but let's exclude one to not confuse Phan.
	'vendor/psr/http-message/src/RequestInterface.php',
	'vendor/psr/log/Psr/Log/LoggerInterface.php',
	'vendor/psr/log/Psr/Log/LogLevel.php',
	'vendor/psr/log/Psr/Log/NullLogger.php'
] );

# Temporarily suppressed warnings.

# Using "??=" syntax would require PHP 7.4, but we support MediaWiki 1.35 (LTS) and PHP 7.3.19.
$cfg['suppress_issue_types'][] = 'PhanPluginDuplicateExpressionAssignmentOperation';

return $cfg;
