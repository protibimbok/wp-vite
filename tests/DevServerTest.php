<?php
declare( strict_types=1 );

namespace Vite\Tests;

use PHPUnit\Framework\TestCase;
use Vite\DevServer;
use Vite\Exception;

final class DevServerTest extends TestCase {
	public function test_load_returns_null_without_a_hot_file(): void {
		self::assertNull( DevServer::load( __DIR__ . '/fixtures/missing.json' ) );
	}

	public function test_load_rejects_invalid_json(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'not valid JSON' );
		DevServer::load( __DIR__ . '/fixtures/truncated.json' );
	}

	public function test_from_array_requires_an_origin(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'no "origin"' );
		DevServer::fromArray( array( 'entries' => array() ) );
	}

	public function test_from_array_requires_entries(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'no "entries"' );
		DevServer::fromArray( array( 'origin' => 'http://localhost:5173' ) );
	}

	public function test_from_array_requires_an_entry_url(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'entry "admin/index" has no "url"' );
		DevServer::fromArray( array( 'origin' => 'http://localhost:5173', 'entries' => array( 'admin/index' => array() ) ) );
	}

	public function test_from_array_defaults_optional_keys(): void {
		$dev = DevServer::fromArray( array( 'origin' => 'http://localhost:5173', 'entries' => array() ) );
		self::assertSame( 'src', $dev->srcDir() );
		self::assertFalse( $dev->refresh() );
		self::assertSame( array(), $dev->entries() );
		self::assertSame( array(), $dev->blocks() );
	}

	public function test_reads_the_hot_file(): void {
		$dev = $this->dev();
		self::assertSame( 'http://localhost:5173', $dev->origin() );
		self::assertSame( 'src', $dev->srcDir() );
		self::assertTrue( $dev->refresh() );
		self::assertSame( array( array( 'name' => 'demo/counter', 'dir' => 'blocks/counter' ) ), $dev->blocks() );
	}

	public function test_entries_are_normalised(): void {
		$dev = $this->dev();
		self::assertTrue( $dev->has( 'admin/index' ) );
		self::assertFalse( $dev->has( 'frontend/index' ) );
		self::assertNull( $dev->entry( 'frontend/index' ) );
		self::assertSame(
			array(
				'url'     => 'http://localhost:5173/src/blocks/counter/index.tsx',
				'scripts' => array( 'wp-blocks', 'react-jsx-runtime' ),
				'modules' => array(),
				'styles'  => array( 'src/blocks/counter/editor.scss' ),
			),
			$dev->entry( 'blocks/counter/index' )
		);
		self::assertSame(
			array(
				'url'     => 'http://localhost:5173/src/admin/index.tsx',
				'scripts' => array(),
				'modules' => array(),
				'styles'  => array(),
			),
			$dev->entry( 'admin/index' )
		);
	}

	public function test_url_joins_against_the_origin(): void {
		$dev = $this->dev();
		self::assertSame( 'http://localhost:5173/src/admin/style.scss', $dev->url( 'src/admin/style.scss' ) );
		self::assertSame( 'http://localhost:5173/@vite/client', $dev->url( '/@vite/client' ) );
	}

	private function dev(): DevServer {
		$dev = DevServer::load( __DIR__ . '/fixtures/hot.json' );
		self::assertNotNull( $dev );
		return $dev;
	}
}
