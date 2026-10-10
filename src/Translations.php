<?php
declare( strict_types=1 );

namespace Vite;

/**
 * Script module translations.
 *
 * WordPress 7.0 loads JSON translations for script modules itself; before that, the same lookup
 * is emulated and the locale data is printed after `wp-i18n`, which runs before any module.
 */
final class Translations {
	/**
	 * @param string $buildDir Build directory relative to the plugin directory.
	 * @param bool   $native   Whether wp_set_script_module_translations() exists (WordPress 7.0+).
	 */
	public function __construct( private readonly string $buildDir, private readonly bool $native ) {
	}

	/** @param array<string, string> $files Built files keyed by module id. */
	public function set( array $files, string $domain, string $path = '' ): void {
		if ( $this->native ) {
			foreach ( array_keys( $files ) as $id ) {
				wp_set_script_module_translations( $id, $domain, $path );
			}
			return;
		}
		foreach ( $files as $id => $file ) {
			$json = $this->load( $id, $file, $domain, $path );
			if ( $json === false ) {
				continue;
			}
			wp_enqueue_script( 'wp-i18n' );
			wp_add_inline_script( 'wp-i18n', sprintf(
				'( ( domain, translations ) => { const localeData = translations.locale_data[ domain ] || translations.locale_data.messages; localeData[""].domain = domain; wp.i18n.setLocaleData( localeData, domain ); } )( %s, %s );',
				wp_json_encode( $domain, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				$json
			) );
		}
	}

	// Mirrors _load_script_textdomain_from_src(): <domain>-<locale>-<md5 of the plugin-relative file>.json.
	private function load( string $id, string $file, string $domain, string $path ): string|false {
		$locale   = determine_locale();
		$relative = ltrim( $this->buildDir . '/' . $file, '/' );
		$name     = ( $domain === 'default' ? $locale : $domain . '-' . $locale ) . '-' . md5( $relative ) . '.json';
		$dir      = $path !== '' ? rtrim( $path, '/\\' ) : WP_LANG_DIR . '/plugins';
		return load_script_translations( $dir . '/' . $name, $id, $domain );
	}
}
