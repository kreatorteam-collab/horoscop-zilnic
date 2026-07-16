/* global CW, jQuery, tinymce, wp */
(function ($) {
    'use strict';

    var $status, $output, currentAction = 'article';

    // Sanitizare defensivă a HTML-ului generat înainte de a-l folosi în admin/editor.
    function cwSanitize(html) {
        var doc = document.implementation.createHTMLDocument('');
        doc.body.innerHTML = String(html || '');

        // 1. Elemente periculoase / nepermise — eliminate complet.
        var strip = doc.body.querySelectorAll('script,iframe,object,embed,style,link,meta,form,base');
        Array.prototype.forEach.call(strip, function (n) { if (n.parentNode) { n.parentNode.removeChild(n); } });

        // 2. <div>/<span> nu au ce căuta în articol (modelul pune uneori un <div> stilizat gol
        //    ca „separator"). Le despachetăm: păstrăm conținutul, iar cele goale dispar.
        var wrap = doc.body.querySelectorAll('div, span');
        Array.prototype.forEach.call(wrap, function (el) {
            var p = el.parentNode;
            if (!p) { return; }
            while (el.firstChild) { p.insertBefore(el.firstChild, el); }
            p.removeChild(el);
        });

        // 3. Curățăm atributele rămase: handlere on*, stiluri inline și linkuri javascript:.
        var all = doc.body.querySelectorAll('*');
        Array.prototype.forEach.call(all, function (el) {
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                var name = a.name.toLowerCase();
                var val = (a.value || '').replace(/\s/g, '').toLowerCase();
                if (name.indexOf('on') === 0 || name === 'style') { el.removeAttribute(a.name); }
                else if ((name === 'href' || name === 'src') && val.indexOf('javascript:') === 0) { el.removeAttribute(a.name); }
            });
        });
        return doc.body.innerHTML;
    }

    // Modelul încadrează uneori tot răspunsul într-un bloc de cod markdown
    // (```html ... ``` sau ~~~), chiar dacă i se cere HTML curat. Scoatem
    // învelișul ca backtick-urile să nu ajungă ca text în articol.
    function stripCodeFence(text) {
        var s = $.trim(String(text || ''));
        if (s.indexOf('```') === -1 && s.indexOf('~~~') === -1) { return s; }
        // Înveliș complet: prima linie e fence-ul (opțional cu limbaj), închidere la final.
        var full = s.match(/^(`{3,}|~{3,})[^\n`~]*\n([\s\S]*?)\n?\1[ \t]*$/);
        if (full) { return $.trim(full[2]); }
        // Sau doar fence de deschidere (răspuns trunchiat) / de închidere rătăcit.
        s = s.replace(/^(`{3,}|~{3,})[^\n`~]*\n/, '');
        s = s.replace(/\n(`{3,}|~{3,})[ \t]*$/, '');
        return $.trim(s);
    }

    // Normalizează un text pentru comparație (spații, diacritice rămase, punctuație finală).
    function normTitle(s) {
        return $.trim(String(s || '')).replace(/\s+/g, ' ').toLowerCase().replace(/[\s.:!?–—-]+$/g, '');
    }

    // Scoate titlul de la începutul articolului (titlul există deja în câmpul WP).
    // Elimină un H1 inițial (mereu) ȘI orice heading inițial (H1-H6) al cărui text
    // repetă titlul articolului — modelul uneori îl pune ca subtitlu.
    function stripLeadingTitle(html, title) {
        var doc = document.implementation.createHTMLDocument('');
        doc.body.innerHTML = cwSanitize(html);
        var first = doc.body.firstElementChild;
        if (!first) { return doc.body.innerHTML; }
        var isHeading = /^H[1-6]$/.test(first.tagName);
        if (first.tagName === 'H1') {
            first.parentNode.removeChild(first);
        } else if (isHeading && title) {
            var a = normTitle(first.textContent), b = normTitle(title);
            if (a && b && (a === b || a.indexOf(b) === 0 || b.indexOf(a) === 0)) {
                first.parentNode.removeChild(first);
            }
        }
        return doc.body.innerHTML;
    }

    // Garantăm nota de final (disclaimer) ca <blockquote>, MEREU la finalul absolut al
    // articolului. Dacă modelul a pus-o în mijloc, o mutăm la sfârșit; dacă lipsește,
    // o construim din disclaimer-ul configurat (CW.disclaimer).
    function ensureDisclaimer(html) {
        var doc = document.implementation.createHTMLDocument('');
        doc.body.innerHTML = String(html || '');

        var note = null;

        // 1. Element cu text „Notă:" — cel mai sigur reper.
        var cand = doc.body.querySelectorAll('blockquote, p, i, em');
        for (var i = cand.length - 1; i >= 0 && !note; i--) {
            if (/Not[ăa]\s*:/.test(cand[i].textContent || '')) {
                var el = cand[i];
                var inBq = el.closest ? el.closest('blockquote') : null;
                if (inBq) { note = inBq; break; }
                if ((el.tagName === 'I' || el.tagName === 'EM') && el.parentNode && el.parentNode.tagName === 'P') {
                    el = el.parentNode;
                }
                var bq = doc.createElement('blockquote');
                el.parentNode.insertBefore(bq, el);
                bq.appendChild(el);
                note = bq;
            }
        }

        // 2. Fallback: orice blockquote existent (în aceste articole = nota).
        if (!note) {
            var bqs = doc.body.querySelectorAll('blockquote');
            if (bqs.length) { note = bqs[bqs.length - 1]; }
        }

        // 3. Nimic — o construim din disclaimer-ul configurat.
        if (!note) {
            var d = $.trim((CW.disclaimer || '').replace(/[‒–—―−]/g, '-'));
            if (!d) { return doc.body.innerHTML; }
            note = doc.createElement('blockquote');
            var p = doc.createElement('p');
            var it = doc.createElement('i');
            it.textContent = d;
            p.appendChild(it);
            note.appendChild(p);
        }

        // Dacă nota iese goală sau doar „Notă:" (model malformat), o umplem cu disclaimer-ul configurat.
        var bodyTxt = $.trim((note.textContent || '').replace(/Not[ăa]\s*:/i, ''));
        if (bodyTxt.length < 15) {
            var dd = $.trim((CW.disclaimer || '').replace(/[‒–—―−]/g, '-'));
            if (dd) {
                while (note.firstChild) { note.removeChild(note.firstChild); }
                var pp = doc.createElement('p');
                var ii = doc.createElement('i');
                ii.textContent = dd;
                pp.appendChild(ii);
                note.appendChild(pp);
            }
        }

        // Nota mereu la finalul absolut (dacă era în mijloc, o mutăm aici).
        doc.body.appendChild(note);
        return doc.body.innerHTML;
    }

    function setStatus(msg, type) {
        $status.show().attr('class', 'cw-status' + (type ? ' cw-' + type : '')).html(msg);
    }

    // Status „se lucrează" cu spinner animat (fără a afișa textul brut generat).
    function setWorking() {
        $status.show().attr('class', 'cw-status cw-working')
               .html('<span class="cw-spinner" aria-hidden="true"></span>' + CW.i18n.working);
    }

    function busy(on) { $('.cw-btn').prop('disabled', on); }

    // Cât timp generăm (request lung), încetinim heartbeat-ul wp-admin ca să nu
    // raporteze „Conexiune pierdută"; îl readucem la normal la final.
    function cwHeartbeat(slow) {
        try {
            if (window.wp && wp.heartbeat && typeof wp.heartbeat.interval === 'function') {
                wp.heartbeat.interval(slow ? 'slow' : 'standard');
            }
        } catch (e) {}
    }

    function costLabel(cost) {
        if (typeof cost !== 'number') { return ''; }
        return ' · ' + CW.i18n.cost + ' $' + cost.toFixed(4);
    }

    function getEditorContent() {
        if (window.wp && wp.data && wp.data.select('core/editor')) {
            try { var c = wp.data.select('core/editor').getEditedPostContent(); if (c) { return c; } } catch (e) {}
        }
        if (window.tinymce && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
            return tinymce.activeEditor.getContent();
        }
        var $ta = $('#content');
        return $ta.length ? $ta.val() : '';
    }

    function getPostTitle() {
        if (window.wp && wp.data && wp.data.select('core/editor')) {
            try { var t = wp.data.select('core/editor').getEditedPostAttribute('title'); if (t) { return t; } } catch (e) {}
        }
        var $t = $('#title');
        return $t.length ? $t.val() : '';
    }

    function insertContent(html) {
        html = cwSanitize(html);
        // Gutenberg
        if (window.wp && wp.data && wp.blocks && wp.data.dispatch('core/block-editor')) {
            try {
                var blocks = wp.blocks.rawHandler({ HTML: html });
                wp.data.dispatch('core/block-editor').insertBlocks(blocks);
                return true;
            } catch (e) {}
        }
        // Classic TinyMCE
        if (window.tinymce && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
            tinymce.activeEditor.execCommand('mceInsertContent', false, html);
            return true;
        }
        // Textarea fallback
        var $ta = $('#content');
        if ($ta.length) { $ta.val($ta.val() + '\n' + html); return true; }
        return false;
    }

    function setTitle(text) {
        text = $.trim(text).replace(/^["']|["']$/g, '');
        if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
            try { wp.data.dispatch('core/editor').editPost({ title: text }); return true; } catch (e) {}
        }
        var $t = $('#title');
        if ($t.length) { $t.val(text).trigger('input'); $('#title-prompt-text').addClass('screen-reader-text'); return true; }
        return false;
    }

    function copyText(text) {
        var tmp = $('<textarea>').val(text).appendTo('body').select();
        try { document.execCommand('copy'); } catch (e) {}
        tmp.remove();
    }

    // Ce facem cu rezultatul, în funcție de acțiune.
    function handleResult(action, text, cost) {
        text = stripCodeFence(text);
        // Fără liniuță lungă (—) / medie (–) etc. — doar cratimă normală „-".
        text = text.replace(/[‒–—―−]/g, '-');
        var c = costLabel(cost);
        if (action === 'title') {
            $output.hide();
            setTitle(text);
            setStatus(CW.i18n.titleSet + c, 'ok');
        } else if (action === 'keywords') {
            $output.html(cwSanitize(text)).show();
            copyText(text);
            setStatus(CW.i18n.copied + c, 'ok');
        } else { // article / rewrite -> direct în editor
            $output.hide();
            var html = (action === 'article') ? stripLeadingTitle(text, getPostTitle()) : cwSanitize(text);
            if (action === 'article') { html = ensureDisclaimer(html); }
            insertContent(html);
            setStatus(CW.i18n.inserted + c, 'ok');
        }
    }

    function collect() {
        // Titlul articolului e mereu baza subiectului (nu mai trebuie scris de două ori).
        // Câmpul din modul e doar pentru instrucțiuni suplimentare opționale.
        var title = $.trim(getPostTitle());
        var extra = $.trim($('#cw-subject').val());
        var subj = title;
        if (extra !== '') {
            subj = (title !== '') ? (title + '\n\nInstrucțiuni suplimentare: ' + extra) : extra;
        }
        return {
            model:   $('#cw-model').val(),
            act:     currentAction,
            subject: subj,
            length:  $('#cw-length').val(),
            content: getEditorContent()
        };
    }

    function runAjax() {
        var data = collect();
        data.action = 'cw_generate';
        data.nonce = CW.nonce;
        busy(true);
        setWorking();

        $.post(CW.ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    handleResult(res.data.action || currentAction, res.data.text, res.data.cost);
                } else {
                    setStatus((res && res.data && res.data.message) ? res.data.message : CW.i18n.error, 'err');
                }
            })
            .fail(function () { setStatus(CW.i18n.error, 'err'); })
            .always(function () { busy(false); });
    }

    function runStream() {
        var data = collect();
        data.nonce = CW.nonce;
        var action = data.act;
        busy(true);
        cwHeartbeat(true);
        setWorking();
        $output.hide().html(''); // nu mai afișăm textul brut în timpul generării
        var acc = '';

        fetch(CW.restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CW.restNonce },
            body: JSON.stringify(data)
        }).then(function (resp) {
            if (!resp.body) { throw new Error('no-stream'); }
            var reader = resp.body.getReader();
            var decoder = new TextDecoder();
            var buf = '';

            function pump() {
                return reader.read().then(function (r) {
                    if (r.done) { busy(false); cwHeartbeat(false); return; }
                    buf += decoder.decode(r.value, { stream: true });
                    var parts = buf.split('\n\n');
                    buf = parts.pop();
                    parts.forEach(function (evt) {
                        var lines = evt.split('\n'), ev = 'message', payload = '';
                        lines.forEach(function (l) {
                            if (l.indexOf('event:') === 0) { ev = l.slice(6).trim(); }
                            else if (l.indexOf('data:') === 0) { payload += l.slice(5).trim(); }
                        });
                        if (ev === 'error') {
                            try { setStatus(JSON.parse(payload).message || CW.i18n.error, 'err'); } catch (e) { setStatus(CW.i18n.error, 'err'); }
                        } else if (ev === 'done') {
                            var c = 0; try { c = JSON.parse(payload).cost; } catch (e) {}
                            handleResult(action, acc, c); // la final: inserează în editor
                        } else if (payload && payload !== 'ok') {
                            // acumulăm intern, dar NU afișăm textul brut (doar spinner-ul rulează)
                            try { var t = JSON.parse(payload).text; if (t) { acc += t; } } catch (e) {}
                        }
                    });
                    return pump();
                });
            }
            return pump();
        }).catch(function () { cwHeartbeat(false); runAjax(); }); // fallback non-streaming
    }

    $(function () {
        $status = $('#cw-status');
        $output = $('#cw-output');

        $('.cw-btn').on('click', function () {
            if (!CW.hasKey) { setStatus(CW.i18n.noKey, 'err'); return; }
            currentAction = $(this).data('action');
            if (CW.stream && (currentAction === 'article' || currentAction === 'rewrite')) {
                runStream();
            } else {
                runAjax();
            }
        });
    });
})(jQuery);
