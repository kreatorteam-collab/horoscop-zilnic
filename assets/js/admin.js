/* global HZAdmin, jQuery, wp */
(function ($) {
    'use strict';

    // Selector de imagine cover (galerie/upload + import din URL), parametrizat pe prefix.
    // prefix 'hz' => #hz-image-pick / #hz_image ... ; prefix 'hzw' => #hzw-image-pick ...
    function initImagePicker(prefix, pickTitle) {
        var frame = null;
        var $hidden  = $('#' + prefix + '_image');
        var $preview = $('#' + prefix + '-image-preview');
        var $remove  = $('#' + prefix + '-image-remove');

        if (!$hidden.length) { return; } // tab-ul nu e randat acum

        $('#' + prefix + '-image-pick').on('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: pickTitle,
                button: { text: 'Folosește imaginea' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $hidden.val(att.id);
                var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                $preview.html('<img src="' + url + '" style="max-width:150px;height:auto;" />');
                $remove.prop('disabled', false);
            });
            frame.open();
        });

        $remove.on('click', function () {
            $hidden.val(0);
            $preview.empty();
            $(this).prop('disabled', true);
        });

        $('#' + prefix + '-image-import').on('click', function () {
            var $btn = $(this), $st = $('#' + prefix + '-image-import-status');
            var url = $.trim($('#' + prefix + '-image-url').val());
            if (!url) { $st.css('color', '#b32d2e').text(' Pune întâi URL-ul imaginii.'); return; }
            $st.css('color', '').text(' Se importă…');
            $btn.prop('disabled', true);

            $.post(HZAdmin.ajaxUrl, {
                action: 'hz_import_image', // acțiune generică (sideload în Media)
                nonce: HZAdmin.nonce,
                url: url
            }).done(function (res) {
                if (res && res.success) {
                    $hidden.val(res.data.id);
                    $preview.html(res.data.html);
                    $remove.prop('disabled', false);
                    $('#' + prefix + '-image-url').val('');
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
    }

    // Buton „Generează test (ciornă)", parametrizat pe prefixul UI și pe acțiunea AJAX.
    function initTestButton(prefix, action) {
        var $btn = $('#' + prefix + '-test');
        if (!$btn.length) { return; }
        var $res = $('#' + prefix + '-test-result');
        $btn.on('click', function () {
            $res.css('color', '').text(' Se generează… (1–2 minute)');
            $btn.prop('disabled', true);
            $.ajax({
                url: HZAdmin.ajaxUrl,
                method: 'POST',
                timeout: 300000,
                data: { action: action, nonce: HZAdmin.nonce }
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
    }

    $(function () {
        // Horoscop
        initImagePicker('hz', 'Alege imaginea de cover pentru horoscop');
        initTestButton('hz', 'hz_test');
        // Vreme
        initImagePicker('hzw', 'Alege imaginea de cover pentru vreme');
        initTestButton('hzw', 'hzw_test');
    });
})(jQuery);
