<?php
/*
Plugin Name: WooCommerce Translation API (WPML)
Description: Translate WooCommerce products using OpenAI or DeepL. Integrates with WPML to create translated product posts (does not overwrite originals). Admin UI + REST endpoint to bulk translate titles, short and long descriptions, and slugs.
Version: 1.1
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Translation_API_Plugin_WPML {

    private $option_key = 'wc_translation_api_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'product_translate_button' ] );
        add_action( 'admin_post_wc_translate_single_product', [ $this, 'handle_single_product_translate' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /* Admin menu and settings */
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Translation API',
            'Translation API',
            'manage_woocommerce',
            'wc-translation-api',
            [ $this, 'settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( $this->option_key, $this->option_key, [ $this, 'sanitize_settings' ] );
        add_settings_section( 'wc_translation_api_main', 'Main Settings', null, $this->option_key );
        add_settings_field( 'provider', 'Provider', [ $this, 'field_provider' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'api_key', 'API Key', [ $this, 'field_api_key' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'source_lang', 'Source language (ISO)', [ $this, 'field_source_lang' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'target_lang', 'Target language (ISO)', [ $this, 'field_target_lang' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'batch_size', 'Batch size', [ $this, 'field_batch_size' ], $this->option_key, 'wc_translation_api_main' );
    }

    public function sanitize_settings( $input ) {
        $out = array();
        $out['provider'] = in_array( $input['provider'] ?? '', ['openai','deepl'] ) ? $input['provider'] : 'openai';
        $out['api_key'] = sanitize_text_field( $input['api_key'] ?? '' );
        $out['source_lang'] = sanitize_text_field( $input['source_lang'] ?? 'en' );
        $out['target_lang'] = sanitize_text_field( $input['target_lang'] ?? 'fr' );
        $out['batch_size'] = intval( $input['batch_size'] ?? 5 );
        if ( $out['batch_size'] < 1 ) $out['batch_size'] = 1;
        if ( $out['batch_size'] > 20 ) $out['batch_size'] = 20;
        return $out;
    }

    public function field_provider() {
        $s = get_option( $this->option_key, [] );
        $val = $s['provider'] ?? 'openai';
        ?>
        <select name="<?php echo esc_attr( $this->option_key ); ?>[provider]">
            <option value="openai" <?php selected( $val, 'openai' ); ?>>OpenAI</option>
            <option value="deepl" <?php selected( $val, 'deepl' ); ?>>DeepL</option>
        </select>
        <p class="description">Select API provider.</p>
        <?php
    }

    public function field_api_key() {
        $s = get_option( $this->option_key, [] );
        $val = $s['api_key'] ?? '';
        ?>
        <input style="width:60%" type="text" name="<?php echo esc_attr( $this->option_key ); ?>[api_key]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">API key for chosen provider.</p>
        <?php
    }

    public function field_source_lang() {
        $s = get_option( $this->option_key, [] );
        $val = $s['source_lang'] ?? 'en';
        ?>
        <input type="text" name="<?php echo esc_attr( $this->option_key ); ?>[source_lang]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">Source language, e.g. en</p>
        <?php
    }

    public function field_target_lang() {
        $s = get_option( $this->option_key, [] );
        $val = $s['target_lang'] ?? 'fr';
        ?>
        <input type="text" name="<?php echo esc_attr( $this->option_key ); ?>[target_lang]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">Target language, e.g. fr</p>
        <?php
    }

    public function field_batch_size() {
        $s = get_option( $this->option_key, [] );
        $val = $s['batch_size'] ?? 5;
        ?>
        <input type="number" min="1" max="20" name="<?php echo esc_attr( $this->option_key ); ?>[batch_size]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">How many products to translate per request.</p>
        <?php
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $settings = get_option( $this->option_key, [] );
        ?>
        <div class="wrap">
            <h1>WooCommerce Translation API (WPML)</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_key );
                do_settings_sections( $this->option_key );
                submit_button();
                ?>
            </form>

            <hr />

            <h2>Translate products (admin)</h2>
            <p>Enter product IDs separated by commas and click Translate (or use the REST endpoint).</p>

            <input id="wc_translate_product_ids" style="width:40%" placeholder="e.g. 12,34,56" />
            <button id="wc_translate_button" class="button button-primary">Translate selected products</button>

            <div id="wc_translate_progress" style="margin-top:12px;"></div>

            <h3>REST endpoint</h3>
            <p>POST <code><?php echo esc_url( rest_url( 'wc-translate/v1/translate' ) ); ?></code></p>
            <p>Payload (JSON): <code>{ "product_ids": [12,34], "target_lang":"fr" }</code></p>
            <p>Requires a logged-in user with <code>manage_woocommerce</code>.</p>
        </div>
        <?php
    }

    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'woocommerce_page_wc-translation-api' ) return;
        wp_enqueue_script( 'wc-translation-api-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '1.1', true );
        wp_localize_script( 'wc-translation-api-admin', 'WCTranslationApi', [
            'rest_url' => rest_url( 'wc-translate/v1/translate' ),
        ] );
    }

    public function product_translate_button( $post ) {
        echo '<p class="form-field"><label>Translate product</label>
            <a class="button button-primary" href="' . esc_url( admin_url( 'admin-post.php?action=wc_translate_single_product&product_id=' . get_the_ID() ) ) . '">Translate Now</a>
            <span class="description">Translate this product using the configured API (creates WPML translation if WPML active).</span></p>';
    }

    public function handle_single_product_translate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied' );
        }
        $product_id = intval( $_GET['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url() );
            exit;
        }

        $result = $this->translate_products( [ $product_id ] );
        $redirect = add_query_arg( [ 'wc_translate_result' => $result ? '1' : '0' ], wp_get_referer() ?: admin_url() );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function register_rest_routes() {
        register_rest_route( 'wc-translate/v1', '/translate', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_translate' ],
            'permission_callback' => function( $request ) {
                return current_user_can( 'manage_woocommerce' );
            },
        ] );
    }

    public function rest_translate( $request ) {
        $params = $request->get_json_params();
        $product_ids = $params['product_ids'] ?? [];
        if ( ! is_array( $product_ids ) ) {
            return new WP_Error( 'invalid_input', 'product_ids must be an array of IDs', [ 'status' => 400 ] );
        }
        $target_lang = sanitize_text_field( $params['target_lang'] ?? ( get_option( $this->option_key )['target_lang'] ?? 'fr' ) );

        $result = $this->translate_products( $product_ids, $target_lang );
        if ( $result ) {
            return rest_ensure_response( [ 'success' => true, 'translated' => count( $result ) ] );
        }
        return new WP_Error( 'translation_failed', 'Translation failed (see logs)', [ 'status' => 500 ] );
    }

    private function translate_products( $product_ids, $target_lang = null ) {
        if ( empty( $product_ids ) ) return [];

        $settings = get_option( $this->option_key, [] );
        $provider = $settings['provider'] ?? 'openai';
        $api_key  = $settings['api_key'] ?? '';
        $source_lang = $settings['source_lang'] ?? 'en';
        $target_lang = $target_lang ?: ( $settings['target_lang'] ?? 'fr' );
        $batch_size = intval( $settings['batch_size'] ?? 5 );
        if ( $batch_size < 1 ) $batch_size = 1;

        $translated_ids = [];

        $chunks = array_chunk( $product_ids, $batch_size );
        foreach ( $chunks as $chunk ) {
            $payload = [];
            foreach ( $chunk as $pid ) {
                $pid = intval( $pid );
                $product = wc_get_product( $pid );
                if ( ! $product ) continue;
                $post = get_post( $pid );
                $payload[] = [
                    'id' => $pid,
                    'title' => $post->post_title,
                    'excerpt' => $post->post_excerpt,
                    'content' => $post->post_content,
                    'slug' => $post->post_name,
                ];
            }
            if ( empty( $payload ) ) continue;

            try {
                $translations = $this->call_translation_provider( $provider, $api_key, $payload, $source_lang, $target_lang );
            } catch ( Exception $e ) {
                error_log( 'WC Translation API error: ' . $e->getMessage() );
                continue;
            }

            foreach ( $translations as $item ) {
                $pid = intval( $item['id'] );
                $original_post = get_post( $pid );
                if ( ! $original_post ) continue;

                // If WPML is active and target differs from source, create translated post and set language via SitePress
                $is_wpml_active = defined('ICL_SITEPRESS_VERSION') && isset($GLOBALS['sitepress']);
                $source_lang_code = $source_lang;
                $target_lang_code = $target_lang;

                if ( $is_wpml_active && strtolower($source_lang_code) !== strtolower($target_lang_code) ) {
                    // create translated post as a separate post
                    $new_post = [
                        'post_title'   => wp_strip_all_tags( $item['title'] ),
                        'post_content' => $item['content'],
                        'post_excerpt' => $item['excerpt'],
                        'post_status'  => $original_post->post_status,
                        'post_type'    => $original_post->post_type,
                        'post_name'    => sanitize_title( $item['slug'] ?: $item['title'] ),
                    ];
                    $new_id = wp_insert_post( $new_post );

                    if ( is_wp_error( $new_id ) || ! $new_id ) {
                        continue;
                    }

                    // Copy product meta and meta keys we care about (price, sku, attributes, etc.)
                    $this->copy_product_meta( $pid, $new_id );

                    // Associate language details via SitePress API
                    try {
                        $sitepress = $GLOBALS['sitepress'];
                        if ( is_object( $sitepress ) && method_exists( $sitepress, 'set_element_language_details' ) ) {
                            // set_element_language_details( $element_id, $element_type, $trid_or_source_element_id, $language_code )
                            // WPML expects trid handling; passing original as 'source_element_id' is accepted too.
                            $sitepress->set_element_language_details( $new_id, 'post_product', $pid, $target_lang_code );
                        } else {
                            // best-effort fallback - store meta for other tools
                            update_post_meta( $new_id, 'wc_translation_api_language', $target_lang_code );
                            update_post_meta( $new_id, 'wc_translation_api_source', $pid );
                        }
                    } catch ( Exception $e ) {
                        // continue even if language linking fails
                        update_post_meta( $new_id, 'wc_translation_api_language', $target_lang_code );
                        update_post_meta( $new_id, 'wc_translation_api_error', $e->getMessage() );
                    }

                    // regenerate product caches
                    $prod_new = wc_get_product( $new_id );
                    if ( $prod_new ) $prod_new->save();

                    $translated_ids[] = $new_id;
                } else {
                    // No WPML -> overwrite original (user should backup/or use WPML to prevent overwrite)
                    $post_arr = [
                        'ID' => $pid,
                        'post_title' => sanitize_text_field( $item['title'] ),
                        'post_excerpt' => wp_kses_post( $item['excerpt'] ),
                        'post_content' => wp_kses_post( $item['content'] ),
                    ];
                    wp_update_post( $post_arr );
                    if ( ! empty( $item['slug'] ) ) {
                        wp_update_post( [ 'ID' => $pid, 'post_name' => sanitize_title( $item['slug'] ) ] );
                    }
                    $prod = wc_get_product( $pid );
                    if ( $prod ) $prod->save();
                    $translated_ids[] = $pid;
                }
            }
        }

        return $translated_ids;
    }

    private function copy_product_meta( $source_id, $dest_id ) {
        // Copy commonly important product meta. You can extend this list to match your store.
        $meta_keys = [
            '_regular_price','_sale_price','_price','_sku','_stock_status',
            '_downloadable','_virtual','_weight','_length','_width','_height',
        ];
        foreach ( $meta_keys as $key ) {
            $val = get_post_meta( $source_id, $key, true );
            if ( $val !== '' ) update_post_meta( $dest_id, $key, maybe_unserialize( $val ) );
        }

        // Copy taxonomies (categories, tags)
        $taxonomies = ['product_cat','product_tag'];
        foreach ( $taxonomies as $tax ) {
            $terms = wp_get_object_terms( $source_id, $tax, ['fields' => 'slugs'] );
            if ( is_array( $terms ) ) {
                wp_set_object_terms( $dest_id, $terms, $tax );
            }
        }

        // Copy all custom fields except ones that are post-specific like GUID
        $all_meta = get_post_meta( $source_id );
        foreach ( $all_meta as $mkey => $vals ) {
            if ( in_array( $mkey, ['_wp_old_slug','_edit_lock','_edit_last','_thumbnail_id'] ) ) continue;
            foreach ( $vals as $v ) {
                add_post_meta( $dest_id, $mkey, maybe_unserialize( $v ) );
            }
        }
    }

    private function call_translation_provider( $provider, $api_key, $payload, $source_lang, $target_lang ) {
        if ( $provider === 'deepl' ) {
            return $this->call_deepl( $api_key, $payload, $source_lang, $target_lang );
        }
        return $this->call_openai( $api_key, $payload, $source_lang, $target_lang );
    }

    private function call_openai( $api_key, $payload, $source_lang, $target_lang ) {
        $items_text = "";
        foreach ( $payload as $p ) {
            $items_text .= "###PRODUCT_ID:" . intval( $p['id'] ) . "\n";
            $items_text .= "TITLE: " . $p['title'] . "\n";
            $items_text .= "EXCERPT: " . $p['excerpt'] . "\n";
            $items_text .= "CONTENT: " . $p['content'] . "\n";
            $items_text .= "SLUG: " . $p['slug'] . "\n\n";
        }

        $system = "You are a professional translator. Translate product fields from $source_lang to $target_lang. Return a JSON array of objects with keys: id, title, excerpt, content, slug. Do not include extra text. Keep HTML tags intact where present.";
        $user = "Translate the following products:\n\n" . $items_text . "\nReturn JSON only.";

        $body = [
            "model" => "gpt-3.5-turbo",
            "messages" => [
                [ "role" => "system", "content" => $system ],
                [ "role" => "user", "content" => $user ],
            ],
            "temperature" => 0.2,
            "max_tokens" => 2000,
        ];

        $response = $this->http_post_json( 'https://api.openai.com/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ] );

        if ( empty( $response['choices'][0]['message']['content'] ) ) {
            throw new Exception( 'Empty response from OpenAI' );
        }

        $raw = $response['choices'][0]['message']['content'];
        $json_text = $this->extract_json( $raw );
        if ( ! $json_text ) {
            $pos = strpos( $raw, '[' );
            if ( $pos !== false ) $json_text = substr( $raw, $pos );
        }
        if ( ! $json_text ) {
            throw new Exception( 'Could not extract JSON from OpenAI response' );
        }

        $decoded = json_decode( $json_text, true );
        if ( ! is_array( $decoded ) ) {
            throw new Exception( 'Invalid JSON from OpenAI: ' . json_last_error_msg() );
        }

        $out = [];
        foreach ( $decoded as $d ) {
            $out[] = [
                'id' => intval( $d['id'] ?? 0 ),
                'title' => $d['title'] ?? '',
                'excerpt' => $d['excerpt'] ?? '',
                'content' => $d['content'] ?? '',
                'slug' => $d['slug'] ?? '',
            ];
        }
        return $out;
    }

    private function call_deepl( $api_key, $payload, $source_lang, $target_lang ) {
        $out = [];
        foreach ( $payload as $p ) {
            $fields = ['title' => $p['title'], 'excerpt' => $p['excerpt'], 'content' => $p['content'], 'slug' => $p['slug']];
            $translated_fields = [];
            foreach ( $fields as $k => $v ) {
                if ( trim( $v ) === '' ) {
                    $translated_fields[$k] = '';
                    continue;
                }
                $resp = $this->http_post_form( 'https://api-free.deepl.com/v2/translate', [
                    'auth_key' => $api_key,
                    'text' => $v,
                    'source_lang' => strtoupper( $source_lang ),
                    'target_lang' => strtoupper( $target_lang ),
                ] );
                if ( empty( $resp['translations'][0]['text'] ) ) {
                    $translated_fields[$k] = '';
                    continue;
                }
                $translated_fields[$k] = $resp['translations'][0]['text'];
            }
            $out[] = [
                'id' => $p['id'],
                'title' => $translated_fields['title'] ?? '',
                'excerpt' => $translated_fields['excerpt'] ?? '',
                'content' => $translated_fields['content'] ?? '',
                'slug' => $translated_fields['slug'] ?? '',
            ];
        }
        return $out;
    }

    private function http_post_json( $url, $body, $headers = [] ) {
        $ch = curl_init( $url );
        $payload = json_encode( $body );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array_merge( $headers, [
            'Content-Length: ' . strlen( $payload )
        ] ) );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
        $res = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err = curl_error( $ch );
        curl_close( $ch );
        if ( $res === false ) {
            throw new Exception( 'HTTP request failed: ' . $err );
        }
        $decoded = json_decode( $res, true );
        if ( $decoded === null ) {
            throw new Exception( "Invalid JSON response (http {$code}) from {$url}: " . substr( $res, 0, 1000 ) );
        }
        return $decoded;
    }

    private function http_post_form( $url, $fields ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
        $res = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err = curl_error( $ch );
        curl_close( $ch );
        if ( $res === false ) {
            throw new Exception( 'HTTP request failed: ' . $err );
        }
        $decoded = json_decode( $res, true );
        if ( $decoded === null ) {
            throw new Exception( "Invalid JSON response (http {$code}): " . substr( $res, 0, 1000 ) );
        }
        return $decoded;
    }

    private function extract_json( $text ) {
        $text = trim( $text );
        if ( preg_match( '/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $text, $m ) ) {
            return $m[1];
        }
        if ( preg_match( '/(\[.*\])\s*$/s', $text, $m ) ) {
            return $m[1];
        }
        if ( preg_match( '/(\{.*\})\s*$/s', $text, $m ) ) {
            return $m[1];
        }
        return false;
    }
}

new WC_Translation_API_Plugin_WPML();

