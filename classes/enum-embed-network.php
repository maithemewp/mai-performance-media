<?php

declare(strict_types=1);

namespace Mai\PerformanceMedia;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The social networks whose embed scripts are delayed until an embed nears the screen.
 *
 * The case order is the order the loader lists them in. Each one knows which pasted
 * script tags belong to it, and the one fixed address the loader fetches later.
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
	 * The address is compared without its protocol, query or case, so a pasted
	 * http://, a protocol-relative or an upper-case copy all match. A subdomain such
	 * as www. is allowed where the library lives on one.
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

		foreach ( self::cases() as $network ) {
			foreach ( $network->paths() as $path ) {
				if ( $src === $path || str_ends_with( $src, '.' . $path ) ) {
					return $network;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the address parts a pasted script is recognized by, without a protocol.
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

	/**
	 * Returns the one address the loader fetches the network's script from.
	 *
	 * Always https, and the same whichever pasted copy was delayed.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function script_url(): string {
		return match ( $this ) {
			self::Instagram => 'https://www.instagram.com/embed.js',
			self::X         => 'https://platform.twitter.com/widgets.js',
			self::TikTok    => 'https://www.tiktok.com/embed.js',
			self::Pinterest => 'https://assets.pinterest.com/js/pinit.js',
		};
	}
}
