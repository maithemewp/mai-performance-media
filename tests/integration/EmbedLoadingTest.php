<?php

declare(strict_types=1);

namespace Mai\PerformanceImages\Tests\Integration;

use Mai\PerformanceImages\EmbedLoading;
use Mai\PerformanceImages\Tests\TestCase;

/**
 * Embed scripts are delayed on the finished page, and one loader is added to start
 * them when an embed nears the screen.
 */
final class EmbedLoadingTest extends TestCase {

	private const LOADER_ID = 'id="mai-performance-media-lazy-embeds"';

	private const INSTAGRAM_QUOTE = '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/ABC123/" data-instgrm-version="14" style="background:#FFF;">Post</blockquote>';

	private const X_QUOTE = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Text</p>&mdash; Name (@handle) <a href="https://twitter.com/handle/status/123">Date</a></blockquote>';

	private const TIKTOK_QUOTE = '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@user/video/123" data-video-id="123"><section></section></blockquote>';

	private function run_filter( string $html ): string {
		return ( new EmbedLoading() )->filter_page( $html );
	}

	private function page_html( string $body ): string {
		return '<html><head><title>T</title></head><body>' . $body . '</body></html>';
	}

	public static function libraries(): array {
		return [
			'instagram'  => [ '//www.instagram.com/embed.js', 'instagram.com/embed.js', 'instagram' ],
			'x twitter'  => [ 'https://platform.twitter.com/widgets.js', 'platform.twitter.com/widgets.js', 'x' ],
			'x new host' => [ 'https://platform.x.com/widgets.js', 'platform.x.com/widgets.js', 'x' ],
			'tiktok'     => [ 'https://www.tiktok.com/embed.js', 'tiktok.com/embed.js', 'tiktok' ],
		];
	}

	/**
	 * @dataProvider libraries
	 */
	public function test_each_library_is_delayed( string $src, string $path, string $network ): void {
		$html = $this->page_html( self::INSTAGRAM_QUOTE . "\n" . '<script async src="' . $src . '" charset="utf-8"></script>' );
		$out  = $this->run_filter( $html );

		$this->assertStringNotContainsString( 'src="' . $src . '"', $out );
		$this->assertStringNotContainsString( 'src="', str_replace( self::INSTAGRAM_QUOTE, '', $out ) );
		$this->assertStringContainsString( self::INSTAGRAM_QUOTE, $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="' . $network . '"', $out );
	}

	public static function unusual_sources(): array {
		return [
			'http'       => [ 'http://www.instagram.com/embed.js' ],
			'upper case' => [ 'HTTPS://WWW.Instagram.COM/embed.js' ],
			'query'      => [ '//www.instagram.com/embed.js?v=2' ],
		];
	}

	/**
	 * @dataProvider unusual_sources
	 */
	public function test_unusual_src_forms_are_delayed( string $src ): void {
		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async src="' . $src . '"></script>' ) );

		// The loader names each network's script itself, so only the page around it is checked.
		$page = (string) preg_replace( '#<script[^>]*' . self::LOADER_ID . '[^>]*>.*?</script>#s', '', $out );

		$this->assertStringNotContainsStringIgnoringCase( 'embed.js', $page );
		$this->assertStringNotContainsString( 'src="' . $src . '"', $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}

	public function test_mai_embed_fixer_tag_is_delayed(): void {
		$out = $this->run_filter( $this->page_html( self::INSTAGRAM_QUOTE . '<script async class="mai-instagram-script" src="//www.instagram.com/embed.js" charset="utf-8"></script>' ) );

		$this->assertStringNotContainsString( 'src="//www.instagram.com', $out );
		$this->assertStringContainsString( 'class="mai-instagram-script"', $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}

	public function test_three_pasted_copies_give_one_loader(): void {
		$embed = self::INSTAGRAM_QUOTE . "\n" . '<script async src="//www.instagram.com/embed.js"></script>';
		$out   = $this->run_filter( $this->page_html( $embed . $embed . $embed ) );

		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
		$this->assertStringContainsString( 'data-networks="instagram"', $out );
		$this->assertStringNotContainsString( 'src="//www.instagram.com', $out );
	}

	public function test_networks_listed_in_order(): void {
		$out = $this->run_filter( $this->page_html(
			self::TIKTOK_QUOTE . '<script async src="https://www.tiktok.com/embed.js"></script>'
			. self::X_QUOTE . '<script async src="https://platform.twitter.com/widgets.js"></script>'
			. self::INSTAGRAM_QUOTE . '<script async src="//www.instagram.com/embed.js"></script>'
		) );

		$this->assertStringContainsString( 'data-networks="instagram,x,tiktok"', $out );
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
		$this->assertStringEndsWith( "</script>\n", $out );
		$this->assertGreaterThan( strpos( $out, '<blockquote' ), strpos( $out, self::LOADER_ID ) );
	}

	public function test_pinit_delayed_only_with_a_pasted_pin(): void {
		$pin    = '<a data-pin-do="embedPin" href="https://www.pinterest.com/pin/1/"></a>';
		$script = '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>';

		$with = $this->run_filter( $this->page_html( $pin . $script ) );

		$this->assertStringNotContainsString( 'src="//assets.pinterest.com', $with );
		$this->assertStringContainsString( 'data-networks="pinterest"', $with );

		$without_pin = $this->page_html( '<p>No pin</p>' . $script );

		$this->assertSame( $without_pin, $this->run_filter( $without_pin ) );
	}

	public function test_pinit_with_data_pin_attributes_is_left_alone(): void {
		$html = $this->page_html(
			'<a data-pin-do="embedPin" href="https://www.pinterest.com/pin/1/"></a>'
			. '<script async defer data-pin-hover="true" src="//assets.pinterest.com/js/pinit.js"></script>'
		);

		$this->assertSame( $html, $this->run_filter( $html ) );

		$mixed = $this->run_filter( $html . '<script async defer src="//assets.pinterest.com/js/pinit.js"></script>' );

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
		$html = $this->page_html(
			'<!-- <script src="//www.instagram.com/embed.js"></script> -->'
			. '<script>var url = "instagram.com/embed.js";</script>'
		);

		$this->assertSame( $html, $this->run_filter( $html ) );
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

		$this->assertStringNotContainsString( 'src="//www.instagram.com', $out );
		$this->assertSame( 1, substr_count( $out, self::LOADER_ID ) );
	}
}
