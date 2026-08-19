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

            $out[] = array(
                'name' => $name,
                'url'  => $url,
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
    public static function to_links($products) {
        $links = array();
        foreach ((array) $products as $p) {
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
        if (strpos($content, 'hz-affiliate-note') !== false) { return $content; }

        $note = '<p class="hz-affiliate-note"><em>' . esc_html(self::disclosure()) . '</em></p>' . "\n";
        return $note . $content;
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
