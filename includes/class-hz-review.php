<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Modul „Articol editorial cu produse” (v3.16.0).
 *
 * Comandat din panoul „Profitshare” de pe app.targetseo.ro prin aceeași rută
 * hz/v1/enqueue, cu `mode: "review"` și o listă de produse per articol. Rulează
 * prin ACEEAȘI coadă ca articolele bulk (cron, lock, imagini Magnific, anti-dubluri,
 * raportare) — aici e doar prompt-ul specializat și transformarea produselor în
 * linkuri de afiliere.
 *
 * REGULA DE FOND, din care iese tot restul: produsul e ILUSTRAȚIA unui sfat, nu
 * subiectul paginii. Regulamentul eMAG interzice explicit site-urile construite ca
 * agregatoare de produse sau comparatoare de prețuri; un articol care e doar o listă
 * de produse cu prețuri arată exact așa, indiferent că datele vin prin API-ul lor
 * oficial. De aceea miezul articolului sunt CRITERIILE de alegere, iar produsele
 * apar ca exemple argumentate pe acele criterii.
 *
 * De aceea, tot din regulă:
 *   - NU cerem modelului prețuri exacte în text. Prețul se schimbă, articolul rămâne;
 *     un articol care spune „costă 1.299 lei” devine informație falsă în câteva luni
 *     (și e o problemă ANPC, nu doar una de imagine).
 *   - NU cerem tabele de produse, butoane „Cumpără” sau enumerări seci.
 *   - Modelul NU scrie linkuri; scrie doar numele produsului. Linkul îl punem noi,
 *     prin HZ_Links, cu rel="sponsored nofollow".
 */
class HZ_Review {

    /** Câte produse acceptăm per articol. Sub 3 nu e comparație; peste 10 nu mai e
     *  editorial, ci catalog — iar fiecare produs primește oricum un paragraf propriu. */
    const MIN_PRODUCTS = 3;
    const MAX_PRODUCTS = 10;

    /** Lungimea implicită (cuvinte) — un editorial cu criterii are nevoie de spațiu. */
    const DEFAULT_WORDS = 1400;

