<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Inserarea linkurilor cerute din exterior (panoul „AI Writer flotă" de pe
 * app.targetseo.ro) în articolul proaspăt generat.
 *
 * Regula de bază: linkul TREBUIE să existe în articol. Modelul primește în prompt
 * ancorele exacte și le folosește de obicei în text — atunci doar le împachetăm în
 * <a>. Dacă totuși nu apar (model creativ, ancoră ciudată), inserăm noi o propoziție
 * scurtă cu ancora, ca să nu livrăm un articol fără linkul plătit. Fiecare caz e
 * raportat înapoi în coadă („2/2 linkuri, 1 forțat"), ca să se vadă ce s-a întâmplat.
 *
 * Nu atingem: textul din interiorul altui <a>, titlurile (h1-h6), figure/figcaption,
 * blockquote, script/style. Un URL se leagă o singură dată per articol.
 */
class HZ_Links {

    /** Elemente în interiorul cărora NU punem linkuri. */
    private static $skip_tags = array('a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'figcaption', 'script', 'style');

    /**
     * Normalizează o intrare de link venită prin REST.
     * @return array|null array('anchor','url','rel') sau null dacă e invalidă.
     */
    public static function sanitize_link($raw) {
        if (!is_array($raw)) { return null; }
        $anchor = trim(wp_strip_all_tags((string) (isset($raw['anchor']) ? $raw['anchor'] : '')));
        $url    = esc_url_raw(trim((string) (isset($raw['url']) ? $raw['url'] : '')));
        $rel    = strtolower(trim((string) (isset($raw['rel']) ? $raw['rel'] : '')));
        if ($anchor === '' || $url === '') { return null; }
        if (!preg_match('#^https?://#i', $url)) { return null; }
        if (!in_array($rel, array('', 'nofollow', 'sponsored', 'ugc'), true)) { $rel = ''; }
        return array(
            'anchor' => mb_substr($anchor, 0, 120),
            'url'    => $url,
            'rel'    => $rel,
        );
    }

    /** Curăță o listă de linkuri; păstrează maximum $max intrări valide. */
    public static function sanitize_list($raw, $max = 5) {
        $out = array();
        if (!is_array($raw)) { return $out; }
        foreach ($raw as $item) {
            $link = self::sanitize_link($item);
            if ($link) { $out[] = $link; }
            if (count($out) >= $max) { break; }
        }
        return $out;
    }

    /**
     * True dacă host-ul e un site OFICIAL al statului român (whitelist extensibilă prin
     * filtrul `hzb_official_ro_domains`). Orice subdomeniu `.gov.ro` e considerat oficial.
     */
    /**
     * Hostul propriu al site-ului (fara www). Un link catre PROPRIUL domeniu nu e
     * niciodata o referinta externa: e link intern, pus fie de noi, fie de model
     * (vezi promptul de nisa auto, care cere linkuri interne pe marci).
     */
    public static function own_host() {
        $h = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        return preg_replace('#^www\.#', '', $h);
    }

    /** True daca hostul e chiar site-ul curent. */
    public static function is_own_host($host) {
        $host = preg_replace('#^www\.#', '', strtolower(trim((string) $host)));
        $own  = self::own_host();
        return ($host !== '' && $own !== '' && $host === $own);
    }

    public static function is_official_ro($host) {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('#^www\.#', '', $host);
        if ($host === '') { return false; }
        if (preg_match('#(^|\.)gov\.ro$#', $host)) { return true; } // *.gov.ro

        $official = array(
            'anaf.ro', 'insse.ro', 'cnas.ro', 'ms.ro', 'edu.ro', 'presidency.ro', 'just.ro',
            'bnr.ro', 'monitoruloficial.ro', 'cdep.ro', 'senat.ro', 'cnpp.ro', 'anofm.ro',
            'anpc.ro', 'ancom.ro', 'roaep.ro', 'mfinante.ro', 'cnsc.ro', 'ansvsa.ro',
            'anm.ro', 'meteoromania.ro', 'inspectiamuncii.ro', 'politiaromana.ro',
        );
        $official = apply_filters('hzb_official_ro_domains', $official);
        foreach ($official as $d) {
            $d = strtolower((string) $d);
            if ($d !== '' && ($host === $d || substr($host, -(strlen($d) + 1)) === '.' . $d)) { return true; }
        }
        return false;
    }

    /**
     * Scoate CITĂRILE lăsate de căutarea web. Modelul (OpenAI Responses API) încheie
     * frazele cu „([domeniu](https://…?utm_source=openai))" — o citare, nu un link
     * editorial: textul ancorei e chiar domeniul. Articolul se salvează ca HTML, deci
     * markdown-ul rămâne VIZIBIL în pagină, ca text stricat.
     *
     * Se scot indiferent de domeniu (inclusiv instituții oficiale): articolul trebuie
     * SCRIS pe baza surselor, nu să le citeze. Un link editorial adevărat — cu text de
     * ancoră normal, nu cu numele domeniului — trece mai departe prin strip_external.
     */
    public static function strip_citations($content) {
        $content = (string) $content;
        $host_rx = '(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}';

        // a) citarea completă, cu parantezele ei:  ([domeniu](url))  /  (vezi [domeniu](url))
        $content = preg_replace(
            '#\(\s*[^()\[\]]{0,20}\[\s*' . $host_rx . '\s*\]\(\s*https?://[^)\s]+\s*\)\s*\)#i',
            '',
            $content
        );
        // b) citarea fără paranteze exterioare:  [domeniu](url)
        $content = preg_replace(
            '#\[\s*' . $host_rx . '\s*\]\(\s*https?://[^)\s]+\s*\)#i',
            '',
            $content
        );
        // c) restul rămas după o curățare veche: paranteză cu DOAR un domeniu, ex. „(oecd.org)"
        $content = preg_replace('#\(\s*' . $host_rx . '\s*\)#i', '', $content);

        // Cicatricile: paranteze goale, spațiu înaintea punctuației, punct dublu.
        $content = preg_replace('#\(\s*\)#', '', $content);
        $content = preg_replace('#\s+([,.;:!?])#', '$1', $content);
        $content = preg_replace('#([.!?])\1+#', '$1', $content);
        $content = preg_replace('#[ \t]{2,}#', ' ', $content);
        return $content;
    }

    /** URL fără parametrii de urmărire adăugați de căutarea web (utm_source=openai etc.). */
    public static function clean_url($url) {
        $url = preg_replace('#([?&])utm_[a-z_]+=[^&\#]*#i', '$1', (string) $url);
        $url = preg_replace('#[?&]+(\#|$)#', '$1', $url);
        return rtrim(str_replace('?&', '?', $url), '?&');
    }

    /**
     * Curăță referințele externe puse de MODEL (mai ales din căutarea web): scoate
     * linkurile lui + numele de site-uri .ro, CU EXCEPȚIA site-urilor oficiale ale
     * statului. Se rulează ÎNAINTE de apply() — linkurile CERUTE de user nu-s încă în
     * articol, deci nu sunt atinse. Scop: pe advertoriale să nu punem link/mențiune
     * către un site .ro concurent.
     */
    public static function strip_external($content, $strip_mentions = true) {
        $content = (string) $content;

        // 0) CITĂRILE căutării web. Se scot ÎNAINTE de orice, indiferent de domeniu.
        $content = self::strip_citations($content);

        // 1) <a href>: păstrăm doar spre domenii oficiale ale statului; restul le
        //    „despachetăm" (rămâne textul ancorei, dispare linkul).
        $content = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            function ($m) {
                $host = strtolower((string) parse_url(html_entity_decode($m[2]), PHP_URL_HOST));
                // Linkurile INTERNE (spre propriul domeniu) raman intacte.
                if (self::is_own_host($host)) { return $m[0]; }
                return self::is_official_ro($host) ? $m[0] : $m[3];
            },
            $content
        );

        // 2) Linkuri Markdown [text](url) rămase -> doar textul, dacă nu-s oficiale.
        //    Pe cele oficiale NU le mai lăsăm în markdown: articolul se salvează ca HTML,
        //    deci „[text](url)" rămânea vizibil ca atare în pagină. Le transformăm în
        //    <a href> adevărat (asta era greșeala din v3.8.6).
        $content = preg_replace_callback(
            '#\[([^\]]+)\]\((https?://[^)\s]+)\)#i',
            function ($m) {
                $host = strtolower((string) parse_url($m[2], PHP_URL_HOST));
                if (self::is_own_host($host)) {
                    return '<a href="' . esc_url($m[2]) . '">' . $m[1] . '</a>';
                }
                if (!self::is_official_ro($host)) { return $m[1]; }
                return '<a href="' . esc_url(self::clean_url($m[2])) . '" target="_blank" rel="nofollow noopener">'
                    . $m[1] . '</a>';
            },
            $content
        );

        // 3) URL-uri „la liber" în text (http(s)://...) către domenii neoficiale -> scoase.
        $content = preg_replace_callback(
            '#\bhttps?://[^\s<)"\']+#i',
            function ($m) {
                $host = strtolower((string) parse_url($m[0], PHP_URL_HOST));
                if (self::is_own_host($host)) { return $m[0]; }
                return self::is_official_ro($host) ? $m[0] : '';
            },
            $content
        );

        // 4) Mențiuni de domenii .ro „la liber" în text (ex. „concurent.ro") -> scoase,
        //    dacă nu-s oficiale. Pasul se poate sări ($strip_mentions = false): la
        //    rescrierea prin model mențiunile le-a scos deja modelul, cu fraza refăcută,
        //    iar ștergerea oarbă ar ciunti o frază corectă.
        if ($strip_mentions) {
            $content = preg_replace_callback(
                '#\b((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+ro)\b#i',
                function ($m) {
                    $h = strtolower($m[1]);
                    if (self::is_own_host($h)) { return $m[0]; }
                    return self::is_official_ro($h) ? $m[0] : '';
                },
                $content
            );
        }

        // Curățenie după scoateri: paranteze goale, spații/punctuație duble.
        // „preluate de Ziarul Financiar și News.ro." -> pasul 4 scotea domeniul și rămânea
        // „…Financiar și." Conjuncția rămasă în aer se duce odată cu el.
        $content = preg_replace('#\s+\b(și|si|sau|ori)\b\s*(?=[,.;:!?])#iu', '', $content);
        $content = preg_replace('#\(\s*\)#', '', $content);
        $content = preg_replace('#[ \t]{2,}#', ' ', $content);
        $content = preg_replace('#\s+([,.;:!?])#', '$1', $content);
        $content = preg_replace('#([,;:])\s*(?=[,.;:])#', '', $content);
        return $content;
    }

    /**
     * Bucata de prompt care cere modelului să folosească ancorele în text.
     * NU-i dăm URL-urile: modelul scrie doar expresia, linkul îl punem noi.
     */
    public static function prompt_hint($links) {
        if (empty($links)) { return ''; }
        $lines = array();
        foreach ($links as $l) {
            $lines[] = '- „' . $l['anchor'] . '"';
        }
        return "\n\nOBLIGATORIU: folosește în corpul articolului, exact în această formă și o singură dată fiecare, "
            . "următoarele expresii, integrate natural într-o propoziție cu sens (nu le enumera, nu le pune în titluri "
            . "și nu adăuga linkuri sau adrese web — de linkuri mă ocup eu):\n"
            . implode("\n", $lines);
    }

    /**
     * Inserează linkurile în conținut.
     *
     * @return array array(
     *   'content' => string,          // HTML-ul cu linkuri
     *   'total'   => int,             // câte linkuri s-au cerut
     *   'done'    => int,             // câte au ajuns în articol
     *   'forced'  => int,             // câte au avut nevoie de propoziția noastră
     *   'failed'  => array,           // ancorele care n-au putut fi inserate deloc
     * )
     */
    public static function apply($content, $links) {
        $result = array(
            'content' => $content,
            'total'   => is_array($links) ? count($links) : 0,
            'done'    => 0,
            'forced'  => 0,
            'failed'  => array(),
        );
        if (empty($links) || !is_array($links)) { return $result; }

        $used_urls = array();
        foreach ($links as $i => $link) {
            $url = $link['url'];
            // Un URL o singură dată per articol (dublurile din listă se ignoră).
            if (isset($used_urls[$url]) || stripos($content, 'href="' . $url . '"') !== false) {
                $result['failed'][] = $link['anchor'];
                continue;
            }

            $linked = self::wrap_first_occurrence($content, $link);
            if ($linked !== null) {
                $content = $linked;
                $result['done']++;
                $used_urls[$url] = 1;
                continue;
            }

            // Modelul n-a folosit ancora: o inserăm noi într-un paragraf din mijloc.
            $forced = self::insert_sentence($content, $link, $i);
            if ($forced !== null) {
                $content = $forced;
                $result['done']++;
                $result['forced']++;
                $used_urls[$url] = 1;
                continue;
            }

            $result['failed'][] = $link['anchor'];
        }

        $result['content'] = $content;
        return $result;
    }

    /** Nota scurtă pentru coadă: „2/2 linkuri (1 forțat)". */
    public static function note($r) {
        if (empty($r['total'])) { return ''; }
        $note = $r['done'] . '/' . $r['total'] . ' linkuri';
        if (!empty($r['forced'])) {
            $note .= ' (' . $r['forced'] . ' inserat' . ($r['forced'] > 1 ? 'e' : '') . ' de noi)';
        }
        if (!empty($r['failed'])) {
            $note .= ' — lipsă: ' . implode(', ', array_slice($r['failed'], 0, 3));
        }
        return $note;
    }

    /* ---------- intern ---------- */

    private static function tag_html($link, $text) {
        $rel = '';
        if ($link['rel'] === 'sponsored') { $rel = ' rel="sponsored nofollow"'; }
        elseif ($link['rel'] !== '')      { $rel = ' rel="' . esc_attr($link['rel']) . '"'; }
        return '<a href="' . esc_url($link['url']) . '"' . $rel . '>' . $text . '</a>';
    }

    /**
     * Împachetează prima apariție a ancorei aflată în text curat (nu în titlu, nu în
     * alt link). Returnează conținutul nou sau null dacă ancora nu apare nicăieri.
     */
    private static function wrap_first_occurrence($content, $link) {
        $parts = preg_split('/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { return null; }

        $depth = array_fill_keys(self::$skip_tags, 0);
        $hit   = false;

        foreach ($parts as $i => $part) {
            if ($part === '') { continue; }

            if ($part[0] === '<') {
                if (preg_match('#^</\s*([a-z0-9]+)#i', $part, $m)) {
                    $tag = strtolower($m[1]);
                    if (isset($depth[$tag]) && $depth[$tag] > 0) { $depth[$tag]--; }
                } elseif (preg_match('#^<\s*([a-z0-9]+)#i', $part, $m)) {
                    $tag = strtolower($m[1]);
                    // Tag auto-închis (<img />) nu deschide nimic.
                    if (isset($depth[$tag]) && substr(rtrim($part), -2) !== '/>') { $depth[$tag]++; }
                }
                continue;
            }

            foreach ($depth as $d) { if ($d > 0) { continue 2; } }

            $pos = self::find_anchor($part, $link['anchor']);
            if ($pos === null) { continue; }

            $len      = mb_strlen($link['anchor']);
            $original = mb_substr($part, $pos, $len);   // păstrăm diacriticele din text
            $parts[$i] = mb_substr($part, 0, $pos)
                . self::tag_html($link, $original)
                . mb_substr($part, $pos + $len);
            $hit = true;
            break;
        }

        return $hit ? implode('', $parts) : null;
    }

    /**
     * Poziția (în caractere) a ancorei într-un text, ignorând majusculele și
     * diacriticele, cu potrivire pe cuvinte întregi. Harta de diacritice e 1:1 pe
     * caracter, deci pozițiile rămân valide și în textul original.
     */
    private static function find_anchor($text, $anchor) {
        $t = self::fold($text);
        $a = self::fold($anchor);
        if ($a === '') { return null; }

        $offset = 0;
        while (true) {
            $pos = mb_stripos($t, $a, $offset);
            if ($pos === false) { return null; }
            $before = $pos > 0 ? mb_substr($t, $pos - 1, 1) : ' ';
            $after  = mb_substr($t, $pos + mb_strlen($a), 1);
            if ($after === '') { $after = ' '; }
            if (!preg_match('/[\p{L}\p{N}]/u', $before) && !preg_match('/[\p{L}\p{N}]/u', $after)) {
                return $pos;
            }
            $offset = $pos + 1;
        }
    }

    /** Diacriticele românești → ASCII, un caracter pentru un caracter. */
    private static function fold($s) {
        return strtr((string) $s, array(
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ));
    }

    /**
     * Plasa de siguranță: adaugă o propoziție scurtă cu ancora la finalul unui
     * paragraf din mijlocul articolului (nu primul, nu ultimul).
     */
    private static function insert_sentence($content, $link, $seed = 0) {
        $templates = array(
            'Mai multe despre %s găsești aici.',
            'Pentru detalii, vezi %s.',
            'Un punct de plecare util este %s.',
            'Merită să te uiți și la %s.',
        );
        $sentence = ' ' . sprintf($templates[$seed % count($templates)], self::tag_html($link, $link['anchor']));

        // Toate închiderile de paragraf care NU sunt în interiorul unei figuri.
        if (preg_match_all('#</p>#i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $spots = $m[0];
            $n     = count($spots);
            if ($n >= 2) {
                $idx = (int) floor($n / 2);          // paragraf din mijloc
                if ($idx >= $n) { $idx = $n - 1; }
                if ($idx === 0) { $idx = 1; }
                $at = $spots[$idx][1];
                return substr($content, 0, $at) . $sentence . substr($content, $at);
            }
            if ($n === 1) {
                $at = $spots[0][1];
                return substr($content, 0, $at) . $sentence . substr($content, $at);
            }
        }

        // Fără paragrafe (improbabil): adăugăm unul la final.
        return rtrim($content) . "\n<p>" . ltrim($sentence) . '</p>';
    }
}
