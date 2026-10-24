<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Evidența utilizării și a costurilor (per model, total și lunar) + limita lunară.
 */
class CW_Usage {

    /** Câte zile de istoric zilnic păstrăm (opțiunea NU e autoload). */
    const DAYS_KEPT = 120;

    /** Contextul curent: ce modul cheltuiește acum. Vezi with_context(). */
    private static $ctx = '';

    /** Etichetele recunoscute, în ordinea în care le arătăm în rapoarte. */
    public static function contexts() {
        return array('bulk', 'review', 'imagini', 'stiri', 'horoscop', 'vreme', 'rewrite', 'editor', 'altele');
    }

    public static function context() {
        return self::$ctx !== '' ? self::$ctx : 'editor';
    }

    /**
     * Rulează $fn marcând tot ce se cheltuiește înăuntru cu eticheta $ctx.
     * Restaurează contextul anterior chiar dacă apelul aruncă excepție, ca un
     * micro-apel imbricat (ex. traducerea interogării de imagini din mijlocul
     * unui articol bulk) să nu lase eticheta greșită în urmă.
     */
    public static function with_context($ctx, callable $fn) {
        $prev = self::$ctx;
        self::$ctx = (string) $ctx;
        try {
            return $fn();
        } finally {
            self::$ctx = $prev;
        }
    }

    /** Adaugă costul în evidența pe ZI + modul + model. Opțiune fără autoload. */
    private static function log_daily($cost, $model_id, $tokens_in = 0, $tokens_out = 0) {
        $cost = (float) $cost;
        if ($cost <= 0 && $tokens_in <= 0 && $tokens_out <= 0) { return; }

        $day  = date('Y-m-d');
        $ctx  = self::context();
        $data = get_option('cw_cost_daily', array());
        if (!is_array($data)) { $data = array(); }

        if (!isset($data[$day]) || !is_array($data[$day])) {
            $data[$day] = array('cost' => 0.0, 'calls' => 0, 'in' => 0, 'out' => 0, 'ctx' => array(), 'models' => array());
        }
        $d = $data[$day];
        $d['cost']  = round((float) $d['cost'] + $cost, 6);
        $d['calls'] = (int) $d['calls'] + 1;
        $d['in']    = (int) $d['in'] + (int) $tokens_in;
        $d['out']   = (int) $d['out'] + (int) $tokens_out;

        if (!isset($d['ctx'][$ctx])) { $d['ctx'][$ctx] = array('cost' => 0.0, 'calls' => 0); }
        $d['ctx'][$ctx]['cost']  = round((float) $d['ctx'][$ctx]['cost'] + $cost, 6);
        $d['ctx'][$ctx]['calls'] = (int) $d['ctx'][$ctx]['calls'] + 1;

        if ($model_id !== '') {
            if (!isset($d['models'][$model_id])) { $d['models'][$model_id] = 0.0; }
            $d['models'][$model_id] = round((float) $d['models'][$model_id] + $cost, 6);
        }
        $data[$day] = $d;

        // Tăiem istoricul vechi: opțiunea trebuie să rămână mică (autoload-ul umflat
        // a fost deja o problemă reală de performanță pe flotă).
        if (count($data) > self::DAYS_KEPT) {
            krsort($data);
            $data = array_slice($data, 0, self::DAYS_KEPT, true);
            ksort($data);
        }
        update_option('cw_cost_daily', $data, 'no');
    }

    /** Istoricul zilnic, ultimele $days zile, cel mai recent primul. */
    public static function daily($days = 30) {
        $data = get_option('cw_cost_daily', array());
        if (!is_array($data)) { return array(); }
        krsort($data);
        $days = max(1, min(self::DAYS_KEPT, (int) $days));
        return array_slice($data, 0, $days, true);
    }

    /** Înregistrează o utilizare după un apel reușit. */
    public static function log($model_id, $usage) {
        $cost = CW_Models::cost($model_id, $usage);
        $in   = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0;
        $out  = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0;

        // Total apeluri + cost cumulat
        update_option('cw_calls_total', (int) get_option('cw_calls_total', 0) + 1);
        update_option('cw_cost_total', round((float) get_option('cw_cost_total', 0) + $cost, 6));

        // Cost lunar
        $month   = date('Y-m');
        $monthly = get_option('cw_cost_monthly', array());
        if (!is_array($monthly)) { $monthly = array(); }
        $monthly[$month] = round((isset($monthly[$month]) ? (float) $monthly[$month] : 0) + $cost, 6);
        update_option('cw_cost_monthly', $monthly);

        // Defalcare per model
        $by = get_option('cw_usage_by_model', array());
        if (!is_array($by)) { $by = array(); }
        if (!isset($by[$model_id])) {
            $by[$model_id] = array('calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0);
        }
        $by[$model_id]['calls'] += 1;
        $by[$model_id]['in']    += $in;
        $by[$model_id]['out']   += $out;
        $by[$model_id]['cost']   = round($by[$model_id]['cost'] + $cost, 6);
        update_option('cw_usage_by_model', $by);

        self::log_daily($cost, $model_id, $in, $out);

        return $cost;
    }

    /**
     * Cost care NU vine din tokeni: căutările web sunt taxate per apel, separat de
     * tokeni. Îl adăugăm în aceleași totaluri, ca limita lunară să-l vadă și ea.
     */
    public static function log_extra($model_id, $cost) {
        $cost = (float) $cost;
        if ($cost <= 0) { return 0.0; }

        update_option('cw_cost_total', round((float) get_option('cw_cost_total', 0) + $cost, 6));

        $month   = date('Y-m');
        $monthly = get_option('cw_cost_monthly', array());
        if (!is_array($monthly)) { $monthly = array(); }
        $monthly[$month] = round((isset($monthly[$month]) ? (float) $monthly[$month] : 0) + $cost, 6);
        update_option('cw_cost_monthly', $monthly);

        $by = get_option('cw_usage_by_model', array());
        if (is_array($by) && isset($by[$model_id])) {
            $by[$model_id]['cost'] = round($by[$model_id]['cost'] + $cost, 6);
            update_option('cw_usage_by_model', $by);
        }

        self::log_daily($cost, $model_id);

        return $cost;
    }

    public static function month_cost($month = null) {
        $month   = $month ? $month : date('Y-m');
        $monthly = get_option('cw_cost_monthly', array());
        return (is_array($monthly) && isset($monthly[$month])) ? (float) $monthly[$month] : 0.0;
    }

    /** True dacă s-a atins limita lunară de cheltuieli. */
    public static function limit_reached() {
        $limit = (float) get_option('cw_monthly_cost_limit', 0);
        if ($limit <= 0) { return false; }
        return self::month_cost() >= $limit;
    }
}
