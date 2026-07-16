<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Evidența utilizării și a costurilor (per model, total și lunar) + limita lunară.
 */
class CW_Usage {

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
