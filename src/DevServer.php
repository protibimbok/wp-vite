<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Reader for the hot file `build/.wp-vite/hot.json` that `wp-vite start` writes while the dev server runs.
 *
 * Each entry is normalised to `{url, scripts, modules, styles}`; the three lists default to empty.
 */
final class DevServer {
	/** @param array<string, mixed> $data */
	private function __construct( private readonly array $data ) {
	}

	/**
	 * Returns null when there is no hot file (no dev server running), throws when it is unusable.
	 *
	 * @throws Exception
	 */
	public static function load( string $hotFile ): ?self {
		if ( ! is_file( $hotFile ) ) {
			return null;
		}
		$json = file_get_contents( $hotFile );
		$data = $json === false ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new Exception( sprintf( 'wp-vite hot file %s is not valid JSON.', $hotFile ) );
		}
		return self::fromArray( $data );
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws Exception
	 */
	public static function fromArray( array $data ): self {
		$origin = $data['origin'] ?? null;
		if ( ! is_string( $origin ) || $origin === '' ) {
			throw new Exception( 'wp-vite hot file has no "origin".' );
		}
		if ( ! is_array( $data['entries'] ?? null ) ) {
			throw new Exception( 'wp-vite hot file has no "entries" array.' );
		}
		$entries = array();
		foreach ( $data['entries'] as $name => $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['url'] ?? null ) ) {
				throw new Exception( sprintf( 'wp-vite hot file entry "%s" has no "url".', (string) $name ) );
			}
			$entries[ (string) $name ] = array(
				'url'     => $entry['url'],
				'scripts' => self::strings( $entry['scripts'] ?? array() ),
				'modules' => self::strings( $entry['modules'] ?? array() ),
				'styles'  => self::strings( $entry['styles'] ?? array() ),
			);
		}
		$blocks = array();
		foreach ( is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array() as $block ) {
			if ( is_array( $block ) && is_string( $block['name'] ?? null ) && is_string( $block['dir'] ?? null ) ) {
				$blocks[] = array( 'name' => $block['name'], 'dir' => $block['dir'] );
			}
		}
		return new self( array(
			'origin'  => rtrim( $origin, '/' ),
			'srcDir'  => is_string( $data['srcDir'] ?? null ) ? $data['srcDir'] : 'src',
			'refresh' => (bool) ( $data['refresh'] ?? false ),
			'entries' => $entries,
			'blocks'  => $blocks,
		) );
	}

	/** Dev server origin without a trailing slash, e.g. `http://localhost:5173`. */
	public function origin(): string {
		return $this->data['origin'];
	}

	/** Source directory relative to the plugin root, as configured in the Vite plugin. */
	public function srcDir(): string {
		return $this->data['srcDir'];
	}

	/** Whether React Fast Refresh is on (the preamble must load before any entry). */
	public function refresh(): bool {
		return $this->data['refresh'];
	}

	/** @return array<string, array<string, mixed>> Entries keyed by name. */
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

	/** @return list<array{name: string, dir: string}> */
	public function blocks(): array {
		return $this->data['blocks'];
	}

	/** Absolute dev-server URL for a path relative to the Vite root, e.g. `src/blocks/x/style.scss`. */
	public function url( string $path ): string {
		return $this->data['origin'] . '/' . ltrim( $path, '/' );
	}

	/** @return list<string> */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}
}
