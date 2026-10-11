<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Functions;
use Vite\DevServer;
use Vite\Exception;
use Vite\Manifest;
use Vite\ScriptModules;

final class ScriptModulesTest extends WordPressTestCase {
	/** @var list<array<mixed>> */
	private array $registered = array();
	/** @var list<array<mixed>> */
	private array $enqueued = array();
	/** @var list<array<mixed>> */
	private array $scripts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->record( 'wp_register_script_module', $this->registered );
		$this->record( 'wp_enqueue_script_module', $this->enqueued );
		$this->record( 'wp_enqueue_script', $this->scripts );
	}

	public function test_registers_chunks_before_the_entry_with_typed_deps(): void {
		$id = $this->modules()->register( 'admin/index' );

		self::assertSame( 'demo/admin/index', $id );
		self::assertSame(
			array(
				array( 'demo/chunks/shared-abc123.js', 'https://example.test/build/chunks/shared-abc123.js', array(), '2e521e87632c9bccbd99' ),
				array(
					'demo/admin/index',
					'https://example.test/build/admin/index.js',
					array( 'demo/chunks/shared-abc123.js', array( 'id' => '@wordpress/a11y', 'import' => 'dynamic' ) ),
					'959b40dd2beb3b54ebbb',
				),
			),
			$this->registered
		);
	}

	public function test_module_externals_are_static_deps(): void {
		$this->modules()->register( 'blocks/counter/view' );
		self::assertSame( array( '@wordpress/interactivity' ), $this->registered[0][2] );
	}

	public function test_dynamic_chunks_are_dynamic_deps_and_registered_once(): void {
		$manifest = Manifest::fromArray( array(
			'version' => 1,
			'entries' => array(
				'a' => $this->entry( 'a.js', array( 'imports' => array( 'chunks/s.js' ), 'dynamicImports' => array( 'chunks/d.js' ) ) ),
				'b' => $this->entry( 'b.js', array( 'imports' => array( 'chunks/s.js' ) ) ),
			),
			'chunks'  => array(
				'chunks/s.js' => $this->chunk( 'chunks/s.js' ),
				'chunks/d.js' => $this->chunk( 'chunks/d.js', array( 'imports' => array( 'chunks/s.js' ), 'modules' => array( '@wordpress/a11y' ) ) ),
			),
			'blocks'  => array(),
		) );
		$modules = $this->modules( $manifest );
		$modules->register( 'a' );
		$modules->register( 'b' );
		$modules->register( 'a' );

		self::assertSame( array( 'demo/chunks/s.js', 'demo/chunks/d.js', 'demo/a', 'demo/b' ), array_column( $this->registered, 0 ) );
		self::assertSame( array( 'demo/chunks/s.js', '@wordpress/a11y' ), $this->registered[1][2] );
		self::assertSame( array( 'demo/chunks/s.js', array( 'id' => 'demo/chunks/d.js', 'import' => 'dynamic' ) ), $this->registered[2][2] );
	}

	public function test_circular_chunk_imports_terminate(): void {
		$manifest = Manifest::fromArray( array(
			'version' => 1,
			'entries' => array( 'a' => $this->entry( 'a.js', array( 'imports' => array( 'chunks/x.js' ) ) ) ),
			'chunks'  => array(
				'chunks/x.js' => $this->chunk( 'chunks/x.js', array( 'imports' => array( 'chunks/y.js' ) ) ),
				'chunks/y.js' => $this->chunk( 'chunks/y.js', array( 'imports' => array( 'chunks/x.js' ) ) ),
			),
			'blocks'  => array(),
		) );
		$this->modules( $manifest )->register( 'a' );
		self::assertSame( array( 'demo/chunks/y.js', 'demo/chunks/x.js', 'demo/a' ), array_column( $this->registered, 0 ) );
	}

	public function test_args_are_forwarded_only_when_supported(): void {
		$this->modules( null, false )->register( 'admin/index', array( 'in_footer' => true ) );
		$this->modules( null, true )->register( 'admin/index', array( 'in_footer' => true ) );
		self::assertCount( 4, $this->registered[1], 'dropped before WordPress 6.9' );
		self::assertCount( 4, $this->registered[2], 'chunks never receive args' );
		self::assertSame( array( 'in_footer' => true ), $this->registered[3][4] );
	}

	public function test_enqueue_adds_classic_dependencies(): void {
		$this->modules()->enqueue( 'admin/index' );
		self::assertSame( array( array( 'demo/admin/index' ) ), $this->enqueued );
		self::assertSame( array( array( 'react-jsx-runtime' ), array( 'wp-element' ) ), $this->scripts );
	}

	public function test_files_lists_the_entry_and_its_chunks(): void {
		self::assertSame(
			array( 'demo/admin/index' => 'admin/index.js', 'demo/chunks/shared-abc123.js' => 'chunks/shared-abc123.js' ),
			$this->modules()->files( 'admin/index' )
		);
	}

	public function test_rejects_unknown_entries(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'entry "nope" is not in the manifest' );
		$this->modules()->register( 'nope' );
	}

	public function test_rejects_iife_entries(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'is a classic script' );
		$this->modules()->register( 'blocks/counter/index' );
	}

	public function test_rejects_unknown_chunks(): void {
		$manifest = Manifest::fromArray( array(
			'version' => 1,
			'entries' => array( 'a' => $this->entry( 'a.js', array( 'imports' => array( 'chunks/missing.js' ) ) ) ),
			'chunks'  => array(),
			'blocks'  => array(),
		) );
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'chunk "chunks/missing.js"' );
		$this->modules( $manifest )->register( 'a' );
	}

	public function test_dev_entries_register_from_the_dev_server_with_module_deps(): void {
		$id = $this->dev_modules()->register( 'blocks/counter/view', array( 'in_footer' => true ) );
		self::assertSame( 'demo/blocks/counter/view', $id );
		self::assertSame(
			array( array( 'demo/blocks/counter/view', 'http://localhost:5173/src/blocks/counter/view.ts', array( '@wordpress/interactivity' ), null, array( 'in_footer' => true ) ) ),
			$this->registered
		);
	}

	public function test_dev_enqueue_uses_the_hot_file_scripts(): void {
		$this->dev_modules()->enqueue( 'blocks/counter/view' );
		self::assertSame( array( array( 'demo/blocks/counter/view' ) ), $this->enqueued );
		self::assertSame( array( array( 'wp-i18n' ) ), $this->scripts );
	}

	public function test_entries_the_dev_server_does_not_serve_register_from_the_manifest(): void {
		$modules = $this->dev_modules();
		$modules->register( 'admin/index' );
		$modules->register( 'blocks/counter/view' );
		self::assertSame(
			array( 'https://example.test/build/chunks/shared-abc123.js', 'https://example.test/build/admin/index.js', 'http://localhost:5173/src/blocks/counter/view.ts' ),
			array_column( $this->registered, 1 )
		);
	}

	public function test_dev_stylesheet_entries_are_not_script_modules(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'entry "blocks/counter/style" is not in the manifest' );
		$this->dev_modules()->register( 'blocks/counter/style' );
	}

	private function modules( ?Manifest $manifest = null, bool $supportsArgs = false, ?DevServer $dev = null ): ScriptModules {
		$manifest ??= Manifest::load( __DIR__ . '/fixtures/demo/build/wp-vite.php' );
		self::assertNotNull( $manifest );
		return new ScriptModules( $manifest, static fn( string $file ): string => 'https://example.test/build/' . $file, 'demo', $supportsArgs, $dev );
	}

	// The fixture hot file serves `blocks/counter/view` but lists `admin/index` without deps, so the
	// latter stands in for an entry the dev server knows nothing about once its hot entry is removed.
	private function dev_modules(): ScriptModules {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/hot.json' ), true );
		self::assertIsArray( $data );
		unset( $data['entries']['admin/index'] );
		return $this->modules( null, true, DevServer::fromArray( $data ) );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function entry( string $file, array $overrides = array() ): array {
		return $overrides + array(
			'file'           => $file,
			'format'         => 'es',
			'src'            => 'src/' . $file,
			'sources'        => array(),
			'scripts'        => array(),
			'modules'        => array(),
			'dynamicModules' => array(),
			'imports'        => array(),
			'dynamicImports' => array(),
			'css'            => array(),
			'styleCss'       => array(),
			'version'        => 'v-' . $file,
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function chunk( string $file, array $overrides = array() ): array {
		return $overrides + array(
			'file'           => $file,
			'scripts'        => array(),
			'modules'        => array(),
			'dynamicModules' => array(),
			'imports'        => array(),
			'dynamicImports' => array(),
			'css'            => array(),
			'version'        => 'v-' . $file,
		);
	}
}
