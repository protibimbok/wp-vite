<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Vite\Exception;
use Vite\Manifest;
use Vite\Styles;

final class StylesTest extends WordPressTestCase {
	/** @var list<array<mixed>> */
	private array $registered = array();
	/** @var list<array<mixed>> */
	private array $data = array();
	/** @var list<array<mixed>> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		$this->record( 'wp_register_style', $this->registered );
		$this->record( 'wp_style_add_data', $this->data );
		$this->record( 'wp_enqueue_style', $this->enqueued );
	}

	public function test_handles_are_derived_from_the_file(): void {
		self::assertSame( 'demo-admin-style-index', $this->styles()->handle( 'admin/style-index.css' ) );
		self::assertSame( 'demo-chunks-shared-abc123', $this->styles()->handle( 'chunks/shared-abc123.css' ) );
	}

	public function test_registers_css_then_style_css_with_rtl_data(): void {
		$handles = $this->styles()->register( 'admin/index' );
		self::assertSame( array( 'demo-admin-index', 'demo-admin-style-index' ), $handles );
		self::assertSame(
			array(
				array( 'demo-admin-index', 'https://example.test/build/admin/index.css', array(), '959b40dd2beb3b54ebbb' ),
				array( 'demo-admin-style-index', 'https://example.test/build/admin/style-index.css', array(), '959b40dd2beb3b54ebbb' ),
			),
			$this->registered
		);
		self::assertSame(
			array( array( 'demo-admin-index', 'rtl', 'replace' ), array( 'demo-admin-style-index', 'rtl', 'replace' ) ),
			$this->data
		);
	}

	public function test_registers_each_handle_once(): void {
		$styles = $this->styles();
		$styles->register( 'admin/index' );
		$styles->enqueue( 'admin/index' );
		self::assertCount( 2, $this->registered );
		self::assertSame( array( array( 'demo-admin-index' ), array( 'demo-admin-style-index' ) ), $this->enqueued );
	}

	public function test_no_rtl_data_without_rtl_build(): void {
		$manifest = Manifest::fromArray( array(
			'version' => 1,
			'rtl'     => false,
			'entries' => array( 'a' => array( 'css' => array( 'a.css' ), 'styleCss' => array(), 'version' => '1' ) ),
			'chunks'  => array(),
			'blocks'  => array(),
		) );
		( new Styles( $manifest, static fn( string $f ): string => $f, 'demo' ) )->register( 'a' );
		self::assertCount( 1, $this->registered );
		self::assertSame( array(), $this->data );
	}

	public function test_entries_without_css_register_nothing(): void {
		self::assertSame( array(), $this->styles()->enqueue( 'blocks/counter/view' ) );
		self::assertSame( array(), $this->registered );
	}

	public function test_rejects_unknown_entries(): void {
		$this->expectException( Exception::class );
		$this->styles()->register( 'nope' );
	}

	private function styles(): Styles {
		$manifest = Manifest::load( __DIR__ . '/fixtures/demo/build/wp-vite.php' );
		self::assertNotNull( $manifest );
		return new Styles( $manifest, static fn( string $file ): string => 'https://example.test/build/' . $file, 'demo' );
	}
}
