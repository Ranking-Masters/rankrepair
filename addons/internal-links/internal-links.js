/* global jQuery, rrAdmin, rrIL */
(function ($) {
    'use strict';

    var RRIL = {

        graph: null,
        graphLoaded: false,
        rows: [],          // laatste overzichtsdata
        scope: null,       // { id, title } wanneer we één doelpagina bekijken

        /* ------------------------------------------------------------ init */

        init: function () {
            $('.rr-il-tab').on('click', function () { RRIL.openTab($(this).data('tab')); });

            $('#rr-il-scan-btn').on('click', function () { RRIL.startScan(); });
            $('#rr-il-bulk-btn').on('click', function () { RRIL.generate(); });
            $('#rr-il-export-btn').on('click', function () { RRIL.exportCsv(); });
            $('#rr-il-apply-btn').on('click', function () { RRIL.startApply(); });
            $('#rr-il-filter').on('change', function () { RRIL.loadSuggestions(); });
            $('#rr-il-approve-all').on('click', function () { RRIL.approveAll(); });
            $('#rr-il-only-content').on('change', function () { RRIL.renderRows(); });
            $('#rr-il-settings-form').on('submit', function (e) { e.preventDefault(); RRIL.saveSettings(); });

            $('#rr-il-check-all').on('change', function () {
                $('#rr-il-tbody .rr-il-pick').prop('checked', $(this).is(':checked'));
                RRIL.updateSelection();
            });
            $(document).on('change', '.rr-il-pick', function () { RRIL.updateSelection(); });

            $(document).on('click', '.rr-il-suggest-btn', function () {
                RRIL.showFor(parseInt($(this).data('id'), 10), $(this));
            });
            $(document).on('click', '#rr-il-scope-clear', function () { RRIL.clearScope(); });
            $(document).on('click', '#rr-il-scope-again', function () {
                RRIL.regenerate(RRIL.scope.id, $(this));
            });
            $(document).on('click', '.rr-il-act', function () {
                var $b = $(this);
                RRIL.act($b.data('act'), parseInt($b.data('id'), 10), $b);
            });
            $(document).on('click', '.rr-il-anchor', function () { RRIL.editAnchor($(this)); });

            RRIL.loadStats();
        },

        openTab: function (tab, reload) {
            $('.rr-il-tab').removeClass('is-active').filter('[data-tab="' + tab + '"]').addClass('is-active');
            $('.rr-il-panel').removeClass('is-active').filter('[data-panel="' + tab + '"]').addClass('is-active');

            if (reload === false) { return; }
            if (tab === 'suggesties') { RRIL.loadSuggestions(); }
            if (tab === 'data') { RRIL.loadData(); }
        },

        /* --------------------------------------------- knop met voortgang */

        // Zet een knop op "bezig" en houdt de voortgang in de knop zelf bij.
        // Scheelt zoeken naar een balkje dat ergens anders op de pagina staat.
        busy: function (sel, text) {
            var $b = $(sel);
            if (!$b.data('label')) { $b.data('label', $b.text()); }
            return {
                step: function (t) { $b.prop('disabled', true).text(t); },
                done: function () { $b.prop('disabled', false).text($b.data('label')); }
            };
        },

        /* ------------------------------------------------------------ scan */

        startScan: function () {
            var b = RRIL.busy('#rr-il-scan-btn');
            $('#rr-il-bulk-btn').prop('disabled', true);
            RRIL.scanBatch(0, b);
        },

        scanBatch: function (offset, b) {
            RRIL.post('rr_il_scan', { offset: offset }, function (d) {
                b.step('Scannen… ' + d.processed + ' / ' + d.total);
                if (d.done) {
                    RRIL.loadStats();
                    b.done();
                    $('#rr-il-bulk-btn').prop('disabled', false);
                } else {
                    RRIL.scanBatch(d.processed, b);
                }
            }, function (msg) {
                b.done();
                $('#rr-il-bulk-btn').prop('disabled', false);
                $('#rr-il-tbody').html('<tr><td colspan="8" class="rr-il-error">' + RRIL.esc(msg) + '</td></tr>');
            });
        },

        loadStats: function () {
            RRIL.post('rr_il_stats', {}, function (d) {
                $('#rr-il-stat-orphans').text(d.orphans);
                $('#rr-il-stat-thin').text(d.thin);
                $('#rr-il-stat-avg').text(d.avg_inbound);
                $('#rr-il-stat-total').text(d.total);
                RRIL.rows = d.rows || [];
                RRIL.renderRows();
                RRIL.updateCounts(d.counts);
            });
        },

        /* -------------------------------------------------------- overzicht */

        visibleRows: function () {
            if (!$('#rr-il-only-content').is(':checked')) { return RRIL.rows; }
            // Pagina's zonder eigen tekst zijn templates en landingspagina's; daar
            // valt niets te linken, en ze verdringen wat er wél toe doet.
            return RRIL.rows.filter(function (r) { return r.words >= 150; });
        },

        renderRows: function () {
            var rows = RRIL.visibleRows();
            var $tb = $('#rr-il-tbody').empty();
            var verborgen = RRIL.rows.length - rows.length;

            if (!rows.length) {
                $tb.html('<tr><td colspan="8">Geen pagina\'s in deze weergave.</td></tr>');
                RRIL.updateSelection();
                return;
            }

            var html = '';
            rows.forEach(function (row) {
                var badge = row.inbound === 0
                    ? '<span class="rr-il-badge rr-il-badge--orphan">orphan</span>'
                    : '<span class="rr-il-badge rr-il-badge--thin">thin</span>';
                html += '<tr data-id="' + row.id + '">' +
                    '<td class="rr-il-col-check"><input type="checkbox" class="rr-il-pick" value="' + row.id + '"></td>' +
                    '<td class="rr-il-col-thumb">' + RRIL.thumb(row.thumb, row.title) + '</td>' +
                    '<td>' + badge + ' <strong>' + RRIL.esc(row.title) + '</strong>' +
                        (row.keyword ? '<br><span class="rr-il-muted">' + RRIL.esc(row.keyword) + '</span>' : '') +
                    '</td>' +
                    '<td>' + row.inbound + '</td>' +
                    '<td>' + row.outbound + '</td>' +
                    '<td>' + row.words + '</td>' +
                    '<td>' + (row.suggested ? '<strong>' + row.suggested + '</strong>' : '–') + '</td>' +
                    '<td><button class="button rr-il-suggest-btn" data-id="' + row.id + '">' +
                        (row.suggested ? 'Bekijk' : 'Zoek') + '</button></td>' +
                    '</tr>';
            });
            if (verborgen > 0) {
                html += '<tr class="rr-il-hiddenrow"><td colspan="8">' + verborgen +
                    ' pagina\'s verborgen omdat ze geen eigen tekst hebben (templates en landingspagina\'s).</td></tr>';
            }
            $tb.html(html);
            RRIL.updateSelection();
        },

        // De letter staat er altijd; de afbeelding ligt erover. Laadt die niet
        // (verwijderde media, verkeerde URL), dan haalt onerror hem weg en zie je
        // de letter — geen gebroken-plaatje-icoon.
        thumb: function (url, title) {
            // Eerste létter, niet het eerste teken: titels als "10 SEO-tips"
            // zouden anders een cijfer tonen en dat leest als een rijnummer.
            var m = String(title || '').match(/\p{L}/u);
            var letter = RRIL.esc(m ? m[0].toUpperCase() : '·');
            var img = url
                ? '<img class="rr-il-thumb-img" src="' + RRIL.escAttr(url) +
                  '" alt="" loading="lazy" onerror="this.remove()">'
                : '';
            return '<span class="rr-il-thumb rr-il-thumb--leeg">' + letter + img + '</span>';
        },

        selected: function () {
            return $('#rr-il-tbody .rr-il-pick:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();
        },

        updateSelection: function () {
            var n = RRIL.selected().length;
            var $b = $('#rr-il-bulk-btn');
            $b.data('label', n ? 'Genereer voor ' + n + ' geselecteerde' : 'Genereer suggesties');
            if (!$b.prop('disabled')) { $b.text($b.data('label')); }

            var totaal = $('#rr-il-tbody .rr-il-pick').length;
            $('#rr-il-check-all').prop('checked', totaal > 0 && n === totaal);
        },

        /* ------------------------------------------------------- genereren */

        // Zonder selectie draait hij over alle zichtbare pagina's; met selectie
        // alleen daarover. Eén AJAX-call per pagina, zodat je kunt meekijken en
        // op elk moment kunt stoppen door de pagina te verlaten.
        generate: function () {
            var ids = RRIL.selected();
            if (!ids.length) {
                ids = RRIL.visibleRows()
                    .filter(function (r) { return r.inbound <= 1 && !r.suggested; })
                    .map(function (r) { return r.id; });
            }
            if (!ids.length) {
                window.alert('Niets te doen: alle zichtbare pagina\'s hebben al suggesties.');
                return;
            }
            if (ids.length > 25 && !window.confirm(
                'Suggesties zoeken voor ' + ids.length + ' pagina\'s. Dat kan even duren. Doorgaan?')) {
                return;
            }

            var b = RRIL.busy('#rr-il-bulk-btn');
            $('#rr-il-scan-btn').prop('disabled', true);
            RRIL.generateNext(ids, 0, 0, b);
        },

        generateNext: function (ids, i, found, b) {
            if (i >= ids.length) {
                b.done();
                $('#rr-il-scan-btn').prop('disabled', false);
                RRIL.loadStats();
                if (found) { RRIL.openTab('suggesties'); }
                else { window.alert('Geen bruikbare plek gevonden op deze pagina\'s.'); }
                return;
            }

            b.step('Zoeken… ' + (i + 1) + '/' + ids.length + ' · ' + found + ' gevonden');

            RRIL.post('rr_il_suggest', { target_id: ids[i], force: 1 }, function (d) {
                RRIL.generateNext(ids, i + 1, found + (d.suggestions || []).length, b);
            }, function () {
                RRIL.generateNext(ids, i + 1, found, b);
            });
        },

        /* ------------------------------------------------------- suggesties */

        // Klikken op een pagina met bestaande suggesties toont ze meteen; alleen
        // een pagina zonder suggesties gaat daadwerkelijk zoeken.
        showFor: function (id, $btn) {
            var row = RRIL.rows.filter(function (r) { return r.id === id; })[0];
            RRIL.scope = { id: id, title: row ? row.title : '#' + id };

            if (row && row.suggested) {
                RRIL.openTab('suggesties', false);
                RRIL.loadSuggestions();
                return;
            }
            RRIL.regenerate(id, $btn);
        },

        regenerate: function (id, $btn) {
            var oud = $btn.text();
            $btn.prop('disabled', true).text('Zoeken…');
            RRIL.post('rr_il_suggest', { target_id: id, force: 1 }, function (d) {
                $btn.prop('disabled', false).text(oud);
                RRIL.scope = { id: id, title: d.target_title || ('#' + id) };
                RRIL.openTab('suggesties', false);
                $('#rr-il-filter').val('pending');
                RRIL.renderScope();
                RRIL.renderSuggestions(d.suggestions, d.rejected);
                RRIL.loadStats();
            }, function (msg) {
                $btn.prop('disabled', false).text(oud);
                window.alert(msg);
            });
        },

        clearScope: function () {
            RRIL.scope = null;
            RRIL.loadSuggestions();
        },

        renderScope: function () {
            var $s = $('#rr-il-scope');
            if (!RRIL.scope) { $s.hide().empty(); return; }
            $s.show().html(
                '<span class="rr-il-scope-label">Suggesties naar</span> ' +
                '<strong>' + RRIL.esc(RRIL.scope.title) + '</strong>' +
                '<button class="button-link" id="rr-il-scope-again">opnieuw zoeken</button>' +
                '<button class="button-link" id="rr-il-scope-clear">toon alle pagina\'s</button>'
            );
        },

        loadSuggestions: function () {
            var data = { status: $('#rr-il-filter').val(), limit: 100 };
            if (RRIL.scope) { data.target_id = RRIL.scope.id; }

            $('#rr-il-suggestions').html('<p class="rr-il-empty">Laden…</p>');
            RRIL.renderScope();

            RRIL.post('rr_il_list', data, function (d) {
                RRIL.updateCounts(d.counts);
                RRIL.renderSuggestions(d.rows, []);
            });
        },

        renderSuggestions: function (rows, rejected) {
            var html = '';

            if (rejected && rejected.length) {
                html += '<details class="rr-il-rejected"><summary>Waarom andere plekken afvielen (' +
                    rejected.length + ' soorten)</summary><ul>';
                rejected.forEach(function (r) {
                    html += '<li><code>' + RRIL.esc(r.gate) + '</code> ' + RRIL.esc(r.reason) +
                        ' <span class="rr-il-muted">×' + r.count + '</span></li>';
                });
                html += '</ul></details>';
            }

            if (!rows || !rows.length) {
                html += '<p class="rr-il-empty">' +
                    (RRIL.scope ? 'Geen bruikbare plek gevonden om naar deze pagina te linken.'
                                : 'Geen suggesties in deze weergave.') + '</p>';
                $('#rr-il-suggestions').html(html);
                return;
            }

            rows.forEach(function (r) { html += RRIL.card(r); });
            $('#rr-il-suggestions').html(html);
        },

        card: function (r) {
            var preview = r.preview || {};
            var zin = preview.after
                ? RRIL.esc(preview.after).replace('«', '<mark>').replace('»', '</mark>')
                : '';

            var acties = r.status === 'applied'
                ? '<button class="button rr-il-act" data-act="undo" data-id="' + r.id + '">Terugdraaien</button>'
                : '<button class="button button-primary rr-il-act" data-act="approve" data-id="' + r.id + '">Goedkeuren</button> ' +
                  '<button class="button rr-il-act" data-act="reject" data-id="' + r.id + '">Afwijzen</button> ' +
                  '<button class="button rr-il-act" data-act="apply" data-id="' + r.id + '">Nu plaatsen</button>';

            var plek = r.position
                ? 'alinea ' + r.position.index + ' van ' + r.position.total
                : 'alinea onbekend';

            return '' +
            '<article class="rr-il-card rr-il-card--' + RRIL.escAttr(r.status) + '" data-id="' + r.id + '">' +

              '<div class="rr-il-status-row">' +
                '<span class="rr-il-status">' + RRIL.esc(RRIL.statusLabel(r.status)) + '</span>' +
                '<span class="rr-il-meta">' + RRIL.esc(RRIL.modeLabel(r.mode)) +
                  ' · ' + RRIL.esc(r.editor) + ' · ' + plek + '</span>' +
              '</div>' +

              // Waar gaat de link heen …
              '<div class="rr-il-party rr-il-party--doel">' +
                RRIL.thumb(r.target_thumb, r.target_title) +
                '<div class="rr-il-party-body">' +
                  '<span class="rr-il-party-label">Deze pagina krijgt de link</span>' +
                  '<a class="rr-il-party-title" href="' + RRIL.escAttr(r.target_url) + '" target="_blank" rel="noopener">' +
                    RRIL.esc(r.target_title) + '</a>' +
                  '<span class="rr-il-party-sub">' + r.target_inbound + ' inkomende links nu</span>' +
                '</div>' +
              '</div>' +

              '<div class="rr-il-arrow" aria-hidden="true">↑</div>' +

              // … en waar komt hij vandaan
              '<div class="rr-il-party rr-il-party--bron">' +
                RRIL.thumb(r.source_thumb, r.source_title) +
                '<div class="rr-il-party-body">' +
                  '<span class="rr-il-party-label">Vanaf deze pagina</span>' +
                  '<a class="rr-il-party-title" href="' + RRIL.escAttr(r.source_url) + '" target="_blank" rel="noopener">' +
                    RRIL.esc(r.source_title) + '</a>' +
                  '<span class="rr-il-party-sub"><a href="' + RRIL.escAttr(r.source_edit || '#') +
                    '" target="_blank" rel="noopener">bewerken</a></span>' +
                '</div>' +
              '</div>' +

              '<div class="rr-il-zin">' +
                '<span class="rr-il-party-label">In deze zin</span>' +
                (zin ? '<p class="rr-il-preview">' + zin + '</p>'
                     : '<p class="rr-il-preview rr-il-muted">Voorbeeld niet beschikbaar — de alinea is gewijzigd.</p>') +
                (r.mode !== 'wrap' && preview.before
                  ? '<p class="rr-il-preview-before"><span class="rr-il-label">was</span> ' + RRIL.esc(preview.before) + '</p>'
                  : '') +
              '</div>' +

              '<div class="rr-il-anchor-row">' +
                '<span class="rr-il-party-label">Met dit woord</span>' +
                '<span class="rr-il-anchor" data-id="' + r.id + '" title="Klik om aan te passen">' +
                  RRIL.esc(r.anchor) + '</span>' +
                '<span class="rr-il-muted">klik om aan te passen</span>' +
              '</div>' +

              (r.reason ? '<p class="rr-il-error">' + RRIL.esc(r.reason) + '</p>' : '') +

              '<footer class="rr-il-card-foot">' + acties + '</footer>' +
            '</article>';
        },

        statusLabel: function (s) {
            return { pending: 'te beoordelen', approved: 'goedgekeurd', rejected: 'afgewezen',
                     applied: 'geplaatst', failed: 'mislukt', undone: 'teruggedraaid' }[s] || s;
        },

        modeLabel: function (m) {
            return { wrap: 'bestaande woorden linken', rewrite: 'zin licht herschreven',
                     clause: 'bijzin toegevoegd' }[m] || m;
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
                    window.alert(msg);
                });
                return;
            }

            RRIL.post(action === 'undo' ? 'rr_il_undo' : 'rr_il_apply', { id: id }, function (d) {
                RRIL.updateCounts(d.counts);
                RRIL.replaceCard(id, d.row);
                if (!d.ok) { window.alert(d.message); }
            }, function (msg) {
                $btn.prop('disabled', false);
                window.alert(msg);
            });
        },

        replaceCard: function (id, row) {
            var $card = $('.rr-il-card[data-id="' + id + '"]');
            if (!row) { $card.remove(); return; }
            var status = $('#rr-il-filter').val();
            if (status !== 'all' && row.status !== status) {
                $card.fadeOut(200, function () { $(this).remove(); });
                return;
            }
            $card.replaceWith(RRIL.card(row));
        },

        approveAll: function () {
            var ids = $('.rr-il-card').map(function () { return parseInt($(this).data('id'), 10); }).get();
            if (!ids.length) { return; }
            var b = RRIL.busy('#rr-il-approve-all');
            var i = 0;
            (function next() {
                if (i >= ids.length) { b.done(); RRIL.loadSuggestions(); return; }
                b.step('Goedkeuren… ' + (i + 1) + '/' + ids.length);
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

            var afgerond = false;
            var done = function (save) {
                if (afgerond) { return; }
                afgerond = true;
                var val = $input.val();
                if (!save || val === old) { $el.text(old); return; }
                $el.text('opslaan…');
                RRIL.post('rr_il_update', { id: id, anchor: val }, function (d) {
                    RRIL.replaceCard(id, d.row);
                }, function (msg) {
                    $el.text(old);
                    window.alert(msg);
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

            var b = RRIL.busy('#rr-il-apply-btn');
            $('#rr-il-apply-log').empty();

            RRIL.post('rr_il_list', { status: 'approved', limit: 200 }, function (d) {
                var ids = d.rows.map(function (r) { return parseInt(r.id, 10); });
                if (!ids.length) {
                    b.done();
                    RRIL.log('Niets goedgekeurd om te plaatsen.', 'muted');
                    return;
                }
                RRIL.applyNext(ids, 0, { ok: 0, fail: 0, retry: 0 }, b);
            });
        },

        applyNext: function (ids, i, tally, b) {
            if (i >= ids.length) {
                b.done();
                var slot = 'Klaar: ' + tally.ok + ' geplaatst';
                if (tally.retry) { slot += ', ' + tally.retry + ' wachten op ruimte (blijven goedgekeurd staan)'; }
                if (tally.fail) { slot += ', ' + tally.fail + ' mislukt'; }
                RRIL.log(slot + '.', (tally.fail || tally.retry) ? 'warn' : 'ok');
                RRIL.loadStats();
                return;
            }

            b.step('Plaatsen… ' + (i + 1) + '/' + ids.length);

            RRIL.post('rr_il_apply', { id: ids[i] }, function (d) {
                var row = d.row || {};
                if (d.ok) {
                    tally.ok++;
                    RRIL.log('✓ ' + (row.source_title || '#' + ids[i]) + ' → ' + (row.target_title || '') +
                        ' ("' + (row.anchor || '') + '")', 'ok');
                } else if (d.retry) {
                    tally.retry++;
                    RRIL.log('· ' + (row.source_title || '#' + ids[i]) + ': ' + d.message + ' — blijft klaarstaan', 'muted');
                } else {
                    tally.fail++;
                    RRIL.log('– ' + (row.source_title || '#' + ids[i]) + ': ' + d.message, 'warn');
                }
                RRIL.updateCounts(d.counts);
                RRIL.applyNext(ids, i + 1, tally, b);
            }, function (msg) {
                tally.fail++;
                RRIL.log('– #' + ids[i] + ': ' + msg, 'warn');
                RRIL.applyNext(ids, i + 1, tally, b);
            });
        },

        log: function (text, kind) {
            $('#rr-il-apply-log').append('<div class="rr-il-log-line rr-il-log--' + (kind || '') + '">' +
                RRIL.esc(text) + '</div>');
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
            $('#rr-il-graph').html('<p class="rr-il-empty">Grafiek laden…</p>');
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

            // Losse knopen trekken de graaf uit elkaar en maken het geheel
            // onleesbaar. Standaard tonen we het deel dat verbonden is; de
            // weespagina's staan in het overzicht, niet hier.
            var verbonden = {};
            data.links.forEach(function (l) { verbonden[l.source] = true; verbonden[l.target] = true; });
            var nodes = data.nodes.filter(function (n) { return verbonden[n.id]; });
            var losse = data.nodes.length - nodes.length;

            try {
                RRIL.graph = ForceGraph3D()(el)
                    .backgroundColor('#0b1020')
                    .width(el.clientWidth)
                    .height(el.clientHeight)
                    .graphData({ nodes: nodes, links: data.links })
                    .nodeId('id')
                    .nodeVal('val')
                    .nodeLabel(function (n) { return n.label + ' — ' + n.inbound + '× gelinkt'; })
                    .nodeColor(function (n) { return COLORS[n.state] || '#9CA3AF'; })
                    .linkColor(function (l) { return l.ours ? '#A855F7' : 'rgba(148,163,184,0.35)'; })
                    .linkWidth(function (l) { return l.ours ? 1.2 : 0.4; })
                    .linkDirectionalParticles(function (l) { return l.ours ? 2 : 0; })
                    .linkDirectionalParticleWidth(1.2)
                    .onNodeClick(function (n) {
                        window.open(rrIL.editUrl + '?post=' + n.id + '&action=edit', '_blank');
                    });
            } catch (e) {
                el.innerHTML = '<p class="rr-il-empty rr-il-error">De graaf kon niet worden opgebouwd: ' +
                    RRIL.esc(e.message) + '</p>';
                return;
            }

            var noot = nodes.length + ' verbonden pagina\'s, ' + data.links.length + ' links';
            if (losse) { noot += ' · ' + losse + ' weespagina\'s niet getoond'; }
            if (data.skipped) { noot += ' · ' + data.skipped + ' links naar andere post-types overgeslagen'; }
            $('#rr-il-graph-note').text(noot);

            $(window).off('resize.rril').on('resize.rril', function () {
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
            var b = RRIL.busy('#rr-il-export-btn');
            b.step('Exporteren…');
            RRIL.post('rr_il_export', {}, function (d) {
                var blob = new Blob(["﻿" + d.csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = d.filename;
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                URL.revokeObjectURL(url);
                b.done();
            }, function (msg) {
                b.done();
                window.alert(msg);
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

        updateCounts: function (counts) {
            if (!counts) { return; }
            $('#rr-il-count-pending').text(counts.pending || 0);
            $('#rr-il-count-approved').text(counts.approved || 0);
        },

        esc: function (s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        },

        // jQuery's text()/html() laat aanhalingstekens staan; in een attribuut
        // breken die de tag open. Vandaar een eigen variant voor href en class.
        escAttr: function (s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    };

    $(function () { RRIL.init(); });
    window.RRIL = RRIL;
})(jQuery);
