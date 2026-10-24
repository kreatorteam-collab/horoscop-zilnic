<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Client pentru API-urile de generare: OpenAI (Chat Completions) și Anthropic (Messages).
 * Modelul cerut decide provider-ul (CW_Models::provider); cheile sunt per provider,
 * stocate criptat. Costurile intră în același tracking (CW_Usage).
 */
class CW_API {

    const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
    const VERSION         = '2023-06-01';
    const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    // Căutarea web la OpenAI merge DOAR pe Responses API (Chat Completions respinge
    // orice parametru de search — verificat live pe gpt-5.4-mini: „Unknown parameter").
    const OPENAI_RESPONSES = 'https://api.openai.com/v1/responses';
    const OPENAI_BASE      = 'https://api.openai.com/v1';

    /**
     * v3.51.0: serverul API pentru modelele GPT e configurabil (`cw_openai_base`), ca să
     * poată merge printr-un gateway compatibil OpenAI (ex. https://api.ns.fm/v1).
     * Gol = OpenAI direct. Se aplică la text, căutare web și imagini.
     */
    public static function openai_base() {
        $b = trim((string) get_option('cw_openai_base', ''));
        return $b !== '' && preg_match('#^https://#i', $b) ? rtrim($b, '/') : self::OPENAI_BASE;
    }

    public static function openai_url($path) {
        return self::openai_base() . '/' . ltrim($path, '/');
    }

    /* ---- Chei ---- */

    public static function api_key() {
        // Cheia Anthropic, stocată criptat; o decriptăm la citire.
        return trim((string) CW_Crypto::decrypt(get_option('cw_api_key', '')));
    }

    public static function openai_key() {
        // Cheie OpenAI PER MODUL: horoscopul, vremea si imaginile pot avea cheia lor, ca
        // sa se vada separat consumul in dashboard-ul OpenAI. Restul (bulk, editor,
        // refresh, produse) folosesc cheia implicita. Context gol sau cheie de modul
        // lipsa => cade pe cheia implicita, deci generarea nu se opreste niciodata.
        $ctx = class_exists('CW_Usage') ? CW_Usage::context() : '';
        $per_ctx = '';
        if ($ctx === 'horoscop') {
            $per_ctx = trim((string) CW_Crypto::decrypt(get_option('cw_openai_key_horoscope', '')));
        } elseif ($ctx === 'vreme') {
            $per_ctx = trim((string) CW_Crypto::decrypt(get_option('cw_openai_key_weather', '')));
        } elseif ($ctx === 'imagini') {
            // Pozele AI + micro-apelurile pentru poze (interogari si alegere stoc Magnific).
            $per_ctx = trim((string) CW_Crypto::decrypt(get_option('cw_openai_key_images', '')));
        }
        if ($per_ctx !== '') { return $per_ctx; }
        return trim((string) CW_Crypto::decrypt(get_option('cw_openai_api_key', '')));
    }

    /** Cheia OpenAI a unui modul anume (ex. 'imagini'), oricare ar fi contextul curent. */
    public static function openai_key_for($ctx) {
        if (!class_exists('CW_Usage')) { return self::openai_key(); }
        return CW_Usage::with_context($ctx, function () {
            return self::openai_key();
        });
    }

    /** Amprenta (ultimele 4 caractere) a cheii dintr-un context, pentru verificare la distanta. */
    public static function openai_key_last4($ctx = '') {
        $prev = null;
        if ($ctx !== '' && class_exists('CW_Usage')) {
            return CW_Usage::with_context($ctx, function () {
                $k = self::openai_key();
                return $k === '' ? '' : substr($k, -4);
            });
        }
        $k = self::openai_key();
        return $k === '' ? '' : substr($k, -4);
    }

    /** True dacă există cel puțin o cheie configurată (orice provider). */
    public static function has_key() {
        return self::api_key() !== '' || self::openai_key() !== '';
    }

    public static function has_provider_key($provider) {
        return $provider === 'openai' ? self::openai_key() !== '' : self::api_key() !== '';
    }

