<?php
/**
 * Opslag van linksuggesties.
 *
 * In fase 1 stonden suggesties in een transient. Nu worden ze beoordeeld,
 * bewerkt en toegepast, en dan mogen ze niet verdwijnen omdat een cache verloopt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Suggestions {

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_APPLIED  = 'applied';
    const STATUS_FAILED   = 'failed';
    const STATUS_UNDONE   = 'undone';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rr_il_suggestions';
    }

    /* ------------------------------------------------------------ schrijven */

    /** @return int|false nieuw id */
    public static function insert(array $data) {
        global $wpdb;

        $row = wp_parse_args($data, [
            'target_id'       => 0,
            'source_id'       => 0,
            'score'           => 0,
            'mode'            => 'wrap',
            'anchor'          => '',
            'segment_ref'     => '',
            'sentence_before' => '',
            'sentence_after'  => '',
            'status'          => self::STATUS_PENDING,
            'reason'          => '',
            'link_uid'        => '',
            'created_at'      => current_time('mysql'),
        ]);

        $ok = $wpdb->insert(self::table(), [
            'target_id'       => (int) $row['target_id'],
            'source_id'       => (int) $row['source_id'],
            'score'           => (float) $row['score'],
            'mode'            => (string) $row['mode'],
            'anchor'          => (string) $row['anchor'],
            'segment_ref'     => (string) $row['segment_ref'],
            'sentence_before' => (string) $row['sentence_before'],
            'sentence_after'  => (string) $row['sentence_after'],
            'status'          => (string) $row['status'],
            'reason'          => (string) $row['reason'],
            'link_uid'        => (string) $row['link_uid'],
            'created_at'      => (string) $row['created_at'],
        ], ['%d','%d','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s']);

        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function update($id, array $fields) {
        global $wpdb;
        $allowed = [
            'anchor' => '%s', 'mode' => '%s', 'segment_ref' => '%s', 'status' => '%s',
            'reason' => '%s', 'link_uid' => '%s', 'content_before' => '%s',
            'content_hash' => '%s', 'applied_at' => '%s', 'sentence_before' => '%s',
            'sentence_after' => '%s', 'source_id' => '%d', 'score' => '%f',
        ];
        $data = [];
        $fmt  = [];
        foreach ($fields as $k => $v) {
            if (isset($allowed[$k])) {
                $data[$k] = $v;
                $fmt[]    = $allowed[$k];
            }
        }
        if (empty($data)) {
            return false;
        }
        return $wpdb->update(self::table(), $data, ['id' => (int) $id], $fmt, ['%d']) !== false;
    }

    /** Verwijdert de nog niet toegepaste suggesties voor een doelpagina. */
    public static function clear_pending_for_target($target_id) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE target_id = %d AND status IN (%s, %s, %s)',
            (int) $target_id, self::STATUS_PENDING, self::STATUS_REJECTED, self::STATUS_FAILED
        ));
    }

    /* --------------------------------------------------------------- lezen */

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    }

    /**
     * @param array $args status (string|array), target_id, source_id, limit, offset
     */
    public static function query(array $args = []) {
        global $wpdb;
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $statuses = (array) $args['status'];
            $where[]  = 'status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            $params   = array_merge($params, $statuses);
        }
        foreach (['target_id', 'source_id'] as $col) {
            if (!empty($args[$col])) {
                $where[]  = "$col = %d";
                $params[] = (int) $args[$col];
            }
        }

        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY score DESC, id ASC';

        if (!empty($args['limit'])) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = (int) $args['limit'];
            $params[] = isset($args['offset']) ? (int) $args['offset'] : 0;
        }

        $prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
        $rows     = $wpdb->get_results($prepared, ARRAY_A);
        return $rows ? $rows : [];
    }

    public static function count_by_status() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out  = [
            self::STATUS_PENDING => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0,
            self::STATUS_APPLIED => 0, self::STATUS_FAILED => 0, self::STATUS_UNDONE => 0,
        ];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function count_applied() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s',
            self::STATUS_APPLIED
        ));
    }

    /**
     * Wat er in deze ronde al aan één bronpagina is toegevoegd. Voedt de
     * gates G9, G10 en G12, zodat opeenvolgende suggesties van elkaar weten.
     */
    public static function source_load($source_id) {
        $rows = self::query([
            'source_id' => $source_id,
            'status'    => [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_APPLIED],
        ]);

        $per_segment = [];
        $prefixes    = [];
        foreach ($rows as $r) {
            $ref = (string) $r['segment_ref'];
            $per_segment[$ref] = isset($per_segment[$ref]) ? $per_segment[$ref] + 1 : 1;
            $prefixes[] = IL_Text::anchor_prefix_class($r['anchor']);
        }

        return [
            'count'       => count($rows),
            'per_segment' => $per_segment,
            'prefixes'    => array_values(array_unique($prefixes)),
        ];
    }

    /** Alle door RankRepair geplaatste links, voor het Data-scherm. */
    public static function applied_edges() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT source_id, target_id FROM ' . self::table() . ' WHERE status = %s',
            self::STATUS_APPLIED
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['source_id'] . '-' . (int) $r['target_id']] = true;
        }
        return $out;
    }
}
