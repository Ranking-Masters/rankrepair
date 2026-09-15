<?php
/**
 * Instellingen van de Interne Links add-on, op één plek.
 *
 * De standaardwaarden zijn bewust conservatief: liever een paar goede links dan
 * een pagina vol. Alles is per site aanpasbaar in het Instellingen-tabblad en
 * per installatie via filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Config {

    private static $defaults = [
        // Hoeveel links we per ronde aan één bronpagina toevoegen.
        'max_links_per_source'  => 2,
        // Hoeveel nieuwe inkomende links één doelpagina per ronde mag krijgen.
        'max_links_per_target'  => 3,
        // Bovengrens op interne linkdichtheid: links per 100 woorden.
        'density_per_100w'      => 1.0,
        // Hoe vaak exact dezelfde ankertekst naar hetzelfde doel mag wijzen.
        'max_same_anchor'       => 3,
        // Aantal suggesties dat de planner per doelpagina probeert te vinden.
        'suggestions_per_target' => 3,
        // Welke plaatsingsmodi mogen.
        'mode_wrap'             => 1,
        'mode_rewrite'          => 1,
        'mode_clause'           => 0,
        // AI gebruiken voor rewrite/clause. Zonder key valt alles terug op wrap.
        'use_ai'                => 1,
    ];

    public static function get($key) {
        if (!isset(self::$defaults[$key])) {
            return null;
        }
        $value = get_option('rr_il_' . $key, self::$defaults[$key]);
        if (is_float(self::$defaults[$key])) {
            $value = (float) $value;
        } elseif (is_int(self::$defaults[$key])) {
            $value = (int) $value;
        }
        return apply_filters('rr_il_config_' . $key, $value);
    }

    public static function set($key, $value) {
        if (!isset(self::$defaults[$key])) {
            return false;
        }
        return update_option('rr_il_' . $key, $value);
    }

    public static function all() {
        $out = [];
        foreach (array_keys(self::$defaults) as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }

    public static function defaults() {
        return self::$defaults;
    }

    /** Toegestane plaatsingsmodi, in volgorde van voorkeur. */
    public static function modes() {
        $modes = [];
        if (self::get('mode_wrap'))    { $modes[] = 'wrap'; }
        if (self::get('mode_rewrite')) { $modes[] = 'rewrite'; }
        if (self::get('mode_clause'))  { $modes[] = 'clause'; }
        return $modes;
    }

    /** Is er een bruikbare AI-configuratie? */
    public static function ai_available() {
        if (!self::get('use_ai') || !function_exists('rr_decrypt_key')) {
            return false;
        }
        return rr_decrypt_key(get_option('rr_gemini_api_key', '')) !== '';
    }
}
