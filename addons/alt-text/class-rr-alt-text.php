<?php
/**
 * Alt-tekst — pure logica (geen WordPress-afhankelijkheden).
 *
 * Prompt bouwen, AI-respons parsen en de voorgestelde alt-tekst opschonen. Bewust
 * los van de addon-class zodat dit zonder WP unit-getest kan worden.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Alt_Text {

    /** Richtlengte (zacht) en absolute bovengrens (hard) voor een alt-tekst. */
    const SOFT_MAX = 125;
    const HARD_MAX = 200;

    /**
     * De instructie voor het vision-model. $hint = optionele context uit de
     * bestandsnaam/titel (merk- of productnaam), alleen te gebruiken als relevant.
     */
    public static function build_prompt(string $hint = ''): string {
        $p = 'Beschrijf deze afbeelding in één korte, feitelijke Nederlandse zin, '
           . 'bedoeld als alt-attribuut voor toegankelijkheid. '
           . 'Begin NIET met "afbeelding van", "foto van" of "plaatje van". '
           . 'Gebruik geen aanhalingstekens. Maximaal ongeveer ' . self::SOFT_MAX . ' tekens. '
           . 'Geen opsomming, geen keyword-stuffing, alleen wat zichtbaar is. '
           . 'Als het duidelijk een logo is, schrijf dan "Logo van <merknaam>".';
        if ($hint !== '') {
            $p .= ' Context ter ondersteuning (uit de bestandsnaam/titel), gebruik dit ALLEEN als het '
                . 'echt relevant is — bijvoorbeeld de merk- of productnaam bij een logo of productfoto — '
                . 'en negeer betekenisloze namen: "' . $hint . '".';
        }
        return $p;
    }

    /**
     * Maakt van een bestandsnaam een bruikbare context-hint (merk/onderwerp), of ''
     * als de naam niets zegt (DSC0001, IMG_1234, Group-2, screenshot, …).
     */
    public static function filename_hint(string $filename): string {
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $filename);   // extensie weg
        $name = preg_replace('/[_\-]+/', ' ', (string) $name);         // scheidingstekens -> spatie
        $name = preg_replace('/\b\d{2,4}x\d{2,4}\b/', ' ', $name);     // afmetingen (1024x768) weg
        $name = preg_replace('/\b(scaled|copy|kopie|final|def|edited|crop|cropped|klein|groot|web)\b/i', ' ', $name);
        $name = preg_replace('/\s+/', ' ', (string) $name);
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }
        // Betekenisloze camera-/exportnamen onderdrukken.
        if (preg_match('/^(img|dsc|dscf|images?|fotos?|photos?|screenshots?|schermafbeeldingen?|groups?|untitled|naamloos|pexels|unsplash|istock)\s*\d*$/i', $name)) {
            return '';
        }
        if (preg_match('/^[\d\s.]+$/', $name)) {   // alleen cijfers/punten over
            return '';
        }
        return $name;
    }

    /**
     * Schoont een rauwe AI-respons op tot een bruikbare alt-tekst: één regel, geen
     * omringende aanhalingstekens, geen "afbeelding van"-prefix, lengte begrensd.
     */
    public static function sanitize_alt(string $raw): string {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }

        // Alles op één regel; witruimte normaliseren.
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);

        // Omringende aanhalingstekens (recht + curly) en spaties weghalen.
        $s = preg_replace('/^[\"\'“”‘’«»\s]+/u', '', $s);
        $s = preg_replace('/[\"\'“”‘’«»\s]+$/u', '', $s);

        // Leidende "(een) afbeelding/foto/plaatje/image/picture/photo van/of" strippen.
        $s = preg_replace(
            '/^(?:een |de |het )?(?:afbeelding|afbeeldingen|foto\'?s?|plaatje|image|picture|photo)\s+(?:van|of)\s+/iu',
            '',
            $s
        );
        $s = trim($s);

        // Afsluitende punt(en) weg (alt-teksten krijgen geen zin-einde).
        $s = preg_replace('/[.\s]+$/u', '', $s);

        // Lengte begrenzen, bij voorkeur op een woordgrens.
        if (self::mb_len($s) > self::HARD_MAX) {
            $s = self::mb_cut($s, self::HARD_MAX);
            $sp = self::mb_rpos($s, ' ');
            if ($sp !== false && $sp > (int) (self::HARD_MAX * 0.6)) {
                $s = self::mb_cut($s, $sp);
            }
            $s = rtrim($s);
        }

        // Eerste letter als hoofdletter.
        return self::mb_ucfirst($s);
    }

    /** Haalt de tekst uit een OpenRouter (OpenAI-compatibele) respons. */
    public static function parse_openrouter($body): string {
        if (!is_array($body)) {
            return '';
        }
        $c = $body['choices'][0]['message']['content'] ?? '';
        return is_string($c) ? trim($c) : '';
    }

    /** Haalt de tekst uit een Google generateContent-respons. */
    public static function parse_google($body): string {
        if (!is_array($body)) {
            return '';
        }
        $c = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return is_string($c) ? trim($c) : '';
    }

    /** Vist een foutmelding uit een (mogelijk) fout-respons. */
    public static function error_message($body, int $code): string {
        if (is_array($body)) {
            if (!empty($body['error']['message']) && is_string($body['error']['message'])) {
                return $body['error']['message'];
            }
            if (!empty($body['error']) && is_string($body['error'])) {
                return $body['error'];
            }
        }
        return 'HTTP ' . $code;
    }

    /** Bouwt een data-URI voor een base64-gecodeerde afbeelding. */
    public static function data_uri(string $mime, string $base64): string {
        return 'data:' . $mime . ';base64,' . $base64;
    }

    /**
     * Kiest het meest geschikte beschikbare afbeeldingsformaat om naar de AI te sturen:
     * groot genoeg om te herkennen, klein genoeg om snel/goedkoop te verzenden.
     */
    public static function pick_size(array $available): string {
        foreach (['medium', 'medium_large', 'large', 'full'] as $size) {
            if (in_array($size, $available, true)) {
                return $size;
            }
        }
        return 'full';
    }

    // --- kleine multibyte-helpers (met nette fallback zonder mbstring) ---

    private static function mb_len(string $s): int {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function mb_cut(string $s, int $len): string {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
    }

    private static function mb_rpos(string $s, string $needle) {
        return function_exists('mb_strrpos') ? mb_strrpos($s, $needle, 0, 'UTF-8') : strrpos($s, $needle);
    }

    private static function mb_ucfirst(string $s): string {
        if ($s === '') {
            return $s;
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
        }
        return ucfirst($s);
    }
}
