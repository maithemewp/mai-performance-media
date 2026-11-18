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
		$url = wp_parse_url( trim( $src ) );

		if ( ! is_array( $url ) || ! isset( $url['host'], $url['path'] ) ) {
			return null;
		}

		$host = strtolower( $url['host'] );
		$path = strtolower( $url['path'] );

		foreach ( self::cases() as $network ) {
			foreach ( $network->libraries() as [ $known_host, $known_path ] ) {
				if ( $path === $known_path && ( $host === $known_host || str_ends_with( $host, '.' . $known_host ) ) ) {
					return $network;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the host and path of each library a pasted script is recognized by.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function libraries(): array {
		return match ( $this ) {
			self::Instagram => [ [ 'instagram.com', '/embed.js' ] ],
			self::X         => [ [ 'platform.twitter.com', '/widgets.js' ], [ 'platform.x.com', '/widgets.js' ] ],
			self::TikTok    => [ [ 'tiktok.com', '/embed.js' ] ],
			self::Pinterest => [ [ 'assets.pinterest.com', '/js/pinit.js' ] ],
		};
	}
}
