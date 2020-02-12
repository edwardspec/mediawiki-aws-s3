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

/**
 * Helper class for profiling of S3-related API calls.
 * Usage:
 * 	$profiling = new AmazonS3ProfilingAssist( "explanation of what S3 operation will happen next" );
 * 	// Do something
 * 	$profiling->log();
 */
class AmazonS3ProfilingAssist {

	/**
	 * @var string
	 */
	protected $description;

	/**
	 * @var float
	 */
	protected $startTime;

	/**
	 * @var Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @param string $description Human-readable name of the operation that we are profiling.
	 */
	public function __construct( $description ) {
		$this->description = $description;
		$this->logger = MediaWiki\Logger\LoggerFactory::getInstance( 'FileOperation' );
		$this->startTime = microtime( true );
	}

	/**
	 * Write "how many seconds elapsed since this profiling started" to the log.
	 */
	public function log() {
		$endTime = microtime( true );

		$this->logger->debug(
			'S3FileBackend: Performance: {seconds} second spent on: {description}',
			[
				'seconds' => sprintf( '%.3f', ( $endTime - $this->startTime ) ),
				'description' => $this->description
			]
		);
	}
}
