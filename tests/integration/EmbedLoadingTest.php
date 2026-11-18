<?php

declare(strict_types=1);

namespace Mai\PerformanceMedia\Tests\Integration;

use Mai\PerformanceMedia\EmbedLoading;
use Mai\PerformanceMedia\EmbedNetwork;
use Mai\PerformanceMedia\Tests\TestCase;

/**
 * Embed scripts are delayed on the finished page, and one loader is added to start
 * them when an embed nears the screen.
 */
final class EmbedLoadingTest extends TestCase {

	private const LOADER_ID = 'id="mai-performance-media-lazy-embeds"';

	private const LOADER_FILE = __DIR__ . '/../../assets/js/lazy-embeds.js';

	private const INSTAGRAM_QUOTE = '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/ABC123/" data-instgrm-version="14" style="background:#FFF;">Post</blockquote>';

	private const X_QUOTE = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Text</p>&mdash; Name (@handle) <a href="https://twitter.com/handle/status/123">Date</a></blockquote>';

	private const TIKTOK_QUOTE = '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@user/video/123" data-video-id="123"><section></section></blockquote>';

	private const PIN = '<a data-pin-do="embedPin" href="https://www.pinterest.com/pin/1/"></a>';

	public function tear_down(): void {
		$this->set_loader( null );

		parent::tear_down();
	}

	private function run_filter( string $html ): string {
		return ( new EmbedLoading() )->filter_page( $html );
	}

	private function page_html( string $body ): string {
		return '<html><head><title>T</title></head><body>' . $body . '</body></html>';
	}

	/**
	 * Sets the loader source EmbedLoading caches for the request. Null makes it read
	 * the file again. Used to stand in for a file that could not be read.
	 *
	 * @param string|null $source The loader source.
	 *
	 * @return void
	 */
	private function set_loader( ?string $source ): void {
		( new \ReflectionProperty( EmbedLoading::class, 'loader' ) )->setValue( null, $source );
	}

