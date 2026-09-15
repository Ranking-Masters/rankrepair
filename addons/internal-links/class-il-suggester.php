<?php
/**
 * Orkestreert het genereren van suggesties voor één doelpagina.
 *
 * De planner bedenkt, de gates beslissen, deze klasse bewaart. Meer doet hij niet.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Suggester {

    /**
     * Genereert (of herlaadt) suggesties voor een doelpagina.
     *
     * @param int  $target_id
     * @param bool $force  opnieuw plannen, ook als er al openstaande suggesties zijn
     * @return array ['suggestions' => [...], 'rejected' => [...]]
     */
    public static function for_target($target_id, $force = false) {
        $target_id = (int) $target_id;

        $existing = IL_Suggestions::query([
            'target_id' => $target_id,
            'status'    => [
                IL_Suggestions::STATUS_PENDING,
                IL_Suggestions::STATUS_APPROVED,
                IL_Suggestions::STATUS_APPLIED,
            ],
        ]);

        if (!$force && !empty($existing)) {
            return ['suggestions' => self::decorate($existing), 'rejected' => [], 'created' => 0];
        }

        if ($force) {
            IL_Suggestions::clear_pending_for_target($target_id);
        }

        $plan    = IL_Planner::plan_for_target($target_id);
        $created = 0;

        foreach ($plan['accepted'] as $cand) {
            IL_Suggestions::insert([
                'target_id'       => $cand['target_id'],
                'source_id'       => $cand['source_id'],
                'score'           => $cand['score'],
                'mode'            => $cand['mode'],
                'anchor'          => $cand['anchor'],
                'segment_ref'     => $cand['segment_ref'],
                'sentence_before' => isset($cand['sentence_before']) ? $cand['sentence_before'] : '',
                'sentence_after'  => isset($cand['sentence_after']) ? $cand['sentence_after'] : '',
                'status'          => IL_Suggestions::STATUS_PENDING,
            ]);
            $created++;
        }

        $rows = IL_Suggestions::query([
            'target_id' => $target_id,
            'status'    => [
                IL_Suggestions::STATUS_PENDING,
                IL_Suggestions::STATUS_APPROVED,
                IL_Suggestions::STATUS_APPLIED,
            ],
        ]);

        return [
            'suggestions' => self::decorate($rows),
            'rejected'    => self::summarise_rejections($plan['rejected']),
            'created'     => $created,
        ];
    }

    /**
     * Vult databaserijen aan met wat de UI nodig heeft: titels, editor, en een
     * voorbeeld van hoe de alinea eruit komt te zien.
     */
    public static function decorate(array $rows) {
        $out = [];
        foreach ($rows as $row) {
            $source = get_post((int) $row['source_id']);
            $target = get_post((int) $row['target_id']);
            if (!$source || !$target) {
                continue;
            }

            // De snapshot van de hele pagina hoort niet in een AJAX-respons:
            // bij 200 Elementor-rijen zijn dat tientallen megabytes JSON.
            unset($row['content_before']);

            $row['source_title'] = get_the_title($source);
            $row['source_edit']  = get_edit_post_link($source->ID, 'raw');
            $row['source_url']   = get_permalink($source->ID);
            $row['target_title'] = get_the_title($target);
            $row['target_url']   = get_permalink($target->ID);
            $row['editor']       = IL_Content::editor_label($source);
            $row['score']        = round((float) $row['score'], 4);
            $row['preview']      = self::preview($row, $source, $target);

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Voor/na van de zin waar de link in komt, met het anker gemarkeerd.
     * Dit is wat iemand beoordeelt — niet een score, maar de zin zelf.
     */
    private static function preview(array $row, WP_Post $source, WP_Post $target) {
        $segments = IL_Content::segments($source);
        $segment  = null;
        foreach ($segments as $seg) {
            if ((string) $seg['ref'] === (string) $row['segment_ref']) {
                $segment = $seg;
                break;
            }
        }
        if (!$segment) {
            return ['before' => '', 'after' => '', 'found' => false];
        }

        if ($row['mode'] === 'wrap') {
            $sentence = '';
            foreach (IL_Text::split_sentences($segment['text']) as $s) {
                if (IL_Text::contains_phrase($s, $row['anchor'])) {
                    $sentence = $s;
                    break;
                }
            }
            if ($sentence === '') {
                return ['before' => '', 'after' => '', 'found' => false];
            }
            $marked = preg_replace(
                IL_Text::boundary_pattern($row['anchor']),
                '«$0»',
                $sentence,
                1
            );
            return ['before' => $sentence, 'after' => $marked, 'found' => true];
        }

        $after = preg_replace(
            IL_Text::boundary_pattern($row['anchor']),
            '«$0»',
            (string) $row['sentence_after'],
            1
        );
        return [
            'before' => (string) $row['sentence_before'],
            'after'  => $after,
            'found'  => $row['sentence_before'] !== '',
        ];
    }

    /** Afwijzingen samenvatten per gate — nuttiger dan een lijst van 40 regels. */
    private static function summarise_rejections(array $rejected) {
        $by_gate = [];
        foreach ($rejected as $r) {
            $key = $r['gate'] . '|' . $r['reason'];
            if (!isset($by_gate[$key])) {
                $by_gate[$key] = ['gate' => $r['gate'], 'reason' => $r['reason'], 'count' => 0];
            }
            $by_gate[$key]['count']++;
        }
        usort($by_gate, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return array_slice(array_values($by_gate), 0, 8);
    }
}
