<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Vite\DevClient;
use Vite\DevServer;

final class DevClientTest extends WordPressTestCase {
	/** @var list<array<mixed>> */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->record( 'wp_register_script_module', $this->calls );
		$this->record( 'wp_enqueue_script_module', $this->calls );
	}

	public function test_registers_and_enqueues_the_client_once(): void {
		Actions\expectAdded( 'admin_notices' )->never();
		$client = new DevClient( $this->dev( false ), 'demo', false );
		$client->enqueue();
		$client->enqueue();
		self::assertSame(
			array(
				array( 'demo/vite-client', 'http://localhost:5173/@wp-vite/client', array(), null ),
				array( 'demo/vite-client' ),
			),
			$this->calls
		);
	}

	public function test_warns_when_refresh_is_on_without_script_debug(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		( new DevClient( $this->dev( true ), 'demo', false ) )->enqueue();
	}

	public function test_records_renderers_for_the_refresh_runtime_with_script_debug(): void {
		Actions\expectAdded( 'admin_notices' )->never();
		$inline = array();
		$this->record( 'wp_add_inline_script', $inline );
		( new DevClient( $this->dev( true ), 'demo', true ) )->enqueue();
		self::assertCount( 1, $inline );
		self::assertSame( 'wp-react-refresh-entry', $inline[0][0] );
		self::assertStringContainsString( 'h.renderers.set', $inline[0][1] );
		self::assertSame( 'after', $inline[0][2] );
	}

	public function test_no_refresh_wiring_without_refresh(): void {
		Actions\expectAdded( 'admin_notices' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();
		( new DevClient( $this->dev( false ), 'demo', true ) )->enqueue();
	}

	public function test_notice_names_script_debug(): void {
		Functions\when( 'esc_html' )->returnArg();
		ob_start();
		( new DevClient( $this->dev( true ), 'demo', false ) )->refresh_notice();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( 'SCRIPT_DEBUG', $html );
		self::assertStringContainsString( 'wp-vite (demo)', $html );
	}

	private function dev( bool $refresh ): DevServer {
		return DevServer::fromArray( array( 'origin' => 'http://localhost:5173', 'refresh' => $refresh, 'entries' => array() ) );
	}
}
