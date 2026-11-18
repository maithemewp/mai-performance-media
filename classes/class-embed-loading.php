<?php

declare(strict_types=1);

namespace Mai\PerformanceMedia;

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
 * Pinterest has two rules of its own. Its script is only delayed on a page with a
 * pasted pin, and never when the script tag has data-pin- attributes, because those
 * set up Pinterest's hover and save buttons, which load as they do today.
 *
 * It uses the same page filter as LoadingAttributes, but does its own pass: that walk
 * only visits images, and the two jobs share nothing.
 *
 * @since 1.0.0
 */
final class EmbedLoading {
	/**
	 * The id of the loader's script tag.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const LOADER_ID = 'mai-performance-media-lazy-embeds';

	/**
	 * The loader's source, read once per request. Empty when the file can't be read.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private static ?string $loader = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		// WordPress starts its page buffer at priority 1000 on this action, and a filter
		// has to be added before then. Priority 10 gets in first.
		add_action( 'wp_before_include_template', [ $this, 'add_page_filter' ], 10, 0 );
	}

	/**
	 * Adds the finished-page filter.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
		 * @since 1.0.0
		 *
		 * @param bool $delay Whether to delay them. Default true.
		 */
		if ( ! apply_filters( 'mai_performance_media_lazy_embeds', true ) || ! self::has_known_script( $html ) ) {
			return $html;
		}

		// Whether the page has a pasted pin, looked up once the first Pinterest script
		// turns up, since most pages have none.
		$pins    = null;
		$tags    = new \WP_HTML_Tag_Processor( $html );
		$delayed = [];

		while ( $tags->next_tag( 'script' ) ) {
			$src = $tags->get_attribute( 'src' );

			// A boolean src attribute reads as true, and a missing one as null.
			$network = is_string( $src ) ? EmbedNetwork::from_src( $src ) : null;

			if ( ! $network ) {
				continue;
			}

			// Pinterest's script is only delayed on a page with a pasted pin. A copy with
			// data-pin- attributes of its own runs the hover or save buttons, so it is
			// left alone.
			if ( EmbedNetwork::Pinterest === $network ) {
				$pins ??= false !== stripos( $html, 'data-pin-do' );

				if ( ! $pins || $tags->get_attribute_names_with_prefix( 'data-pin-' ) ) {
					continue;
				}
			}

			$tags->remove_attribute( 'src' );

			$delayed[ $network->value ] = true;
		}

		if ( ! $delayed ) {
			return $html;
		}

		// Without the loader nothing would put a delayed script back. The walk only
		// changed the tag processor, so the page as it came in is returned whole.
		$loader = self::loader();

		if ( '' === $loader ) {
			return $html;
		}

		// Listed in case order, whatever order the page had them in.
		$networks = array_filter( array_column( EmbedNetwork::cases(), 'value' ), fn( string $name ): bool => isset( $delayed[ $name ] ) );

		$tag = wp_get_inline_script_tag(
			$loader,
			[
				'id'            => self::LOADER_ID,
				'data-networks' => implode( ',', $networks ),
			]
		);

		// WordPress returns an empty tag when it refuses the script text, for example
		// when a filter gives the tag a type it can't escape for. The page as it came
		// in still has every src.
		if ( '' === $tag ) {
			return $html;
		}

		return self::add_loader( $tags->get_updated_html(), $tag );
	}

	/**
	 * Whether the page mentions any known embed script, as a quick check before the walk.
	 *
	 * This runs on every page, so it looks for each library's file name once, which is
	 * fewer scans than one per library. The walk then checks the whole address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html The finished page.
	 *
	 * @return bool
	 */
	private static function has_known_script( string $html ): bool {
		$files = [];

		foreach ( EmbedNetwork::cases() as $network ) {
			foreach ( $network->libraries() as [ , $path ] ) {
				$files[ basename( $path ) ] = true;
			}
		}

		foreach ( array_keys( $files ) as $file ) {
			if ( false !== stripos( $html, $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the loader's source, or an empty string when the file can't be read.
	 *
	 * The readable check keeps a PHP warning out of the page.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function loader(): string {
		if ( null === self::$loader ) {
			$file   = dirname( __DIR__ ) . '/assets/js/lazy-embeds.js';
			$source = is_readable( $file ) ? file_get_contents( $file ) : false;

			self::$loader = is_string( $source ) ? trim( $source ) : '';
		}

		return self::$loader;
	}

	/**
	 * Adds the loader tag before the last closing body tag, or at the end without one.
	 *
	 * The tag is built with wp_get_inline_script_tag() so a site's script attribute
	 * filters, such as a content security policy nonce, apply to it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html The page.
	 * @param string $tag  The loader's script tag.
	 *
	 * @return string
	 */
	private static function add_loader( string $html, string $tag ): string {
		$body = strripos( $html, '</body>' );

		if ( false === $body ) {
			return $html . $tag;
		}

		return substr( $html, 0, $body ) . $tag . substr( $html, $body );
	}
}
