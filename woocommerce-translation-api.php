<?php
/*
Plugin Name: WooCommerce Translation API (WPML + Polylang + Background)
Description: Translate WooCommerce products using OpenAI or DeepL. Integrates with WPML & Polylang (creates translated product posts). Uses Action Scheduler for background processing and supports a glossary.
Version: 1.2
Author: Your Name
Text Domain: wc-translation-api
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Translation_API_Plugin' ) ) :

class WC_Translation_API_Plugin {

    private $option_key = 'wc_translation_api_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'product_translate_button' ] );
        add_action( 'admin_post_wc_translate_single_product', [ $this, 'handle_single_product_translate' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Background processing hook (Action Scheduler)
        add_action( 'wc_translation_process_batch', [ $this, 'process_translation_batch' ], 10, 1 );
    }

    /* Admin menu */
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
        add_settings_field( 'target_lang', 'Default target language (ISO)', [ $this, 'field_target_lang' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'batch_size', 'Batch size', [ $this, 'field_batch_size' ], $this->option_key, 'wc_translation_api_main' );
        add_settings_field( 'glossary', 'Glossary (one entry per line: source=>target)', [ $this, 'field_glossary' ], $this->option_key, 'wc_translation_api_main' );
    }

    public function sanitize_settings( $input ) {
        $out = array();
        $out['provider'] = in_array( $input['provider'] ?? '', ['openai','deepl'] ) ? $input['provider'] : 'openai';
        $out['api_key'] = sanitize_text_field( $input['api_key'] ?? '' );
        $out['source_lang'] = sanitize_text_field( $input['source_lang'] ?? 'en' );
        $out['target_lang'] = sanitize_text_field( $input['target_lang'] ?? 'fr' );
        $out['batch_size'] = intval( $input['batch_size'] ?? 5 );
        if ( $out['batch_size'] < 1 ) $out['batch_size'] = 1;
        if ( $out['batch_size'] > 50 ) $out['batch_size'] = 50;
        // Normalize glossary as plain text
        $out['glossary'] = wp_kses_post( $input['glossary'] ?? '' );
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
        <p class="description">Choose translation provider.</p>
        <?php
    }

    public function field_api_key() {
        $s = get_option( $this->option_key, [] );
        $val = $s['api_key'] ?? '';
        ?>
        <input style="width:60%" type="text" name="<?php echo esc_attr( $this->option_key ); ?>[api_key]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">API key for the provider (OpenAI or DeepL).</p>
        <?php
    }

    public function field_source_lang() {
        $s = get_option( $this->option_key, [] );
        $val = $s['source_lang'] ?? 'en';
        ?>
        <input type="text" name="<?php echo esc_attr( $this->option_key ); ?>[source_lang]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">Source language (ISO), e.g. en</p>
        <?php
    }

    public function field_target_lang() {
        $s = get_option( $this->option_key, [] );
        $val = $s['target_lang'] ?? 'fr';
        ?>
        <input type="text" name="<?php echo esc_attr( $this->option_key ); ?>[target_lang]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">Default target language (ISO), e.g. fr</p>
        <?php
    }

    public function field_batch_size() {
        $s = get_option( $this->option_key, [] );
        $val = $s['batch_size'] ?? 5;
        ?>
        <input type="number" min="1" max="50" name="<?php echo esc_attr( $this->option_key ); ?>[batch_size]" value="<?php echo esc_attr( $val ); ?>" />
        <p class="description">Products per background job (Action Scheduler).</p>
        <?php
    }

    public function field_glossary() {
        $s = get_option( $this->option_key, [] );
        $val = $s['glossary'] ?? '';
        ?>
        <textarea name="<?php echo esc_attr( $this->option_key ); ?>[glossary]" rows="6" style="width:60%"><?php echo esc_textarea( $val ); ?></textarea>
        <p class="description">Add brand names or common phrases to preserve or map, one per line format: <code>SourceTerm=>TargetTerm</code> or just <code>SourceTerm</code> to preserve.</p>
        <?php
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $settings = get_option( $this->option_key, [] );
        ?>
        <div class="wrap">
            <h1>WooCommerce Translation API</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_key );
                do_settings_sections( $this->option_key );
                submit_button();
                ?>
            </form>

            <hr />

            <h2>Translate products (admin)</h2>
            <p>Enter product IDs separated by commas and click Translate to queue background jobs. You can also call the REST endpoint.</p>

            <input id="wc_translate_product_ids" style="width:40%" placeholder="e.g. 12,34,56" />
            <select id="wc_translate_target_lang">
                <option value=""><?php echo esc_html( $settings['target_lang'] ?? '' ); ?> (default)</option>
                <!-- optional quick select of site languages could be added -->
            </select>
            <button id="wc_translate_button" class="button button-primary">Queue translation</button>

            <div id="wc_translate_progress" style="margin-top:12px;"></div>

            <h3>REST endpoint</h3>
            <p>POST <code><?php echo esc_url( rest_url( 'wc-translate/v1/translate' ) ); ?></code></p>
            <p>Payload (JSON): <code>{ "product_ids": [12,34], "target_lang":"fr" }</code></p>
            <p>Requires a logged-in user with <code>manage_woocommerce</code>. For external automation use Application Passwords or another WP auth method.</p>
        </div>
        <?php
    }

    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'woocommerce_page_wc-translation-api' ) return;
        wp_enqueue_script( 'wc-translation-api-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '1.2', true );
        wp_localize_script( 'wc-translation-api-admin', 'WCTranslationApi', [
            'rest_url' => rest_url( 'wc-translate/v1/translate' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public function product_translate_button( $post ) {
        echo '<p class="form-field"><label>Translate product</label>
            <a class="button button-primary" href="' . esc_url( admin_url( 'admin-post.php?action=wc_translate_single_product&product_id=' . get_the_ID() ) ) . '">Translate Now</a>
            <span class="description">Creates translation (WPML/Polylang if active) using the configured API.</span></p>';
    }

    public function handle_single_product_translate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Permission denied' );
        $product_id = intval( $_GET['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url() );
            exit;
        }

        $settings = get_option( $this->option_key, [] );
        $target_lang = sanitize_text_field( $_GET['target_lang'] ?? ( $settings['target_lang'] ?? '' ) );

        // enqueue background job for this single product
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'wc_translation_process_batch', [ 'product_ids' => [$product_id], 'target_lang' => $target_lang ] );
        } else {
            // fallback to immediate processing
            $this->process_translation_batch( [ 'product_ids' => [$product_id], 'target_lang' => $target_lang ] );
        }

        $redirect = add_query_arg( [ 'wc_translate_queued' => 1 ], wp_get_referer() ?: admin_url() );
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
        $target_lang = sanitize_text_field( $params['target_lang'] ?? ( get_option( $this->option_key )['target_lang'] ?? '' ) );

        $settings = get_option( $this->option_key, [] );
        $batch_size = intval( $settings['batch_size'] ?? 5 );
        if ( $batch_size < 1 ) $batch_size = 1;

        // Chunk and schedule background jobs
        $chunks = array_chunk( $product_ids, $batch_size );
        foreach ( $chunks as $chunk ) {
            $payload = [ 'product_ids' => $chunk, 'target_lang' => $target_lang ];
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( 'wc_translation_process_batch', $payload );
            } else {
                // immediate fallback
                $this->process_translation_batch( $payload );
            }
        }

        return rest_ensure_response( [ 'success' => true, 'queued' => count( $chunks ) ] );
    }

    /**
     * Background worker (Action Scheduler hook).
     * $args = ['product_ids' => [...], 'target_lang' => 'fr']
     */
    public function process_translation_batch( $args ) {
        $product_ids = $args['product_ids'] ?? [];
        $target_lang  = $args['target_lang'] ?? null;
        if ( ! is_array( $product_ids ) || empty( $product_ids ) ) return false;

        $settings = get_option( $this->option_key, [] );
        $provider = $settings['provider'] ?? 'openai';
        $api_key  = $settings['api_key'] ?? '';
        $source_lang = $settings['source_lang'] ?? 'en';
        $target_lang = $target_lang ?: ( $settings['target_lang'] ?? 'fr' );
        $glossary_text = $settings['glossary'] ?? '';
        $glossary = $this->parse_glossary( $glossary_text );

        // Build payload for translation provider
        $payload = [];
        foreach ( $product_ids as $pid ) {
            $pid = intval( $pid );
            $post = get_post( $pid );
            if ( ! $post ) continue;
            $payload[] = [
                'id' => $pid,
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'content' => $post->post_content,
                'slug' => $post->post_name,
            ];
        }
        if ( empty( $payload ) ) return false;

        try {
            $translations = $this->call_translation_provider( $provider, $api_key, $payload, $source_lang, $target_lang, $glossary );
        } catch ( Exception $e ) {
            error_log( 'WC Translation API error: ' . $e->getMessage() );
            return false;
        }

        // Apply translations: create WPML/Polylang translations where available
        foreach ( $translations as $item ) {
            $pid = intval( $item['id'] ?? 0 );
            if ( ! $pid ) continue;
            $original_post = get_post( $pid );
            if ( ! $original_post ) continue;

            $is_wpml_active = defined('ICL_SITEPRESS_VERSION') && isset($GLOBALS['sitepress']);
            $is_polylang_active = function_exists( 'pll_the_languages' ) || defined( 'POLYLANG_VERSION' );

            // If WPML active and target differs -> create new translated post and link via SitePress
            if ( $is_wpml_active && strtolower($settings['source_lang']) !== strtolower($target_lang) ) {
                $new_post = [
                    'post_title'   => wp_strip_all_tags( $item['title'] ),
                    'post_content' => $item['content'],
                    'post_excerpt' => $item['excerpt'],
                    'post_status'  => $original_post->post_status,
                    'post_type'    => $original_post->post_type,
                    'post_name'    => sanitize_title( $item['slug'] ?: $item['title'] ),
                ];
                $new_id = wp_insert_post( $new_post );
                if ( $new_id && ! is_wp_error( $new_id ) ) {
                    $this->copy_product_meta( $pid, $new_id );
                    // link languages using SitePress
                    try {
                        $sitepress = $GLOBALS['sitepress'];
                        if ( is_object( $sitepress ) && method_exists( $sitepress, 'set_element_language_details' ) ) {
                            $sitepress->set_element_language_details( $new_id, 'post_product', $pid, $target_lang );
                        } else {
                            update_post_meta( $new_id, 'wc_translation_api_language', $target_lang );
                            update_post_meta( $new_id, 'wc_translation_api_source', $pid );
                        }
                    } catch ( Exception $e ) {
                        update_post_meta( $new_id, 'wc_translation_api_error', $e->getMessage() );
                    }
                    $prod_new = wc_get_product( $new_id );
                    if ( $prod_new ) $prod_new->save();
                }
            }
            // Polylang path
            elseif ( $is_polylang_active && function_exists( 'pll_set_post_language' ) && strtolower($settings['source_lang']) !== strtolower($target_lang) ) {
                $new_post = [
                    'post_title'   => wp_strip_all_tags( $item['title'] ),
                    'post_content' => $item['content'],
                    'post_excerpt' => $item['excerpt'],
                    'post_status'  => $original_post->post_status,
                    'post_type'    => $original_post->post_type,
                    'post_name'    => sanitize_title( $item['slug'] ?: $item['title'] ),
                ];
                $new_id = wp_insert_post( $new_post );
                if ( $new_id && ! is_wp_error( $new_id ) ) {
                    $this->copy_product_meta( $pid, $new_id );
                    // set language
                    try {
                        pll_set_post_language( $new_id, $target_lang );
                        // link translations: get existing translations of original and add new
                        $translations_map = [];
                        $orig_translations = function_exists('pll_get_post_translations') ? pll_get_post_translations( $pid ) : [];
                        if ( is_array( $orig_translations ) ) {
                            $translations_map = $orig_translations;
                        }
                        $translations_map[$target_lang] = $new_id;
                        if ( function_exists( 'pll_save_post_translations' ) ) {
                            pll_save_post_translations( $translations_map );
                        }
                    } catch ( Exception $e ) {
                        update_post_meta( $new_id, 'wc_translation_api_error', $e->getMessage() );
                    }
                    $prod_new = wc_get_product( $new_id );
                    if ( $prod_new ) $prod_new->save();
                }
            }
            else {
                // No multilingual plugin -> overwrite original (warning: recommended to enable WPML/Polylang to avoid overwriting)
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
            }
        }

        return true;
    }

    private function parse_glossary( $text ) {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', trim( $text ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            if ( strpos( $line, '=>' ) !== false ) {
                list( $a, $b ) = array_map('trim', explode('=>', $line, 2) );
                if ( $a !== '' ) $out[ $a ] = $b;
            } else {
                $out[ $line ] = $line; // preserve unchanged
            }
        }
        return $out;
    }

    private function call_translation_provider( $provider, $api_key, $payload, $source_lang, $target_lang, $glossary = [] ) {
        if ( $provider === 'deepl' ) {
            return $this->call_deepl( $api_key, $payload, $source_lang, $target_lang, $glossary );
        }
        return $this->call_openai( $api_key, $payload, $source_lang, $target_lang, $glossary );
    }

    /* OpenAI path */
    private function call_openai( $api_key, $payload, $source_lang, $target_lang, $glossary = [] ) {
        // Build items and prompt. We also include glossary instructions to preserve/replace terms.
        $items_text = "";
        foreach ( $payload as $p ) {
            $items_text .= "###PRODUCT_ID:" . intval( $p['id'] ) . "\n";
            $items_text .= "TITLE: " . $p['title'] . "\n";
            $items_text .= "EXCERPT: " . $p['excerpt'] . "\n";
            $items_text .= "CONTENT: " . $p['content'] . "\n";
            $items_text .= "SLUG: " . $p['slug'] . "\n\n";
        }

        // Glossary instructions
        $glossary_text = '';
        if ( ! empty( $glossary ) ) {
            $glossary_text = "Glossary (do not translate source => target):\n";
            foreach ( $glossary as $k => $v ) {
                $glossary_text .= $k . ' => ' . $v . "\n";
            }
            $glossary_text .= "\n";
        }

        $system = "You are a professional translator. Translate product fields from $source_lang to $target_lang. Return a JSON array of objects with keys: id, title, excerpt, content, slug. Keep HTML tags intact. DO NOT output any explanation or additional text.";
        $user = $glossary_text . "Translate the following products:\n\n" . $items_text . "\nReturn JSON only.";

        $body = [
            "model" => "gpt-3.5-turbo",
            "messages" => [
                [ "role" => "system", "content" => $system ],
                [ "role" => "user", "content" => $user ],
            ],
            "temperature" => 0.1,
            "max_tokens" => 3000,
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

        // Apply glossary replacements post-hoc to be safe
        $out = [];
        foreach ( $decoded as $d ) {
            $title = $d['title'] ?? '';
            $excerpt = $d['excerpt'] ?? '';
            $content = $d['content'] ?? '';
            $slug = $d['slug'] ?? '';

            if ( ! empty( $glossary ) ) {
                foreach ( $glossary as $s => $t ) {
                    // replace only exact matches and case-insensitive where appropriate
                    if ( $s === $t ) {
                        // preserve original source occurrence -> replace translated form back to source
                        $title = str_ireplace( $t, $s, $title );
                        $excerpt = str_ireplace( $t, $s, $excerpt );
                        $content = str_ireplace( $t, $s, $content );
                        $slug = str_ireplace( $t, $s, $slug );
                    } else {
                        // map source -> target
                        $title = str_ireplace( $s, $t, $title );
                        $excerpt = str_ireplace( $s, $t, $excerpt );
                        $content = str_ireplace( $s, $t, $content );
                        $slug = str_ireplace( $s, $t, $slug );
                    }
                }
            }

            $out[] = [
                'id' => intval( $d['id'] ?? 0 ),
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'slug' => $slug,
            ];
        }

        return $out;
    }

    /* DeepL path: translate each field separately */
    private function call_deepl( $api_key, $payload, $source_lang, $target_lang, $glossary = [] ) {
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

            // apply glossary
            if ( ! empty( $glossary ) ) {
                foreach ( $translated_fields as $k => $v ) {
                    foreach ( $glossary as $s => $t ) {
                        if ( $s === $t ) {
                            // preserve original term in translated text
                            $translated_fields[$k] = str_ireplace( $t, $s, $translated_fields[$k] );
                        } else {
                            // replace source with target mapping
                            $translated_fields[$k] = str_ireplace( $s, $t, $translated_fields[$k] );
                        }
                    }
                }
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

    private function copy_product_meta( $source_id, $dest_id ) {
        $meta_keys = [
            '_regular_price','_sale_price','_price','_sku','_stock_status',
            '_downloadable','_virtual','_weight','_length','_width','_height',
        ];
        foreach ( $meta_keys as $key ) {
            $val = get_post_meta( $source_id, $key, true );
            if ( $val !== '' && $val !== false ) update_post_meta( $dest_id, $key, maybe_unserialize( $val ) );
        }

        // taxonomies
        $taxonomies = ['product_cat','product_tag'];
        foreach ( $taxonomies as $tax ) {
            $terms = wp_get_object_terms( $source_id, $tax, ['fields' => 'slugs'] );
            if ( is_array( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $dest_id, $terms, $tax );
            }
        }

        // Copy all custom meta except some
        $all_meta = get_post_meta( $source_id );
        foreach ( $all_meta as $mkey => $vals ) {
            if ( in_array( $mkey, ['_wp_old_slug','_edit_lock','_edit_last','_thumbnail_id'] ) ) continue;
            foreach ( $vals as $v ) {
                add_post_meta( $dest_id, $mkey, maybe_unserialize( $v ) );
            }
        }
    }

    /* HTTP helpers */
    private function http_post_json( $url, $body, $headers = [] ) {
        $ch = curl_init( $url );
        $payload = json_encode( $body );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array_merge( $headers, [
            'Content-Length: ' . strlen( $payload )
        ] ) );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
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

new WC_Translation_API_Plugin();

endif;
