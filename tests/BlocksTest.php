<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Vite\Blocks;
use Vite\Manifest;

final class BlocksTest extends WordPressTestCase {
	private const BUILD = __DIR__ . '/fixtures/demo/build';

	/** @var list<array<mixed>> */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->record( 'wp_register_block_types_from_metadata_collection', $this->calls );
		$this->record( 'wp_register_block_metadata_collection', $this->calls );
		$this->record( 'register_block_type', $this->calls );
	}

	public function test_uses_the_collection_api_when_blocks_share_one_directory(): void {
		$this->blocks( collectionApi: true )->register();
		self::assertSame( array( array( self::BUILD . '/blocks', self::BUILD . '/blocks-manifest.php' ) ), $this->calls );
	}

	public function test_registers_each_block_from_the_collection_before_6_8(): void {
		$this->blocks( collectionApi: false )->register();
		self::assertSame(
			array(
				array( self::BUILD . '/blocks', self::BUILD . '/blocks-manifest.php' ),
				array( self::BUILD . '/blocks/counter' ),
				array( self::BUILD . '/blocks/other' ),
			),
			$this->calls
		);
	}

	public function test_registers_each_block_when_depths_differ(): void {
		$manifest = $this->manifest( array(
			'blocks'     => array( array( 'name' => 'demo/counter', 'dir' => 'blocks/counter' ), array( 'name' => 'demo/deep', 'dir' => 'deep/nested/x' ) ),
			'blocksRoot' => null,
		) );
		$this->blocks( $manifest, true )->register();
		self::assertSame(
			array(
				array( self::BUILD, self::BUILD . '/blocks-manifest.php' ),
				array( self::BUILD . '/blocks/counter' ),
				array( self::BUILD . '/deep/nested/x' ),
			),
			$this->calls
		);
	}

	public function test_reads_block_json_without_a_blocks_manifest(): void {
		$this->blocks( buildPath: '/plugins/demo/build' )->register();
		self::assertSame( array( array( '/plugins/demo/build/blocks/counter' ), array( '/plugins/demo/build/blocks/other' ) ), $this->calls );
	}

	public function test_does_nothing_without_blocks(): void {
		$this->blocks( $this->manifest( array( 'blocks' => array(), 'blocksRoot' => null ) ) )->register();
		self::assertSame( array(), $this->calls );
	}

	public function test_enqueues_classic_deps_of_a_view_module_when_the_block_renders(): void {
		$manifest = Manifest::load( self::BUILD . '/wp-vite.php' );
		self::assertNotNull( $manifest );
		$data = ( require self::BUILD . '/wp-vite.php' );
		$data['entries']['blocks/counter/view']['scripts'] = array( 'wp-i18n' );
		$callbacks = array();
		Filters\expectAdded( 'render_block_demo/counter' )->once()->whenHappen( static function ( callable $callback ) use ( &$callbacks ): void {
			$callbacks[] = $callback;
		} );
		Filters\expectAdded( 'render_block_demo/other' )->once()->whenHappen( static function ( callable $callback ) use ( &$callbacks ): void {
			$callbacks[] = $callback;
		} );
		$enqueued = array();
		$this->record( 'wp_enqueue_script', $enqueued );

		$this->blocks( Manifest::fromArray( $data ) )->register();
		self::assertCount( 2, $callbacks );
		self::assertSame( '<p>x</p>', $callbacks[0]( '<p>x</p>' ) );
		self::assertSame( array( array( 'wp-i18n' ) ), $enqueued );
	}

	public function test_adds_no_render_filter_without_classic_deps(): void {
		Functions\expect( 'add_filter' )->never();
		$this->blocks()->register();
	}

	private function blocks( ?Manifest $manifest = null, bool $collectionApi = true, string $buildPath = self::BUILD ): Blocks {
		$manifest ??= Manifest::load( self::BUILD . '/wp-vite.php' );
		self::assertNotNull( $manifest );
		return new Blocks( $manifest, $buildPath, $collectionApi );
	}

	/** @param array<string, mixed> $overrides */
	private function manifest( array $overrides ): Manifest {
		return Manifest::fromArray( $overrides + ( require self::BUILD . '/wp-vite.php' ) );
	}
}
