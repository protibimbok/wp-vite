<?php
declare( strict_types=1 );

namespace Vite\Tests;

use PHPUnit\Framework\TestCase;
use Vite\Exception;
use Vite\Manifest;

final class ManifestTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/demo/build/wp-vite.php';

	public function test_load_returns_null_when_the_file_is_missing(): void {
		self::assertNull( Manifest::load( __DIR__ . '/fixtures/missing.php' ) );
	}

	public function test_load_rejects_a_file_that_does_not_return_an_array(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'did not return an array' );
		Manifest::load( __DIR__ . '/fixtures/not-array.php' );
	}

	public function test_from_array_rejects_other_versions(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Unsupported wp-vite manifest version 2' );
		Manifest::fromArray( array( 'version' => 2, 'entries' => array(), 'chunks' => array(), 'blocks' => array() ) );
	}

	public function test_from_array_rejects_a_missing_version(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'version NULL' );
		Manifest::fromArray( array( 'entries' => array() ) );
	}

	public function test_from_array_rejects_missing_collections(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'missing the "chunks" array' );
		Manifest::fromArray( array( 'version' => 1, 'entries' => array(), 'blocks' => array() ) );
	}

	public function test_from_array_defaults_optional_top_level_keys(): void {
		$manifest = Manifest::fromArray( array( 'version' => 1, 'entries' => array(), 'chunks' => array(), 'blocks' => array() ) );
		self::assertFalse( $manifest->rtl() );
		self::assertNull( $manifest->blocksRoot() );
		self::assertSame( array(), $manifest->entries() );
		self::assertSame( array(), $manifest->chunks() );
		self::assertSame( array(), $manifest->blocks() );
	}

	public function test_reads_top_level_values(): void {
		$manifest = $this->manifest();
		self::assertTrue( $manifest->rtl() );
		self::assertSame( 'blocks', $manifest->blocksRoot() );
		self::assertSame( array( 'blocks/counter/index', 'blocks/counter/view', 'admin/index' ), array_keys( $manifest->entries() ) );
		self::assertSame(
			array(
				array( 'name' => 'demo/counter', 'dir' => 'blocks/counter' ),
				array( 'name' => 'demo/other', 'dir' => 'blocks/other' ),
			),
			$manifest->blocks()
		);
	}

	public function test_entry_lookup(): void {
		$manifest = $this->manifest();
		self::assertTrue( $manifest->has( 'admin/index' ) );
		self::assertFalse( $manifest->has( 'admin/missing' ) );
		self::assertNull( $manifest->entry( 'admin/missing' ) );

		$entry = $manifest->entry( 'admin/index' );
		self::assertNotNull( $entry );
		self::assertSame( 'admin/index.js', $entry['file'] );
		self::assertSame( 'es', $entry['format'] );
		self::assertSame( array( '@wordpress/a11y' ), $entry['dynamicModules'] );
		self::assertSame( array( 'chunks/shared-abc123.js' ), $entry['imports'] );
		self::assertSame( array( 'admin/style-index.css' ), $entry['styleCss'] );
		self::assertSame( '959b40dd2beb3b54ebbb', $entry['version'] );
	}

	public function test_chunk_lookup(): void {
		$manifest = $this->manifest();
		self::assertNull( $manifest->chunk( 'chunks/missing.js' ) );
		$chunk = $manifest->chunk( 'chunks/shared-abc123.js' );
		self::assertNotNull( $chunk );
		self::assertSame( array( 'wp-i18n' ), $chunk['scripts'] );
		self::assertSame( '2e521e87632c9bccbd99', $chunk['version'] );
	}

	public function test_block_entries_are_keyed_by_field(): void {
		$manifest = $this->manifest();
		self::assertSame(
			array( 'editorScript' => 'blocks/counter/index', 'viewScriptModule' => 'blocks/counter/view' ),
			$manifest->blockEntries( 'demo/counter' )
		);
		self::assertSame( array( 'viewScriptModule' => 'blocks/counter/view' ), $manifest->blockEntries( 'demo/other' ) );
		self::assertSame( array(), $manifest->blockEntries( 'demo/none' ) );
	}

	public function test_block_entry_resolves_the_entry(): void {
		$manifest = $this->manifest();
		$entry    = $manifest->blockEntry( 'demo/other', 'viewScriptModule' );
		self::assertNotNull( $entry );
		self::assertSame( 'blocks/counter/view.js', $entry['file'] );
		self::assertNull( $manifest->blockEntry( 'demo/other', 'editorScript' ) );
	}

	private function manifest(): Manifest {
		$manifest = Manifest::load( self::FIXTURE );
		self::assertNotNull( $manifest );
		return $manifest;
	}
}
