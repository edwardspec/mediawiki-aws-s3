<?php

/**
 * AWS extension for MediaWiki.
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
 * Unit test of MWAWS\FSFile.
 *
 * @group TestsWithNoNeedForAwsCredentials
 * @covers MWAWS\FSFile
 */
class MWAWSFSFileTest extends MediaWikiTestCase {
	/**
	 * Verify that MWAWS\FSFile provides the methods that TempFSFile has.
	 * @dataProvider dataProvider
	 * @param string $methodName
	 * @param bool $mustExist
	 */
	public function testFSFile( $methodName, $mustExist ) {
		$fsFile = new MWAWS\FSFile( "path" );
		$methodExists = true;

		try {
			$fsFile->$methodName();
		} catch ( BadMethodCallException $e ) {
			$methodExists = false;
		}

		$this->assertEquals( $mustExist, $methodExists,
			"Is method \"$methodName\" provided by " . get_class( $fsFile ) . "?"
		);
	}

	/**
	 * Provides datasets for testFSFile().
	 */
	public function dataProvider() {
		return [
			[ 'purge', true ],
			[ 'bind', true ],
			[ 'preserve', true ],
			[ 'autocollect', true ],
			[ 'someMethodNotInTempFSFile', false ],
			[ 'anotherMethod', false ]
		];
	}
}