	/**
	 * Returns the src of every script tag except the loader's, in page order.
	 *
	 * @param string $html The page.
	 *
	 * @return string[]
	 */
	private function script_srcs( string $html ): array {
		$srcs = [];
		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'script' ) ) {
			$src = $tags->get_attribute( 'src' );

			if ( is_string( $src ) && ! $this->is_loader( $tags ) ) {
				$srcs[] = $src;
			}
		}

		return $srcs;
	}

	/**
	 * Returns the loader's script text, or null when the page has no loader.
	 *
	 * @param string $html The page.
	 *
	 * @return string|null
	 */
	private function loader_text( string $html ): ?string {
		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'script' ) ) {
			if ( $this->is_loader( $tags ) ) {
				return $tags->get_modifiable_text();
			}
		}

		return null;
	}

	/**
	 * Whether the last tag on the page is the loader.
	 *
	 * @param string $html The page.
	 *
	 * @return bool
	 */
	private function loader_is_last( string $html ): bool {
		$tags = new \WP_HTML_Tag_Processor( $html );
		$last = false;

		while ( $tags->next_tag() ) {
			$last = $this->is_loader( $tags );
		}

		return $last;
	}

	private function is_loader( \WP_HTML_Tag_Processor $tags ): bool {
		return 'SCRIPT' === $tags->get_tag() && 'mai-performance-media-lazy-embeds' === $tags->get_attribute( 'id' );
	}

	public static function libraries(): array {
		return [
			'instagram'  => [ '//www.instagram.com/embed.js', self::INSTAGRAM_QUOTE, 'instagram' ],
			'x twitter'  => [ 'https://platform.twitter.com/widgets.js', self::X_QUOTE, 'x' ],
			'x new host' => [ 'https://platform.x.com/widgets.js', self::X_QUOTE, 'x' ],
			'tiktok'     => [ 'https://www.tiktok.com/embed.js', self::TIKTOK_QUOTE, 'tiktok' ],
			'pinterest'  => [ '//assets.pinterest.com/js/pinit.js', self::PIN, 'pinterest' ],
		];
	}

	/**
	 * @dataProvider libraries
	 */
	public function test_each_library_is_delayed( string $src, string $embed, string $network ): void {
		$out = $this->run_filter( $this->page_html( $embed . "\n" . '<script async src="' . $src . '" charset="utf-8"></script>' ) );

		$this->assertSame( [], $this->script_srcs( $out ) );
		$this->assertStringContainsString( $embed . "\n", $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="' . $network . '"', $out );
	}

	public static function unusual_sources(): array {
		return [
			'instagram http'          => [ '<script async src="http://www.instagram.com/embed.js"></script>', 'instagram' ],
			'instagram upper case'    => [ '<script async src="HTTPS://WWW.Instagram.COM/embed.js"></script>', 'instagram' ],
			'instagram query'         => [ '<script async src="//www.instagram.com/embed.js?v=2"></script>', 'instagram' ],
			'instagram fragment'      => [ '<script async src="//www.instagram.com/embed.js#top"></script>', 'instagram' ],
			'instagram single quotes' => [ "<script async SRC='//www.instagram.com/embed.js'></script>", 'instagram' ],
			'x upper case'            => [ '<script async src="HTTPS://PLATFORM.Twitter.COM/widgets.js"></script>', 'x' ],
			'x query'                 => [ '<script async src="https://platform.x.com/widgets.js?v=2"></script>', 'x' ],
			'x fragment'              => [ '<script async src="https://platform.twitter.com/widgets.js#x"></script>', 'x' ],
			'x single quotes'         => [ "<script async SRC='https://platform.twitter.com/widgets.js'></script>", 'x' ],
			'tiktok upper case'       => [ '<script async src="HTTPS://WWW.TikTok.COM/embed.js"></script>', 'tiktok' ],
			'tiktok query'            => [ '<script async src="https://www.tiktok.com/embed.js?lang=en"></script>', 'tiktok' ],
			'tiktok fragment'         => [ '<script async src="https://www.tiktok.com/embed.js#a"></script>', 'tiktok' ],
			'tiktok single quotes'    => [ "<script async SRC='https://www.tiktok.com/embed.js'></script>", 'tiktok' ],
		];
	}

	/**
	 * @dataProvider unusual_sources
	 */
	public function test_unusual_src_forms_are_delayed( string $script, string $network ): void {
		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . $script ) );

		$this->assertSame( [], $this->script_srcs( $out ) );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="' . $network . '"', $out );
	}

	public static function lookalike_sources(): array {
		return [
			'longer host'        => [ 'https://evilinstagram.com/embed.js' ],
			'host as subdomain'  => [ 'https://www.instagram.com.evil.com/embed.js' ],
			'path on other site' => [ 'https://example.org/instagram.com/embed.js' ],
			'protocol relative'  => [ '//cdn.example.com/tiktok.com/embed.js' ],
			'dotted path'        => [ 'https://example.com/foo.instagram.com/embed.js' ],
			'longer path'        => [ 'https://www.instagram.com/v2/embed.js' ],
		];
	}

	/**
	 * @dataProvider lookalike_sources
	 */
	public function test_lookalike_sources_are_untouched( string $src ): void {
		$html = $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="' . $src . '"></script>' );

		$this->assertNull( EmbedNetwork::from_src( $src ) );
		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_mai_embed_fixer_tag_is_delayed(): void {
		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async class="mai-instagram-script" src="//www.instagram.com/embed.js" charset="utf-8"></script>' ) );

		$this->assertSame( [], $this->script_srcs( $out ) );
		$this->assertStringContainsString( 'class="mai-instagram-script"', $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}

	public function test_three_pasted_copies_give_one_loader(): void {
		$embed = self::INSTAGRAM_QUOTE . "\n" . '<script async src="//www.instagram.com/embed.js"></script>';
		$out   = $this->run_filter( $this->page_html( $embed . $embed . $embed ) );

		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="instagram"', $out );
		$this->assertSame( [], $this->script_srcs( $out ) );
	}

	public function test_networks_listed_in_order(): void {
		$out = $this->run_filter( $this->page_html(
			self::PIN . '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>'
			. self::TIKTOK_QUOTE . '<script async src="https://www.tiktok.com/embed.js"></script>'
			. self::X_QUOTE . '<script async src="https://platform.twitter.com/widgets.js"></script>'
			. self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>'
		) );

		$this->assertStringContainsString( 'data-networks="instagram,x,tiktok,pinterest"', $out );
	}

	public function test_loader_goes_before_the_last_body_close(): void {
		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' ) );

		$loader = strpos( $out, self::LOADER_ID );

		$this->assertNotFalse( $loader );
		$this->assertGreaterThan( $loader, strripos( $out, '</body>' ) );
		$this->assertSame( 1, substr_count( $out, '</body>' ) );
	}

	public function test_loader_is_appended_without_body_close(): void {
		$out = $this->run_filter( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertTrue( $this->loader_is_last( $out ) );
		$this->assertStringStartsWith( self::INSTAGRAM_QUOTE, $out );
	}

	public function test_loader_file_is_safe_to_print_inline(): void {
		$source = (string) file_get_contents( self::LOADER_FILE );

		$this->assertNotSame( '', trim( $source ) );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $source );
		$this->assertStringNotContainsStringIgnoringCase( '</script', $source );
		$this->assertStringNotContainsString( '<!--', $source );

		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' ) );

		$this->assertStringContainsString( 'IntersectionObserver', (string) $this->loader_text( $out ) );
	}

	public function test_loader_networks_match_the_enum(): void {
		$source = (string) file_get_contents( self::LOADER_FILE );

		// Each network in the loader's networks object starts with its fixed src.
		preg_match_all( "#^\t{3}([a-z]+): \\{\n\t{4}src: '([^']+)',$#m", $source, $matches, PREG_SET_ORDER );

		$keys = array_column( $matches, 1 );

		$this->assertSame( array_column( EmbedNetwork::cases(), 'value' ), $keys );

		foreach ( $matches as [ , $key, $src ] ) {
			$this->assertSame( EmbedNetwork::from( $key ), EmbedNetwork::from_src( $src ), $key );
		}
	}

	public function test_unreadable_loader_leaves_the_page_identical(): void {
		// A failed read is cached as an empty source.
		$this->set_loader( '' );

		$html = $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_refused_loader_tag_leaves_the_page_identical(): void {
		// WordPress only refuses script text it can't escape, which needs a type it
		// doesn't know and text that could close the tag.
		$this->set_loader( 'document.write( "<script></script>" );' );
		add_filter( 'wp_inline_script_attributes', fn( array $attributes ): array => $attributes + [ 'type' => 'text/plain' ] );
		$this->setExpectedIncorrectUsage( 'WP_HTML_Tag_Processor::set_modifiable_text' );

		$html = $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_pinit_delayed_only_with_a_pasted_pin(): void {
		$script = '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>';

		$with = $this->run_filter( $this->page_html( self::PIN . $script ) );

		$this->assertSame( [], $this->script_srcs( $with ) );
		$this->assertSame( 1, substr_count( $with, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="pinterest"', $with );

		$without_pin = $this->page_html( '<p>No pin</p>' . $script );

		$this->assertSame( $without_pin, $this->run_filter( $without_pin ) );
	}

	public function test_pinit_with_data_pin_attributes_is_left_alone(): void {
		$html = $this->page_html(
			self::PIN
			. '<script async defer data-pin-hover="true" src="//assets.pinterest.com/js/pinit.js"></script>'
		);

		$this->assertSame( $html, $this->run_filter( $html ) );

		$mixed = $this->run_filter( $html . '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>' );

		$this->assertSame( [ '//assets.pinterest.com/js/pinit.js' ], $this->script_srcs( $mixed ) );
		$this->assertStringContainsString( 'data-pin-hover="true" src="//assets.pinterest.com/js/pinit.js"', $mixed );
		$this->assertStringContainsString( 'data-networks="pinterest"', $mixed );

		$only_hover = $this->run_filter( $html . self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$this->assertStringContainsString( 'data-networks="instagram"', $only_hover );
	}

	public function test_other_scripts_are_untouched(): void {
		$html = $this->page_html( '<script src="https://example.org/jquery.js"></script><script>var a = 1;</script>' );

		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_comment_and_script_text_are_untouched(): void {
		$comment = '<!-- <script src="//www.instagram.com/embed.js"></script> -->';
		$inline  = '<script>var url = "instagram.com/embed.js";</script>';
		$html    = $this->page_html( $comment . $inline );

		$this->assertSame( $html, $this->run_filter( $html ) );

		// With a real embed script on the same page, only that one loses its src.
		$out = $this->run_filter( $this->page_html( $comment . $inline . self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' ) );

		$this->assertStringContainsString( $comment . $inline . self::INSTAGRAM_QUOTE, $out );
		$this->assertSame( [], $this->script_srcs( $out ) );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}

	public function test_running_twice_gives_one_loader(): void {
		$once = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' ) );

		$this->assertSame( $once, $this->run_filter( $once ) );
		$this->assertSame( 1, substr_count( $once, self::LOADER_ID ) );
	}

	public function test_non_string_input(): void {
		$filter = new EmbedLoading();

		$this->assertSame( '', $filter->filter_page( null ) );
		$this->assertSame( '5', $filter->filter_page( 5 ) );
		$this->assertSame( '', $filter->filter_page( [ 'page' ] ) );
	}

	public function test_filter_turns_it_off(): void {
		add_filter( 'mai_performance_media_lazy_embeds', '__return_false' );

		$html = $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_page_without_embeds_is_identical(): void {
		$html = $this->page_html( '<p>Hello</p><img src="https://example.org/a.jpg" width="10" height="10" alt="">' );

		$this->assertSame( $html, $this->run_filter( $html ) );
	}

	public function test_through_the_real_page_buffer(): void {
		$html = $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>' );

		$out = $this->page( fn() => $html );

		$this->assertSame( [], $this->script_srcs( $out ) );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}
}
