<?php

declare(strict_types=1);

namespace Mai\PerformanceImages;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Delays social embed scripts on the finished page, until an embed nears the screen.
 *
 * Instagram, X, TikTok and Pinterest embeds each come with a script that loads right
 * away, and a post can paste the same one many times. On the finished page this
 * removes the src from each of those script tags, which leaves an empty script that
 * runs nothing, and adds one small loader before the closing body tag. The loader
 * fetches a network's script once, when the first embed of that network comes close
 * to the screen.
 *
 * It uses the same page filter as LoadingAttributes, but does its own pass: that walk
 * only visits images, and the two jobs share nothing.
 *
 * @since 0.10.0
 */
final class EmbedLoading {
	/**
	 * The id of the loader's script tag.
	 *
	 * @since 0.10.0
	 *
	 * @var string
	 */
	private const LOADER_ID = 'mai-performance-media-lazy-embeds';

	/**
	 * The loader's source, read once per request.
	 *
	 * @since 0.10.0
	 *
	 * @var string|null
	 */
	private static ?string $loader = null;

	/**
	 * Constructor.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	public function __construct() {
		// WordPress starts collecting the page at priority 1000 when the page filter
		// exists by then, so this adds it earlier on the same action.
		add_action( 'wp_before_include_template', [ $this, 'add_page_filter' ], 10, 0 );
	}

	/**
	 * Adds the finished-page filter.
	 *
	 * @since 0.10.0
	 *
	 * @return void
	 */
	public function add_page_filter(): void {
		add_filter( 'wp_template_enhancement_output_buffer', [ $this, 'filter_page' ] );
	}

	/**
	 * Delays the embed scripts on the finished page and adds the loader.
	 *
	 * WP filter callbacks must be public.
	 *
	 * @since 0.10.0
	 *
	 * @param mixed $html The finished page.
	 *
	 * @return string
	 */
	public function filter_page( mixed $html ): string {
		if ( ! is_string( $html ) ) {
			return is_scalar( $html ) ? (string) $html : '';
		}

		/**
		 * Filters whether social embed scripts are delayed until an embed nears the screen.
		 *
		 * @since 0.10.0
		 *
		 * @param bool $delay Whether to delay them. Default true.
		 */
		if ( ! apply_filters( 'mai_performance_media_lazy_embeds', true ) || ! self::has_known_script( $html ) ) {
			return $html;
		}

		// A Pinterest script only runs pasted pins when the page has one. Without a
		// pin it is the hover and save buttons, which load as they do today.
		$pins     = false !== stripos( $html, 'data-pin-do' );
		$tags     = new \WP_HTML_Tag_Processor( $html );
		$networks = [];

		while ( $tags->next_tag( 'script' ) ) {
			$src = $tags->get_attribute( 'src' );

			// A boolean src attribute reads as true, and a missing one as null.
			$network = is_string( $src ) ? EmbedNetwork::from_src( $src ) : null;

			if ( ! $network ) {
				continue;
			}

			// A script with data-pin attributes of its own is configured for hover or
			// save buttons, so it is left alone.
			if ( EmbedNetwork::Pinterest === $network && ( ! $pins || $tags->get_attribute_names_with_prefix( 'data-pin-' ) ) ) {
				continue;
			}

			$tags->remove_attribute( 'src' );

			$networks[ $network->value ] = $network;
		}

		if ( ! $networks ) {
			return $html;
		}

		$list = array_filter(
			array_map( fn( EmbedNetwork $network ) => $network->value, EmbedNetwork::cases() ),
			fn( string $value ) => isset( $networks[ $value ] )
		);

		return self::add_loader( $tags->get_updated_html(), implode( ',', $list ) );
	}

	/**
	 * Whether the page mentions any known embed script, as a quick check before the walk.
	 *
	 * @since 0.10.0
	 *
	 * @param string $html The finished page.
	 *
	 * @return bool
	 */
	private static function has_known_script( string $html ): bool {
		foreach ( EmbedNetwork::cases() as $network ) {
			foreach ( $network->paths() as $path ) {
				if ( false !== stripos( $html, $path ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Adds the loader before the last closing body tag, or at the end without one.
	 *
	 * Built with wp_get_inline_script_tag() so a site's script attribute filters,
	 * such as a content security policy nonce, apply to it.
	 *
	 * @since 0.10.0
	 *
	 * @param string $html     The page.
	 * @param string $networks The delayed networks, comma separated.
	 *
	 * @return string
	 */
	private static function add_loader( string $html, string $networks ): string {
		self::$loader ??= (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/lazy-embeds.js' );

		$tag = wp_get_inline_script_tag(
			self::$loader,
			[
				'id'            => self::LOADER_ID,
				'data-networks' => $networks,
			]
		);

		$body = strripos( $html, '</body>' );

		if ( false === $body ) {
			return $html . $tag;
		}

		return substr( $html, 0, $body ) . $tag . substr( $html, $body );
	}
}