    /**
     * Normalizează lista de produse venită prin REST.
     *
     * Fiecare produs:
     *   name  (obligatoriu) — numele SCURT, folosit ca ancoră în text. Titlurile eMAG
     *                         complete („Aspirator vertical Rowenta X-Force Flex 8.60
     *                         Animal RH9679WO, 0.9L, 45 min...") nu se potrivesc niciodată
     *                         cuvânt-cu-cuvânt în textul generat, deci panoul trimite
     *                         forma scurtă („Rowenta X-Force Flex 8.60”).
     *   url   (obligatoriu) — deeplink-ul (la noi: redirectorul propriu, nu linkul
     *                         Profitshare direct — vezi nota din panou).
     *   note  (opțional)    — o descriere scurtă pentru model: ce e și ce-l diferențiază.
     *                         NU ajunge ca atare în articol, doar orientează scrierea.
     *
     * @return array listă normalizată (maximum MAX_PRODUCTS)
     */
    public static function sanitize_products($raw) {
        $out = array();
        if (!is_array($raw)) { return $out; }

        $seen = array();
        foreach ($raw as $p) {
            if (!is_array($p)) { continue; }
            $name = trim(wp_strip_all_tags((string) (isset($p['name']) ? $p['name'] : '')));
            $url  = esc_url_raw(trim((string) (isset($p['url']) ? $p['url'] : '')));
            $note = trim(wp_strip_all_tags((string) (isset($p['note']) ? $p['note'] : '')));

            if ($name === '' || $url === '') { continue; }
            if (!preg_match('#^https?://#i', $url)) { continue; }

            // Ancoră prea lungă = nu se potrivește în text. Ancoră prea scurtă („HP”)
            // se potrivește accidental oriunde.
            $name = mb_substr($name, 0, 80);
            if (mb_strlen($name) < 3) { continue; }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) { continue; } // același produs de două ori
            $seen[$key] = 1;

            $img = esc_url_raw(trim((string) (isset($p['image']) ? $p['image'] : '')));
            if ($img !== '' && !preg_match('#^https?://#i', $img)) { $img = ''; }

            $out[] = array(
                'name' => $name,
                'url'  => $url,
                // Poza produsului, de pe CDN-ul magazinului (vine prin API-ul oficial).
                // Se descarcă în biblioteca site-ului la generare — nu o luăm prin hotlink:
                // linkul lor se poate schimba oricând, iar atunci articolul rămâne cu o
                // imagine ruptă pe care n-o vede nimeni până se plânge un cititor.
                'image' => $img,
                // 220, nu 160: nota e titlul complet din catalog, iar acolo stau
                // atributele reale („160x200, Memory Foam, 7 zone de confort"). Tăiat
                // prea scurt, rămâne doar brandul și modelul n-are pe ce se sprijini.
                'note' => mb_substr($note, 0, 220),
            );
            if (count($out) >= self::MAX_PRODUCTS) { break; }
        }
        return $out;
    }

    /**
     * Produsele devin linkuri pentru HZ_Links: ancora = numele produsului, rel =
     * sponsored. Astfel refolosim garanția existentă („linkul TREBUIE să existe în
     * articol"), inclusiv plasa de siguranță când modelul nu folosește numele exact.
     */
    public static function to_links($products, $only_without_image = false) {
        $links = array();
        foreach ((array) $products as $p) {
            // v3.16.1: produsele CU poză nu se mai leagă în text. Aveau trei linkuri
            // fiecare (numele din text + poza + legenda blocului), ceea ce transformă
            // articolul într-un covor de linkuri. Singurul link rămâne blocul.
            if ($only_without_image && !empty($p['image'])) { continue; }
            $links[] = array(
                'anchor' => $p['name'],
                'url'    => $p['url'],
                'rel'    => 'sponsored',
            );
        }
        return $links;
    }

    /**
     * Prompt-ul editorial. Nu folosim șablonul `cw_article_prompt` al site-ului:
     * acela e pentru articole informative obișnuite și n-are structura pe criterii.
     * Păstrăm însă stilul site-ului prin system prompt-ul din CW_API (același ca la
     * restul articolelor), deci articolul nu sună „străin” de site.
     */
    public static function prompt($title, $products, $words = 0, $extra = '') {
        $words = (int) $words > 0 ? (int) $words : self::DEFAULT_WORDS;
        $n     = count($products);

        $lista = array();
        foreach ($products as $i => $p) {
            $line = ($i + 1) . '. ' . $p['name'];
            if ($p['note'] !== '') { $line .= ' — ' . $p['note']; }
            $lista[] = $line;
        }

        $prompt = "Scrie un articol editorial în limba română, cu diacritice, despre: {$title}\n\n"
            . "Lungime: aproximativ {$words} de cuvinte.\n\n"

            . "STRUCTURA OBLIGATORIE:\n"
            . "1. Introducere (2-3 paragrafe): problema reală a cititorului și de ce alegerea e grea. "
            . "NU menționa niciun produs aici.\n"
            . "2. Un capitol <h2> despre CUM SE ALEGE: 4-6 criterii concrete, fiecare explicat într-un "
            . "paragraf propriu, cu ce înseamnă practic și ce greșesc oamenii când îl ignoră. "
            . "ACESTA este miezul articolului și partea cea mai lungă.\n"
            . "3. Un capitol <h2> cu recomandări: fiecare dintre cele {$n} produse de mai jos, într-un "
            . "paragraf propriu, în care spui PENTRU CINE e potrivit și DE CE, argumentat pe criteriile "
            . "explicate mai sus. Nu descrie fișa tehnică — explică decizia.\n"
            . "4. Un capitol <h2> cu greșeli frecvente sau lucruri de evitat.\n"
            . "5. O concluzie scurtă care ajută cititorul să decidă singur.\n\n"

            . "PRODUSELE (folosește numele EXACT, o singură dată fiecare, în capitolul de recomandări).\n"
            . "După liniuță e titlul din catalog — SINGURA informație factuală pe care o ai despre "
            . "produs. Deduci din el ce poți (dimensiuni, material, tip) și te oprești acolo: orice "
            . "altceva ar fi inventat.\n"
            . implode("\n", $lista) . "\n\n"

            . "REGULI STRICTE:\n"
            . "- NU scrie prețuri, sume în lei sau euro, procente de reducere sau disponibilitate în stoc. "
            . "Prețurile se schimbă, articolul rămâne. Poți vorbi despre raport calitate-preț în termeni "
            . "generali (ex. „opțiune accesibilă”, „investiție pe termen lung”), fără cifre.\n"
            . "- NU face tabel de produse, listă de specificații tehnice, „top 10” numerotat sau butoane "
            . "de cumpărare. Articolul este un text editorial, nu un catalog.\n"
            . "- NU scrie linkuri, adrese web (URL) sau nume de magazine online. De linkuri mă ocup eu.\n"
            . "- NU inventa teste, măsurători, premii sau păreri de utilizatori pe care nu le ai. "
            . "Scrie din raționament practic, nu din date pe care nu le deții.\n"
            . "- NU folosi superlative de reclamă („cel mai bun de pe piață”, „revoluționar”, „incredibil”).\n"
            . "- Scrie la persoana a doua, direct către cititor, ca un sfat de la cineva care se pricepe.\n"
            . "- Produsul este ILUSTRAȚIA unui sfat, nu subiectul articolului. Dacă ai scoate numele "
            . "produselor, textul trebuie să rămână util în sine.\n\n"

            . "FORMAT: HTML curat, doar <h2>, <h3>, <p>, <ul>, <li>, <strong>. Fără <h1>, fără <html>, "
            . "fără <body>, fără markdown, fără blocuri de cod.";

        if (trim((string) $extra) !== '') {
            $prompt .= "\n\nInstrucțiuni suplimentare pentru acest lot:\n" . trim((string) $extra);
        }
        return $prompt;
    }

    /**
     * Nota de dezvăluire a afilierii. Obligatorie: Google o cere pentru conținut cu
     * linkuri plătite, iar DSA/ANPC o cer pentru comunicare comercială. Se pune SUS,
     * înainte de primul paragraf — o notă ascunsă la subsol nu bifează „vizibilă”.
     *
     * Textul se poate schimba per site prin filtrul `hz_review_disclosure`.
     */
    public static function disclosure() {
        $text = 'Acest articol conține linkuri de afiliere. Dacă alegi să cumperi prin ele, '
              . 'putem primi un comision, fără niciun cost suplimentar pentru tine. '
              . 'Recomandările rămân alegerea noastră editorială.';
        return (string) apply_filters('hz_review_disclosure', $text);
    }

    /** Adaugă nota de dezvăluire la începutul articolului (o singură dată). */
    public static function add_disclosure($content) {
        $content = (string) $content;
        // OPRITĂ implicit din v3.16.1, la cererea explicită a userului. Rămâne
        // comandabilă de la distanță (`hz_review_disclosure`, prin hz/v1/settings), ca
        // repornirea pe toată flota să fie o comandă, nu o versiune nouă de plugin.
        //
        // Notă tehnică, nu insistență: rel="sponsored nofollow" de pe linkuri rămâne
        // pus indiferent de setarea asta — el e semnalul citit automat de Google.
        if ((int) get_option('hz_review_disclosure', 0) !== 1) { return $content; }
        if (strpos($content, 'hz-affiliate-note') !== false) { return $content; }

        $note = '<p class="hz-affiliate-note"><em>' . esc_html(self::disclosure()) . '</em></p>' . "\n";
        return $note . $content;
    }

    /**
     * Descarcă pozele produselor în biblioteca site-ului și le atașează articolului.
     *
     * NU hotlinkăm: URL-ul de pe CDN-ul magazinului se schimbă sau expiră, iar atunci
     * rămâi cu imagini rupte în articole vechi, fără să afle nimeni. Descărcate, sunt
     * servite de pe domeniul propriu și trec prin redimensionarea WordPress.
     *
     * @return array url produs => id atașament
     */
    public static function sideload_images($products, $post_id) {
        $out = array();
        if (empty($products)) { return $out; }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($products as $p) {
            if (empty($p['image'])) { continue; }

            // API-ul dă de multe ori MINIATURA, nu poza produsului (măsurat: 140x140
            // de la Evrik). O miniatură întinsă la 600px e neapărat neclară, iar asta
            // nu se poate repara din CSS. Încercăm întâi originalul, ghicit prin
            // scoaterea sufixului de dimensiune din numele fișierului.
            $tmp = null;
            foreach (self::image_candidates($p['image']) as $candidate) {
                $try = download_url($candidate, 60);
                if (is_wp_error($try)) { continue; }
                $size = @getimagesize($try);
                // Sub 300px nu merită schimbul: păstrăm ce am luat și încercăm mai departe.
                if ($size && (int) $size[0] >= 300) { $tmp = $try; break; }
                if ($tmp === null) { $tmp = $try; } else { @unlink($try); }
            }
            if (!$tmp) { continue; } // o poză lipsă nu strică articolul

            $name = sanitize_file_name(sanitize_title($p['name']));
            if ($name === '') { $name = 'produs'; }
            $ext = strtolower((string) pathinfo((string) parse_url($p['image'], PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!preg_match('/^(jpe?g|png|webp|gif|avif)$/', $ext)) { $ext = 'jpg'; }

            $att = media_handle_sideload(
                array('name' => $name . '.' . $ext, 'tmp_name' => $tmp),
                $post_id,
                $p['name']
            );
            if (is_wp_error($att)) { @unlink($tmp); continue; }

            update_post_meta($att, '_wp_attachment_image_alt', sanitize_text_field($p['name']));
            update_post_meta($att, '_hz_review_product', 1);
            $out[$p['url']] = (int) $att;
        }
        return $out;
    }

    /**
     * Variantele de URL de încercat pentru poza unui produs, de la cea mai mare la
     * cea dată de API.
     *
     * Magazinele pe WordPress (majoritatea celor din rețea) redimensionează pozele cu
     * sufix în nume: `poza-140x140.jpg`. Fișierul original stă la `poza.jpg`, în
     * același loc. Scoțând sufixul avem o șansă bună la imaginea mare, iar dacă nu
     * există pur și simplu pică descărcarea și rămânem cu miniatura.
     *
     * @return array URL-uri unice, în ordinea încercării
     */
    public static function image_candidates($url) {
        $url  = (string) $url;
        $out  = array();

        $orig = preg_replace('#-\d{2,4}x\d{2,4}(\.[a-z0-9]{3,4})(\?.*)?$#i', '$1$2', $url);
        if ($orig !== $url && $orig !== '') { $out[] = $orig; }

        $out[] = $url;
        return array_values(array_unique($out));
    }

    /**
     * Pune blocul fiecărui produs (poză + nume, ambele link) IMEDIAT DUPĂ paragraful
     * în care produsul e recomandat.
     *
     * Asta e diferența dintre un editorial și un catalog: pozele nu stau una sub alta
     * într-o grilă la finalul articolului, ci ilustrează fiecare argument acolo unde e
     * făcut. Dacă produsul nu apare în text (n-ar trebui — HZ_Links garantează), blocul
     * lui pur și simplu nu se pune, ca să nu ajungă un raft de poze la coadă.
     */
    public static function insert_product_blocks($content, $products, $attachments) {
        if (empty($products) || empty($attachments)) { return $content; }

        $nr = 0;
        // Cursor: fiecare produs se cauta DUPA cel precedent. Fara el, un produs pomenit
        // in introducere sau in capitolul de criterii isi primea cardul acolo, iar
        // paragraful care il argumenta ramanea mai jos, orfan.
        // Pornim din capitolul de RECOMANDARI, nu de la inceputul articolului: modelul
        // pomeneste adesea un produs mai devreme (in criterii), iar cardul ateriza
        // acolo, cu paragraful lui adevarat ramas mai jos. Cursorul rezolva doar
        // produsele urmatoare; primul are nevoie de punctul asta de pornire.
        $cursor = self::recommendations_offset($content, $products);

        foreach ($products as $p) {
            $nr++;
            if (empty($attachments[$p['url']])) { continue; }
            $att = (int) $attachments[$p['url']];

            $img = wp_get_attachment_image($att, 'large', false, array(
                'class'   => 'hz-product-img',
                'loading' => 'lazy',
                'alt'     => esc_attr($p['name']),
            ));
            if (!$img) { continue; }

            $r = self::wrap_paragraph($content, $p, $nr, $img, $cursor);
            if ($r === null) { continue; }
            $content = $r['content'];
            $cursor  = $r['cursor'];
        }
        return $content;
    }

    /**
     * Impacheteaza paragraful care recomanda produsul intr-un card: poza intr-o parte,
     * textul in cealalta.
     *
     * De ce inghitim paragraful in loc sa punem blocul dupa el: altfel poza si numele
     * stau intr-o caseta, iar argumentul intr-un paragraf separat dedesubt - arata
     * dezlipite, ca doua lucruri fara legatura. Impreuna se citesc ca o recomandare.
     *
     * @return array|null array('content','cursor') sau null daca nu gasim paragraful
     */
    /**
     * Unde incepe capitolul de recomandari: sectiunea <h2> in care apar CELE MAI MULTE
     * nume de produse.
     *
     * Nu cautam titlul dupa cuvinte („Recomandari") - modelul il formuleaza cum vrea,
     * iar pe alta nisa poate fi „Ce merita banii". Numaratoarea de produse e semnalul
     * sigur: capitolul de recomandari e, prin constructie, singurul care le contine pe
     * toate.
     *
     * @return int offset in continut (0 = nu s-a putut determina, cautam de la inceput)
     */
    private static function recommendations_offset($content, $products) {
        if (!preg_match_all('#<h2\b[^>]*>#i', $content, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        $starts = array();
        foreach ($m[0] as $hit) { $starts[] = (int) $hit[1]; }
        $starts[] = strlen($content); // sfarsitul ultimei sectiuni

        $best = 0;
        $bestCount = 0;
        for ($i = 0; $i < count($starts) - 1; $i++) {
            $section = self::fold_text(substr($content, $starts[$i], $starts[$i + 1] - $starts[$i]));
            $count = 0;
            foreach ($products as $p) {
                $needle = self::fold_text($p['name']);
                if ($needle !== '' && strpos($section, $needle) !== false) { $count++; }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $starts[$i];
            }
        }

        // Sub doua produse intr-o sectiune nu putem vorbi de „capitolul de recomandari".
        return $bestCount >= 2 ? $best : 0;
    }

    /**
     * Pozitia (in textul ORIGINAL) a primei aparitii a lui $needle dupa $from,
     * cautand fara diacritice si fara majuscule.
     *
     * Aplatizarea schimba lungimea sirului (diacriticele romanesti au doi octeti in
     * UTF-8, inlocuitorii ASCII unul singur), deci construim si o harta
     * pozitie_aplatizata -> pozitie_originala. Altfel offset-ul intors e mai mic decat
     * cel real, cu cate o unitate pentru fiecare diacritica dinainte.
     *
     * @return int|false
     */
    private static function find_in_folded($haystack, $needle, $from = 0) {
        if ($needle === '') { return false; }

        $tr = array(
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
            'ş' => 's', 'ţ' => 't', 'Ă' => 'a', 'Â' => 'a', 'Î' => 'i',
            'Ș' => 's', 'Ț' => 't', 'Ş' => 's', 'Ţ' => 't',
        );

        $flat = '';
        $map  = array();
        $i = 0;
        $n = strlen($haystack);
        while ($i < $n) {
            $hit = null;
            foreach ($tr as $k => $v) {
                if ($haystack[$i] === $k[0] && substr($haystack, $i, strlen($k)) === $k) {
                    $hit = array($k, $v);
                    break;
                }
            }
            if ($hit !== null) {
                $map[strlen($flat)] = $i;
                $flat .= $hit[1];
                $i += strlen($hit[0]);
            } else {
                $map[strlen($flat)] = $i;
                $flat .= strtolower($haystack[$i]);
                $i++;
            }
        }

        // Sarim aparitiile dinaintea cursorului (in coordonate ORIGINALE).
        $at = 0;
        while (true) {
            $hitFlat = strpos($flat, $needle, $at);
            if ($hitFlat === false) { return false; }
            $orig = isset($map[$hitFlat]) ? $map[$hitFlat] : false;
            if ($orig === false) { return false; }
            if ($orig >= $from) { return $orig; }
            $at = $hitFlat + 1;
        }
    }

    private static function wrap_paragraph($content, $p, $nr, $img, $cursor) {
        $needle = self::fold_text($p['name']);
        if ($needle === '') { return null; }

        // Cautam in textul „aplatizat" (fara diacritice, litere mici), dar avem nevoie
        // de pozitia din textul ORIGINAL: aplatizarea SCURTEAZA sirul, fiindca „a" pe
        // doi octeti devine „a" pe unul. Fara harta de pozitii, decalajul se aduna la
        // fiecare diacritica si cardul ajunge peste alt paragraf - exact cum au iesit
        // carduri cu titlul unui produs si textul altuia.
        $pos = self::find_in_folded($content, $needle, $cursor);
        if ($pos === false) { return null; }

        // Paragraful care il contine: ultima deschidere <p> dinaintea numelui.
        if (!preg_match_all('#<p[^>]*>#i', substr($content, 0, $pos), $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open       = end($m[0]);
        $start      = (int) $open[1];
        $inner_from = $start + strlen($open[0]);

        $end = stripos($content, '</p>', $pos);
        if ($end === false) { return null; }

        $inner = substr($content, $inner_from, $end - $inner_from);
        $href  = esc_url($p['url']);

        $card = '<div class="hz-product" id="produs-' . $nr . '">'
              . '<div class="hz-product-media">' . $img . '</div>'
              . '<div class="hz-product-body">'
              // Fara numar (v3.16.7): articolul nu e un clasament, iar numerotarea
              // promitea o ierarhie pe care textul nu o sustine. Poza ramane in stanga.
              . '<p class="hz-product-title">'
              // Poza NU e link: un singur link per produs, pe nume.
              . '<a href="' . $href . '" rel="sponsored nofollow" target="_blank">'
              . esc_html($p['name']) . '</a>'
              . '</p>'
              . '<p class="hz-product-text">' . $inner . '</p>'
              . '</div></div>';

        $new = substr($content, 0, $start) . $card . substr($content, $end + 4);
        return array('content' => $new, 'cursor' => $start + strlen($card));
    }

    /**
     * Inserează blocul după paragraful în care e recomandat produsul.
     *
     * Caută întâi linkul (dacă produsul a fost și legat în text), apoi NUMELE lui.
     * A doua cale e cea normală de la v3.16.1: produsele cu poză nu se mai leagă în
     * text, ca să nu apară de trei ori (text + poză + legendă) — singurul link rămâne
     * blocul.
     *
     * Fără paragraf potrivit nu inserăm nimic: un bloc rătăcit la finalul articolului
     * arată exact ca raftul de catalog pe care îl evităm.
     */
    private static function place_after_mention($content, $p, $block) {
        $pos = stripos($content, 'href="' . $p['url'] . '"');

        if ($pos === false) {
            // Numele, fără diacritice și majuscule — modelul scrie „Saltea Evrik
            // Confort PLUS", catalogul poate avea „SALTEA EVRIK CONFORT plus".
            $hay = self::fold_text($content);
            $needle = self::fold_text($p['name']);
            if ($needle === '') { return $content; }
            $pos = strpos($hay, $needle);
            if ($pos === false) { return $content; }
        }

        $end = stripos($content, '</p>', $pos);
        if ($end === false) { return $content; }
        $end += 4;

        return substr($content, 0, $end) . "\n" . $block . substr($content, $end);
    }

    /**
     * Diacriticele românești → ASCII, 1:1 pe caracter, plus litere mici. Harta e
     * caracter-cu-caracter tocmai ca pozițiile din textul transformat să rămână
     * valide în textul original.
     */
    private static function fold_text($s) {
        return strtolower(strtr((string) $s, array(
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        )));
    }

    /**
     * Verificare de siguranță ÎNAINTE de publicare: articolul chiar e editorial sau
     * modelul a livrat totuși un catalog? Returnează un mesaj de avertisment (care
     * ajunge în nota din coadă) sau '' dacă e în regulă.
     *
     * Nu blocăm publicarea — articolul e deja plătit, iar un fals pozitiv ar arunca
     * text bun. Dar problema trebuie să fie VIZIBILĂ în panou, altfel derivă tăcut.
     */
    public static function warnings($content, $products) {
        $out = array();
        // Tagurile devin SPAȚIU, nu dispar: strip_tags simplu lipește textul a două
        // elemente vecine („…1.299 lei</p><p>Alt paragraf” -> „leiAlt”), iar granița de
        // cuvânt din regexul de preț n-ar mai avea unde să cadă.
        $text = wp_strip_all_tags(preg_replace('#<[^>]+>#', ' ', (string) $content));

        // Prețuri scăpate în text, în ciuda instrucțiunii. Sumele vin în tot felul de
        // forme („1.299 lei”, „1299 RON”, „€250”), deci nu fixăm numărul de cifre.
        if (preg_match('#\d+(?:[.,]\d+)*\s*(?:lei|ron|eur)\b#iu', $text)
            || preg_match('#(?:€\s*\d|\d\s*€)#u', $text)) {
            $out[] = 'conține prețuri';
        }
        // Tabel = catalog.
        if (stripos($content, '<table') !== false) {
            $out[] = 'conține tabel';
        }
        // Prea puțin text pentru câte produse are: semnul unei liste, nu al unui editorial.
        // str_word_count nu e sigur pe UTF-8 (diacriticele sunt multibyte), deci numărăm
        // secvențe de litere Unicode.
        $words = preg_match_all('/\p{L}+/u', $text);
        $n     = max(1, count($products));
        if ($words > 0 && ($words / $n) < 110) {
            $out[] = 'prea scurt pentru ' . $n . ' produse (' . $words . ' cuvinte)';
        }

        return empty($out) ? '' : 'VERIFICĂ: ' . implode(', ', $out);
    }
}
