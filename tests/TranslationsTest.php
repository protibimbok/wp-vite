<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Functions;
use Vite\Translations;

final class TranslationsTest extends WordPressTestCase {
	private const FILES = array( 'demo/admin/index' => 'admin/index.js', 'demo/chunks/s.js' => 'chunks/s.js' );

	public function test_uses_core_module_translations_when_available(): void {
		$calls = array();
		$this->record( 'wp_set_script_module_translations', $calls );
		( new Translations( 'build', true ) )->set( self::FILES, 'demo', '/lang' );
		self::assertSame(
			array( array( 'demo/admin/index', 'demo', '/lang' ), array( 'demo/chunks/s.js', 'demo', '/lang' ) ),
			$calls
		);
	}

	public function test_prints_locale_data_after_wp_i18n_on_older_wordpress(): void {
		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', '/wp-content/languages' );
		}
		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$loaded = array();
		Functions\when( 'load_script_translations' )->alias( static function ( string $file, string $handle, string $domain ) use ( &$loaded ) {
			$loaded[] = array( $file, $handle, $domain );
			return str_contains( $file, md5( 'build/admin/index.js' ) ) ? '{"locale_data":{"demo":{"":{}}}}' : false;
		} );
		$enqueued = array();
		$inline   = array();
		$this->record( 'wp_enqueue_script', $enqueued );
		$this->record( 'wp_add_inline_script', $inline );

		( new Translations( 'build', false ) )->set( self::FILES, 'demo' );

		self::assertSame(
			array(
				array( '/wp-content/languages/plugins/demo-de_DE-' . md5( 'build/admin/index.js' ) . '.json', 'demo/admin/index', 'demo' ),
				array( '/wp-content/languages/plugins/demo-de_DE-' . md5( 'build/chunks/s.js' ) . '.json', 'demo/chunks/s.js', 'demo' ),
			),
			$loaded
		);
		self::assertSame( array( array( 'wp-i18n' ) ), $enqueued );
		self::assertCount( 1, $inline );
		self::assertSame( 'wp-i18n', $inline[0][0] );
		self::assertStringContainsString( 'wp.i18n.setLocaleData( localeData, domain ); } )( "demo", {"locale_data":{"demo":{"":{}}}} );', $inline[0][1] );
	}

	public function test_fallback_uses_the_given_path_and_default_domain_naming(): void {
		Functions\when( 'determine_locale' )->justReturn( 'fr_FR' );
		$loaded = array();
		Functions\when( 'load_script_translations' )->alias( static function ( string $file ) use ( &$loaded ) {
			$loaded[] = $file;
			return false;
		} );
		( new Translations( '', false ) )->set( array( 'x' => 'index.js' ), 'default', '/plugin/languages/' );
		self::assertSame( array( '/plugin/languages/fr_FR-' . md5( 'index.js' ) . '.json' ), $loaded );
	}
}
