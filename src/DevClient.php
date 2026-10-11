<?php
declare( strict_types=1 );

namespace Vite;

/**
 * The wp-vite dev client (`<origin>/@wp-vite/client`, which loads Vite's own client), enqueued on every
 * page in dev mode so that pages receive full reloads after classic block scripts rebuild and
 * stylesheet links get hot-swapped, including the copies inside the block editor's canvas iframe.
 */
final class DevClient {
	// react-refresh's stub devtools hook (core's wp-react-refresh-entry, loaded before React) does not
	// record renderers, so a refresh runtime that loads later, like Vite's, finds none to refresh;
	// record them the way the React DevTools hook does.
	private const RENDERER_SHIM = '(function(h){if(!h||!h.renderers)return;var i=h.inject;h.inject=function(r){var id=i.apply(this,arguments);h.renderers.set(id,r);return id;};})(window.__REACT_DEVTOOLS_GLOBAL_HOOK__);';

	private bool $enqueued = false;

	/** @param bool $scriptDebug The `SCRIPT_DEBUG` constant. */
	public function __construct(
		private readonly DevServer $dev,
		private readonly string $prefix,
		private readonly bool $scriptDebug,
	) {
	}

	public function id(): string {
		return $this->prefix . '/vite-client';
	}

	public function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;
		wp_register_script_module( $this->id(), $this->dev->url( '/@wp-vite/client' ), array(), null );
		wp_enqueue_script_module( $this->id() );
		if ( ! $this->dev->refresh() ) {
			return;
		}
		if ( $this->scriptDebug ) {
			wp_add_inline_script( 'wp-react-refresh-entry', self::RENDERER_SHIM, 'after' );
		} else {
			add_action( 'admin_notices', $this->refresh_notice( ... ) );
		}
	}

	// Fast Refresh needs the development build of React, which WordPress only loads with SCRIPT_DEBUG.
	public function refresh_notice(): void {
		printf(
			'<div class="notice notice-warning"><p>wp-vite (%s): React Fast Refresh needs <code>define( \'SCRIPT_DEBUG\', true );</code> in wp-config.php; without it WordPress loads the production build of React and component edits trigger no update.</p></div>',
			esc_html( $this->prefix )
		);
	}
}
