<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Modulul AI Writer, INCLUS în acest plugin (fost plugin separat „claude-writer",
 * eliminat automat de pe site la v2.0.0 — vezi horoscop-zilnic.php).
 *
 * Folosește ACELEAȘI opțiuni cw_* din wp_options ca pluginul vechi, deci cheile
 * API (OpenAI/Anthropic), prompturile, statisticile de cost și toate setările
 * se preiau automat, fără nicio reconfigurare.
 */

if (!defined('CW_VERSION')) { define('CW_VERSION', HZ_VERSION); }
if (!defined('CW_PATH'))    { define('CW_PATH', HZ_PATH . 'writer/'); }
if (!defined('CW_URL'))     { define('CW_URL', HZ_URL . 'writer/'); }

require_once CW_PATH . 'includes/class-cw-crypto.php';
require_once CW_PATH . 'includes/class-cw-models.php';
require_once CW_PATH . 'includes/class-cw-usage.php';
require_once CW_PATH . 'includes/class-cw-api.php';
require_once CW_PATH . 'includes/class-cw-admin.php';
require_once CW_PATH . 'includes/class-cw-editor.php';
require_once CW_PATH . 'includes/class-cw-rest.php';

add_action('plugins_loaded', function () {
    (new CW_Admin())->init();
    (new CW_Editor())->init();
    (new CW_Rest())->init();
});

/**
 * Seed + migrare (portat din claude-writer.php v1.4.2, fără updater/auto-update):
 * pe site-urile fără AI Writer instalat vreodată semănăm valorile implicite;
 * pe cele cu AI Writer, patch-urile chirurgicale de prompt rămân idempotente.
 */
