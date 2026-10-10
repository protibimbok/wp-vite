<?php
declare( strict_types=1 );

namespace Vite;

/** Registers the stylesheets of manifest entries (entry CSS plus the CSS of statically imported chunks). */
final class Styles {
	/** @var array<string, true> */
	private array $registered = array();

	/** @param \Closure(string): string $url Maps a built file to its public URL. */
	public function __construct(
		private readonly Manifest $manifest,
		private readonly \Closure $url,
		private readonly string $prefix,
	) {
	}

	/** Style handle of a built CSS file: `admin/style-index.css` becomes `<prefix>-admin-style-index`. */
	public function handle( string $file ): string {
		return $this->prefix . '-' . str_replace( '/', '-', preg_replace( '/\.css$/', '', $file ) ?? $file );
	}

	/** @return list<string> Handles in load order. */
	public function register( string $entry ): array {
		$item = $this->manifest->entry( $entry )
			?? throw new Exception( sprintf( 'wp-vite entry "%s" is not in the manifest.', $entry ) );
		$handles = array();
		foreach ( array_merge( $item['css'], $item['styleCss'] ) as $file ) {
			$handle = $this->handle( $file );
			if ( ! isset( $this->registered[ $handle ] ) ) {
				$this->registered[ $handle ] = true;
				wp_register_style( $handle, ( $this->url )( $file ), array(), $item['version'] );
				if ( $this->manifest->rtl() ) {
					wp_style_add_data( $handle, 'rtl', 'replace' );
				}
			}
			$handles[] = $handle;
		}
		return $handles;
	}

	/** @return list<string> Handles in load order. */
	public function enqueue( string $entry ): array {
		$handles = $this->register( $entry );
		foreach ( $handles as $handle ) {
			wp_enqueue_style( $handle );
		}
		return $handles;
	}
}