    /**
     * Dacă lipsește cheia provider-ului modelului cerut dar există cheia celuilalt,
     * cădem pe modelul implicit al provider-ului disponibil — generarea nu se oprește
     * niciodată doar pentru că un site nu are (încă) ambele chei.
     */
    public static function resolve_model($model_id) {
        if (!CW_Models::exists($model_id)) { $model_id = CW_Models::default_model(); }
        $provider = CW_Models::provider($model_id);
        if (self::has_provider_key($provider)) {
            return $model_id;
        }
        $other = $provider === 'openai' ? 'anthropic' : 'openai';
        if (self::has_provider_key($other)) {
            return CW_Models::provider_default($other);
        }
        return $model_id;
    }

    public static function headers() {
        return array(
            'x-api-key'         => self::api_key(),
            'anthropic-version' => self::VERSION,
            'content-type'      => 'application/json',
        );
    }

    public static function openai_headers() {
        return array(
            'Authorization' => 'Bearer ' . self::openai_key(),
            'content-type'  => 'application/json',
        );
    }

    /** System promptul efectiv (filtrabil — ex. Horoscop Zilnic îl înlocuiește pe al lui). */
    public static function system_prompt() {
        return apply_filters('cw_system_prompt', trim((string) get_option('cw_system_prompt', '')));
    }

    /* ---- Payload Anthropic (Messages API) ---- */

    /* ---- Căutare web (informații la zi) ---- */

    /**
     * E pornită căutarea web pentru acest apel? Implicit OPRITĂ per site
     * (`cw_web_search`), dar filtrul `cw_web_search` permite pornirea punctuală —
     * așa o cere modulul Articole Bulk pentru loturile marcate „la zi".
     *
     * NU merge pe streaming (editorul): acolo rămâne apelul clasic.
     */
    public static function web_search_on($model_id = '') {
        return (int) apply_filters('cw_web_search', (int) get_option('cw_web_search', 0), $model_id) === 1;
    }

    /**
     * Rulează un apel cu căutarea web FORȚAT oprită. Folosit acolo unde căutarea n-are
     * ce căuta și ar fi bani aruncați: micro-apelurile (interogarea de imagini, titluri,
     * cuvinte cheie), conținutul zilnic (horoscop, vreme — au sursele lor) și refresh-ul
     * pe articolele existente (zeci de mii de articole).
     */
    public static function no_web(callable $fn) {
        $off = function () { return 0; };
        add_filter('cw_web_search', $off, 99);
        try {
            return $fn();
        } finally {
            remove_filter('cw_web_search', $off, 99);
        }
    }

    /** Câte căutări cel mult într-un apel (Anthropic le limitează explicit). */
    public static function web_search_max() {
        return max(1, min(10, (int) get_option('cw_web_search_max', 3)));
    }

    /** Cost estimat per căutare (se taxează per apel, nu în tokeni). */
    public static function web_search_cost() {
        return max(0, (float) get_option('cw_web_search_cost', 0.01));
    }

