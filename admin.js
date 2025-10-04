jQuery(function($){
    $('#wc_translate_button').on('click', function(e){
        e.preventDefault();
        var ids = $('#wc_translate_product_ids').val().trim();
        if (!ids) {
            alert('Please enter product IDs (comma separated).');
            return;
        }
        var arr = ids.split(',').map(function(x){ return parseInt(x.trim(),10); }).filter(Boolean);
        if (!arr.length) {
            alert('No valid IDs found.');
            return;
        }

        $('#wc_translate_progress').html('Starting translation...');

        var data = {
            product_ids: arr
        };

        $.ajax({
            url: WCTranslationApi.rest_url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function(res){
                $('#wc_translate_progress').html('Success — translated ' + (res.translated || 0) + ' products.');
            },
            error: function(xhr){
                var msg = 'Translation failed';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg += ': ' + xhr.responseJSON.message;
                $('#wc_translate_progress').html(msg);
            }
        });

    });
});

