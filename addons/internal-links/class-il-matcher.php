<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Matcher {

    /** Boost-factor wanneer target- en kandidaat-keyword overlappen. */
    const KEYWORD_BOOST = 1.5;

    /**
     * @param array      $target     ['tokens' => lijst of term=>aantal, 'keyword' => string]
     * @param array      $candidates id => zelfde vorm als $target
     * @param int        $top_n
     * @param array|null $idf        vooraf berekende IDF (uit IL_Index); anders
     *                               wordt hij over target + kandidaten berekend
     */
    public static function score_candidates(array $target, array $candidates, int $top_n = 3, ?array $idf = null): array {
        $top_n = max(1, (int) $top_n);

        $target_tokens = self::as_counts(isset($target['tokens']) ? (array) $target['tokens'] : []);
        if (empty($target_tokens) || empty($candidates)) {
            return [];
        }

        if ($idf === null) {
            // Document-frequency over target + alle kandidaten.
            $docs = ['__target__' => $target_tokens];
            foreach ($candidates as $id => $c) {
                $docs[$id] = self::as_counts(isset($c['tokens']) ? (array) $c['tokens'] : []);
            }

            $doc_count = count($docs);
            $df = [];
            foreach ($docs as $counts) {
                foreach (array_keys($counts) as $term) {
                    $df[$term] = isset($df[$term]) ? $df[$term] + 1 : 1;
                }
            }
            $idf = [];
            foreach ($df as $term => $n) {
                // Gladde IDF, altijd > 0.
                $idf[$term] = log(($doc_count + 1) / ($n + 1)) + 1;
            }
        }

        $target_vec = self::tfidf_vector($target_tokens, $idf);

        $target_kw = self::norm_kw($target['keyword'] ?? '');

        $scored = [];
        foreach ($candidates as $id => $c) {
            $vec  = self::tfidf_vector(self::as_counts((array) ($c['tokens'] ?? [])), $idf);
            $sim  = self::cosine($target_vec, $vec);
            if ($sim <= 0) {
                continue;
            }
            $cand_kw = self::norm_kw($c['keyword'] ?? '');
            if ($target_kw !== '' && $cand_kw !== '' && $target_kw === $cand_kw) {
                $sim *= self::KEYWORD_BOOST;
            }
            $scored[] = ['id' => (int) $id, 'score' => round($sim, 6)];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['score'] <=> $a['score'];
        });

        return array_slice($scored, 0, $top_n);
    }

    /**
     * Accepteert zowel een platte lijst tokens als een term=>aantal-map.
     * De index levert het tweede, losse aanroepen en tests het eerste.
     */
    private static function as_counts(array $tokens) {
        if (empty($tokens)) {
            return [];
        }
        if (array_keys($tokens) === range(0, count($tokens) - 1)) {
            $counts = [];
            foreach ($tokens as $t) {
                $counts[$t] = isset($counts[$t]) ? $counts[$t] + 1 : 1;
            }
            return $counts;
        }
        return $tokens;
    }

    private static function tfidf_vector(array $tf, array $idf) {
        if (empty($tf)) {
            return [];
        }
        $len = array_sum($tf);
        if ($len <= 0) {
            return [];
        }
        $vec = [];
        foreach ($tf as $term => $count) {
            $w = ($count / $len) * (isset($idf[$term]) ? $idf[$term] : 0);
            if ($w != 0.0) {
                $vec[$term] = $w;
            }
        }
        return $vec;
    }

    private static function cosine(array $a, array $b) {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $dot = 0.0;
        foreach ($a as $term => $wa) {
            if (isset($b[$term])) {
                $dot += $wa * $b[$term];
            }
        }
        if ($dot == 0.0) {
            return 0.0;
        }
        $na = 0.0; foreach ($a as $wa) { $na += $wa * $wa; }
        $nb = 0.0; foreach ($b as $wb) { $nb += $wb * $wb; }
        $denom = sqrt($na) * sqrt($nb);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    private static function norm_kw($kw) {
        return trim(mb_strtolower((string) $kw, 'UTF-8'));
    }
}
