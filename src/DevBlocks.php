<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Registers the blocks listed in the hot file while the dev server runs.
 *
 * Every block registers from its built `block.json` as in production (classic scripts are watch-built
 * into the build directory with real asset files). The fields the dev server serves instead,
 * `viewScriptModule` and the style fields, are removed from the metadata and replaced at
 * registration time by modules and stylesheets on the dev server, so core enqueues them at the
 * usual moments.
 */
final class DevBlocks {
	private const SETTINGS = array(
		'viewScriptModule' => 'view_script_module_ids',
		'editorStyle'      => 'editor_style_handles',
		'style'            => 'style_handles',
		'viewStyle'        => 'view_style_handles',
	);

	/** @var array<string, array<string, list<string>>> Served fields and their entry names, keyed by block name. */
	private array $fields = array();

	/**
	 * @param string $buildPath        Absolute path of the build directory.
	 * @param bool   $clientNavigation Whether WP_Interactivity_API::add_client_navigation_support_to_script_module() exists (WordPress 6.9+).
	 */
	public function __construct(
		private readonly DevServer $dev,
		private readonly string $buildPath,
		private readonly ScriptModules $modules,
		private readonly Styles $styles,
		private readonly bool $clientNavigation,
	) {
	}

	public function register(): void {
		$blocks = $this->dev->blocks();
		if ( $blocks === array() ) {
			return;
		}
		foreach ( $blocks as $block ) {
			$this->fields[ $block['name'] ] = array_intersect_key( $block['fields'], self::SETTINGS );
			foreach ( $block['fields']['viewScriptModule'] ?? array() as $entry ) {
				Blocks::enqueue_scripts_on_render( $block['name'], $this->modules->scripts( $entry ) );
			}
		}
		add_filter( 'block_type_metadata', $this->strip_served_fields( ... ) );
		add_filter( 'block_type_metadata_settings', $this->add_dev_assets( ... ), 10, 2 );

		$manifest = $this->buildPath . '/blocks-manifest.php';
		if ( is_file( $manifest ) ) {
			// One collection at the build root covers blocks at any depth (see Blocks).
			wp_register_block_metadata_collection( $this->buildPath, $manifest );
		}
		foreach ( $blocks as $block ) {
			register_block_type( $this->path( $block['dir'] ) );
		}
	}

	/**
	 * `block_type_metadata`: drops the `file:` references of served fields; plain handles stay.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function strip_served_fields( array $metadata ): array {
		$fields = $this->fields_of( $metadata );
		foreach ( array_keys( $fields ) as $field ) {
			$kept = array();
			foreach ( (array) ( $metadata[ $field ] ?? array() ) as $value ) {
				if ( ! is_string( $value ) || ! str_starts_with( $value, 'file:' ) ) {
					$kept[] = $value;
				}
			}
			if ( $kept === array() ) {
				unset( $metadata[ $field ] );
			} else {
				$metadata[ $field ] = $kept;
			}
		}
		return $metadata;
	}

	/**
	 * `block_type_metadata_settings`: appends the dev module ids and style handles.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function add_dev_assets( array $settings, array $metadata ): array {
		foreach ( $this->fields_of( $metadata ) as $field => $entries ) {
			$key     = self::SETTINGS[ $field ];
			$handles = is_array( $settings[ $key ] ?? null ) ? array_values( $settings[ $key ] ) : array();
			foreach ( $entries as $entry ) {
				if ( $field === 'viewScriptModule' ) {
					$handles[] = $this->register_module( $entry, $metadata );
				} else {
					array_push( $handles, ...$this->styles->register( $entry ) );
				}
			}
			$settings[ $key ] = $handles;
		}
		return $settings;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, list<string>>
	 */
	private function fields_of( array $metadata ): array {
		$name = $metadata['name'] ?? null;
		return is_string( $name ) ? $this->fields[ $name ] ?? array() : array();
	}

	/**
	 * Mirrors register_block_script_module_id(): interactive blocks load their module late and low priority.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function register_module( string $entry, array $metadata ): string {
		$interactivity = $metadata['supports']['interactivity'] ?? false;
		$interactive   = $interactivity === true || ( is_array( $interactivity ) && ( $interactivity['interactive'] ?? false ) === true );
		$navigation    = $interactivity === true || ( is_array( $interactivity ) && ( $interactivity['clientNavigation'] ?? false ) === true );
		$id            = $this->modules->register( $entry, $interactive ? array( 'fetchpriority' => 'low', 'in_footer' => true ) : array() );
		if ( $interactive && $navigation && $this->clientNavigation ) {
			wp_interactivity()->add_client_navigation_support_to_script_module( $id );
		}
		return $id;
	}

	/** @param string $dir Block directory relative to the plugin root, e.g. `src/blocks/x`; built copies live at `<build>/blocks/x`. */
	private function path( string $dir ): string {
		$src = $this->dev->srcDir();
		if ( $dir === $src ) {
			$dir = '';
		} elseif ( str_starts_with( $dir, $src . '/' ) ) {
			$dir = substr( $dir, strlen( $src ) + 1 );
		}
		return $dir === '' ? $this->buildPath : $this->buildPath . '/' . $dir;
	}
}
