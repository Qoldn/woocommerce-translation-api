<?php
/**
 * Plugin Name: WooCommerce Translation API
 * Description: Translate WooCommerce products via OpenAI or DeepL with WPML integration.
 * Version: 1.1.0
 * Author: Qoldn
 * License: GPL2+
 * Text Domain: wc-translation-api
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('WC_TRANSLATION_API_PATH', plugin_dir_path(__FILE__));
define('WC_TRANSLATION_API_URL', plugin_dir_url(__FILE__));

require_once WC_TRANSLATION_API_PATH . 'includes/class-wc-translation-settings.php';
require_once WC_TRANSLATION_API_PATH . 'includes/class-wc-translation-meta-box.php';
require_once WC_TRANSLATION_API_PATH . 'includes/class-wc-translation-service.php';
require_once WC_TRANSLATION_API_PATH . 'includes/class-wc-translation-bulk.php';

add_action('plugins_loaded', function() {
    new WC_Translation_Settings();
    new WC_Translation_Meta_Box();
    new WC_Translation_Service();
    new WC_Translation_Bulk();
});
