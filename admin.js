jQuery(document).ready(function ($) {

    const $button = $('#wc-translate-now');
    const $status = $('#wc-translation-status');

    if (!$button.length) return;

    $button.on('click', function () {
        $status.html('<p>⏳ Translating products, please wait...</p>');
        $button.prop('disabled', true).text('Translating...');

        // Fetch all product IDs via AJAX (you can modify to select certain ones)
        $.ajax({
            url: '/wp-json/wc/v3/products?per_page=100',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', WCTranslationApi.nonce);
            },
            success: function (products) {
                const productIds = products.map(p => p.id);

                if (!productIds.length) {
                    $status.html('<p>⚠️ No products found.</p>');
                    $button.prop('disabled', false).text('Translate All Products');
                    return;
                }

                // Send the translation request
                $.ajax({
                    url: WCTranslationApi.endpoint,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        product_ids: productIds,
                        target_lang: $('input[name="wc_translation_api_settings[target_lang]"]').val() || 'fr'
                    }),
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', WCTranslationApi.nonce);
                    },
                    success: function (response) {
                        console.log(response);
                        $status.html('<p style="color:green;">✅ ' + WCTranslationApi.successText + '</p>');
                        $button.prop('disabled', false).text('Translate All Products');
                    },
                    error: function (xhr) {
                        console.error(xhr.responseText);
                        let msg = WCTranslationApi.errorText;
                        try {
                            const json = JSON.parse(xhr.responseText);
                            if (json && json.message) msg = json.message;
                        } catch (e) { }
                        $status.html('<p style="color:red;">❌ ' + msg + '</p>');
                        $button.prop('disabled', false).text('Translate All Products');
                    }
                });
            },
            error: function (xhr) {
                console.error(xhr.responseText);
                $status.html('<p style="color:red;">❌ Could not fetch products. Make sure WooCommerce REST API is accessible.</p>');
                $button.prop('disabled', false).text('Translate All Products');
            }
        });
    });
});
