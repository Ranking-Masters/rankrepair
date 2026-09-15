/* global jQuery, rrAdmin, rrIL */
(function ($) {
    'use strict';

    var RRIL = {

        graph: null,
        graphLoaded: false,

        /* ------------------------------------------------------------ init */

        init: function () {
            $('.rr-il-tab').on('click', function () { RRIL.openTab($(this).data('tab')); });

            $('#rr-il-scan-btn').on('click', function () { RRIL.startScan(); });
            $('#rr-il-bulk-btn').on('click', function () { RRIL.startBulk(); });
            $('#rr-il-export-btn').on('click', function () { RRIL.exportCsv(); });
            $('#rr-il-apply-btn').on('click', function () { RRIL.startApply(); });
            $('#rr-il-filter').on('change', function () { RRIL.loadSuggestions(); });
            $('#rr-il-approve-all').on('click', function () { RRIL.approveAll(); });
            $('#rr-il-settings-form').on('submit', function (e) { e.preventDefault(); RRIL.saveSettings(); });

            $(document).on('click', '.rr-il-suggest-btn', function () {
                RRIL.suggestFor(parseInt($(this).data('id'), 10), $(this));
            });
            $(document).on('click', '.rr-il-act', function () {
                var $b = $(this);
                RRIL.act($b.data('act'), parseInt($b.data('id'), 10), $b);
            });
            $(document).on('click', '.rr-il-anchor', function () { RRIL.editAnchor($(this)); });

            RRIL.loadStats();
        },

        openTab: function (tab) {
            $('.rr-il-tab').removeClass('is-active').filter('[data-tab="' + tab + '"]').addClass('is-active');
            $('.rr-il-panel').removeClass('is-active').filter('[data-panel="' + tab + '"]').addClass('is-active');

            if (tab === 'suggesties') { RRIL.loadSuggestions(); }
            if (tab === 'data') { RRIL.loadData(); }
        },

        /* ------------------------------------------------------------ scan */

        startScan: function () {
            $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', true);
            $('#rr-il-progress').show();
            RRIL.scanBatch(0);
        },

        scanBatch: function (offset) {
            RRIL.post('rr_il_scan', { offset: offset }, function (d) {
                RRIL.progress('#rr-il-progress-fill', '#rr-il-progress-txt', d.processed, d.total);
                if (d.done) {
                    RRIL.loadStats();
                    $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', false);
                    setTimeout(function () { $('#rr-il-progress').fadeOut(300); }, 800);
                } else {
                    RRIL.scanBatch(d.processed);
                }
            }, function (msg) {
                $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', false);
                $('#rr-il-tbody').html('<tr><td colspan="6" class="rr-il-error">' + RRIL.esc(msg) + '</td></tr>');
            });
        },

        loadStats: function () {
            RRIL.post('rr_il_stats', {}, function (d) {
                $('#rr-il-stat-orphans').text(d.orphans);
                $('#rr-il-stat-thin').text(d.thin);
                $('#rr-il-stat-avg').text(d.avg_inbound);
                $('#rr-il-stat-total').text(d.total);
                RRIL.renderRows(d.rows);
                RRIL.updateCounts(d.counts);
            });
        },

        renderRows: function (rows) {
            var $tb = $('#rr-il-tbody').empty();
            if (!rows.length) {
                $tb.html('<tr><td colspan="6">Geen orphan- of thin-pagina\'s gevonden.</td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (row) {
                var badge = row.inbound === 0
                    ? '<span class="rr-il-badge rr-il-badge--orphan">orphan</span>'
                    : '<span class="rr-il-badge rr-il-badge--thin">thin</span>';
                html += '<tr data-id="' + row.id + '">' +
                    '<td>' + badge + ' ' + RRIL.esc(row.title) + '</td>' +
                    '<td>' + RRIL.esc(row.type) + '</td>' +
                    '<td>' + row.inbound + '</td>' +
                    '<td>' + row.outbound + '</td>' +
                    '<td>' + (row.suggested ? row.suggested : '–') + '</td>' +
                    '<td><button class="button rr-il-suggest-btn" data-id="' + row.id + '">Suggesties</button></td>' +
                    '</tr>';
            });
            $tb.html(html);
        },

        /* ------------------------------------------------------- suggesties */

        startBulk: function () {
            $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', true);
            $('#rr-il-progress').show();
            RRIL.bulkBatch(0, 0);
        },

        bulkBatch: function (offset, found) {
            RRIL.post('rr_il_suggest_bulk', { offset: offset }, function (d) {
                var total = found + d.found;
                RRIL.progress('#rr-il-progress-fill', '#rr-il-progress-txt', d.processed, d.total, total + ' gevonden');
                if (d.done) {
                    $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', false);
                    RRIL.loadStats();
                    RRIL.openTab('suggesties');
                } else {
                    RRIL.bulkBatch(d.processed, total);
                }
            }, function (msg) {
                $('#rr-il-scan-btn, #rr-il-bulk-btn').prop('disabled', false);
                alert(msg);
            });
        },

        suggestFor: function (id, $btn) {
            $btn.prop('disabled', true).text('Zoeken…');
            RRIL.post('rr_il_suggest', { target_id: id, force: 1 }, function (d) {
                $btn.prop('disabled', false).text('Suggesties');
                RRIL.openTab('suggesties');
                RRIL.renderSuggestions(d.suggestions, d.rejected);
            }, function (msg) {
                $btn.prop('disabled', false).text('Suggesties');
                alert(msg);
            });
        },

        loadSuggestions: function () {
            var status = $('#rr-il-filter').val();
            $('#rr-il-suggestions').html('<p class="rr-il-empty">Laden…</p>');
            RRIL.post('rr_il_list', { status: status, limit: 100 }, function (d) {
                RRIL.updateCounts(d.counts);
                RRIL.renderSuggestions(d.rows, []);
            });
        },

        renderSuggestions: function (rows, rejected) {
            var html = '';

            if (rejected && rejected.length) {
                html += '<details class="rr-il-rejected"><summary>Afgewezen kandidaten (' + rejected.length + ' soorten)</summary><ul>';
                rejected.forEach(function (r) {
                    html += '<li><code>' + RRIL.esc(r.gate) + '</code> ' + RRIL.esc(r.reason) + ' <span class="rr-il-muted">×' + r.count + '</span></li>';
                });
                html += '</ul></details>';
            }

            if (!rows || !rows.length) {
                html += '<p class="rr-il-empty">Geen suggesties in deze weergave.</p>';
                $('#rr-il-suggestions').html(html);
                return;
            }

            rows.forEach(function (r) { html += RRIL.card(r); });
            $('#rr-il-suggestions').html(html);
        },

        card: function (r) {
            var preview = r.preview || {};
            var after = preview.after ? RRIL.esc(preview.after)
                .replace('«', '<mark>').replace('»', '</mark>') : '';

            var actions = '';
            if (r.status === 'applied') {
                actions = '<button class="button rr-il-act" data-act="undo" data-id="' + r.id + '">Terugdraaien</button>';
            } else {
                actions =
                    '<button class="button button-primary rr-il-act" data-act="approve" data-id="' + r.id + '">Goedkeuren</button> ' +
                    '<button class="button rr-il-act" data-act="reject" data-id="' + r.id + '">Afwijzen</button> ' +
                    '<button class="button rr-il-act" data-act="apply" data-id="' + r.id + '">Nu plaatsen</button>';
            }

            return '' +
                '<article class="rr-il-card rr-il-card--' + RRIL.esc(r.status) + '" data-id="' + r.id + '">' +
                  '<header class="rr-il-card-head">' +
                    '<div>' +
                      '<span class="rr-il-status">' + RRIL.esc(RRIL.statusLabel(r.status)) + '</span> ' +
                      '<strong>' + RRIL.esc(r.source_title) + '</strong>' +
                      '<span class="rr-il-muted"> → </span>' +
                      '<a href="' + RRIL.esc(r.target_url) + '" target="_blank" rel="noopener">' + RRIL.esc(r.target_title) + '</a>' +
                    '</div>' +
                    '<div class="rr-il-meta">' +
                      '<span title="Editor van de bronpagina">' + RRIL.esc(r.editor) + '</span> · ' +
                      '<span title="Plaatsingsmodus">' + RRIL.esc(RRIL.modeLabel(r.mode)) + '</span> · ' +
                      '<span title="Relevantiescore">' + RRIL.esc(r.score) + '</span>' +
                    '</div>' +
                  '</header>' +
                  '<div class="rr-il-anchor-row">' +
                    '<span class="rr-il-label">Ankertekst</span>' +
                    '<span class="rr-il-anchor" data-id="' + r.id + '" title="Klik om aan te passen">' + RRIL.esc(r.anchor) + '</span>' +
                  '</div>' +
                  (after ? '<p class="rr-il-preview">' + after + '</p>' : '<p class="rr-il-preview rr-il-muted">Voorbeeld niet beschikbaar — de alinea is gewijzigd.</p>') +
                  (r.mode !== 'wrap' && preview.before ? '<p class="rr-il-preview-before"><span class="rr-il-label">Was</span> ' + RRIL.esc(preview.before) + '</p>' : '') +
                  (r.reason ? '<p class="rr-il-error">' + RRIL.esc(r.reason) + '</p>' : '') +
                  '<footer class="rr-il-card-foot">' +
                    actions +
                    ' <a class="rr-il-editlink" href="' + RRIL.esc(r.source_edit || '#') + '" target="_blank" rel="noopener">Bron bewerken</a>' +
                  '</footer>' +
                '</article>';
        },

        statusLabel: function (s) {
            return { pending: 'te beoordelen', approved: 'goedgekeurd', rejected: 'afgewezen',
                     applied: 'geplaatst', failed: 'mislukt', undone: 'teruggedraaid' }[s] || s;
        },

        modeLabel: function (m) {
            return { wrap: 'bestaande woorden', rewrite: 'zin herschreven', clause: 'bijzin' }[m] || m;
        },

        /* ----------------------------------------------------------- acties */

        act: function (action, id, $btn) {
            if (action === 'undo' && !window.confirm(rrIL.i18n.confirmUndo)) { return; }

            var map = { approve: 'approved', reject: 'rejected' };
            $btn.prop('disabled', true);

            if (map[action]) {
                RRIL.post('rr_il_update', { id: id, status: map[action] }, function (d) {
                    RRIL.updateCounts(d.counts);
                    RRIL.replaceCard(id, d.row);
                }, function (msg) {
                    $btn.prop('disabled', false);
                    alert(msg);
                });
                return;
            }

            RRIL.post(action === 'undo' ? 'rr_il_undo' : 'rr_il_apply', { id: id }, function (d) {
                RRIL.updateCounts(d.counts);
                RRIL.replaceCard(id, d.row);
                if (!d.ok) { alert(d.message); }
            }, function (msg) {
                $btn.prop('disabled', false);
                alert(msg);
            });
        },

        replaceCard: function (id, row) {
            var $card = $('.rr-il-card[data-id="' + id + '"]');
            if (!row) { $card.remove(); return; }
            var status = $('#rr-il-filter').val();
            if (status !== 'all' && row.status !== status) { $card.fadeOut(200, function () { $(this).remove(); }); return; }
            $card.replaceWith(RRIL.card(row));
        },

        approveAll: function () {
            var ids = $('.rr-il-card').map(function () { return parseInt($(this).data('id'), 10); }).get();
            if (!ids.length) { return; }
            var i = 0;
            (function next() {
                if (i >= ids.length) { RRIL.loadSuggestions(); return; }
                RRIL.post('rr_il_update', { id: ids[i], status: 'approved' }, function (d) {
                    RRIL.updateCounts(d.counts);
                    i++; next();
                }, function () { i++; next(); });
            })();
        },

        editAnchor: function ($el) {
            if ($el.find('input').length) { return; }
            var id = parseInt($el.data('id'), 10);
            var old = $el.text();
            var $input = $('<input type="text" class="rr-il-anchor-input">').val(old);

            $el.empty().append($input);
            $input.trigger('focus').trigger('select');

            var done = function (save) {
                var val = $input.val();
                if (!save || val === old) { $el.text(old); return; }
                $el.text('opslaan…');
                RRIL.post('rr_il_update', { id: id, anchor: val }, function (d) {
                    RRIL.replaceCard(id, d.row);
                }, function (msg) {
                    $el.text(old);
                    alert(msg);
                });
            };

            $input.on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); done(true); }
                if (e.key === 'Escape') { done(false); }
            }).on('blur', function () { done(false); });
        },

        /* -------------------------------------------------------- toepassen */

        startApply: function () {
            if (!window.confirm(rrIL.i18n.confirmApply)) { return; }

            $('#rr-il-apply-btn').prop('disabled', true);
            $('#rr-il-apply-log').empty();
            $('#rr-il-apply-progress').show();

            RRIL.post('rr_il_list', { status: 'approved', limit: 200 }, function (d) {
                var ids = d.rows.map(function (r) { return parseInt(r.id, 10); });
                if (!ids.length) {
                    $('#rr-il-apply-btn').prop('disabled', false);
                    $('#rr-il-apply-progress').hide();
                    RRIL.log('Niets goedgekeurd om te plaatsen.', 'muted');
                    return;
                }
                RRIL.applyNext(ids, 0, { ok: 0, fail: 0 });
            });
        },

        applyNext: function (ids, i, tally) {
            if (i >= ids.length) {
                $('#rr-il-apply-btn').prop('disabled', false);
                RRIL.log('Klaar: ' + tally.ok + ' geplaatst, ' + tally.fail + ' overgeslagen.', tally.fail ? 'warn' : 'ok');
                RRIL.loadStats();
                return;
            }

            RRIL.progress('#rr-il-apply-fill', '#rr-il-apply-txt', i, ids.length);

            RRIL.post('rr_il_apply', { id: ids[i] }, function (d) {
                var row = d.row || {};
                if (d.ok) {
                    tally.ok++;
                    RRIL.log('✓ ' + (row.source_title || '#' + ids[i]) + ' → ' + (row.target_title || '') + ' ("' + (row.anchor || '') + '")', 'ok');
                } else {
                    tally.fail++;
                    RRIL.log('– ' + (row.source_title || '#' + ids[i]) + ': ' + d.message, 'warn');
                }
                RRIL.updateCounts(d.counts);
                RRIL.applyNext(ids, i + 1, tally);
            }, function (msg) {
                tally.fail++;
                RRIL.log('– #' + ids[i] + ': ' + msg, 'warn');
                RRIL.applyNext(ids, i + 1, tally);
            });
        },

        log: function (text, kind) {
            $('#rr-il-apply-log').append('<div class="rr-il-log-line rr-il-log--' + (kind || '') + '">' + RRIL.esc(text) + '</div>');
            var el = document.getElementById('rr-il-apply-log');
            el.scrollTop = el.scrollHeight;
        },

        /* ------------------------------------------------------------- data */

        loadData: function () {
            RRIL.post('rr_il_profile', {}, function (d) { RRIL.renderMetrics(d); });

            if (RRIL.graph) { return; }
            RRIL.loadGraphLib(function () {
                RRIL.post('rr_il_graph', {}, function (d) { RRIL.renderGraph(d); });
            });
        },

        loadGraphLib: function (cb) {
            if (RRIL.graphLoaded) { cb(); return; }
            var s = document.createElement('script');
            s.src = rrIL.graphLib;
            s.onload = function () { RRIL.graphLoaded = true; cb(); };
            s.onerror = function () {
                $('#rr-il-graph').html('<p class="rr-il-empty rr-il-error">De grafiekbibliotheek kon niet worden geladen.</p>');
            };
            document.head.appendChild(s);
        },

        renderGraph: function (data) {
            var el = document.getElementById('rr-il-graph');
            el.innerHTML = '';

            if (!data.nodes.length) {
                el.innerHTML = '<p class="rr-il-empty">Nog geen scan uitgevoerd.</p>';
                return;
            }

            var COLORS = { orphan: '#EF4444', thin: '#F59E0B', ok: '#10B981', hub: '#6366F1' };

            RRIL.graph = ForceGraph3D()(el)
                .backgroundColor('#0b1020')
                .width(el.clientWidth)
                .height(el.clientHeight)
                .graphData({ nodes: data.nodes, links: data.links })
                .nodeId('id')
                .nodeVal('val')
                .nodeLabel(function (n) { return n.label + ' — ' + n.inbound + '× gelinkt'; })
                .nodeColor(function (n) { return COLORS[n.state] || '#9CA3AF'; })
                .linkColor(function (l) { return l.ours ? '#A855F7' : 'rgba(148,163,184,0.35)'; })
                .linkWidth(function (l) { return l.ours ? 1.2 : 0.4; })
                .linkDirectionalParticles(function (l) { return l.ours ? 2 : 0; })
                .linkDirectionalParticleWidth(1.2)
                .onNodeClick(function (n) {
                    window.open(rrAdmin.pluginUrl ? '/wp-admin/post.php?post=' + n.id + '&action=edit' : '#', '_blank');
                });

            $(window).on('resize.rril', function () {
                if (RRIL.graph) { RRIL.graph.width(el.clientWidth).height(el.clientHeight); }
            });
        },

        renderMetrics: function (d) {
            var m = d.metrics, v = d.verdicts;
            var rows = [
                ['Pagina\'s', m.total],
                ['Interne links', m.links],
                ['Door RankRepair geplaatst', m.placed_by_rr],
                ['Gemiddeld inkomend', m.avg_inbound],
                ['Mediaan inkomend', m.median_inbound],
                ['Naar de top 10%', Math.round(m.top_share * 100) + '%'],
                ['Unieke ankerteksten', Math.round(m.anchor_diversity * 100) + '%'],
                ['Wederzijdse links', Math.round(m.reciprocity * 100) + '%']
            ];

            var html = '<table class="rr-il-metrics">';
            rows.forEach(function (r) {
                html += '<tr><th>' + RRIL.esc(r[0]) + '</th><td>' + RRIL.esc(r[1]) + '</td></tr>';
            });
            html += '</table><ul class="rr-il-verdicts">';
            Object.keys(v).forEach(function (k) {
                html += '<li class="rr-il-verdict rr-il-verdict--' + v[k][0] + '">' + RRIL.esc(v[k][1]) + '</li>';
            });
            html += '</ul>';

            $('#rr-il-metrics').html(html);
        },

        /* ------------------------------------------------------ instellingen */

        saveSettings: function () {
            var data = { save: 1 };
            $('#rr-il-settings-form').find('input').each(function () {
                var $i = $(this);
                // Niet-aangevinkte checkboxes worden niet meegestuurd; expliciet 0 dus.
                data[$i.attr('name')] = $i.is(':checkbox') ? ($i.is(':checked') ? 1 : 0) : $i.val();
            });

            RRIL.post('rr_il_settings', data, function (d) {
                rrIL.config = d.config;
                $('#rr-il-settings-msg').text('Opgeslagen.').show().delay(2000).fadeOut();
            });
        },

        /* ----------------------------------------------------------- export */

        exportCsv: function () {
            var $btn = $('#rr-il-export-btn').prop('disabled', true).text('Exporteren…');
            RRIL.post('rr_il_export', {}, function (d) {
                var blob = new Blob(["﻿" + d.csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = d.filename;
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                URL.revokeObjectURL(url);
                $btn.prop('disabled', false).text('Exporteer CSV');
            }, function (msg) {
                $btn.prop('disabled', false).text('Exporteer CSV');
                alert(msg);
            });
        },

        /* ---------------------------------------------------------- helpers */

        post: function (action, data, onOk, onErr) {
            $.post(rrAdmin.ajaxUrl, $.extend({ action: action, nonce: rrAdmin.nonce }, data))
                .done(function (r) {
                    if (r && r.success) { onOk(r.data); }
                    else if (onErr) { onErr(r && r.data && r.data.message ? r.data.message : 'Er ging iets mis.'); }
                })
                .fail(function () { if (onErr) { onErr('Verbindingsfout.'); } });
        },

        progress: function (fill, txt, done, total, suffix) {
            var pct = total > 0 ? Math.round(done / total * 100) : 100;
            $(fill).css('width', pct + '%');
            $(txt).text(done + ' / ' + total + (suffix ? ' · ' + suffix : ''));
        },

        updateCounts: function (counts) {
            if (!counts) { return; }
            $('#rr-il-count-pending').text(counts.pending || 0);
            $('#rr-il-count-approved').text(counts.approved || 0);
        },

        esc: function (s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }
    };

    $(function () { RRIL.init(); });
    window.RRIL = RRIL;
})(jQuery);
