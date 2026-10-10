<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Reader for the build manifest `build/wp-vite.php` written by wp-vite-plugin.
 *
 * Entry, chunk and block arrays are passed through as written; the shape is documented in
 * wp-vite-plugin's `docs/manifest.md`.
 */
final class Manifest {
	public const VERSION = 1;

	/** @param array<string, mixed> $data */
	private function __construct( private readonly array $data ) {
	}

	/**
	 * Returns null when the file does not exist (the plugin was not built), throws when it is unusable.
	 *
	 * @throws Exception
	 */
	public static function load( string $file ): ?self {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = require $file;
		if ( ! is_array( $data ) ) {
			throw new Exception( sprintf( 'wp-vite manifest %s did not return an array.', $file ) );
		}
		return self::fromArray( $data );
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws Exception
	 */
	public static function fromArray( array $data ): self {
		$version = $data['version'] ?? null;
		if ( $version !== self::VERSION ) {
			throw new Exception( sprintf(
				'Unsupported wp-vite manifest version %s; this package reads version %d. Update wp-vite-plugin or protibimbok/wp-vite.',
				is_scalar( $version ) ? var_export( $version, true ) : gettype( $version ),
				self::VERSION
			) );
		}
		foreach ( array( 'entries', 'chunks', 'blocks' ) as $key ) {
			if ( ! is_array( $data[ $key ] ?? null ) ) {
				throw new Exception( sprintf( 'wp-vite manifest is missing the "%s" array.', $key ) );
			}
		}
		$data['rtl']        = (bool) ( $data['rtl'] ?? false );
		$data['blocksRoot'] = is_string( $data['blocksRoot'] ?? null ) ? $data['blocksRoot'] : null;
		return new self( $data );
	}

	/** Whether `*-rtl.css` files exist next to every stylesheet. */
	public function rtl(): bool {
		return $this->data['rtl'];
	}

	/** @return array<string, array<string, mixed>> Entries keyed by name, e.g. `admin/index`. */
	public function entries(): array {
		return $this->data['entries'];
	}

	public function has( string $name ): bool {
		return isset( $this->data['entries'][ $name ] );
	}

	/** @return array<string, mixed>|null */
	public function entry( string $name ): ?array {
		return $this->data['entries'][ $name ] ?? null;
	}

	/** @return array<string, array<string, mixed>> Shared chunks keyed by file, e.g. `chunks/shared-abc123.js`. */
	public function chunks(): array {
		return $this->data['chunks'];
	}

	/** @return array<string, mixed>|null */
	public function chunk( string $file ): ?array {
		return $this->data['chunks'][ $file ] ?? null;
	}

	/** @return list<array{name: string, dir: string}> Blocks in discovery order. */
	public function blocks(): array {
		return $this->data['blocks'];
	}

	/** Common parent of all block directories relative to outDir ('' for outDir itself), or null when depths differ. */
	public function blocksRoot(): ?string {
		return $this->data['blocksRoot'];
	}

	/**
	 * Entry names keyed by block.json field (`editorScript`, `viewScriptModule`, ...) for one block.
	 *
	 * @return array<string, string>
	 */
	public function blockEntries( string $block ): array {
		$fields = array();
		foreach ( $this->data['entries'] as $name => $entry ) {
			foreach ( $entry['sources'] as $source ) {
				if ( $source['block'] === $block ) {
					$fields[ $source['field'] ] = $name;
				}
			}
		}
		return $fields;
	}

	/** @return array<string, mixed>|null */
	public function blockEntry( string $block, string $field ): ?array {
		$name = $this->blockEntries( $block )[ $field ] ?? null;
		return $name === null ? null : $this->data['entries'][ $name ];
	}
}
