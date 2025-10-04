=== WooCommerce Translation API ===
Contributors: Qoldn
Tags: woocommerce, translation, wpml, openai, deepl
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WooCommerce products automatically with OpenAI or DeepL, fully integrated with WPML.

== Description ==
Adds product-level translation controls, allowing you to pick which products to translate and into which language. 
Integrates with WPML for proper multilingual management. Includes "Translate Now" button in the product editor.

== Features ==
* OpenAI (ChatGPT) & DeepL integration
* WPML language dropdown in product edit screen
* Translate on save, via bulk action, or instantly via "Translate Now"
* Secure API key storage

== Installation ==
1. Upload `woocommerce-translation-api` to `/wp-content/plugins/`.
2. Activate plugin from WordPress admin.
3. Go to **Settings > Translation API** and set API keys.
4. Edit a product → enable translation → select target language → click "Translate Now".

== Changelog ==
= 1.2.0 =
* Added "Translate Now" button with AJAX
* Improved WPML integration
