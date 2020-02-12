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

use Wikimedia\TestingAccessWrapper;

/**
 * Unit test of AmazonS3LocalCache.
 *
 * @group TestsWithNoNeedForAwsCredentials
 * @covers AmazonS3LocalCache
 */
class AmazonS3LocalCacheTest extends MediaWikiTestCase {

	// Sample virtual URL (mwstore://something), as used by MediaWiki.
	protected $virtualUrl = 'mwstore://AmazonS3/local-public/Something_something.png';

	/**
	 * Verify that AmazonS3LocalCache::get() returns a temporary file when the cache is disabled.
	 */
	public function testDisabledLocalCache() {
		$this->setMwGlobals( 'wgAWSLocalCacheDirectory', false ); // Disable the cache

		$file = AmazonS3LocalCache::get( $this->virtualUrl );

		// When the cache is disabled, obtained file must be temporary, existing and empty.
		$this->assertInstanceOf( TempFSFile::class, $file );
		$this->assertTrue( $file->exists() );
		$this->assertSame( 0, $file->getSize() );

		// When the cache is disabled, postDownloadLogic() shouldn't do anything at all.
		$unmodifiedFile = clone $file;
		AmazonS3LocalCache::postDownloadLogic( $file );

		$this->assertEquals( $unmodifiedFile, $file );
	}

	/**
	 * Verify that get(), postDownloadLogic() and invalidate() work correctly with enabled cache.
	 */
	public function testEnabledLocalCache() {
		$largeFileContents = "Hello, World!";
		$smallFileContents = "Hello!";

		// Value for $wgAWSLocalCacheMinSize that would result in:
		// - $largeFileContents being cached
		// - $smallFileContents NOT being cached
		$thresholdInBytes = 0.5 * ( strlen( $largeFileContents ) + strlen( $smallFileContents ) );

		$expectedExtension = FSFile::extensionFromPath( $this->virtualUrl );
		$expectedTemporaryPathRegex = "/\.S3LocalCache\.[0-9a-fA-F]{32,32}\." .
			quotemeta( $expectedExtension ) . '$/';

		$cacheDir = wfTempDir() . "/" . wfRandomString( 32 );
		wfMkdirParents( $cacheDir );

		$this->setMwGlobals( 'wgAWSLocalCacheDirectory', $cacheDir ); // Enable the cache
		$this->setMwGlobals( 'wgAWSLocalCacheMinSize', $thresholdInBytes );

		// Step 1: get() a small file that doesn't exist in the cache yet.

		$file = AmazonS3LocalCache::get( $this->virtualUrl );

		// When the cache is enabled, obtained file must NOT be temporary.
		// Furthermore, if it wasn't found in the cache, then the file shouldn't exist yet.
		// It must also have a temporary name of a certain pattern (with ".S3LocalCache." in it).

		$this->assertInstanceOf( MWAWS\FSFile::class, $file );
		$this->assertNotInstanceOf( TempFSFile::class, $file );
		$this->assertFalse( $file->exists() );
		$this->assertRegExp( $expectedTemporaryPathRegex, $file->getPath() );

		// If contents downloaded into $file are smaller than $wgAWSLocalCacheMinSize bytes,
		// then $file must become temporary after postDownloadLogic().
		wfMkdirParents( dirname( $file->getPath() ) ); // This is done by backend, not LocalCache.
		file_put_contents( $file->getPath(), $smallFileContents );

		AmazonS3LocalCache::postDownloadLogic( $file );
		$this->assertInstanceOf( TempFSFile::class, $file );

		// This TempFSFile must be scheduled for automatic deletion.
		$testingWrapper = TestingAccessWrapper::newFromObject( $file );
		$this->assertTrue( $testingWrapper->canDelete );

		// Step 2: since this file was small (and shouldn't have been cached),
		// let's double-check that new get() won't find it in the cache.
		$file = AmazonS3LocalCache::get( $this->virtualUrl );

		$this->assertInstanceOf( MWAWS\FSFile::class, $file );
		$this->assertFalse( $file->exists() );

		// Step 3: verify that if we write more than $wgAWSLocalCacheMinSize bytes into $file,
		// then postDownloadLogic() will store it in the cache,
		// renaming it to have non-temporary name.
		wfMkdirParents( dirname( $file->getPath() ) ); // This is done by backend, not LocalCache.
		file_put_contents( $file->getPath(), $largeFileContents );

		$checkLargeFile = function ( FSFile $testFile )
			use ( $expectedTemporaryPathRegex, $largeFileContents )
		{
			$this->assertInstanceOf( MWAWS\FSFile::class, $testFile );
			$this->assertNotInstanceOf( TempFSFile::class, $testFile );
			$this->assertTrue( $testFile->exists() );
			$this->assertNotRegExp( $expectedTemporaryPathRegex, $testFile->getPath() );
			$this->assertEquals( $largeFileContents, file_get_contents( $testFile->getPath() ) );
		};

		AmazonS3LocalCache::postDownloadLogic( $file );
		$checkLargeFile( $file );

		// Step 4: since the file should now be in the cache,
		// verify that new get() will actually find it there.
		$file = AmazonS3LocalCache::get( $this->virtualUrl );
		$checkLargeFile( $file );

		// Step 5: verify that invalidate() successfully removes the file from the cache.
		AmazonS3LocalCache::invalidate( $this->virtualUrl );
		$file = AmazonS3LocalCache::get( $this->virtualUrl );

		$this->assertFalse( $file->exists() );

		// Step 6: verify that an attempt to invalidate() a non-existent file
		// wouldn't result in any errors or warnings.
		AmazonS3LocalCache::invalidate( $this->virtualUrl );
	}
}
