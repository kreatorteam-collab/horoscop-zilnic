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

    /* ---- Chei ---- */

    public static function api_key() {
        // Cheia Anthropic, stocată criptat; o decriptăm la citire.
        return trim((string) CW_Crypto::decrypt(get_option('cw_api_key', '')));
    }

    public static function openai_key() {
        return trim((string) CW_Crypto::decrypt(get_option('cw_openai_api_key', '')));
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
            $effort = get_option('cw_effort', 'medium');
            if (!in_array($effort, array('low', 'medium', 'high'), true)) { $effort = 'medium'; }
            $payload['output_config'] = array('effort' => $effort);
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
            $effort = get_option('cw_effort', 'medium');
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
        // Aceeași garanție de articol complet: dacă răspunsul se taie la limita de tokeni
        // (finish_reason=length), cerem continuarea. 1 apel inițial + maximum 2 continuări.
        $base_messages = is_array($user_prompt)
            ? $user_prompt
            : array(array('role' => 'user', 'content' => $user_prompt));
        $messages   = $base_messages;
        $full       = '';
        $cost       = 0.0;
        $last_usage = array();
        $max_rounds = 3;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = self::build_openai_payload($model_id, $messages, false, $max_tokens);

            $response = wp_remote_post(self::OPENAI_ENDPOINT, array(
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

            $choice = isset($body['choices'][0]) ? $body['choices'][0] : array();
            $text   = isset($choice['message']['content']) ? (string) $choice['message']['content'] : '';
            $finish = isset($choice['finish_reason']) ? $choice['finish_reason'] : '';

            $usage      = self::normalize_openai_usage(isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : array());
            $last_usage = $usage;
            $cost      += CW_Usage::log($model_id, $usage);
            $full      .= $text;

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
