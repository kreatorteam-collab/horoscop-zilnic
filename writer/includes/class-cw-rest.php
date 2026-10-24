<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Endpoint de streaming (SSE). Primește acțiunea, apelează provider-ul modelului
 * (OpenAI sau Anthropic) cu stream=true, parsează evenimentele și retransmite
 * text normalizat către browser: data: {"text":"..."}.
 */
class CW_Rest {

    public function init() {
        add_action('rest_api_init', array($this, 'register'));
    }

    public function register() {
        register_rest_route('claude-writer/v1', '/stream', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'stream'),
            'permission_callback' => function (WP_REST_Request $request) {
                $nonce = $request->get_header('X-WP-Nonce');
                if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
                    return current_user_can('edit_posts');
                }
                return is_user_logged_in() && current_user_can('edit_posts');
            },
        ));
    }

    public function stream(WP_REST_Request $request) {
        // Eliberăm imediat lock-ul de sesiune PHP: acest request de streaming ține workerul
        // ocupat 30–90s; dacă sesiunea rămâne deschisă (ex. session.auto_start pe server),
        // heartbeat-ul wp-admin al aceluiași user se blochează pe lock → „Conexiune pierdută"
        // + salvare dezactivată. Generarea nu mai are nevoie de sesiune după acest punct.
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        if (!wp_verify_nonce($request->get_param('nonce'), 'cw_nonce')) {
            return new WP_Error('forbidden', __('Eroare de securitate.', 'claude-writer'), array('status' => 403));
        }
        if (!CW_API::has_key()) {
            return new WP_Error('bad_request', __('Cheia API nu este configurată.', 'claude-writer'), array('status' => 400));
        }
        if (CW_Usage::limit_reached()) {
            return new WP_Error('bad_request', __('Limita lunară de cheltuieli a fost atinsă.', 'claude-writer'), array('status' => 400));
        }

        $model   = sanitize_text_field((string) $request->get_param('model'));
        $action  = sanitize_key((string) $request->get_param('act'));
        $subject = sanitize_textarea_field((string) $request->get_param('subject'));
        $content = wp_kses_post((string) $request->get_param('content'));
        $length  = (int) $request->get_param('length');

        $model = CW_API::resolve_model($model);
        if (!$action) { $action = 'article'; }

        $prompt = CW_Editor::build_prompt($action, $subject, $content, $length);

        // Pregătește SSE către browser.
        nocache_headers();
        @set_time_limit(0);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @ob_implicit_flush(true);
        @ini_set('zlib.output_compression', 0);

        echo "event: open\n";
        echo "data: ok\n\n";
        @flush();

        if (!function_exists('curl_init')) {
            $this->sse_error(__('cURL indisponibil pe server.', 'claude-writer'));
            exit;
        }

        if (CW_Models::provider($model) === 'openai') {
            $this->stream_openai($model, $prompt);
        } else {
            $this->stream_anthropic($model, $prompt);
        }
        exit;
    }

    /* ---- Anthropic (Messages API, SSE) ---- */

    private function stream_anthropic($model, $prompt) {
        // Continuăm automat cu prefill cât timp răspunsul se taie la „max_tokens", ca
        // articolul livrat să fie întotdeauna complet. 1 apel inițial + max 2 continuări.
        $messages   = array(array('role' => 'user', 'content' => $prompt));
        $fullText   = '';
        $totalCost  = 0.0;
        $totalUsage = array(
            'input_tokens' => 0, 'output_tokens' => 0,
            'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0,
        );
        $max_rounds = 3;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = CW_API::build_payload($model, $messages, true);

            // Stare colectată din evenimentele Anthropic pentru runda curentă.
            $state = array(
                'buffer' => '',
                'text'   => '',
                'stop'   => '',
                'usage'  => array(
                    'input_tokens' => 0, 'output_tokens' => 0,
                    'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0,
                ),
            );

            $ch = curl_init(CW_API::ENDPOINT);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-api-key: ' . CW_API::api_key(),
                'anthropic-version: ' . CW_API::VERSION,
                'content-type: application/json',
                'accept: text/event-stream',
            ));
            curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$state) {
                $state['buffer'] .= $chunk;
                while (($pos = strpos($state['buffer'], "\n")) !== false) {
                    $line = rtrim(substr($state['buffer'], 0, $pos), "\r");
                    $state['buffer'] = substr($state['buffer'], $pos + 1);
                    if (strpos($line, 'data:') !== 0) { continue; }

                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') { continue; }

                    $json = json_decode($data, true);
                    if (!is_array($json) || !isset($json['type'])) { continue; }

                    switch ($json['type']) {
                        case 'message_start':
                            $u = isset($json['message']['usage']) ? $json['message']['usage'] : array();
                            $state['usage']['input_tokens']                = isset($u['input_tokens']) ? (int) $u['input_tokens'] : 0;
                            $state['usage']['cache_read_input_tokens']     = isset($u['cache_read_input_tokens']) ? (int) $u['cache_read_input_tokens'] : 0;
                            $state['usage']['cache_creation_input_tokens'] = isset($u['cache_creation_input_tokens']) ? (int) $u['cache_creation_input_tokens'] : 0;
                            break;

                        case 'content_block_delta':
                            $t = (isset($json['delta']['type']) && $json['delta']['type'] === 'text_delta' && isset($json['delta']['text']))
                                ? $json['delta']['text'] : '';
                            if ($t !== '') {
                                $state['text'] .= $t;
                                echo 'data: ' . wp_json_encode(array('text' => $t)) . "\n\n";
                                if (ob_get_level()) { @ob_flush(); }
                                @flush();
                            }
                            break;

                        case 'message_delta':
                            if (isset($json['usage']['output_tokens'])) {
                                $state['usage']['output_tokens'] = (int) $json['usage']['output_tokens'];
                            }
                            if (isset($json['delta']['stop_reason'])) {
                                $state['stop'] = $json['delta']['stop_reason'];
                            }
                            break;

                        case 'error':
                            $msg = isset($json['error']['message']) ? $json['error']['message'] : 'eroare API';
                            CW_API::maybe_credit_alert('anthropic', $msg);
                            echo "event: error\n";
                            echo 'data: ' . wp_json_encode(array('message' => $msg)) . "\n\n";
                            @flush();
                            break;
                    }
                }
                return strlen($chunk);
            });

            $ok = curl_exec($ch);
            if ($ok === false) {
                // Eroare de transport: dacă n-avem nimic deloc, raportăm; altfel păstrăm ce avem.
                if ($fullText === '' && $state['text'] === '') {
                    $this->sse_error(curl_error($ch));
                    curl_close($ch);
                    exit;
                }
                curl_close($ch);
                $fullText .= $state['text'];
                break;
            }
            curl_close($ch);

            $fullText  .= $state['text'];
            $totalCost += CW_Usage::log($model, $state['usage']);
            foreach (array_keys($totalUsage) as $k) {
                $totalUsage[$k] += isset($state['usage'][$k]) ? (int) $state['usage'][$k] : 0;
            }

            if ($state['stop'] !== 'max_tokens') {
                break; // articol terminat natural
            }

            // S-a tăiat: continuăm cu prefill — modelul continuă de unde a rămas,
            // iar browserul primește în continuare deltele și le adaugă la text.
            $fullText = rtrim($fullText);
            if ($fullText === '') { break; }
            $messages = array(
                array('role' => 'user',      'content' => $prompt),
                array('role' => 'assistant', 'content' => $fullText),
            );
        }

        echo "event: done\n";
        echo 'data: ' . wp_json_encode(array('cost' => $totalCost, 'usage' => $totalUsage)) . "\n\n";
        @flush();
    }

    /* ---- OpenAI (Chat Completions, SSE) ---- */

    private function stream_openai($model, $prompt) {
        // Aceeași garanție de articol complet: continuăm la finish_reason=length.
        $base_messages = array(array('role' => 'user', 'content' => $prompt));
        $messages   = $base_messages;
        $fullText   = '';
        $totalCost  = 0.0;
        $totalUsage = array(
            'input_tokens' => 0, 'output_tokens' => 0,
            'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0,
        );
        $max_rounds = 3;

        for ($round = 0; $round < $max_rounds; $round++) {
            $payload = CW_API::build_openai_payload($model, $messages, true);

            $state = array(
                'buffer' => '',
                'text'   => '',
                'finish' => '',
                'usage'  => array(),
            );

            $ch = curl_init(CW_API::openai_url('chat/completions'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . CW_API::openai_key(),
                'content-type: application/json',
                'accept: text/event-stream',
            ));
            curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$state) {
                $state['buffer'] .= $chunk;
                while (($pos = strpos($state['buffer'], "\n")) !== false) {
                    $line = rtrim(substr($state['buffer'], 0, $pos), "\r");
                    $state['buffer'] = substr($state['buffer'], $pos + 1);
                    if (strpos($line, 'data:') !== 0) { continue; }

                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') { continue; }

                    $json = json_decode($data, true);
                    if (!is_array($json)) { continue; }

                    // Erorile OpenAI vin ca obiect {"error":{...}} chiar și pe stream.
                    if (isset($json['error'])) {
                        $msg = isset($json['error']['message']) ? $json['error']['message'] : 'eroare API';
                        CW_API::maybe_credit_alert('openai', $msg);
                        echo "event: error\n";
                        echo 'data: ' . wp_json_encode(array('message' => $msg)) . "\n\n";
                        @flush();
                        continue;
                    }

                    if (isset($json['choices'][0]['delta']['content']) && $json['choices'][0]['delta']['content'] !== '') {
                        $t = (string) $json['choices'][0]['delta']['content'];
                        $state['text'] .= $t;
                        echo 'data: ' . wp_json_encode(array('text' => $t)) . "\n\n";
                        if (ob_get_level()) { @ob_flush(); }
                        @flush();
                    }
                    if (isset($json['choices'][0]['finish_reason']) && $json['choices'][0]['finish_reason'] !== null) {
                        $state['finish'] = (string) $json['choices'][0]['finish_reason'];
                    }
                    // Ultimul chunk (stream_options.include_usage) aduce usage-ul total.
                    if (isset($json['usage']) && is_array($json['usage'])) {
                        $state['usage'] = $json['usage'];
                    }
                }
                return strlen($chunk);
            });

            $ok = curl_exec($ch);
            if ($ok === false) {
                if ($fullText === '' && $state['text'] === '') {
                    $this->sse_error(curl_error($ch));
                    curl_close($ch);
                    exit;
                }
                curl_close($ch);
                $fullText .= $state['text'];
                break;
            }
            curl_close($ch);

            $fullText .= $state['text'];
            $usage     = CW_API::normalize_openai_usage($state['usage']);
            $totalCost += CW_Usage::log($model, $usage);
            foreach (array_keys($totalUsage) as $k) {
                $totalUsage[$k] += isset($usage[$k]) ? (int) $usage[$k] : 0;
            }

            if ($state['finish'] !== 'length') {
                break; // articol terminat natural
            }

            $fullText = rtrim($fullText);
            if ($fullText === '') { break; }
            $messages   = $base_messages;
            $messages[] = array('role' => 'assistant', 'content' => $fullText);
            $messages[] = array('role' => 'user', 'content' => 'Continuă EXACT de unde ai rămas, fără să repeți nimic din ce ai scris deja și fără nicio introducere. Doar continuarea textului, în același format HTML.');
        }

        echo "event: done\n";
        echo 'data: ' . wp_json_encode(array('cost' => $totalCost, 'usage' => $totalUsage)) . "\n\n";
        @flush();
    }

    private function sse_error($message) {
        echo "event: error\n";
        echo 'data: ' . wp_json_encode(array('message' => $message)) . "\n\n";
        @flush();
    }
}
