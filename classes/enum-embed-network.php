<?php

declare(strict_types=1);

namespace Mai\PerformanceMedia;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The social networks whose embed scripts are delayed until an embed nears the screen.
 *
 * The case order is the order the loader lists them in. Each one knows which pasted
 * script tags belong to it. The address each script is fetched from later lives in
 * assets/js/lazy-embeds.js, under the same name as the case value.
 *
 * @since 1.0.0
 */
enum EmbedNetwork: string {
	case Instagram = 'instagram';
	case X         = 'x';
	case TikTok    = 'tiktok';
	case Pinterest = 'pinterest';

	/**
	 * Returns the network a script address belongs to, if it is one of the known libraries.
	 *
	 * The address is compared without its protocol, query, fragment or case, so a
	 * pasted http://, a protocol-relative or an upper-case copy all match. The host
	 * has to be the library's own or a subdomain of it, such as www., and the path has
	 * to match exactly, so a lookalike host or a copy on another site does not match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src The script's src attribute.
	 *
	 * @return self|null
	 */
	public static function from_src( string $src ): ?self {
		$src = strtolower( trim( $src ) );
		$src = (string) preg_replace( '#^(?:[a-z][a-z0-9+.-]*:)?//#', '', $src );
		$src = strtok( $src, '?#' );

		if ( ! is_string( $src ) ) {
			return null;
		}

		[ $host, $path ] = array_pad( explode( '/', $src, 2 ), 2, '' );

		foreach ( self::cases() as $network ) {
			foreach ( $network->paths() as $known ) {
				[ $known_host, $known_path ] = explode( '/', $known, 2 );

				if ( $path === $known_path && ( $host === $known_host || str_ends_with( $host, '.' . $known_host ) ) ) {
					return $network;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the host and path a pasted script is recognized by, without a protocol.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function paths(): array {
		return match ( $this ) {
			self::Instagram => [ 'instagram.com/embed.js' ],
			self::X         => [ 'platform.twitter.com/widgets.js', 'platform.x.com/widgets.js' ],
			self::TikTok    => [ 'tiktok.com/embed.js' ],
			self::Pinterest => [ 'assets.pinterest.com/js/pinit.js' ],
		};
	}
}
