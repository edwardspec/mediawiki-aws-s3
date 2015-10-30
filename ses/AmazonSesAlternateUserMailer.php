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

use Aws\Ses\SesClient;
use Aws\Ses\Exception\SesException;

/**
 * Mailer for Amazon Simple Email Service
 *
 * @author Daniel Friesen <daniel@redwerks.org>
 */
abstract class AmazonSesAlternateUserMailer {

	static function hook( $headers, $to, $from, $subject, $body ) {
		global $wgAWSSES;
		if ( $wgAWSSES ) {
			try {
				self::send( $headers, $to, $from, $subject, $body );
			} catch ( MWException $e ) {
				return $e->getMessage();
			}

			return false;
		}

		return true;
	}

	private static function send( $headers, $to, $from, $subject, $body ) {
		global $wgAWSSES, $wgAWSCredentials, $wgAWSRegion, $wgAWSUseHTTPS, $wgEnotifMaxRecips;
		$params = $wgAWSSES === true ? array() : $wgAWSSES;

		wfDebug( "Sending mail via Aws\\Ses\\SesClient\n" );

		if ( isset( $params['aws-https'] ) ) {
			$useHTTPS = (bool)$params['aws-https'];
		} else {
			$useHTTPS = (bool)$wgAWSUseHTTPS;
		}

		$client = SesClient::factory( array(
			'key' => isset( $params['aws-key'] ) ? $params['aws-key'] : $wgAWSCredentials['key'],
			'secret' => isset( $params['aws-secret'] ) ? $params['aws-secret'] : $wgAWSCredentials['secret'],
			'region' => isset( $params['aws-region'] ) ? $params['aws-region'] : $wgAWSRegion,
			'scheme' => $useHTTPS ? 'https' : 'http',
			'ssl.certificate_authority' => $useHTTPS ?: null
		) );

		if ( wfIsWindows() ) {
			$endl = "\r\n";
		} else {
			$endl = "\n";
		}

		$headers['Subject'] = UserMailer::quotedPrintable( $subject );

		# When sending only to one recipient, shows it its email using To:
		if ( count( $to ) == 1 ) {
			$headers['To'] = $to[0]->toString();
		}

		$headers = UserMailer::arrayToHeaderString( $headers, $endl );
		$rawMessage = $headers . $endl . $endl . $body;

		$chunks = array_chunk( $to, min( 50, $wgEnotifMaxRecips ) );
		foreach ( $chunks as $chunk ) {
			try {
				$client->sendRawEmail( array(
					'Destinations' => $chunk,
					'RawMessage' => array(
						'Data' => base64_encode($rawMessage)
					)
				) );
			} catch ( SesException $e ) {
				# FIXME : some chunks might be sent while others are not!
				throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
			}
		}

		return true;
	}
}
