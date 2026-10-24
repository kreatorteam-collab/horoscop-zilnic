/* global CWAdmin, jQuery */
(function ($) {
    'use strict';

    $(function () {
        // Slider temperature -> afișează valoarea
        $('#cw_temperature').on('input change', function () {
            $('#cw-temp-val').text($(this).val());
        });

        // Test cheie API OpenAI
        $('#cw-test-openai-key').on('click', function () {
            var $btn = $(this), $res = $('#cw-test-openai-result');
            $res.removeClass('cw-ok cw-err').text('…');
            $btn.prop('disabled', true);

            $.post(CWAdmin.ajaxUrl, {
                action: 'cw_test_openai_key',
                nonce: CWAdmin.nonce
            }).done(function (res) {
                if (res && res.success) {
                    $res.addClass('cw-ok').text('✓ ' + res.data.message);
                } else {
                    $res.addClass('cw-err').text('✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $res.addClass('cw-err').text('✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Lista modelelor de pe serverul GPT configurat (v3.51.1)
        $('#cw-list-models').on('click', function () {
            var $btn = $(this), $res = $('#cw-list-models-result');
            $res.removeClass('cw-ok cw-err').text('…');
            $btn.prop('disabled', true);
            $.post(CWAdmin.ajaxUrl, { action: 'cw_list_models', nonce: CWAdmin.nonce }).done(function (res) {
                if (res && res.success) {
                    $res.addClass('cw-ok').text(res.data.message);
                } else {
                    $res.addClass('cw-err').text('✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $res.addClass('cw-err').text('✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        // Test cheie API Anthropic
        $('#cw-test-key').on('click', function () {
            var $btn = $(this), $res = $('#cw-test-result');
            $res.removeClass('cw-ok cw-err').text('…');
            $btn.prop('disabled', true);

            $.post(CWAdmin.ajaxUrl, {
                action: 'cw_test_key',
                nonce: CWAdmin.nonce,
                key: $('#cw_api_key').val()
            }).done(function (res) {
                if (res && res.success) {
                    $res.addClass('cw-ok').text('✓ ' + res.data.message);
                } else {
                    $res.addClass('cw-err').text('✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $res.addClass('cw-err').text('✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
