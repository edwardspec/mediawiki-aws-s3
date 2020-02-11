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

# Temporarily suppressed warnings.

# We are still backward compatible with MediaWiki 1.27 (and PHP 5.6),
# so we can't use "x ?? y" syntax (introduced in PHP 7) yet.
$cfg['suppress_issue_types'][] = 'PhanPluginDuplicateConditionalNullCoalescing';

return $cfg;
