<?php
/**
 * Plugin Name: WooCommerce Translation API
 * Description: Automatically translate WooCommerce products using OpenAI or DeepL, integrated with WPML.
 * Version: 1.2.1
 * Author: GPT Dev
 * Requires Plugins: woocommerce, sitepress-multilingual-cms
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Translation_API {

    private $option_name = 'wc_translation_api_settings';
    private $option_group = 'wc_translation_api_group';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /** -------------------------
     * ADMIN MENU
     * --------------------------*/
    public function add_menu() {
        add_menu_page(
            'Product Translator',
            'Product Translator',
            'manage_woocommerce',
            'wc-translation-api',
            [ $this, 'settings_page' ],
            'dashicons-translation',
            56
        );
    }

    public function settings_page() {
        $options = get_option( $this->option_name );
        ?>
        <div class="wrap">
            <h1>WooCommerce Translation API</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->option_group );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Provider</th>
                        <td>
                            <select name="<?php echo esc_attr($this->option_name); ?>[provider]">
                                <option value="openai" <?php selected( $options['provider'] ?? '', 'openai' ); ?>>OpenAI</option>
                                <option value="deepl" <?php selected( $options['provider'] ?? '', 'deepl' ); ?>>DeepL</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_name); ?>[api_key]" size="50"
                                value="<?php echo esc_attr( $options['api_key'] ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Target Language</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_name); ?>[target_lang]" value="<?php echo esc_attr( $options['target_lang'] ?? 'fr' ); ?>" />
                            <p class="description">Enter a two-letter code (e.g. fr, es, de, ar).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Translate Products</h2>
            <p>Select products in WooCommerce → Products → Bulk actions → Translate via API</p>
            <button id="wc-translate-now" class="button button-primary">Translate All Products</button>
            <div id="wc-translation-status"></div>
        </div>
        <?php
    }

    /** -------------------------
     * SCRIPTS
     * --------------------------*/
    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'toplevel_page_wc-translation-api' ) {
            return;
        }

        wp_enqueue_script(
            'wc-translation-api-js',
            plugin_dir_url(__FILE__) . 'admin.js',
            [ 'jquery' ],
            '1.2.1',
            true
        );

        wp_localize_script( 'wc-translation-api-js', 'WCTranslationApi', [
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'endpoint' => rest_url( 'wc-translate/v1/translate' ),
            'successText' => __( 'Translation completed successfully!', 'wc-translation-api' ),
            'errorText'   => __( 'An error occurred during translation.', 'wc-translation-api' )
        ]);
    }

    /** -------------------------
     * REST ROUTES
     * --------------------------*/
    public function register_rest_routes() {
        register_rest_route( 'wc-translate/v1', '/translate', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_translate' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ]);
    }

    public function check_permissions( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'You must be logged in to translate products.', 'wc-translation-api' ), [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions. Only Admin or Shop Manager allowed.', 'wc-translation-api' ), [ 'status' => 403 ] );
        }
        return true;
    }

    /** -------------------------
     * TRANSLATION LOGIC
     * --------------------------*/
    public function rest_translate( $request ) {
        $params = $request->get_json_params();
        $product_ids = $params['product_ids'] ?? [];
        $target_lang = sanitize_text_field( $params['target_lang'] ?? 'fr' );

        if ( empty( $product_ids ) ) {
            return new WP_REST_Response([ 'error' => 'No product IDs provided.' ], 400);
        }

        foreach ( $product_ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            $translated_data = $this->translate_product( $product, $target_lang );
            if ( $translated_data ) {
                $this->save_translated_product( $product, $translated_data, $target_lang );
            }
        }

        return new WP_REST_Response([ 'message' => 'Translation completed.' ], 200);
    }

    private function translate_product( $product, $target_lang ) {
        $options = get_option( $this->option_name );
        $provider = $options['provider'] ?? 'openai';
        $api_key  = $options['api_key'] ?? '';

        $text = sprintf(
            "Product: %s\nShort: %s\nDescription: %s\nTranslate all fields into %s.",
            $product->get_name(),
            $product->get_short_description(),
            $product->get_description(),
            $target_lang
        );

        if ( $provider === 'openai' ) {
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [ 'role' => 'system', 'content' => 'You are a professional product translator for e-commerce.' ],
                        [ 'role' => 'user', 'content' => $text ],
                    ],
                ]),
                'timeout' => 60,
            ]);
        } else {
            // DeepL API
            $response = wp_remote_post( 'https://api-free.deepl.com/v2/translate', [
                'body' => [
                    'auth_key' => $api_key,
                    'text' => $text,
                    'target_lang' => strtoupper($target_lang),
                ],
            ]);
        }

        if ( is_wp_error( $response ) ) return false;
        $body = wp_remote_retrieve_body( $response );
        return $body;
    }

    private function save_translated_product( $original, $translated_data, $target_lang ) {
        $data = json_decode( $translated_data, true );
        if ( empty( $data ) ) return;

        $new_product = new WC_Product_Simple();
        $new_product->set_name( $data['title'] ?? $original->get_name() );
        $new_product->set_description( $data['description'] ?? $original->get_description() );
        $new_product->set_short_description( $data['short_description'] ?? $original->get_short_description() );
        $new_product->save();

        // WPML Integration
        if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'wpml_update_translatable_content' ) ) {
            global $sitepress;
            $sitepress->set_element_language_details(
                apply_filters( 'wpml_element_type', 'post_product' ),
                get_post_type( $new_product->get_id() ),
                $new_product->get_id(),
                $target_lang,
                $original->get_id()
            );
        }
    }
}

new WC_Translation_API();
