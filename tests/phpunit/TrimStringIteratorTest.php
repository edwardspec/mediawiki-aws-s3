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
 * Unit test of TrimStringIterator.
 *
 * @group TestsWithNoNeedForAwsCredentials
 * @covers TrimStringIterator
 */
class TrimStringIteratorTest extends MediaWikiTestCase {
	/**
	 * Check that TrimStringIterator correctly modifies input strings.
	 * @dataProvider dataProvider
	 * @param int[] $constructorArguments Parameters to pass to constructor.
	 * @param string[] $inputValues Values returned by the internal Iterator.
	 * @param string[] $expectedOutputValues Values expected from TrimStringIterator.
	 */
	public function testTrimStringIterator(
		array $constructorArguments,
		array $inputValues,
		array $expectedOutputValues
	) {
		$iterator = new TrimStringIterator(
			new ArrayIterator( $inputValues ),
			...$constructorArguments
		);
		$this->assertArrayEquals( $expectedOutputValues, iterator_to_array( $iterator ) );
	}

	/**
	 *
	 * Provides datasets for testTrimStringIterator().
	 */
	public function dataProvider() {
		return [
			[
				[ 5 ],
				[ 'test/1.txt', 'test/somefile.png', 'test/directory/' ],
				[ '1.txt', 'somefile.png', 'directory/' ]
			],
			[
				[ 7 ],
				[ 'test/1.txt', 'test/somefile.png', 'test/directory/' ],
				[ 'txt', 'mefile.png', 'rectory/' ]
			],
			[
				[ 5, 1 ],
				[ 'test/1.txt', 'test/somefile.png', 'test/directory/' ],
				[ '1.tx', 'somefile.pn', 'directory' ]
			],
			[
				[ 0, 4 ],
				[ 'test/1.txt', 'test/somefile.png', 'test/directory/' ],
				[ 'test/1', 'test/somefile', 'test/direct' ]
			]
		];
	}
}
