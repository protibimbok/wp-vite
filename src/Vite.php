<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Entry point for a plugin: one instance per plugin, created with `Vite::init( __FILE__ )`.
 *
 * Holds no static state because WordPress loads a single copy of this class for every plugin that
 * ships the package; keep the instance in your own plugin (a function with a `static` local works).
 */
final class Vite {
	private ?Manifest $manifest = null;
	private ?ScriptModules $modules = null;
	private ?Styles $styles = null;
	private Blocks|DevBlocks|null $blocks = null;
	private ?Translations $translations = null;
	private DevServer|false|null $dev = null;
	private ?DevClient $client = null;

	private function __construct(
		private readonly string $file,
		private readonly string $manifestFile,
		private readonly string $hotFile,
		private readonly string $buildPath,
		private readonly string $buildDir,
		private readonly string $prefix,
	) {
	}

	/**
	 * @param string      $file     The plugin's main file (`__FILE__`).
	 * @param string|null $manifest Path to `wp-vite.php`. Default `build/wp-vite.php` next to `$file`.
	 * @param string|null $hotFile  Path to the dev server hot file. Default `.wp-vite/hot.json` inside the build dir.
	 * @param string|null $prefix   Prefix for module ids and style handles. Default: the plugin directory name.
	 */
	public static function init( string $file, ?string $manifest = null, ?string $hotFile = null, ?string $prefix = null ): self {
		$dir        = dirname( self::normalize( $file ) );
		$manifest ??= $dir . '/build/wp-vite.php';
		$build      = dirname( self::normalize( $manifest ) );
		if ( $build !== $dir && ! str_starts_with( $build, $dir . '/' ) ) {
			throw new Exception( sprintf( 'wp-vite build directory %s must be inside the plugin directory %s.', $build, $dir ) );
		}
		return new self(
			$file,
			$manifest,
			$hotFile ?? $build . '/.wp-vite/hot.json',
			$build,
			ltrim( substr( $build, strlen( $dir ) ), '/' ),
			$prefix ?? basename( $dir )
		);
	}

	/** Whether the build manifest exists. The other methods throw when it does not. */
	public function is_built(): bool {
		return is_file( $this->manifestFile );
	}

	/** @throws Exception When the plugin has not been built. */
	public function manifest(): Manifest {
		return $this->manifest ??= Manifest::load( $this->manifestFile )
			?? throw new Exception( sprintf( 'wp-vite manifest %s not found. Run the build.', $this->manifestFile ) );
	}

	/** The running dev server, or null when there is none. Read once per request. */
	public function dev(): ?DevServer {
		$this->dev ??= DevServer::load( $this->hotFile ) ?? false;
		return $this->dev ?: null;
	}

	/** Build directory relative to the plugin directory ('' when they are the same). */
	public function build_dir(): string {
		return $this->buildDir;
	}

	/** Public URL of a file inside the build directory. */
	public function url( string $file ): string {
		return esc_url_raw( plugins_url( ltrim( $this->buildDir . '/' . $file, '/' ), $this->file ) );
	}

	/** Script module id of an entry, `<prefix>/<entry>`. */
	public function module_id( string $entry ): string {
		return $this->modules()->id( $entry );
	}

	/**
	 * Registers an ESM entry with its chunks and module dependencies, and registers its styles.
	 *
	 * @param array<string, mixed> $args `in_footer` / `fetchpriority`, forwarded on WordPress 6.9+ and ignored before.
	 * @return string The module id.
	 */
	public function register_script_module( string $entry, array $args = array() ): string {
		$id = $this->modules()->register( $entry, $args );
		$this->styles()->register( $entry );
		return $id;
	}

	/**
	 * Enqueues an ESM entry together with its classic `wp-*` dependencies and styles.
	 *
	 * @param array<string, mixed> $args See register_script_module().
	 * @return string The module id.
	 */
	public function enqueue_script_module( string $entry, array $args = array() ): string {
		$id = $this->modules()->enqueue( $entry, $args );
		$this->styles()->enqueue( $entry );
		return $id;
	}

	/**
	 * Registers every block of the build from its built `block.json`. Call on `init`.
	 *
	 * Uses `blocks-manifest.php` as a block metadata collection when the build wrote one.
	 */
	public function register_blocks(): void {
		$this->blocks()->register();
	}

	/** @return list<string> Style handles of the entry's stylesheets. */
	public function register_style( string $entry ): array {
		return $this->styles()->register( $entry );
	}

	/** @return list<string> Style handles of the entry's stylesheets. */
	public function enqueue_style( string $entry ): array {
		return $this->styles()->enqueue( $entry );
	}

	/**
	 * Adds data that WordPress prints as JSON for the entry's module (`script_module_data_{id}`).
	 *
	 * @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $data Merged into existing data.
	 */
	public function add_module_data( string $entry, array|callable $data ): void {
		$id = $this->module_id( $entry );
		add_filter( "script_module_data_{$id}", static function ( array $current ) use ( $data ): array {
			return array_merge( $current, is_callable( $data ) ? $data( $current ) : $data );
		} );
	}

	/**
	 * Loads JSON translations for the entry and every chunk it imports.
	 *
	 * @param string $path Directory with the `.json` files; default WP_LANG_DIR/plugins.
	 */
	public function set_translations( string $entry, string $domain, string $path = '' ): void {
		$this->modules()->register( $entry );
		// Core derives the JSON file name from the module URL, which is the dev server's in dev mode.
		$this->translations ??= new Translations( $this->buildDir, function_exists( 'wp_set_script_module_translations' ) && $this->dev() === null );
		$this->translations->set( $this->modules()->files( $entry ), $domain, $path );
	}

	private function modules(): ScriptModules {
		return $this->modules ??= new ScriptModules( $this->manifest(), $this->url( ... ), $this->prefix, self::supports_args(), $this->dev_mode() );
	}

	private function styles(): Styles {
		return $this->styles ??= new Styles( $this->manifest(), $this->url( ... ), $this->prefix, $this->dev_mode() );
	}

	private function blocks(): Blocks|DevBlocks {
		if ( $this->blocks === null ) {
			$dev          = $this->dev_mode();
			$this->blocks = $dev === null
				? new Blocks( $this->manifest(), $this->buildPath, function_exists( 'wp_register_block_types_from_metadata_collection' ) )
				: new DevBlocks( $dev, $this->buildPath, $this->modules(), $this->styles(), self::supports_client_navigation() );
		}
		return $this->blocks;
	}

	/** The dev server when one runs; its client is enqueued on the first use of the API. */
	private function dev_mode(): ?DevServer {
		$dev = $this->dev();
		if ( $dev !== null ) {
			$this->client ??= new DevClient( $dev, $this->prefix, defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
			$this->client->enqueue();
		}
		return $dev;
	}

	// wp_register_script_module() gained $args in WordPress 6.9.
	private static function supports_args(): bool {
		return ( new \ReflectionFunction( 'wp_register_script_module' ) )->getNumberOfParameters() >= 5;
	}

	// WP_Interactivity_API::add_client_navigation_support_to_script_module() arrived in WordPress 6.9.
	private static function supports_client_navigation(): bool {
		return class_exists( \WP_Interactivity_API::class ) && ( new \ReflectionClass( \WP_Interactivity_API::class ) )->hasMethod( 'add_client_navigation_support_to_script_module' );
	}

	private static function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
