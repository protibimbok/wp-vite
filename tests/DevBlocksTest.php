<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Vite\DevBlocks;
use Vite\DevServer;
use Vite\Manifest;
use Vite\ScriptModules;
use Vite\Styles;

final class DevBlocksTest extends WordPressTestCase {
	private const BUILD = __DIR__ . '/fixtures/demo/build';

	/** @var list<array<mixed>> */
	private array $calls = array();
	/** @var list<array<mixed>> */
	private array $modules = array();
	/** @var list<array<mixed>> */
	private array $styles = array();
	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->record( 'wp_register_block_metadata_collection', $this->calls );
		$this->record( 'register_block_type', $this->calls );
		$this->record( 'wp_register_script_module', $this->modules );
		$this->record( 'wp_register_style', $this->styles );
	}

	public function test_registers_the_collection_and_every_block_from_the_build_directory(): void {
		$this->expect_filters();
		$this->blocks()->register();
		self::assertSame(
			array(
				array( self::BUILD, self::BUILD . '/blocks-manifest.php' ),
				array( self::BUILD . '/blocks/counter' ),
				array( self::BUILD . '/blocks/other' ),
			),
			$this->calls
		);
		self::assertSame( array(), $this->modules, 'dev assets register when core processes the metadata, not before' );
	}

	public function test_registers_blocks_without_a_blocks_manifest(): void {
		$this->expect_filters();
		$this->blocks( buildPath: '/plugins/demo/build' )->register();
		self::assertSame( array( array( '/plugins/demo/build/blocks/counter' ), array( '/plugins/demo/build/blocks/other' ) ), $this->calls );
	}

	public function test_does_nothing_without_blocks(): void {
		Functions\expect( 'add_filter' )->never();
		$this->blocks( DevServer::fromArray( array( 'origin' => 'http://localhost:5173', 'entries' => array() ) ) )->register();
		self::assertSame( array(), $this->calls );
	}

	public function test_metadata_filter_drops_served_file_references_and_keeps_handles(): void {
		$this->expect_filters();
		$this->blocks()->register();
		$metadata = array(
			'name'             => 'demo/counter',
			'editorScript'     => 'file:./index.js',
			'viewScriptModule' => 'file:./view.js',
			'style'            => array( 'file:./style.css', 'wp-shared' ),
			'editorStyle'      => 'file:./editor.css',
			'viewStyle'        => 'file:./view.css',
		);
		self::assertSame(
			array( 'name' => 'demo/counter', 'editorScript' => 'file:./index.js', 'style' => array( 'wp-shared' ), 'viewStyle' => 'file:./view.css' ),
			$this->filters['block_type_metadata']( $metadata )
		);
	}

	public function test_metadata_filter_ignores_other_blocks(): void {
		$this->expect_filters();
		$this->blocks()->register();
		$metadata = array( 'name' => 'core/paragraph', 'style' => 'file:./style.css' );
		self::assertSame( $metadata, $this->filters['block_type_metadata']( $metadata ) );
		self::assertSame( array( 'x' => 1 ), $this->filters['block_type_metadata_settings']( array( 'x' => 1 ), $metadata ) );
	}

	public function test_settings_filter_appends_dev_modules_and_styles(): void {
		$this->expect_filters();
		$interactivity = \Mockery::mock();
		$interactivity->shouldReceive( 'add_client_navigation_support_to_script_module' )->once()->with( 'demo/blocks/counter/view' );
		Functions\when( 'wp_interactivity' )->justReturn( $interactivity );
		$this->blocks()->register();
		$settings = $this->filters['block_type_metadata_settings'](
			array( 'style_handles' => array( 'wp-shared' ) ),
			array( 'name' => 'demo/counter', 'supports' => array( 'interactivity' => true ) )
		);
		self::assertSame(
			array(
				'style_handles'          => array( 'wp-shared', 'demo-blocks-counter-style' ),
				'view_script_module_ids' => array( 'demo/blocks/counter/view' ),
				'editor_style_handles'   => array( 'demo-blocks-counter-editor' ),
			),
			$settings
		);
		self::assertSame(
			array(
				array(
					'demo/blocks/counter/view',
					'http://localhost:5173/src/blocks/counter/view.ts',
					array( '@wordpress/interactivity' ),
					null,
					array( 'fetchpriority' => 'low', 'in_footer' => true ),
				),
			),
			$this->modules
		);
		self::assertSame(
			array(
				array( 'demo-blocks-counter-style', 'http://localhost:5173/src/blocks/counter/style.scss', array(), null ),
				array( 'demo-blocks-counter-editor', 'http://localhost:5173/src/blocks/counter/editor.scss', array(), null ),
			),
			$this->styles
		);
	}

	public function test_non_interactive_blocks_register_their_module_without_args(): void {
		$this->expect_filters();
		$this->blocks()->register();
		$this->filters['block_type_metadata_settings']( array(), array( 'name' => 'demo/counter' ) );
		self::assertCount( 4, $this->modules[0] );
	}

	public function test_enqueues_classic_deps_of_a_dev_view_module_when_the_block_renders(): void {
		$this->expect_filters();
		$enqueued = array();
		$this->record( 'wp_enqueue_script', $enqueued );
		$this->blocks()->register();
		self::assertSame( '<p>x</p>', $this->filters['render_block_demo/counter']( '<p>x</p>' ) );
		self::assertSame( array( array( 'wp-i18n' ) ), $enqueued );
	}

	private function expect_filters(): void {
		foreach ( array( 'block_type_metadata', 'block_type_metadata_settings', 'render_block_demo/counter' ) as $hook ) {
			Filters\expectAdded( $hook )->once()->whenHappen( function ( callable $callback ) use ( $hook ): void {
				$this->filters[ $hook ] = $callback;
			} );
		}
	}

	private function blocks( ?DevServer $dev = null, string $buildPath = self::BUILD ): DevBlocks {
		$dev    ??= DevServer::load( __DIR__ . '/fixtures/hot.json' );
		$manifest = Manifest::load( self::BUILD . '/wp-vite.php' );
		self::assertNotNull( $dev );
		self::assertNotNull( $manifest );
		$url = static fn( string $file ): string => 'https://example.test/build/' . $file;
		return new DevBlocks( $dev, $buildPath, new ScriptModules( $manifest, $url, 'demo', true, $dev ), new Styles( $manifest, $url, 'demo', $dev ), true );
	}
}
