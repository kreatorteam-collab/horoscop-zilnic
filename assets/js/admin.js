/* global HZAdmin, jQuery, wp */
(function ($) {
    'use strict';

    $(function () {
        // Imagine cover: alegere din galerie / upload (modalul standard WP)
        var frame = null;
        $('#hz-image-pick').on('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'Alege imaginea de cover pentru horoscop',
                button: { text: 'Folosește imaginea' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $('#hz_image').val(att.id);
                var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                $('#hz-image-preview').html('<img src="' + url + '" style="max-width:150px;height:auto;" />');
                $('#hz-image-remove').prop('disabled', false);
            });
            frame.open();
        });

        // Elimină imaginea (0 = fără cover)
        $('#hz-image-remove').on('click', function () {
            $('#hz_image').val(0);
            $('#hz-image-preview').empty();
            $(this).prop('disabled', true);
        });

        // Import dintr-un URL extern -> Media
        $('#hz-image-import').on('click', function () {
            var $btn = $(this), $st = $('#hz-image-import-status');
            var url = $.trim($('#hz-image-url').val());
            if (!url) { $st.css('color', '#b32d2e').text(' Pune întâi URL-ul imaginii.'); return; }
            $st.css('color', '').text(' Se importă…');
            $btn.prop('disabled', true);

            $.post(HZAdmin.ajaxUrl, {
                action: 'hz_import_image',
                nonce: HZAdmin.nonce,
                url: url
            }).done(function (res) {
                if (res && res.success) {
                    $('#hz_image').val(res.data.id);
                    $('#hz-image-preview').html(res.data.html);
                    $('#hz-image-remove').prop('disabled', false);
                    $('#hz-image-url').val('');
                    $st.css('color', '#00a32a').text(' ✓ Importată. Apasă „Salvează setările".');
                } else {
                    $st.css('color', '#b32d2e').text(' ✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $st.css('color', '#b32d2e').text(' ✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Generează o ciornă de probă (poate dura 1-2 minute)
        $('#hz-test').on('click', function () {
            var $btn = $(this), $res = $('#hz-test-result');
            $res.css('color', '').text(' Se generează… (1–2 minute)');
            $btn.prop('disabled', true);

            $.ajax({
                url: HZAdmin.ajaxUrl,
                method: 'POST',
                timeout: 300000,
                data: { action: 'hz_test', nonce: HZAdmin.nonce }
            }).done(function (res) {
                if (res && res.success) {
                    var link = res.data.edit_link ? ' <a href="' + res.data.edit_link + '" target="_blank">Deschide ciorna</a>' : '';
                    $res.css('color', '#00a32a').html(' ✓ ' + res.data.message + link);
                } else {
                    $res.css('color', '#b32d2e').text(' ✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $res.css('color', '#b32d2e').text(' ✗ Eroare de rețea sau timeout — verifică în Articole dacă ciorna a apărut totuși.');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