add_action('admin_init', function () {
    $stored = get_option('cw_db_version', '');
    if (version_compare($stored, CW_VERSION, '>=')) {
        return;
    }

    // Site fără istoric AI Writer: valorile implicite (autoload no — doar admin).
    $defaults = array(
        'cw_api_key'            => '',
        'cw_openai_api_key'     => '',
        'cw_default_model'      => 'gpt-5.6-terra',
        'cw_effort'             => 'medium',
        'cw_temperature'        => 0.8,
        'cw_max_tokens'         => 8000,
        'cw_stream_enabled'     => 1,
        'cw_monthly_cost_limit' => 0,
        'cw_system_prompt'      => CW_Admin::default_system_prompt(),
        'cw_article_prompt'     => CW_Admin::default_article_prompt(),
        'cw_rewrite_prompt'     => CW_Admin::default_rewrite_prompt(),
        'cw_title_prompt'       => CW_Admin::default_title_prompt(),
        'cw_keywords_prompt'    => CW_Admin::default_keywords_prompt(),
        'cw_disclaimer'         => CW_Admin::default_disclaimer(),
        'cw_alert_email'        => 'kreatorteam@gmail.com',
        'cw_calls_total'        => 0,
        'cw_cost_total'         => 0,
        'cw_cost_monthly'       => array(),
        'cw_usage_by_model'     => array(),
    );
    foreach ($defaults as $name => $value) {
        add_option($name, $value, '', 'no');
    }

    // Vechile valori implicite (înainte de 1.1.0). Dacă opțiunea încă le conține,
    // o considerăm „needitată" și o aducem la noul prompt editorial.
    $old_system  = "Ești un copywriter SEO profesionist care scrie în limba română. Reguli stricte:\n"
        . "- Folosește OBLIGATORIU diacriticele românești corecte (ă, â, î, ș, ț).\n"
        . "- Scrie natural, direct, ca un expert uman. Evită limbajul artificial de AI.\n"
        . "- INTERZIS: clișee și umplutură de tipul „În era digitală”, „În lumea de azi”, „Este important de menționat”, „Cu toate acestea”, „În concluzie”.\n"
        . "- Fără emoji. Fără liniuță lungă (—); folosește virgulă sau punct.\n"
        . "- Structurează cu subtitluri (H2/H3) relevante, paragrafe scurte și liste când ajută.\n"
        . "- Ton informativ, concret, util. Exemple specifice, nu generalități.\n"
        . "- Nu inventa date sau statistici false.";

    $old_article = "Scrie CORPUL unui articol complet, optimizat SEO, pe tema: {{continut}}.\n"
        . "NU include titlul ca H1 — titlul există deja separat în pagină. Începe direct cu un paragraf de introducere care prinde cititorul.\n"
        . "Folosește subtitluri H2/H3 relevante, paragrafe scurte, liste când ajută și o încheiere utilă (fără subtitlul „Concluzie”).\n"
        . "Lungime: aproximativ {{cuvinte}} de cuvinte.\n"
        . "Returnează DOAR HTML curat: h2, h3, p, ul, li, strong. FĂRĂ h1, fără blocuri de cod, fără ```html.";

    // Promptul de articol implicit din 1.1.0/1.1.1 (înainte de regula de lungime pe ghiduri).
    $old_article_110 = <<<'TXT'
Redactează articolul pe baza acestei teme / titlu / brief:
{{continut}}

Lungime țintă: aproximativ {{cuvinte}} de cuvinte (respectă regula de a nu umfla artificial — dacă subiectul cere mai puțin, scrie mai puțin).
Începe DIRECT cu primul paragraf (lead-ul). NU repeta titlul în conținut: fără H1, și fără ca primul subtitlu (H2/H3) să fie titlul articolului sau o reformulare a lui. Titlul există deja deasupra, în pagină. Primul element livrat trebuie să fie un <p>, nu un heading. Aplică toate regulile editoriale, de structură și de format HTML din instrucțiunile de sistem.
Livrează DOAR HTML curat (h2, h3, p, ul, ol, li, strong, i), fără markdown, fără blocuri de cod și fără niciun comentariu înainte sau după articol.
TXT;

    $cur_system = get_option('cw_system_prompt', '');
    if ($cur_system === '' || $cur_system === $old_system) {
        update_option('cw_system_prompt', CW_Admin::default_system_prompt(), 'no');
    } else {
        // Patch-uri chirurgicale pe promptul de sistem (păstrăm restul personalizărilor).
        $patched = $cur_system;

        // 1) Linia de lungime (1.1.0 -> 1.1.2).
        $old_len = '– Lungimea optimă: 900–1300 cuvinte (nu umfla artificial)';
        $new_len = '– Lungime: articol standard 900–1000 cuvinte; pentru ghiduri, tutoriale, liste pas-cu-pas sau teme complexe extinde la 1500–2000 de cuvinte (fără a umfla artificial)';
        if (strpos($patched, $old_len) !== false) {
            $patched = str_replace($old_len, $new_len, $patched);
        }

        // 2) Nota informativă devine OBLIGATORIE în fiecare articol (1.1.3 -> 1.1.4).
        $old_nota = <<<'TXT'
La finalul articolului, înainte de paragraful de încheiere SAU după, adaugă un paragraf în <i> </i> cu un disclaimer scurt, neutru, profesionist. Specialistul recomandat se alege în funcție de nișă: medic, farmacist, nutriționist, dermatolog, ginecolog, pediatru, avocat, consultant financiar, broker credite, mecanic auto, electrician autorizat, instalator, veterinar, psiholog, antrenor personal, agent imobiliar etc. Nu folosi ton alarmist.

Pentru articole pur recreative (rețete simple, recomandări de filme/cărți, povești, lifestyle ușor) unde nu are sens un disclaimer cu specialist, poți omite nota informativă sau o poți formula ca o simplă mențiune editorială scurtă.
TXT;
        $new_nota = <<<'TXT'
OBLIGATORIU în FIECARE articol, fără excepție: la finalul articolului, înainte de paragraful de încheiere SAU după, adaugă o notă informativă (paragraf în <i> </i>, începută cu „Notă:") — un disclaimer scurt, neutru, profesionist. Specialistul recomandat se alege în funcție de nișă: medic, farmacist, nutriționist, dermatolog, ginecolog, pediatru, avocat, consultant financiar, broker credite, mecanic auto, electrician autorizat, instalator, veterinar, psiholog, antrenor personal, agent imobiliar etc. Nu folosi ton alarmist.

Chiar și pentru articolele pur recreative (rețete, recomandări de filme/cărți, povești, lifestyle ușor) nota NU se omite niciodată — o formulezi scurt și editorial (o mențiune de bun-simț), dar trebuie să existe în orice articol.
TXT;
        if (strpos($patched, $old_nota) !== false) {
            $patched = str_replace($old_nota, $new_nota, $patched);
        }

        // 3b) Interzicem explicit și liniuța medie (–), nu doar pe cea lungă (1.4.2).
        $patched = str_replace(
            '– fără emoji și fără liniuță lungă (—); folosește virgulă, punct sau paranteze',
            '– fără emoji. INTERZISE în text liniuța lungă (—) și liniuța medie (–): când ai nevoie de o liniuță folosește DOAR cratima simplă (-), altfel virgulă, punct sau paranteze',
            $patched
        );

        // 3) Nota informativă: format <blockquote> (1.1.4 -> 1.1.9).
        $patched = str_replace(
            '(paragraf în <i> </i>, începută cu „Notă:")',
            'într-un bloc <blockquote> cu textul în <i> </i>, începută cu „Notă:"',
            $patched
        );
        $patched = str_replace(
            '– Disclaimer-ul cu <i> ... </i>',
            '– Nota informativă (disclaimer) e OBLIGATORIE și se pune într-un <blockquote> cu textul în <i> ... </i>',
            $patched
        );

        if ($patched !== $cur_system) {
            update_option('cw_system_prompt', $patched, 'no');
        }
    }

    $cur_article = get_option('cw_article_prompt', '');
    if ($cur_article === '' || $cur_article === $old_article || $cur_article === $old_article_110) {
        update_option('cw_article_prompt', CW_Admin::default_article_prompt(), 'no');
    }

    // Articolele se trunchiau acolo unde max_tokens era prea mic.
    $max = (int) get_option('cw_max_tokens', 8000);
    if ($max > 0 && $max < 6000) {
        update_option('cw_max_tokens', 8000, 'no');
    }

    // Trecere UNICĂ pe Sonnet 4.6 (istoric, site-urile rămase pe Haiku).
    if (false === get_option('cw_model_default_sonnet', false)) {
        if (get_option('cw_default_model', '') === 'claude-haiku-4-5') {
            update_option('cw_default_model', 'claude-sonnet-4-6', 'no');
        }
        update_option('cw_model_default_sonnet', 1, 'no');
    }

    // Trecere UNICĂ pe GPT-5.6 Terra ca model implicit (decizia userului, 2026-07).
    if (false === get_option('cw_model_default_gpt56', false)) {
        update_option('cw_default_model', 'gpt-5.6-terra', 'no');
        update_option('cw_model_default_gpt56', 1, 'no');
    }

    // Streaming pornit implicit.
    if ((int) get_option('cw_stream_enabled', 0) === 0) {
        update_option('cw_stream_enabled', 1, 'no');
    }

    // Notă de final (disclaimer) configurabilă.
    if (false === get_option('cw_disclaimer', false)) {
        update_option('cw_disclaimer', CW_Admin::default_disclaimer(), 'no');
    }

    update_option('cw_db_version', CW_VERSION, 'no');
});
