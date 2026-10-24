<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Registrul modelelor disponibile (OpenAI GPT-5.6 + Claude): preț, capabilități, etichete.
 * Sursă prețuri ($/1M tokeni): GPT-5.6 Sol 5/30, Terra 2.5/15, Luna 1/6 (lansate 2026-07-09);
 * Haiku 4.5 = 1/5, Sonnet 4.6 = 3/15, Opus 4.8 = 5/25.
 */
class CW_Models {

    /**
     * v3.51.2: modelele serverului GPT configurat (gateway compatibil OpenAI, ex.
     * creator.ms / api.ns.fm), salvate de butonul „Vezi modelele disponibile". Apar în
     * dropdown-uri într-un grup separat; prețul nu-l știm, deci costul lor e 0 în rapoarte.
     */
    public static function gateway() {
        $ids = get_option('cw_gateway_models', array());
        if (!is_array($ids) || trim((string) get_option('cw_openai_base', '')) === '') { return array(); }
        $out = array();
        $builtin = self::builtin(); // modelele cunoscute își păstrează eticheta și prețul
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '' || isset($out[$id])) { continue; }
            if (isset($builtin[$id])) { $out[$id] = $builtin[$id] + array('gateway' => true); continue; }
            $out[$id] = array(
                'label' => $id, 'tagline' => 'Server API', 'desc' => 'Model de pe serverul API configurat.',
                'provider' => 'openai', 'in' => 0.0, 'out' => 0.0,
                'temperature' => false, 'effort' => false, 'badge' => 'server', 'gateway' => true,
            );
        }
        return $out;
    }

    /** Eticheta grupului din dropdown pentru modelele gateway-ului (ex. „creator.ms (api.ns.fm)"). */
    public static function gateway_label() {
        $host = (string) wp_parse_url((string) get_option('cw_openai_base', ''), PHP_URL_HOST);
        return 'creator.ms' . ($host !== '' ? ' (' . $host . ')' : '');
    }

    public static function all() {
        $all = self::builtin();
        foreach (self::gateway() as $id => $m) { $all[$id] = $m; }
        return $all;
    }

    public static function builtin() {
        return array(
            'gpt-5.6-sol' => array(
                'label'    => 'GPT-5.6 Sol',
                'tagline'  => 'Flagship OpenAI',
                'desc'     => 'Cel mai capabil GPT. Pentru conținut premium și teme complexe.',
                'provider' => 'openai',
                'in'       => 5.0,
                'out'      => 30.0,
                'temperature' => false,  // modelele cu raționament resping temperature custom
                'effort'      => true,   // reasoning_effort: low | medium | high
                'badge'    => 'premium',
            ),
            'gpt-5.6-terra' => array(
                'label'    => 'GPT-5.6 Terra',
                'tagline'  => 'Echilibrat',
                'desc'     => 'Cel mai bun raport calitate/preț din familia GPT-5.6. Recomandat pentru articole.',
                'provider' => 'openai',
                'in'       => 2.5,
                'out'      => 15.0,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'echilibrat',
            ),
            'gpt-5.6-luna' => array(
                'label'    => 'GPT-5.6 Luna',
                'tagline'  => 'Ieftin & rapid',
                'desc'     => 'Cel mai ieftin GPT-5.6. Bun pentru volum mare (ex. horoscop zilnic).',
                'provider' => 'openai',
                'in'       => 1.0,
                'out'      => 6.0,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'ieftin',
            ),
            'gpt-5.5' => array(
                'label'    => 'GPT-5.5',
                'tagline'  => 'Flagship anterior',
                'desc'     => 'Generația anterioară de top (înaintea lui 5.6 Sol).',
                'provider' => 'openai',
                'in'       => 5.0,
                'out'      => 30.0,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'premium',
            ),
            'gpt-5.4' => array(
                'label'    => 'GPT-5.4',
                'tagline'  => 'Echilibrat (vechi)',
                'desc'     => 'Generația anterioară echilibrată (înaintea lui Terra).',
                'provider' => 'openai',
                'in'       => 2.5,
                'out'      => 15.0,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'echilibrat',
            ),
            'gpt-5.4-mini' => array(
                'label'    => 'GPT-5.4 Mini',
                'tagline'  => 'Foarte ieftin',
                'desc'     => 'Mic și foarte ieftin. Pentru volum mare, sarcini simple.',
                'provider' => 'openai',
                'in'       => 0.75,
                'out'      => 4.5,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'ieftin',
            ),
            'gpt-5-nano' => array(
                'label'    => 'GPT-5 Nano',
                'tagline'  => 'Cel mai ieftin',
                'desc'     => 'Cel mai ieftin și rapid GPT-5. Ideal pentru volum mare foarte simplu (horoscop, vreme).',
                'provider' => 'openai',
                'in'       => 0.05,
                'out'      => 0.40,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'ieftin',
            ),
            'gpt-5.1' => array(
                'label'    => 'GPT-5.1',
                'tagline'  => 'Linia GPT-5 clasică',
                'desc'     => 'Succesorul direct al lui GPT-5, la același preț.',
                'provider' => 'openai',
                'in'       => 1.25,
                'out'      => 10.0,
                'temperature' => false,
                'effort'      => true,
                'badge'    => 'ieftin',
            ),
            'claude-haiku-4-5' => array(
                'label'    => 'Claude Haiku 4.5',
                'tagline'  => 'Ieftin & rapid',
                'desc'     => 'Cel mai ieftin Claude. Bun pentru volum mare și articole generale.',
                'provider' => 'anthropic',
                'in'       => 1.0,
                'out'      => 5.0,
                'temperature' => true,   // acceptă temperature
                'effort'      => false,  // NU acceptă output_config.effort (dă eroare 400)
                'badge'    => 'ieftin',
            ),
            'claude-sonnet-4-6' => array(
                'label'    => 'Claude Sonnet 4.6',
                'tagline'  => 'Echilibrat',
                'desc'     => 'Cel mai bun raport calitate/preț din familia Claude.',
                'provider' => 'anthropic',
                'in'       => 3.0,
                'out'      => 15.0,
                'temperature' => true,
                'effort'      => true,
                'badge'    => 'echilibrat',
            ),
            'claude-opus-4-8' => array(
                'label'    => 'Claude Opus 4.8',
                'tagline'  => 'Calitate maximă',
                'desc'     => 'Cel mai capabil Claude. Pentru conținut premium care trebuie să convertească.',
                'provider' => 'anthropic',
                'in'       => 5.0,
                'out'      => 25.0,
                'temperature' => false,  // Opus 4.8 NU acceptă temperature/top_p (eroare 400)
                'effort'      => true,
                'badge'    => 'premium',
            ),
        );
    }

    public static function exists($id) {
        return array_key_exists($id, self::all());
    }

    public static function get($id) {
        $all = self::all();
        return isset($all[$id]) ? $all[$id] : null;
    }

    /** 'openai' sau 'anthropic' (implicit anthropic pentru ID-uri necunoscute). */
    public static function provider($id) {
        $m = self::get($id);
        return ($m && isset($m['provider'])) ? $m['provider'] : 'anthropic';
    }

    /** Modelul implicit configurat (cu fallback sigur). */
    public static function default_model() {
        $m = get_option('cw_default_model', 'gpt-5.6-terra');
        return self::exists($m) ? $m : 'gpt-5.6-terra';
    }

    /** Modelul implicit al unui provider (folosit ca rezervă când lipsește o cheie). */
    public static function provider_default($provider) {
        return $provider === 'anthropic' ? 'claude-sonnet-4-6' : 'gpt-5.6-terra';
    }

    /**
     * Cost în dolari pentru o utilizare dată.
     * cache_read ~0.1x input, cache_creation ~1.25x input (câmpurile lipsesc la OpenAI = 0).
     */
    public static function cost($model_id, $usage) {
        $m = self::get($model_id);
        if (!$m) { return 0.0; }
        $in    = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0;
        $out   = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0;
        $cread = isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : 0;
        $ccrea = isset($usage['cache_creation_input_tokens']) ? (int) $usage['cache_creation_input_tokens'] : 0;

        $cost  = ($in / 1000000.0) * $m['in'];
        $cost += ($out / 1000000.0) * $m['out'];
        $cost += ($cread / 1000000.0) * $m['in'] * 0.1;
        $cost += ($ccrea / 1000000.0) * $m['in'] * 1.25;
        return $cost;
    }
}
