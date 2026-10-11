<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Vite\Exception;
use Vite\Vite;

final class ViteTest extends WordPressTestCase {
	private const PLUGIN_FILE = __DIR__ . '/fixtures/demo/demo.php';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'plugins_url' )->alias( static fn( string $path, string $file ): string => 'https://example.test/wp-content/plugins/' . basename( dirname( str_replace( '\\', '/', $file ) ) ) . '/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	public function test_init_derives_paths_and_prefix_from_the_plugin_file(): void {
		$vite = Vite::init( self::PLUGIN_FILE );
		self::assertTrue( $vite->is_built() );
		self::assertSame( 'build', $vite->build_dir() );
		self::assertSame( 'https://example.test/wp-content/plugins/demo/build/admin/index.js', $vite->url( 'admin/index.js' ) );
		self::assertSame( 'demo/admin/index', $vite->module_id( 'admin/index' ) );
		self::assertNull( $vite->dev() );
	}

	public function test_manifest_in_the_plugin_root(): void {
		$vite = Vite::init( '/plugins/demo/demo.php', '/plugins/demo/wp-vite.php' );
		self::assertFalse( $vite->is_built() );
		self::assertSame( '', $vite->build_dir() );
		self::assertSame( 'https://example.test/wp-content/plugins/demo/x.js', $vite->url( 'x.js' ) );
	}

	public function test_init_accepts_custom_paths_and_prefix(): void {
		$vite = Vite::init( 'C:\\plugins\\demo\\demo.php', 'C:\\plugins\\demo\\dist\\assets\\wp-vite.php', null, 'my' );
		self::assertSame( 'dist/assets', $vite->build_dir() );
		self::assertSame( 'https://example.test/wp-content/plugins/demo/dist/assets/x.js', $vite->url( 'x.js' ) );
	}

	public function test_init_rejects_a_build_dir_outside_the_plugin(): void {
		$this->expectException( Exception::class );
		Vite::init( '/plugins/demo/demo.php', '/plugins/other/build/wp-vite.php' );
	}

	public function test_manifest_throws_when_not_built(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Run the build' );
		Vite::init( '/plugins/demo/demo.php' )->manifest();
	}

	public function test_enqueue_script_module_registers_modules_and_styles(): void {
		$modules = array();
		$styles  = array();
		$this->record( 'wp_register_script_module', $modules );
		$this->record( 'wp_enqueue_script_module', $styles );
		$this->record( 'wp_enqueue_script', $styles );
		$this->record( 'wp_register_style', $styles );
		$this->record( 'wp_style_add_data', $styles );
		$this->record( 'wp_enqueue_style', $styles );

		$id = $this->vite()->enqueue_script_module( 'admin/index' );

		self::assertSame( 'demo/admin/index', $id );
		self::assertSame( array( 'demo/chunks/shared-abc123.js', 'demo/admin/index' ), array_column( $modules, 0 ) );
		self::assertSame( 'https://example.test/wp-content/plugins/demo/build/admin/index.js', $modules[1][1] );
		self::assertSame(
			array(
				array( 'demo/admin/index' ),
				array( 'react-jsx-runtime' ),
				array( 'wp-element' ),
				array( 'demo-admin-index', 'https://example.test/wp-content/plugins/demo/build/admin/index.css', array(), '959b40dd2beb3b54ebbb' ),
				array( 'demo-admin-index', 'rtl', 'replace' ),
				array( 'demo-admin-style-index', 'https://example.test/wp-content/plugins/demo/build/admin/style-index.css', array(), '959b40dd2beb3b54ebbb' ),
				array( 'demo-admin-style-index', 'rtl', 'replace' ),
				array( 'demo-admin-index' ),
				array( 'demo-admin-style-index' ),
			),
			$styles
		);
	}

	public function test_register_only_registers(): void {
		$calls = array();
		$this->record( 'wp_register_script_module', $calls );
		$this->record( 'wp_register_style', $calls );
		$this->record( 'wp_style_add_data', $calls );
		Functions\expect( 'wp_enqueue_script_module' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$vite = $this->vite();
		self::assertSame( 'demo/admin/index', $vite->register_script_module( 'admin/index' ) );
		self::assertSame( array( 'demo-admin-index', 'demo-admin-style-index' ), $vite->register_style( 'admin/index' ) );
		self::assertCount( 6, $calls );
	}

	public function test_register_blocks_uses_the_build_directory(): void {
		$calls = array();
		$this->record( 'wp_register_block_types_from_metadata_collection', $calls );
		$this->record( 'wp_register_block_metadata_collection', $calls );
		$this->record( 'register_block_type', $calls );
		$this->vite()->register_blocks();
		// With or without the 6.8 API the first call names the collection directory and manifest.
		$build = dirname( self::PLUGIN_FILE ) . '/build';
		self::assertSame( array( $build . '/blocks', $build . '/blocks-manifest.php' ), $calls[0] );
	}

	public function test_add_module_data_merges_into_the_filter(): void {
		Functions\when( 'wp_register_script_module' )->justReturn();
		$callbacks = array();
		Filters\expectAdded( 'script_module_data_demo/admin/index' )->twice()->whenHappen( static function ( callable $callback ) use ( &$callbacks ): void {
			$callbacks[] = $callback;
		} );

		$vite = $this->vite();
		$vite->add_module_data( 'admin/index', array( 'a' => 1 ) );
		$vite->add_module_data( 'admin/index', static fn( array $data ): array => array( 'b' => $data['a'] + 1 ) );

		$data = array();
		foreach ( $callbacks as $callback ) {
			$data = $callback( $data );
		}
		self::assertSame( array( 'a' => 1, 'b' => 2 ), $data );
	}

	public function test_set_translations_covers_the_entry_and_its_chunks(): void {
		Functions\when( 'wp_register_script_module' )->justReturn();
		$calls = array();
		$this->record( 'wp_set_script_module_translations', $calls );
		$this->vite()->set_translations( 'admin/index', 'demo', '/lang' );
		self::assertSame( array( 'demo/admin/index', 'demo/chunks/shared-abc123.js' ), array_column( $calls, 0 ) );
	}

	public function test_dev_mode_enqueues_the_client_and_serves_entries_from_the_dev_server(): void {
		$calls = array();
		$this->record( 'wp_register_script_module', $calls );
		$this->record( 'wp_enqueue_script_module', $calls );
		$this->record( 'wp_enqueue_script', $calls );
		Functions\expect( 'wp_register_style' )->never();
		Actions\expectAdded( 'admin_notices' )->once();

		$vite = Vite::init( self::PLUGIN_FILE, null, __DIR__ . '/fixtures/hot.json' );
		self::assertNotNull( $vite->dev() );
		self::assertSame( 'demo/admin/index', $vite->enqueue_script_module( 'admin/index' ) );
		self::assertSame(
			array(
				array( 'demo/vite-client', 'http://localhost:5173/@wp-vite/client', array(), null ),
				array( 'demo/vite-client' ),
				array( 'demo/admin/index', 'http://localhost:5173/src/admin/index.tsx', array(), null ),
				array( 'demo/admin/index' ),
			),
			$calls
		);
	}

	public function test_dev_mode_registers_blocks_through_the_hot_file(): void {
		Functions\when( 'wp_register_script_module' )->justReturn();
		Functions\when( 'wp_enqueue_script_module' )->justReturn();
		Actions\expectAdded( 'admin_notices' )->once();
		Filters\expectAdded( 'block_type_metadata' )->once();
		Filters\expectAdded( 'block_type_metadata_settings' )->once();
		Filters\expectAdded( 'render_block_demo/counter' )->once();
		$calls = array();
		$this->record( 'wp_register_block_metadata_collection', $calls );
		$this->record( 'register_block_type', $calls );

		Vite::init( self::PLUGIN_FILE, null, __DIR__ . '/fixtures/hot.json' )->register_blocks();
		$build = dirname( self::PLUGIN_FILE ) . '/build';
		self::assertSame( array( array( $build, $build . '/blocks-manifest.php' ), array( $build . '/blocks/counter' ), array( $build . '/blocks/other' ) ), $calls );
	}

	public function test_dev_mode_translations_use_the_built_files(): void {
		Functions\when( 'wp_register_script_module' )->justReturn();
		Functions\when( 'wp_enqueue_script_module' )->justReturn();
		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Functions\when( 'load_script_translations' )->justReturn( false );
		Functions\expect( 'wp_set_script_module_translations' )->never();
		Actions\expectAdded( 'admin_notices' )->once();

		Vite::init( self::PLUGIN_FILE, null, __DIR__ . '/fixtures/hot.json' )->set_translations( 'admin/index', 'demo', '/lang' );
	}

	private function vite(): Vite {
		return Vite::init( self::PLUGIN_FILE );
	}
}
