/* global jQuery, rrAdmin */
(function ($) {
    'use strict';

    var state = { paged: 1, totalPages: 1 };

    function ajax(action, data) {
        return $.post(rrAdmin.ajaxUrl, $.extend({ action: action, nonce: rrAdmin.nonce }, data));
    }

    function status(msg, isError) {
        $('#rr-alt-status').html(msg ? '<span class="' + (isError ? 'rr-alt-err' : '') + '">' + msg + '</span>' : '');
    }

    function card(item) {
        var $c = $('<div class="rr-alt-card" data-id="' + item.id + '"></div>');
        var thumb = item.thumb
            ? '<img src="' + item.thumb + '" alt="" loading="lazy">'
            : '<div class="rr-alt-noimg">?</div>';
        $c.append('<div class="rr-alt-thumb">' + thumb + '</div>');

        var $body = $('<div class="rr-alt-body"></div>');
        var name = item.filename || ('#' + item.id);
        var editLink = item.edit ? ' <a href="' + item.edit + '" target="_blank" class="rr-alt-editlink">↗</a>' : '';
        $body.append('<div class="rr-alt-name" title="' + name + '">' + name + editLink + '</div>');
        $body.append('<textarea class="rr-alt-text" rows="2" placeholder="' + 'Nog geen voorstel — klik Genereer' + '"></textarea>');

        var $row = $('<div class="rr-alt-btns"></div>');
        $row.append('<button class="button rr-alt-gen">Genereer</button> ');
        $row.append('<button class="button button-primary rr-alt-apply" disabled>Opslaan</button> ');
        $row.append('<span class="rr-alt-state"></span>');
        $body.append($row);

        $c.append($body);
        return $c;
    }

    function renderPager() {
        var $p = $('#rr-alt-pager').empty();
        if (state.totalPages <= 1) { return; }
        $p.append('<button class="button rr-alt-prev"' + (state.paged <= 1 ? ' disabled' : '') + '>&laquo; Vorige</button> ');
        $p.append('<span class="rr-alt-pageinfo">Pagina ' + state.paged + ' / ' + state.totalPages + '</span> ');
        $p.append('<button class="button rr-alt-next"' + (state.paged >= state.totalPages ? ' disabled' : '') + '>Volgende &raquo;</button>');
    }

    function scan(paged) {
        status('Scannen…');
        $('#rr-alt-list').addClass('rr-alt-loading');
        ajax('rr_alt_scan', { paged: paged || 1 })
            .done(function (res) {
                if (!res || !res.success) { status((res && res.data) || 'Fout bij scannen.', true); return; }
                var d = res.data;
                state.paged = d.paged;
                state.totalPages = d.total_pages;
                var $list = $('#rr-alt-list').empty();
                if (!d.items.length) {
                    $list.append('<p class="rr-alt-empty">🎉 Geen afbeeldingen zonder alt-tekst gevonden.</p>');
                    $('#rr-alt-generate-all, #rr-alt-apply-all').prop('disabled', true);
                } else {
                    d.items.forEach(function (it) { $list.append(card(it)); });
                    $('#rr-alt-generate-all').prop('disabled', false);
                }
                status('Totaal zonder alt-tekst: ' + d.total);
                renderPager();
            })
            .fail(function () { status('Netwerkfout bij scannen.', true); })
            .always(function () { $('#rr-alt-list').removeClass('rr-alt-loading'); });
    }

    function generate($card) {
        var id = $card.data('id');
        var $gen = $card.find('.rr-alt-gen');
        var $st = $card.find('.rr-alt-state');
        $gen.prop('disabled', true);
        $st.text('Genereren…').removeClass('rr-alt-err');
        return ajax('rr_alt_generate', { id: id })
            .done(function (res) {
                if (res && res.success) {
                    $card.find('.rr-alt-text').val(res.data.alt);
                    $card.find('.rr-alt-apply').prop('disabled', false);
                    $st.text('Voorstel klaar — controleer & sla op.');
                } else {
                    $st.text((res && res.data) || 'Fout.').addClass('rr-alt-err');
                }
            })
            .fail(function () { $st.text('Netwerkfout.').addClass('rr-alt-err'); })
            .always(function () { $gen.prop('disabled', false); });
    }

    function apply($card) {
        var id = $card.data('id');
        var alt = $.trim($card.find('.rr-alt-text').val());
        var $st = $card.find('.rr-alt-state');
        if (!alt) { $st.text('Leeg — niets opgeslagen.').addClass('rr-alt-err'); return; }
        $card.find('.rr-alt-apply').prop('disabled', true);
        ajax('rr_alt_apply', { id: id, alt: alt })
            .done(function (res) {
                if (res && res.success) {
                    $st.text('✓ Opgeslagen').removeClass('rr-alt-err');
                    $card.addClass('rr-alt-done');
                } else {
                    $st.text((res && res.data) || 'Fout.').addClass('rr-alt-err');
                    $card.find('.rr-alt-apply').prop('disabled', false);
                }
            })
            .fail(function () { $st.text('Netwerkfout.').addClass('rr-alt-err'); $card.find('.rr-alt-apply').prop('disabled', false); });
    }

    // Sequentieel genereren voor alle kaarten op de pagina (ontziet rate-limits).
    function generateAll() {
        var $cards = $('#rr-alt-list .rr-alt-card').not('.rr-alt-done');
        var i = 0;
        $('#rr-alt-generate-all').prop('disabled', true);
        (function next() {
            if (i >= $cards.length) {
                $('#rr-alt-generate-all').prop('disabled', false);
                $('#rr-alt-apply-all').prop('disabled', false);
                status('Klaar met genereren. Controleer de voorstellen en sla op.');
                return;
            }
            status('Genereren ' + (i + 1) + ' / ' + $cards.length + '…');
            generate($($cards[i])).always(function () { i++; next(); });
        })();
    }

    function applyAll() {
        $('#rr-alt-list .rr-alt-card').each(function () {
            var $c = $(this);
            if ($c.hasClass('rr-alt-done')) { return; }
            if ($.trim($c.find('.rr-alt-text').val())) { apply($c); }
        });
    }

    $(function () {
        $('#rr-alt-scan').on('click', function () { scan(1); });
        $('#rr-alt-generate-all').on('click', generateAll);
        $('#rr-alt-apply-all').on('click', applyAll);

        $('#rr-alt-list').on('click', '.rr-alt-gen', function () { generate($(this).closest('.rr-alt-card')); });
        $('#rr-alt-list').on('click', '.rr-alt-apply', function () { apply($(this).closest('.rr-alt-card')); });
        $('#rr-alt-list').on('input', '.rr-alt-text', function () {
            var $c = $(this).closest('.rr-alt-card');
            $c.find('.rr-alt-apply').prop('disabled', !$.trim($(this).val()));
        });

        $('#rr-alt-pager').on('click', '.rr-alt-prev', function () { scan(state.paged - 1); });
        $('#rr-alt-pager').on('click', '.rr-alt-next', function () { scan(state.paged + 1); });
    });
})(jQuery);
