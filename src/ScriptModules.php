<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Registers manifest entries and their chunks with WP_Script_Modules.
 *
 * Chunks are registered as module dependencies (static or dynamic, as imported), so core prints
 * `modulepreload` links for the static ones and puts every module id in the import map. While the
 * dev server runs, entries it serves register from their dev URL instead, with the hot file's module
 * ids as static dependencies.
 */
final class ScriptModules {
	/** @var array<string, true> */
	private array $registered = array();

	/** @param \Closure(string): string $url Maps a built file to its public URL. */
	public function __construct(
		private readonly Manifest $manifest,
		private readonly \Closure $url,
		private readonly string $prefix,
		private readonly bool $supportsArgs,
		private readonly ?DevServer $dev = null,
	) {
	}

	public function id( string $entry ): string {
		return $this->prefix . '/' . $entry;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return string The module id.
	 */
	public function register( string $entry, array $args = array() ): string {
		$id = $this->id( $entry );
		if ( isset( $this->registered[ $id ] ) ) {
			return $id;
		}
		$dev = $this->dev_entry( $entry );
		if ( $dev !== null ) {
			$this->registered[ $id ] = true;
			$this->add( $id, $dev['url'], $dev['modules'], null, $args );
			return $id;
		}
		$item                    = $this->entry( $entry );
		$this->registered[ $id ] = true;
		$this->add( $id, ( $this->url )( $item['file'] ), $this->deps( $item ), $item['version'], $args );
		return $id;
	}

	/**
	 * Script modules cannot declare classic script dependencies, so those are enqueued alongside.
	 *
	 * @param array<string, mixed> $args
	 * @return string The module id.
	 */
	public function enqueue( string $entry, array $args = array() ): string {
		$id = $this->register( $entry, $args );
		wp_enqueue_script_module( $id );
		foreach ( $this->scripts( $entry ) as $handle ) {
			wp_enqueue_script( $handle );
		}
		return $id;
	}

	/** @return list<string> Classic script handles the entry needs. */
	public function scripts( string $entry ): array {
		return ( $this->dev_entry( $entry ) ?? $this->entry( $entry ) )['scripts'];
	}

	/**
	 * Built files of an entry and every chunk it can load, keyed by module id.
	 *
	 * @return array<string, string>
	 */
	public function files( string $entry ): array {
		$item  = $this->entry( $entry );
		$files = array( $this->id( $entry ) => $item['file'] );
		foreach ( array_merge( $item['imports'], $item['dynamicImports'] ) as $file ) {
			$files[ $this->chunk_id( $file ) ] = $file;
		}
		return $files;
	}

	/** @return array<string, mixed>|null The served script module entry; null in production or for a stylesheet entry. */
	private function dev_entry( string $name ): ?array {
		$item = $this->dev?->entry( $name );
		return $item !== null && $item['format'] === 'es' ? $item : null;
	}

	/** @return array<string, mixed> */
	private function entry( string $name ): array {
		$item = $this->manifest->entry( $name )
			?? throw new Exception( sprintf( 'wp-vite entry "%s" is not in the manifest.', $name ) );
		if ( $item['format'] !== 'es' ) {
			throw new Exception( sprintf(
				'wp-vite entry "%s" is a classic script (block.json editorScript/script/viewScript); WordPress registers it from block.json.',
				$name
			) );
		}
		return $item;
	}

	/**
	 * @param array<string, mixed> $item Entry or chunk record.
	 * @return list<string|array{id: string, import: string}>
	 */
	private function deps( array $item ): array {
		$deps = array();
		foreach ( $item['imports'] as $file ) {
			$deps[] = $this->register_chunk( $file );
		}
		foreach ( $item['modules'] as $id ) {
			$deps[] = $id;
		}
		foreach ( $item['dynamicImports'] as $file ) {
			$deps[] = array( 'id' => $this->register_chunk( $file ), 'import' => 'dynamic' );
		}
		foreach ( $item['dynamicModules'] as $id ) {
			$deps[] = array( 'id' => $id, 'import' => 'dynamic' );
		}
		return $deps;
	}

	private function register_chunk( string $file ): string {
		$id = $this->chunk_id( $file );
		if ( isset( $this->registered[ $id ] ) ) {
			return $id;
		}
		$chunk = $this->manifest->chunk( $file )
			?? throw new Exception( sprintf( 'wp-vite chunk "%s" is not in the manifest.', $file ) );
		// Marked before recursing so circular chunk imports terminate.
		$this->registered[ $id ] = true;
		$this->add( $id, ( $this->url )( $file ), $this->deps( $chunk ), $chunk['version'] );
		return $id;
	}

	private function chunk_id( string $file ): string {
		return $this->prefix . '/' . $file;
	}

	/**
	 * @param list<string|array{id: string, import: string}> $deps
	 * @param array<string, mixed>                           $args
	 */
	private function add( string $id, string $src, array $deps, ?string $version, array $args = array() ): void {
		if ( $args !== array() && $this->supportsArgs ) {
			wp_register_script_module( $id, $src, $deps, $version, $args );
		} else {
			wp_register_script_module( $id, $src, $deps, $version );
		}
	}
}
