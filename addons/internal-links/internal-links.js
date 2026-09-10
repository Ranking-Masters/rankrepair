(function ($) {
    'use strict';

    var RRIL = {
        total: 0,

        init: function () {
            $('#rr-il-scan-btn').on('click', function () { RRIL.startScan(); });
            $('#rr-il-export-btn').on('click', function () { RRIL.exportCsv(); });
            $(document).on('click', '.rr-il-suggest-btn', function () {
                RRIL.openSuggestions(parseInt($(this).data('id'), 10));
            });
            $(document).on('click', '#rr-il-modal-close, #rr-il-modal-overlay', function () { RRIL.closeModal(); });
        },

        startScan: function () {
            $('#rr-il-scan-btn').prop('disabled', true);
            $('#rr-il-progress').show();
            RRIL.scanBatch(0);
        },

        scanBatch: function (offset) {
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_scan', nonce: rrAdmin.nonce, offset: offset })
                .done(function (r) {
                    if (!r || !r.success) {
                        RRIL.scanError(r && r.data ? r.data.message : 'Scanfout');
                        return;
                    }
                    RRIL.total = r.data.total;
                    var pct = r.data.total > 0 ? Math.round(r.data.processed / r.data.total * 100) : 100;
                    $('#rr-il-progress-fill').css('width', pct + '%');
                    $('#rr-il-progress-txt').text(r.data.processed + ' / ' + r.data.total);

                    if (r.data.done) {
                        RRIL.loadStats();
                    } else {
                        RRIL.scanBatch(r.data.processed);
                    }
                })
                .fail(function () { RRIL.scanError('Verbindingsfout tijdens scan.'); });
        },

        scanError: function (msg) {
            $('#rr-il-scan-btn').prop('disabled', false);
            $('#rr-il-tbody').html('<tr><td colspan="5" style="color:#b32d2e">' + RRIL.esc(msg) + '</td></tr>');
        },

        loadStats: function () {
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_stats', nonce: rrAdmin.nonce })
                .done(function (r) {
                    $('#rr-il-scan-btn').prop('disabled', false);
                    setTimeout(function () { $('#rr-il-progress').fadeOut(300); }, 800);
                    if (!r || !r.success) { return; }
                    var d = r.data;
                    $('#rr-il-stat-orphans').text(d.orphans);
                    $('#rr-il-stat-thin').text(d.thin);
                    $('#rr-il-stat-avg').text(d.avg_inbound);
                    $('#rr-il-stat-total').text(d.total);
                    RRIL.renderRows(d.rows);
                })
                .fail(function () {
                    $('#rr-il-scan-btn').prop('disabled', false);
                    $('#rr-il-progress').fadeOut(300);
                });
        },

        renderRows: function (rows) {
            var $tb = $('#rr-il-tbody').empty();
            if (!rows.length) {
                $tb.html('<tr><td colspan="5">' + RRIL.esc('Geen orphan- of thin-pagina\'s gevonden. 🎉') + '</td></tr>');
                return;
            }
            rows.forEach(function (row) {
                var badge = row.inbound === 0
                    ? '<span class="rr-il-badge rr-il-badge--orphan">orphan</span>'
                    : '<span class="rr-il-badge rr-il-badge--thin">thin</span>';
                $tb.append(
                    '<tr data-id="' + row.id + '">' +
                        '<td>' + badge + ' ' + RRIL.esc(row.title) + '</td>' +
                        '<td>' + RRIL.esc(row.type) + '</td>' +
                        '<td>' + row.inbound + '</td>' +
                        '<td>' + row.outbound + '</td>' +
                        '<td><button class="button rr-il-suggest-btn" data-id="' + row.id + '">' + RRIL.esc('Suggesties') + '</button></td>' +
                    '</tr>'
                );
            });
        },

        openSuggestions: function (id) {
            RRIL.showModal('<div class="rr-il-modal-loading">' + RRIL.esc('Suggesties laden…') + '</div>');
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_suggest', nonce: rrAdmin.nonce, target_id: id })
                .done(function (r) {
                    if (!r || !r.success) { RRIL.showModal('<p style="color:#b32d2e">' + RRIL.esc('Fout bij laden.') + '</p>'); return; }
                    RRIL.renderSuggestions(r.data);
                })
                .fail(function () { RRIL.showModal('<p style="color:#b32d2e">' + RRIL.esc('Verbindingsfout.') + '</p>'); });
        },

        renderSuggestions: function (data) {
            var html = '';
            if (!data.has_ai) {
                html += '<div class="rr-il-note">' + RRIL.esc('Geen AI-key ingesteld — ankerteksten zijn heuristisch.') + '</div>';
            }
            if (!data.suggestions.length) {
                html += '<p>' + RRIL.esc('Geen relevante bronnen gevonden.') + '</p>';
            } else {
                html += '<table class="rr-il-modal-table"><thead><tr>' +
                        '<th>' + RRIL.esc('Bron') + '</th><th>' + RRIL.esc('Score') + '</th>' +
                        '<th>' + RRIL.esc('Ankertekst') + '</th><th>' + RRIL.esc('Context') + '</th>' +
                        '<th>' + RRIL.esc('Plaatsing') + '</th></tr></thead><tbody>';
                data.suggestions.forEach(function (s) {
                    html += '<tr>' +
                        '<td>' + RRIL.esc(s.source_title) + ' <span class="rr-il-muted">#' + s.source_id + '</span></td>' +
                        '<td>' + s.score + '</td>' +
                        '<td><strong>' + RRIL.esc(s.anchor_text) + '</strong></td>' +
                        '<td class="rr-il-context">' + RRIL.esc(s.context_sentence || '—') + '</td>' +
                        '<td>' + RRIL.esc(s.placement_hint) + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table>';
            }
            RRIL.showModal(html);
        },

        showModal: function (inner) {
            RRIL.closeModal();
            var $overlay = $('<div id="rr-il-modal-overlay"></div>');
            var $modal = $('<div id="rr-il-modal"><button id="rr-il-modal-close" aria-label="Sluiten">&times;</button><div id="rr-il-modal-body"></div></div>');
            $modal.find('#rr-il-modal-body').html(inner);
            $('body').append($overlay).append($modal);
        },

        closeModal: function () {
            $('#rr-il-modal-overlay, #rr-il-modal').remove();
        },

        exportCsv: function () {
            var $btn = $('#rr-il-export-btn').prop('disabled', true).text('Exporteren…');
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_export', nonce: rrAdmin.nonce })
                .done(function (r) {
                    if (r && r.success) {
                        var blob = new Blob([r.data.csv], { type: 'text/csv;charset=utf-8;' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url; a.download = r.data.filename;
                        document.body.appendChild(a); a.click(); document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    } else {
                        alert(RRIL.esc(r && r.data && r.data.message ? r.data.message : 'Exporteren mislukt.'));
                    }
                })
                .fail(function () { alert(RRIL.esc('Verbindingsfout tijdens exporteren.')); })
                .always(function () { $btn.prop('disabled', false).text('Exporteer CSV'); });
        },

        esc: function (s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }
    };

    $(function () { RRIL.init(); });
    window.RRIL = RRIL;
})(jQuery);
