<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Rescrierea articolelor deja publicate care au rămas cu CITĂRI de la căutarea web.
 *
 * Contextul: articolele scrise cu web search pe OpenAI au primit în text citări
 * markdown — „([legislatie.just.ro](https://…?utm_source=openai))". Conținutul se
 * salvează ca HTML, deci citarea se vede ca text stricat în pagină. Curățarea
 * mecanică (HZ_Links::strip_citations) le scoate, dar lasă în proză atribuirile de
 * tip „potrivit Agerpres" — articolul trebuia SCRIS pe baza surselor, nu să le
 * citeze. De aia trecem articolul prin model.
 *
 * Regula de linkuri cerută: se păstrează DOAR linkuri către instituții oficiale
 * (ANAF, BNR, INS, legislatie.just.ro, *.gov.ro…), ca <a href> pe un text cu sens.
 * Restul: fără link și fără numele publicației.
 *
 * Comandat din exterior prin ruta semnată hz/v1/rewrite (vezi HZ_Remote), un
 * articol per apel — fără coadă și fără cron, ca panoul să vadă imediat rezultatul.
 * Textul original se salvează în meta `_hz_rw_backup` ÎNAINTE de suprascriere, deci
 * revenirea e o singură interogare.
 */
class HZ_Rewrite {

    const META_BACKUP = '_hz_rw_backup';
    const META_AT     = '_hz_rw_at';
    const META_MODEL  = '_hz_rw_model';

    /** Model implicit: cel mai ieftin din listă (0,75$/4,5$ la 1M tokeni). */
    const DEFAULT_MODEL = 'gpt-5.4-mini';

    /**
     * ID-urile articolelor publicate care mai au citări în conținut.
     * Tiparele: link markdown rămas „](http" și parametrul de urmărire al căutării.
     */
    public static function find_posts($limit = 50) {
        global $wpdb;
        $limit = max(1, min(500, (int) $limit));
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
               AND (post_content LIKE %s OR post_content LIKE %s)
             ORDER BY ID ASC LIMIT %d",
            '%](http%',
            '%utm_source=openai%',
            $limit
        )));
    }

    /** Câte articole cu citări mai are site-ul (pentru raportul din panou). */
    public static function count_posts() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
               AND (post_content LIKE %s OR post_content LIKE %s)",
            '%](http%',
            '%utm_source=openai%'
        ));
    }

    /**
     * Rescrie UN articol. Returnează array cu ce s-a întâmplat (sau 'error').
     *
     * @param int    $post_id
     * @param string $model  gol = DEFAULT_MODEL
     * @param bool   $dry    true = doar arată ce ar ieși, nu salvează
     */
    public static function rewrite($post_id, $model = '', $dry = false) {
        $post_id = (int) $post_id;
        $post    = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return array('id' => $post_id, 'error' => 'post_negasit');
        }
        if (!class_exists('CW_API') || !CW_API::has_key()) {
            return array('id' => $post_id, 'error' => 'fara_cheie_ai');
        }

        $original = (string) $post->post_content;
        $cit_before = preg_match_all('#\]\(https?://#i', $original);

        $model = $model !== '' ? $model : self::DEFAULT_MODEL;
        $model = CW_API::resolve_model($model);

        // Fără căutare web: articolul are deja informația, iar o căutare nouă ar
        // aduce exact citările pe care le scoatem.
        $result = CW_Usage::with_context('rewrite', function () use ($model, $post, $original) {
            return CW_API::no_web(function () use ($model, $post, $original) {
                return CW_API::generate($model, self::prompt($post->post_title, $original), self::max_tokens($original));
            });
        });
        if (is_wp_error($result)) {
            return array('id' => $post_id, 'error' => $result->get_error_message());
        }

        $new  = self::clean_output((string) $result['text']);
        $cost = isset($result['cost']) ? (float) $result['cost'] : 0.0;

        $bad = self::reject_reason($original, $new);
        if ($bad !== '') {
            // Textul întors e suspect (tăiat, prea scurt, tot cu citări) — NU salvăm.
            return array('id' => $post_id, 'error' => $bad, 'cost' => $cost,
                'len_before' => strlen($original), 'len_after' => strlen($new));
        }

        $out = array(
            'id'         => $post_id,
            'ok'         => true,
            'title'      => $post->post_title,
            'model'      => $model,
            'cost'       => $cost,
            'citari'     => (int) $cit_before,
            'len_before' => strlen($original),
            'len_after'  => strlen($new),
            'dry'        => (bool) $dry,
        );
        if ($dry) { return $out; }

        // Copia de siguranță se scrie o SINGURĂ dată: la a doua rulare pe același
        // articol vrem tot textul de dinainte de prima rescriere.
        if (get_post_meta($post_id, self::META_BACKUP, true) === '') {
            update_post_meta($post_id, self::META_BACKUP, $original);
        }
        update_post_meta($post_id, self::META_AT, current_time('mysql'));
        update_post_meta($post_id, self::META_MODEL, $model);

        $modified     = $post->post_modified;
        $modified_gmt = $post->post_modified_gmt;

        // Cererea vine nesemnată de un user WP, deci kses ar tăia HTML-ul.
        kses_remove_filters();
        $res = wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash($new)), true);
        kses_init_filters();
        if (is_wp_error($res)) {
            return array('id' => $post_id, 'error' => $res->get_error_message(), 'cost' => $cost);
        }

        // Curățarea nu e o actualizare editorială: păstrăm data modificării, ca tema
        // să nu afișeze „Actualizat la" pe 80 de articole dintr-o reparație tehnică.
        global $wpdb;
        $wpdb->update($wpdb->posts,
            array('post_modified' => $modified, 'post_modified_gmt' => $modified_gmt),
            array('ID' => $post_id)
        );
        clean_post_cache($post_id);

        return $out;
    }

    /** Revine la textul dinainte de rescriere. */
    public static function restore($post_id) {
        $post_id = (int) $post_id;
        $backup  = get_post_meta($post_id, self::META_BACKUP, true);
        if ($backup === '') { return array('id' => $post_id, 'error' => 'fara_backup'); }
        kses_remove_filters();
        wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash($backup)), true);
        kses_init_filters();
        return array('id' => $post_id, 'ok' => true, 'restored' => true);
    }

    /* ---------------- intern ---------------- */

    private static function max_tokens($original) {
        // Articolul iese cam cât a intrat; ~4 caractere/token, cu marjă pentru
        // raționament. Plafonul rămâne cel al modelului.
        return max(2000, min(16000, (int) (strlen($original) / 2)));
    }

    private static function prompt($title, $html) {
        $officiale = 'anaf.ro, static.anaf.ro, legislatie.just.ro, monitoruloficial.ro, bnr.ro, '
            . 'insse.ro, statistici.insse.ro, anpc.ro, anofm.ro, inspectiamuncii.ro, cnas.ro, '
            . 'cnpp.ro, ms.ro, edu.ro, orice domeniu .gov.ro';

        return "Ești redactor. Primești un articol PUBLICAT care a fost scris cu ajutorul căutării web "
            . "și a rămas plin de citări. Rescrie-l ca articol curat.\n\n"
            . "TITLU: $title\n\n"
            . "CE TREBUIE SCOS COMPLET:\n"
            . "- citările de tipul ([domeniu.ro](https://…)) sau [domeniu](https://…) — NICIUNA nu rămâne;\n"
            . "- orice adresă web scrisă în text (http://, https://, www.);\n"
            . "- numele publicațiilor și al site-urilor de la care s-a luat informația "
            . "(ex. Agerpres, Ziarul Financiar, News.ro, Reddit, orice revistă online). Informația RĂMÂNE, "
            . "atribuirea devine generică: „datele oficiale”, „statisticile publicate”, „un studiu de specialitate”.\n\n"
            . "LINKURI PERMISE (singura excepție): doar către instituții oficiale — $officiale.\n"
            . "Cel mult 2 pe articol, scrise ca <a href=\"URL\" target=\"_blank\" rel=\"nofollow noopener\">text cu sens</a> "
            . "(ancora e o expresie normală, ex. „Codul muncii” sau „calendarul ANAF”, NICIODATĂ numele domeniului). "
            . "Dacă nu ai un URL oficial sigur în articol, nu pui niciun link — e perfect acceptabil ca articolul să iasă fără linkuri.\n\n"
            . "CE SE PĂSTREAZĂ:\n"
            . "- subiectul, structura (aceleași secțiuni H2/H3), listele, lungimea (±10%) și tonul;\n"
            . "- TOATE cifrele, termenele, procentele și denumirile legale, exact cum sunt. Nu inventa date noi "
            . "și nu adăuga informații pe care articolul nu le are;\n"
            . "- româna cu diacritice, corectă gramatical. Frazele din care scoți o sursă se reformulează firesc, "
            . "nu rămân ciuntite.\n\n"
            . "IEȘIRE: DOAR HTML-ul articolului (<p>, <h2>, <h3>, <ul>, <ol>, <strong>), fără <h1>, fără markdown, "
            . "fără blocuri ``` și fără comentarii despre ce ai schimbat.\n\n"
            . "ARTICOLUL:\n" . $html;
    }

    /** Curăță ieșirea modelului și impune regula de linkuri, indiferent ce a scris. */
    private static function clean_output($text) {
        $text = trim($text);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = preg_replace('/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text);
        if (class_exists('HZ_Links')) {
            // strip_external fără pasul de mențiuni: numele de site-uri le-a scos deja
            // modelul, iar ștergerea oarbă ar putea ciunti fraze rescrise corect.
            $text = HZ_Links::strip_external($text, false);
        }
        return trim($text);
    }

    /** Motivul pentru care textul întors NU e bun de salvat ('' = e bun). */
    private static function reject_reason($original, $new) {
        if (strlen($new) < 800) { return 'raspuns_prea_scurt'; }
        if (strlen($new) < 0.6 * strlen($original)) { return 'raspuns_trunchiat'; }
        if (strpos($new, '<p') === false && strpos($new, '<h2') === false) { return 'raspuns_fara_html'; }
        if (preg_match('#\]\(https?://#i', $new)) { return 'inca_are_citari'; }
        return '';
    }
}