    public static function build_payload($model_id, $user_prompt, $stream = false, $max_tokens = null) {
        $m = CW_Models::get($model_id);
        if (!$m) { $model_id = CW_Models::provider_default('anthropic'); $m = CW_Models::get($model_id); }

        if ($max_tokens === null) {
            $max_tokens = (int) get_option('cw_max_tokens', 8000);
        }
        $max_tokens = max(256, min(16000, (int) $max_tokens));

        $system = self::system_prompt();

        // $user_prompt poate fi un string (un singur mesaj „user") sau un array de mesaje
        // gata construit — folosit pentru continuarea automată cu prefill „assistant".
        $messages = is_array($user_prompt)
            ? $user_prompt
            : array(array('role' => 'user', 'content' => $user_prompt));

        $payload = array(
            'model'      => $model_id,
            'max_tokens' => $max_tokens,
            'messages'   => $messages,
        );

        // System prompt cu prompt caching (gratuit; reduce costul când e refolosit).
        if ($system !== '') {
            $payload['system'] = array(
                array(
                    'type'          => 'text',
                    'text'          => $system,
                    'cache_control' => array('type' => 'ephemeral'),
                ),
            );
        }

        // Parametrii se trimit DOAR pe modelele care îi acceptă, ca să nu primim 400.
        if (!empty($m['temperature'])) {
            $temp = (float) get_option('cw_temperature', 0.8);
            $payload['temperature'] = max(0.0, min(1.0, $temp));
        }
        if (!empty($m['effort'])) {
            // Filtrabil (v3.2.0): sarcinile simple (ex. extragerea de FAQ dintr-un text
            // existent) cer explicit 'low' — aceeași calitate, de câteva ori mai ieftin.
            $effort = apply_filters('cw_effort', get_option('cw_effort', 'medium'), $model_id);
            if (!in_array($effort, array('low', 'medium', 'high'), true)) { $effort = 'medium'; }
            $payload['output_config'] = array('effort' => $effort);
        }

        // Căutare web: unealtă server-side Anthropic. Blocurile suplimentare din
        // răspuns (server_tool_use / web_search_tool_result) sunt ignorate de parser,
        // care ia doar blocurile de tip „text".
        if (!$stream && self::web_search_on($model_id)) {
            $payload['tools'] = array(array(
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                'max_uses' => self::web_search_max(),
            ));
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /* ---- Payload OpenAI (Chat Completions) ---- */

    public static function build_openai_payload($model_id, $user_prompt, $stream = false, $max_tokens = null) {
        $m = CW_Models::get($model_id);
        if (!$m) { $model_id = CW_Models::provider_default('openai'); $m = CW_Models::get($model_id); }

        if ($max_tokens === null) {
            $max_tokens = (int) get_option('cw_max_tokens', 8000);
        }
        $max_tokens = max(256, min(16000, (int) $max_tokens));

        // $user_prompt: string sau array de mesaje (pentru continuarea automată).
        $messages = is_array($user_prompt)
            ? $user_prompt
            : array(array('role' => 'user', 'content' => $user_prompt));

        $system = self::system_prompt();
        if ($system !== '') {
            array_unshift($messages, array('role' => 'system', 'content' => $system));
        }

        $payload = array(
            'model'                 => $model_id,
            'messages'              => $messages,
            // GPT-5.x folosește max_completion_tokens (max_tokens e respins).
            'max_completion_tokens' => $max_tokens,
        );

        // Modelele GPT-5.x resping temperature custom; effort-ul se mapează direct.
        if (!empty($m['effort'])) {
            $effort = apply_filters('cw_effort', get_option('cw_effort', 'medium'), $model_id);
            if (!in_array($effort, array('low', 'medium', 'high'), true)) { $effort = 'medium'; }
            $payload['reasoning_effort'] = $effort;
        }

        if ($stream) {
            $payload['stream'] = true;
            // Cere blocul de usage în ultimul chunk, pentru cost tracking.
            $payload['stream_options'] = array('include_usage' => true);
        }

        return $payload;
    }

    /**
     * Generare OpenAI CU căutare web, prin Responses API.
     *
     * Diferă de Chat Completions: promptul merge în `input`, system-ul în
     * `instructions`, iar răspunsul vine ca listă `output[]` cu blocuri
     * `web_search_call` (căutările făcute) și `message` (textul, cu citări în
     * `annotations`). Nu facem continuare prin prefill aici — dăm de la început
     * `max_output_tokens` generos, fiindcă un al doilea apel ar relua căutările.
     */
    private static function generate_openai_web($model_id, $user_prompt, $max_tokens = null, $rescue = false) {
        if ($max_tokens === null) { $max_tokens = (int) get_option('cw_max_tokens', 8000); }
        $max_tokens = max(512, min(16000, (int) $max_tokens));

        // $user_prompt poate fi array de mesaje (continuare) — aici îl aplatizăm.
        $input = is_array($user_prompt)
            ? implode("\n\n", array_map(function ($m) {
                return is_array($m) && isset($m['content']) ? (string) $m['content'] : (string) $m;
            }, $user_prompt))
            : (string) $user_prompt;

        // PLAFONUL de căutări la OpenAI cere DOUĂ lucruri, măsurat pe gpt-5.4-mini cu
        // aceeași întrebare (august 2026):
        //   fără nimic                      -> 4 apeluri / 11 interogări
        //   doar max_tool_calls = 1         -> 2 apeluri /  8 interogări (doar reduce)
        //   max_tool_calls + instrucțiune   -> 1 apel    /  1 interogare
        // `max_uses` (sintaxa Anthropic) e respins de OpenAI cu 400.
        $cap = self::web_search_max();
        $input = sprintf(
            'LIMITĂ: fă cel mult %d %s web în total, apoi scrie exclusiv din ce ai găsit.',
            $cap,
            $cap === 1 ? 'căutare' : 'căutări'
        ) . "\n\n" . $input;

        $payload = array(
            'model'             => $model_id,
            'input'             => $input,
            'tools'             => array(array('type' => 'web_search')),
            'max_tool_calls'    => $cap,
            'max_output_tokens' => $max_tokens,
        );
        $system = self::system_prompt();
        if ($system !== '') { $payload['instructions'] = $system; }

        // Runda de salvare (primul apel n-a scos text): gandire minima, ca tot bugetul
        // de iesire sa ramana pentru articol.
        if ($rescue) {
            $mm = CW_Models::get($model_id);
            if (!empty($mm['effort'])) { $payload['reasoning'] = array('effort' => 'low'); }
        }

        $response = wp_remote_post(self::openai_url('responses'), array(
            'headers' => self::openai_headers(),
            'body'    => wp_json_encode($payload),
            'timeout' => 300, // căutările adaugă zeci de secunde
        ));

        if (is_wp_error($response)) {
            return new WP_Error('cw_http', __('Eroare de conexiune: ', 'claude-writer') . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) && isset($body['error']['message'])
                ? $body['error']['message']
                : __('Răspuns invalid de la API.', 'claude-writer');
            self::maybe_credit_alert('openai', $msg);
            return new WP_Error('cw_api', sprintf(__('Eroare API (%d): %s', 'claude-writer'), $code, $msg));
        }

        $text     = '';
        $searches = 0;   // apeluri de căutare (unitatea taxată)
        $queries  = 0;   // interogări trimise (un apel poate grupa mai multe)
        foreach ((isset($body['output']) && is_array($body['output'])) ? $body['output'] : array() as $item) {
            $type = isset($item['type']) ? $item['type'] : '';
            if ($type === 'web_search_call') {
                $searches++;
                if (isset($item['action']['queries']) && is_array($item['action']['queries'])) {
                    $queries += count($item['action']['queries']);
                } else {
                    $queries++;
                }
                continue;
            }
            if ($type === 'message' && isset($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $c) {
                    if (isset($c['text'])) { $text .= $c['text']; }
                }
            }
        }

        $usage = self::normalize_responses_usage(isset($body['usage']) ? $body['usage'] : array());
        $cost  = CW_Usage::log($model_id, $usage);
        if ($searches > 0) {
            $cost += CW_Usage::log_extra($model_id, $searches * self::web_search_cost());
        }

        // La Responses API, tokenii de raționament intră în max_output_tokens: dacă
        // răspunsul iese „incomplete", textul e tăiat — o raportăm, să se vadă în coadă.
        $incomplete = (isset($body['status']) && $body['status'] === 'incomplete');

        // Zero caractere: cautarea web + rationamentul au mancat tot plafonul de iesire.
        // Reincercam o data cu plafon maxim si efort minim, apoi raportam cauza.
        if (trim($text) === '') {
            if (!$rescue) {
                $retry = self::generate_openai_web($model_id, $user_prompt, 16000, true);
                if (!is_wp_error($retry)) {
                    $retry['cost'] += $cost; // apelul gol ramane platit, il pastram in total
                    return $retry;
                }
                $d = $retry->get_error_data();
                $retry->add_data(array('cost' => $cost + (is_array($d) && isset($d['cost']) ? (float) $d['cost'] : 0)));
                return $retry;
            }
            $reason = isset($body['incomplete_details']['reason']) ? (string) $body['incomplete_details']['reason'] : '';
            return new WP_Error('cw_empty', sprintf(
                /* translators: 1: status, 2: tokeni de iesire */
                __('Modelul n-a întors niciun text (status=%1$s%2$s, %3$d tokeni de ieșire). Căutarea web plus raționamentul au consumat tot plafonul — mărește „Tokeni maximi” sau oprește căutarea web pentru acest lot.', 'claude-writer'),
                isset($body['status']) ? (string) $body['status'] : '?',
                $reason !== '' ? '/' . $reason : '',
                isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0
            ), array('cost' => $cost));
        }

        return array(
            'text'       => trim($text),
            'usage'      => $usage,
            'cost'       => $cost,
            'searches'   => $searches,
            'queries'    => $queries,
            'incomplete' => $incomplete,
        );
    }

    /** Usage-ul Responses API -> formatul intern (input/output, cache separat). */
    public static function normalize_responses_usage($usage) {
        $in     = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0;
        $out    = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0;
        $cached = isset($usage['input_tokens_details']['cached_tokens'])
            ? (int) $usage['input_tokens_details']['cached_tokens'] : 0;
        return array(
            'input_tokens'                => max(0, $in - $cached),
            'output_tokens'               => $out,
            'cache_read_input_tokens'     => $cached,
            'cache_creation_input_tokens' => 0,
        );
    }

    /** Normalizează usage-ul OpenAI la formatul intern (cel Anthropic). */
    public static function normalize_openai_usage($usage) {
        $prompt = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
        $out    = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;
        $cached = isset($usage['prompt_tokens_details']['cached_tokens'])
            ? (int) $usage['prompt_tokens_details']['cached_tokens'] : 0;

        // prompt_tokens INCLUDE tokenii cache-uiți; îi separăm ca să nu-i taxăm dublu
        // (cache read la OpenAI costă ~10% din input — aceeași formulă din CW_Models::cost).
        return array(
            'input_tokens'                => max(0, $prompt - $cached),
            'output_tokens'               => $out,
            'cache_read_input_tokens'     => $cached,
            'cache_creation_input_tokens' => 0,
        );
    }

    /* ---- Alertă „credite epuizate" ---- */

    /**
     * Detectează erorile de tip „nu mai ai credit" (OpenAI: insufficient_quota;
     * Anthropic: credit balance is too low) și anunță adminul: e-mail (max unul
     * la 3 zile per provider, altfel cronul zilnic ar spama) + banner în wp-admin
     * (afișat de CW_Admin cât timp există opțiunea de eroare).
     */
    public static function maybe_credit_alert($provider, $message) {
        $m = strtolower((string) $message);
        $is_credit = $provider === 'openai'
            ? (strpos($m, 'insufficient_quota') !== false || strpos($m, 'exceeded your current quota') !== false)
            : (strpos($m, 'credit balance') !== false);
        if (!$is_credit) { return; }

        update_option('cw_credit_error_' . $provider, array('time' => time(), 'message' => (string) $message), 'no');

        $last = (int) get_option('cw_credit_mail_' . $provider, 0);
        if (time() - $last < 3 * DAY_IN_SECONDS) { return; }
        update_option('cw_credit_mail_' . $provider, time(), 'no');

        $label   = $provider === 'openai' ? 'OpenAI (GPT)' : 'Anthropic (Claude)';
        $host    = wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = sprintf('[%s] Creditele API %s s-au terminat', $host, $label);
        $body    = sprintf(
            "Generarea AI a eșuat pe %s pentru că a rămas fără credit cheia API %s.\n\n"
            . "Eroarea primită de la API:\n%s\n\n"
            . "Ce e de făcut: alimentează contul %s. După alimentare, generarea (articole + horoscop zilnic) își revine singură, nu trebuie schimbat nimic în WordPress.\n\n"
            . "Notă: e-mailul acesta se trimite cel mult o dată la 3 zile per site; până la alimentare vezi și un banner roșu în wp-admin.",
            home_url(), $label, $message, $label
        );
        // Implicit toate alertele merg la adresa centrală; per site se poate schimba din Setări.
        $to = sanitize_email((string) get_option('cw_alert_email', 'kreatorteam@gmail.com'));
        if ($to === '') { $to = 'kreatorteam@gmail.com'; }
        wp_mail($to, $subject, $body);
    }

    /** La prima generare reușită, alerta se stinge singură. */
    public static function clear_credit_alert($provider) {
        if (get_option('cw_credit_error_' . $provider)) {
            delete_option('cw_credit_error_' . $provider);
            delete_option('cw_credit_mail_' . $provider);
        }
    }

    /* ---- Generare non-streaming ---- */

    /**
     * Apel non-streaming, orice provider. Returnează array('text','usage','cost') sau WP_Error.
     */
    public static function generate($model_id, $user_prompt, $max_tokens = null) {
        if (!self::has_key()) {
            return new WP_Error('cw_no_key', __('Nicio cheie API configurată (OpenAI sau Anthropic).', 'claude-writer'));
        }
        if (CW_Usage::limit_reached()) {
            return new WP_Error('cw_limit', __('Ai atins limita lunară de cheltuieli. Mărește-o din setări.', 'claude-writer'));
        }

        $model_id = self::resolve_model($model_id);
        $provider = CW_Models::provider($model_id);
        if (!self::has_provider_key($provider)) {
            return new WP_Error('cw_no_key', sprintf(
                __('Cheia API %s nu este configurată.', 'claude-writer'),
                $provider === 'openai' ? 'OpenAI' : 'Anthropic'
            ));
        }

        $result = $provider === 'openai'
            ? self::generate_openai($model_id, $user_prompt, $max_tokens)
            : self::generate_anthropic($model_id, $user_prompt, $max_tokens);

        if (!is_wp_error($result)) {
            self::clear_credit_alert($provider); // generare reușită -> alerta se stinge
        }
        return $result;
    }

    private static function generate_anthropic($model_id, $user_prompt, $max_tokens = null) {
        // Continuăm automat cât timp răspunsul se taie la „max_tokens", ca articolul să fie
        // întotdeauna terminat. 1 apel inițial + maximum 2 continuări prin prefill „assistant".
        $messages   = array(array('role' => 'user', 'content' => $user_prompt));
        $full       = '';
        $cost       = 0.0;
        $last_usage = array();
        $max_rounds = 3;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = self::build_payload($model_id, $messages, false, $max_tokens);

            $response = wp_remote_post(self::ENDPOINT, array(
                'headers' => self::headers(),
                'body'    => wp_json_encode($payload),
                'timeout' => 180,
            ));

            if (is_wp_error($response)) {
                if ($full !== '') { break; } // păstrăm textul parțial deja obținut
                return new WP_Error('cw_http', __('Eroare de conexiune: ', 'claude-writer') . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200 || !is_array($body)) {
                if ($full !== '') { break; }
                $msg = is_array($body) && isset($body['error']['message'])
                    ? $body['error']['message']
                    : __('Răspuns invalid de la API.', 'claude-writer');
                self::maybe_credit_alert('anthropic', $msg);
                return new WP_Error('cw_api', sprintf(__('Eroare API (%d): %s', 'claude-writer'), $code, $msg));
            }

            // Extrage textul din blocurile de conținut.
            $text = '';
            if (isset($body['content']) && is_array($body['content'])) {
                foreach ($body['content'] as $block) {
                    if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                        $text .= $block['text'];
                    }
                }
            }

            $usage      = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : array();
            $last_usage = $usage;
            $cost      += CW_Usage::log($model_id, $usage);
            $full      .= $text;

            // Căutările web se taxează per apel, nu în tokeni.
            $searches = isset($usage['server_tool_use']['web_search_requests'])
                ? (int) $usage['server_tool_use']['web_search_requests'] : 0;
            if ($searches > 0) {
                $cost += CW_Usage::log_extra($model_id, $searches * self::web_search_cost());
            }

            $stop = isset($body['stop_reason']) ? $body['stop_reason'] : '';
            if ($stop !== 'max_tokens') {
                break; // articol terminat natural
            }

            // S-a tăiat: continuăm cu prefill — modelul continuă exact de unde a rămas.
            $full = rtrim($full);
            if ($full === '') { break; }
            $messages = array(
                array('role' => 'user',      'content' => $user_prompt),
                array('role' => 'assistant', 'content' => $full),
            );
        }

        return array(
            'text'  => trim($full),
            'usage' => $last_usage,
            'cost'  => $cost,
        );
    }

    private static function generate_openai($model_id, $user_prompt, $max_tokens = null) {
        // Cu căutare web mergem pe Responses API (singurul care acceptă unealta).
        if (self::web_search_on($model_id)) {
            return self::generate_openai_web($model_id, $user_prompt, $max_tokens);
        }

        // Aceeași garanție de articol complet: dacă răspunsul se taie la limita de tokeni
        // (finish_reason=length), cerem continuarea. 1 apel inițial + maximum 2 continuări.
        $base_messages = is_array($user_prompt)
            ? $user_prompt
            : array(array('role' => 'user', 'content' => $user_prompt));
        $messages   = $base_messages;
        $full       = '';
        $cost       = 0.0;
        $last_usage = array();
        $max_rounds = 4;
        $rescue     = false; // a doua sansa, cand primul apel n-a scos niciun caracter

        for ($round = 0; $round < $max_rounds; $round++) {
            // La modelele cu rationament, tokenii de gandire intra in plafonul de iesire:
            // daca modelul consuma tot bugetul „gandind", raspunsul vine GOL, dar taxat.
            // In runda de salvare urcam plafonul la maxim si taiem efortul la minim.
            $payload = self::build_openai_payload($model_id, $messages, false, $rescue ? 16000 : $max_tokens);
            if ($rescue && isset($payload['reasoning_effort'])) {
                $payload['reasoning_effort'] = 'low';
            }

            $response = wp_remote_post(self::openai_url('chat/completions'), array(
                'headers' => self::openai_headers(),
                'body'    => wp_json_encode($payload),
                'timeout' => 180,
            ));

            if (is_wp_error($response)) {
                if ($full !== '') { break; }
                return new WP_Error('cw_http', __('Eroare de conexiune: ', 'claude-writer') . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200 || !is_array($body)) {
                if ($full !== '') { break; }
                $msg = is_array($body) && isset($body['error']['message'])
                    ? $body['error']['message']
                    : __('Răspuns invalid de la API.', 'claude-writer');
                self::maybe_credit_alert('openai', $msg);
                return new WP_Error('cw_api', sprintf(__('Eroare API (%d): %s', 'claude-writer'), $code, $msg));
            }

            $choice  = isset($body['choices'][0]) ? $body['choices'][0] : array();
            $text    = isset($choice['message']['content']) ? (string) $choice['message']['content'] : '';
            $finish  = isset($choice['finish_reason']) ? $choice['finish_reason'] : '';
            $refusal = isset($choice['message']['refusal']) ? trim((string) $choice['message']['refusal']) : '';

            $usage      = self::normalize_openai_usage(isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : array());
            $last_usage = $usage;
            $cost      += CW_Usage::log($model_id, $usage);
            $full      .= $text;

            // Raspuns fara niciun caracter: reincercam O DATA cu plafon maxim si efort
            // minim; daca nici asa nu iese text, raportam cauza exacta in coada.
            if (trim($full) === '') {
                if (!$rescue) {
                    $rescue   = true;
                    $messages = $base_messages;
                    continue;
                }
                return new WP_Error('cw_empty', sprintf(
                    /* translators: 1: finish_reason, 2: tokeni de iesire, 3: refuz eventual */
                    __('Modelul n-a întors niciun text (finish_reason=%1$s, %2$d tokeni de ieșire%3$s). La modelele cu raționament tokenii de gândire intră în plafonul de ieșire — mărește „Tokeni maximi” sau scade efortul de raționament.', 'claude-writer'),
                    $finish !== '' ? $finish : '?',
                    isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
                    $refusal !== '' ? ', refuz: ' . $refusal : ''
                ), array('cost' => $cost));
            }

            if ($finish !== 'length') {
                break; // articol terminat natural
            }

            $full = rtrim($full);
            if ($full === '') { break; }
            // OpenAI nu are prefill: dăm textul parțial ca mesaj assistant + instrucțiune
            // explicită de continuare fără repetiții.
            $messages   = $base_messages;
            $messages[] = array('role' => 'assistant', 'content' => $full);
            $messages[] = array('role' => 'user', 'content' => 'Continuă EXACT de unde ai rămas, fără să repeți nimic din ce ai scris deja și fără nicio introducere. Doar continuarea textului, în același format HTML.');
        }

        return array(
            'text'  => trim($full),
            'usage' => $last_usage,
            'cost'  => $cost,
        );
    }
}
