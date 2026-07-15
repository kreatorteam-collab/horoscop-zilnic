/* global HZAdmin, jQuery */
(function ($) {
    'use strict';

    $(function () {
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
