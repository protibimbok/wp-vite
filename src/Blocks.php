<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Registers the blocks listed in the manifest from the built `block.json` files.
 *
 * Uses the block metadata collection (`blocks-manifest.php`) when the build wrote one, so core
 * never parses the JSON files: through `wp_register_block_types_from_metadata_collection()` on
 * WordPress 6.8+ when every block sits directly under one directory, otherwise by registering the
 * collection and each block individually.
 */
final class Blocks {
	/** @param string $buildPath Absolute path of the build directory. */
	public function __construct(
		private readonly Manifest $manifest,
		private readonly string $buildPath,
		private readonly bool $collectionApi,
	) {
	}

	public function register(): void {
		$blocks = $this->manifest->blocks();
		if ( $blocks === array() ) {
			return;
		}
		$manifest = $this->buildPath . '/blocks-manifest.php';
		$root     = $this->manifest->blocksRoot();
		$path     = $this->path( $root ?? '' );
		if ( ! is_file( $manifest ) ) {
			$this->register_each( $blocks );
		} elseif ( $root !== null && $this->collectionApi ) {
			wp_register_block_types_from_metadata_collection( $path, $manifest );
		} else {
			// The registry matches blocks to a collection by path prefix and looks them up by directory
			// basename, so one collection covers blocks at any depth.
			wp_register_block_metadata_collection( $path, $manifest );
			$this->register_each( $blocks );
		}
		$this->enqueue_classic_deps_on_render( $blocks );
	}

	/** @param list<array{name: string, dir: string}> $blocks */
	private function register_each( array $blocks ): void {
		foreach ( $blocks as $block ) {
			register_block_type( $this->path( $block['dir'] ) );
		}
	}

	/**
	 * Core registers a `viewScriptModule` from its asset.php, which cannot name classic scripts; with
	 * `moduleClassicDeps: 'warn'` the manifest still lists them, so they are enqueued when the block renders.
	 *
	 * @param list<array{name: string, dir: string}> $blocks
	 */
	private function enqueue_classic_deps_on_render( array $blocks ): void {
		foreach ( $blocks as $block ) {
			self::enqueue_scripts_on_render( $block['name'], $this->manifest->blockEntry( $block['name'], 'viewScriptModule' )['scripts'] ?? array() );
		}
	}

	/** @param list<string> $scripts Classic script handles. */
	public static function enqueue_scripts_on_render( string $block, array $scripts ): void {
		if ( $scripts === array() ) {
			return;
		}
		add_filter( "render_block_{$block}", static function ( mixed $content ) use ( $scripts ): mixed {
			foreach ( $scripts as $handle ) {
				wp_enqueue_script( $handle );
			}
			return $content;
		} );
	}

	private function path( string $dir ): string {
		return $dir === '' ? $this->buildPath : $this->buildPath . '/' . $dir;
	}
}
