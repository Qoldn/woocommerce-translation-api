<?php
/**
 * Plugin Name: WooCommerce Translation API
 * Description: Translate WooCommerce products via OpenAI or DeepL with WPML integration.
 * Version: 1.2.0
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

// Enqueue admin JS
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'post.php' || $hook === 'post-new.php' || $hook === 'settings_page_wc_translation_api') {
        wp_enqueue_script(
            'wc-translation-api-admin',
            WC_TRANSLATION_API_URL . 'assets/js/admin.js',
            ['jquery'],
            '1.2.0',
            true
        );
        wp_localize_script('wc-translation-api-admin', 'wcTranslationApi', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wc_translation_api_nonce'),
        ]);
    }
});
