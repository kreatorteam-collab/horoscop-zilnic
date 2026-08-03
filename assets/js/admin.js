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

    // ---- Articole Bulk: trimitere lot + poll pe coada de procesare ----
    function initBulk() {
        var $queue = $('#hzb-queue');
        if (!$queue.length) { return; } // tab-ul nu e randat acum

        var labels = {
            pending: ['În coadă', '#996800'],
            working: ['Se generează…', '#2271b1'],
            done:    ['✓ Gata', '#00a32a'],
            error:   ['✗ Eroare', '#b32d2e']
        };

        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function refresh() {
            $.post(HZAdmin.ajaxUrl, { action: 'hzb_status', nonce: HZAdmin.nonce }).done(function (res) {
                if (!res || !res.success) { return; }
                var d = res.data, rows = '';
                if (!d.items.length) {
                    rows = '<tr><td colspan="4">Coada e goală. Adaugă un lot de titluri mai sus.</td></tr>';
                }
                d.items.forEach(function (it) {
                    var st = labels[it.status] || [it.status, ''];
                    var detail = '';
                    if (it.status === 'error') { detail = esc(it.error); }
                    else if (it.note) { detail = esc(it.note); }
                    if (it.edit_link) {
                        detail += (detail ? ' — ' : '') + '<a href="' + esc(it.edit_link) + '" target="_blank">Deschide</a>';
                    }
                    rows += '<tr><td>' + esc(it.title) + '</td>' +
                        '<td style="color:' + st[1] + ';">' + st[0] + '</td>' +
                        '<td>' + (it.cost ? '$' + Number(it.cost).toFixed(3) : '—') + '</td>' +
                        '<td>' + detail + '</td></tr>';
                });
                $queue.html(rows);
                var info = 'Descărcări Magnific azi: ' + d.downloads + ' (cota se resetează ' + esc(d.reset) + ').';
                if (d.pending > 0) { info += ' În lucru: ' + d.pending + ' articole.'; }
                if (d.stopped) { info += ' ⏸ Procesarea e OPRITĂ — apasă „Repornește procesarea".'; }
                if (d.paused) { info += ' ⚠ ' + esc(d.paused); }
                $('#hzb-progress').html(info);
                $('#hzb-toggle').data('stopped', d.stopped ? 1 : 0)
                    .text(d.stopped ? 'Repornește procesarea' : 'Oprește procesarea');
                // Poll des cât timp se lucrează, rar când e liniște.
                clearTimeout(refresh.timer);
                refresh.timer = setTimeout(refresh, d.pending > 0 ? 5000 : 30000);
            });
        }

        $('#hzb-enqueue').on('click', function () {
            var $btn = $(this), $st = $('#hzb-enqueue-status');
            $st.css('color', '').text(' Se trimite…');
            $btn.prop('disabled', true);
            $.post(HZAdmin.ajaxUrl, {
                action: 'hzb_enqueue',
                nonce: HZAdmin.nonce,
                titles: $('#hzb-titles').val(),
                category: $('#hzb-category').val(),
                status: $('#hzb-status').val(),
                model: $('#hzb-model').val(),
                extra: $('#hzb-extra').val()
            }).done(function (res) {
                if (res && res.success) {
                    $st.css('color', '#00a32a').text(' ✓ ' + res.data.message);
                    $('#hzb-titles').val('');
                    refresh();
                } else {
                    $st.css('color', '#b32d2e').text(' ✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $st.css('color', '#b32d2e').text(' ✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        $('#hzb-toggle').on('click', function () {
            var stopNow = $(this).data('stopped') ? 0 : 1;
            $.post(HZAdmin.ajaxUrl, { action: 'hzb_toggle', nonce: HZAdmin.nonce, stop: stopNow }).done(refresh);
        });

        $('#hzb-retry').on('click', function () {
            $.post(HZAdmin.ajaxUrl, { action: 'hzb_retry', nonce: HZAdmin.nonce }).done(refresh);
        });

        $('#hzb-clear').on('click', function () {
            if (!window.confirm('Golești coada? Articolele deja create rămân neatinse.')) { return; }
            $.post(HZAdmin.ajaxUrl, { action: 'hzb_clear', nonce: HZAdmin.nonce }).done(refresh);
        });

        $('#hzb-quota').on('click', function () {
            var $btn = $(this), $res = $('#hzb-quota-result');
            $res.css('color', '').text(' Se verifică…');
            $btn.prop('disabled', true);
            $.post(HZAdmin.ajaxUrl, { action: 'hzb_quota', nonce: HZAdmin.nonce }).done(function (res) {
                if (res && res.success) {
                    var d = res.data;
                    var col = d.remaining > 10 ? '#00a32a' : (d.remaining > 0 ? '#996800' : '#b32d2e');
                    $res.css('color', col).text('Azi ai consumat ' + d.used + ' poze. Îți mai rămân ' +
                        d.remaining + ' din ' + d.limit + '. Cota se resetează ' + d.reset + '.');
                } else {
                    $res.css('color', '#b32d2e').text(' ✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
            }).fail(function () {
                $res.css('color', '#b32d2e').text(' ✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        refresh();
    }

    $(function () {
        // Horoscop
        initImagePicker('hz', 'Alege imaginea de cover pentru horoscop');
        initTestButton('hz', 'hz_test');
        // Vreme
        initImagePicker('hzw', 'Alege imaginea de cover pentru vreme');
        initTestButton('hzw', 'hzw_test');
        // Articole Bulk
        initBulk();
    });
})(jQuery);
