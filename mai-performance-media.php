<?php
/**
 * Plugin Name:       Mai Performance Media
 * Description:       Loads each page's main image right away, lazy loads the rest, and loads social embeds as readers scroll to them.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            JiveDig
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mai-performance-media
 */

namespace Mai\PerformanceMedia;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Include vendor files.
require_once __DIR__ . '/vendor/autoload.php';

// Initialize.
LoadingAttributes::instance();
new EmbedLoading();
new ImageLoading();
new MaiBlocks();
new Updater();

add_action( 'after_setup_theme', __NAMESPACE__ . '\add_mai_engine_support' );
/**
 * Add support for Mai Theme v2.
 *
 * @since 0.1.0
 *
 * @return void
 */
function add_mai_engine_support() {
	if ( ! class_exists( '\Mai_Engine' ) ) {
		return;
	}

	new MaiEngine();
	new MaiEntryLoading();
}
