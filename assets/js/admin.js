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
                var info = d.exhausted
                    ? '⛔ Magnific a refuzat descărcările azi — cota contului e consumată. Se resetează ' + esc(d.reset) + '.'
                    : 'Descărcări Magnific azi: ' + d.downloads + ' (cota se resetează ' + esc(d.reset) + ').';
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
                extra: $('#hzb-extra').val(),
                // Bifa e per lot: trimitem mereu 0/1, ca să putem și opri căutarea
                // pe un lot chiar dacă site-ul o are pornită.
                web: $('#hzb-web').is(':checked') ? 1 : 0
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

        // Mesajul e important: fără el, un click pe „Reîncearcă erorile" când nu
        // există erori pare că nu face nimic.
        function actionWithFeedback(action, $btn, busyText) {
            var $res = $('#hzb-action-result');
            $res.css('color', '').text(' ' + busyText);
            $btn.prop('disabled', true);
            $.post(HZAdmin.ajaxUrl, { action: action, nonce: HZAdmin.nonce }).done(function (res) {
                if (res && res.success) {
                    $res.css('color', '#00a32a').text(' ✓ ' + (res.data.message || 'Gata.'));
                } else {
                    $res.css('color', '#b32d2e').text(' ✗ ' + ((res && res.data && res.data.message) ? res.data.message : 'Eroare'));
                }
                refresh();
            }).fail(function () {
                $res.css('color', '#b32d2e').text(' ✗ Eroare de rețea');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        }

        $('#hzb-retry').on('click', function () {
            actionWithFeedback('hzb_retry', $(this), 'Se verifică…');
        });

        $('#hzb-backfill').on('click', function () {
            actionWithFeedback('hzb_backfill', $(this), 'Se caută imagini… (poate dura un minut)');
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
                    if (d.exhausted) {
                        // Refuzul contului bate contorul: el vede doar ce a descărcat plugin-ul.
                        $res.css('color', '#b32d2e').html('⛔ Magnific a refuzat descărcările azi: cota contului e consumată. ' +
                            'Se resetează ' + d.reset + '. <span style="font-weight:400;color:#666;">(prin plugin s-au descărcat ' +
                            d.used + ' poze azi; restul cotei a fost consumat în afara plugin-ului - de pe magnific.com sau din alte unelte)</span>');
                    } else {
                        var col = d.remaining > 10 ? '#00a32a' : (d.remaining > 0 ? '#996800' : '#b32d2e');
                        var scope = d.scope ? ' <span style="font-weight:400;color:#666;">(' + d.scope + ')</span>' : '';
                        $res.css('color', col).html('Azi s-au descărcat ' + d.used + ' poze prin plugin. Estimativ mai rămân ' +
                            d.remaining + ' din ' + d.limit + '. Cota se resetează ' + d.reset + '.' + scope);
                    }
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

    /* ---- Refresh conținut (FAQ + idei-cheie pe articolele existente) ---- */
    function initRefresh() {
        if (!$('#hzr-progress').length) { return; }
        var $res = $('#hzr-action-result');

        function say(ok, msg) {
            $res.css('color', ok ? '#00a32a' : '#b32d2e').html((ok ? '✓ ' : '✗ ') + msg);
        }

        function refresh() {
            $.post(HZAdmin.ajaxUrl, { action: 'hzr_status', nonce: HZAdmin.nonce }).done(function (r) {
                if (!r || !r.success) { return; }
                var d = r.data, c = d.counts || {};
                $('#hzr-c-total').text(c.total || 0);
                $('#hzr-c-cats').text(c.excl_cats || 0);
                $('#hzr-c-skip').text(c.skipped || 0);
                $('#hzr-c-done').text(c.done || 0);
                $('#hzr-c-elig').text(c.eligible || 0);

                // Defalcarea motivelor de sarire: fara ea, „Sarite: 53" pare un bug.
                var $r = $('#hzr-reasons').empty();
                $.each(c.reasons || {}, function (reason, n) {
                    $r.append(
                        $('<tr/>').append(
                            $('<td/>').css({ paddingLeft: '28px', color: '#646970' }).text('– ' + reason),
                            $('<td/>').css({ textAlign: 'right', color: '#646970' }).text(n)
                        )
                    );
                });

                var info = 'Azi: <strong>' + d.today + '/' + d.limit + '</strong> articole. ' +
                           'Total procesate: <strong>' + d.done + '</strong>. Cost cumulat: <strong>$' + d.cost + '</strong>.';
                if (!d.enabled) {
                    info += ' <span style="color:#996800;">Modulul e OPRIT din setări.</span>';
                } else if (d.stopped) {
                    info += ' <span style="color:#996800;">Procesare pusă pe pauză.</span>';
                } else if (d.next) {
                    info += ' Următorul articol: ' + d.next +
                            (d.next_in ? ' (peste ' + d.next_in + ')' : '') + '.';
                } else {
                    info += ' <span style="color:#996800;">Nicio rulare programată — apasă „Re-evaluează" sau repornește procesarea.</span>';
                }
                if (d.idle) { info += ' <span style="color:#996800;">Nu mai sunt articole eligibile.</span>'; }
                if (d.error) { info += ' <span style="color:#b32d2e;">Ultima eroare: ' + d.error + '</span>'; }

                // Se vede CA lucreaza: articolul aflat in lucru chiar acum.
                if (d.current && d.current.title) {
                    var secs = Math.max(0, Math.round(Date.now() / 1000) - (parseInt(d.current.t, 10) || 0));
                    info = '<span style="color:#2271b1;font-weight:600;">⏳ Acum lucrează la: „' + d.current.title +
                           '" (de ' + secs + ' secunde)</span><br />' + info;
                } else if (d.working) {
                    info = '<span style="color:#2271b1;font-weight:600;">⏳ Procesare în curs…</span><br />' + info;
                }
                $('#hzr-progress').html(info);

                // Ce a facut deja: ultimele articole atinse, cu link si cost.
                var $log = $('#hzr-log').empty();
                if (d.log && d.log.length) {
                    var rows = '';
                    $.each(d.log, function (i, e) {
                        rows += '<tr>' +
                            '<td style="white-space:nowrap;color:#646970;">' + (e.t || '').substring(5, 16) + '</td>' +
                            '<td><a href="' + e.url + '" target="_blank" rel="noopener">' + e.title + '</a></td>' +
                            '<td>' + (e.what || '') + '</td>' +
                            '<td style="text-align:right;white-space:nowrap;">$' + Number(e.cost || 0).toFixed(4) + '</td>' +
                            '</tr>';
                    });
                    $log.html('<h3 style="margin-bottom:6px;">Ce s-a procesat</h3>' +
                        '<table class="widefat striped" style="max-width:900px;"><thead><tr>' +
                        '<th style="width:110px;">Când</th><th>Articol</th><th>Ce s-a adăugat</th>' +
                        '<th style="width:90px;text-align:right;">Cost</th></tr></thead><tbody>' + rows + '</tbody></table>');
                }

                $('#hzr-toggle').data('stopped', d.stopped ? 1 : 0)
                    .text(d.stopped ? 'Repornește procesarea' : 'Oprește procesarea');
            });
        }

        $('#hzr-toggle').on('click', function () {
            var stopNow = $(this).data('stopped') ? 0 : 1;
            $.post(HZAdmin.ajaxUrl, { action: 'hzr_toggle', nonce: HZAdmin.nonce, stop: stopNow }).done(function (r) {
                if (r && r.success) { say(true, r.data.message); }
                refresh();
            });
        });

        $('#hzr-test').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $res.css('color', '').text('Se procesează un articol… (20-60 de secunde)');
            $.post(HZAdmin.ajaxUrl, {
                action: 'hzr_test',
                nonce: HZAdmin.nonce,
                post_id: $('#hzr-test-id').val() || 0
            }).done(function (r) {
                if (r && r.success) {
                    var d = r.data;
                    say(true, d.message + ' <a href="' + d.link + '" target="_blank">Vezi articolul</a>' +
                        (d.edit ? ' · <a href="' + d.edit + '" target="_blank">Editează</a>' : '') +
                        ' <span style="font-weight:400;color:#666;">($' + d.cost + ')</span>');
                } else {
                    say(false, (r && r.data && r.data.message) ? r.data.message : 'Eroare');
                }
                refresh();
            }).fail(function () {
                say(false, 'Eroare de rețea (sau timeout - articolul poate fi totuși procesat, reîncarcă pagina).');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        function rollback(scope, confirmMsg) {
            if (!window.confirm(confirmMsg)) { return; }
            $res.css('color', '').text('Se restaurează…');
            $.post(HZAdmin.ajaxUrl, { action: 'hzr_rollback', nonce: HZAdmin.nonce, scope: scope }).done(function (r) {
                say(!!(r && r.success), (r && r.data && r.data.message) ? r.data.message : 'Eroare');
                refresh();
            });
        }

        $('#hzr-rollback-one').on('click', function () {
            rollback('one', 'Pui la loc conținutul original pentru ultimul articol procesat?');
        });
        $('#hzr-rollback-all').on('click', function () {
            rollback('all', 'Pui la loc conținutul original pentru TOATE articolele procesate? (se face în loturi de 25)');
        });

        $('#hzr-rescan').on('click', function () {
            $res.css('color', '').text('Se re-evaluează…');
            $.post(HZAdmin.ajaxUrl, { action: 'hzr_rescan', nonce: HZAdmin.nonce }).done(function (r) {
                say(!!(r && r.success), (r && r.data && r.data.message) ? r.data.message : 'Eroare');
                refresh();
            });
        });

        refresh();
        // 10s, nu 30: cand lucreaza vrei sa vezi ca se misca, nu sa astepti jumatate de minut.
        setInterval(refresh, 10000);
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
        // Refresh conținut
        initRefresh();
    });
})(jQuery);
