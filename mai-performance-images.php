<?php
/**
 * Plugin Name:       Mai Performance Images
 * Description:       Loads each page's main image right away and lazy loads the rest, with Image Loading settings for blocks, Mai grids and the Customizer.
 * Version:           0.9.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            JiveDig
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mai-performance-images
 */

namespace Mai\PerformanceImages;

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
