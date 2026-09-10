<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Text {

    /** NL-stopwoorden (compacte, praktische set). */
    private static $stopwords = [
        'de','het','een','en','van','te','dat','die','in','op','voor','met','als','zijn','er','maar',
        'om','door','over','ze','uit','aan','bij','nog','kan','naar','wordt','wat','worden','deze',
        'dit','is','was','ook','tot','je','jij','wij','we','ik','hij','zij','u','uw','ons','onze',
        'niet','geen','wel','meer','veel','heel','zeer','dan','of','omdat','want','dus','al','hier',
    ];

    public static function tokenize($text) {
        $text  = mb_strtolower((string) $text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out   = [];
        foreach ($parts as $p) {
            if (mb_strlen($p, 'UTF-8') >= 3) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function remove_stopwords(array $tokens) {
        $stop = array_flip(self::$stopwords);
        return array_values(array_filter($tokens, function ($t) use ($stop) {
            return !isset($stop[$t]);
        }));
    }

    public static function extract_internal_hrefs($html, $home_host) {
        $html = (string) $html;
        if (trim($html) === '') {
            return [];
        }
        $home_host = strtolower(preg_replace('/^www\./i', '', (string) $home_host));

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            $host = preg_replace('/^www\./i', '', $host);

            $is_internal = ($host === '' || $host === $home_host);
            if (!$is_internal) {
                continue;
            }
            $out[] = [
                'href'   => $href,
                'anchor' => trim($a->textContent),
            ];
        }
        return $out;
    }

    public static function detect_editor($post_content, $has_elementor) {
        if ($has_elementor) {
            return 'elementor';
        }
        if (strpos((string) $post_content, '<!-- wp:') !== false) {
            return 'gutenberg';
        }
        return 'classic';
    }
}
