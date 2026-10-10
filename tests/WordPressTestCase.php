<?php
declare( strict_types=1 );

namespace Vite\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class WordPressTestCase extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Records every call to a WordPress function, in order.
	 *
	 * @param list<array<mixed>> $calls
	 */
	protected function record( string $function, array &$calls ): void {
		Monkey\Functions\when( $function )->alias( static function ( ...$args ) use ( &$calls ): void {
			$calls[] = $args;
		} );
	}
}
