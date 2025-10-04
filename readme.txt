=== WooCommerce Translation API ===
Contributors: Qoldn
Tags: woocommerce, translation, wpml, openai, deepl
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WooCommerce products automatically with OpenAI or DeepL, fully integrated with WPML.

== Description ==
This plugin adds product-level translation controls, allowing you to pick which products to translate and into which language. Integrates with WPML for proper multilingual management.

== Features ==
* OpenAI (ChatGPT) & DeepL integration
* WPML language dropdown in product edit screen
* Translate on save or via bulk action
* Secure API key storage

== Installation ==
1. Upload `woocommerce-translation-api` to `/wp-content/plugins/`.
2. Activate plugin from WordPress admin.
3. Go to **Settings > Translation API** and set API keys.
4. Edit a product → enable translation → select target language → save.

== Changelog ==
= 1.1.0 =
* Added WPML integration
* Product meta box for translation control
* Bulk action "Translate Products"
