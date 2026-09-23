/**
 * RankRepair – Image Optimizer Add-on
 * Implements Figma design node 29:2
 */

(function ($) {
    'use strict';

    var RRImg = {
        allImages:    [],
        selectedIds:  [],
        currentIndex: 0,
        isRunning:    false,
        isStopped:    false,
        totalSaved:   0,
        successCount: 0,
        errorCount:   0,
        skippedCount: 0,

        // =====================================================================
        // Boot
        // =====================================================================

        init: function () {
            this.bindSettingsBar();
            this.bindTableEvents();
            this.bindFooterBar();
            this.bindDisplayScanner();
            this.bindExclusions();
            this.autoScan();
            this.refreshExcludedCount();
        },

        // =====================================================================
        // Settings bar
        // =====================================================================

        bindSettingsBar: function () {
            // Format segment
            $(document).on('click', '#rr-img-format-seg .rr-img-seg__btn', function () {
                $('#rr-img-format-seg .rr-img-seg__btn').removeClass('rr-img-seg__btn--active');
                $(this).addClass('rr-img-seg__btn--active');
                RRImg.saveSetting('format', $(this).data('val'));
                RRImg.autoScan();
            });

            // Max width segment
            $(document).on('click', '#rr-img-width-seg .rr-img-seg__btn', function () {
                $('#rr-img-width-seg .rr-img-seg__btn').removeClass('rr-img-seg__btn--active');
                $(this).addClass('rr-img-seg__btn--active');
                RRImg.saveSetting('max_width', $(this).data('val'));
                RRImg.autoScan();
            });

            // Quality slider
            $('#rr-img-quality-slider').on('input', function () {
                var val = $(this).val();
                $('#rr-img-quality-val').text(val + '%');
                var pct = Math.round((val - 60) / 40 * 100);
                $('#rr-img-slider-fill').css('width', pct + '%');
            }).on('change', function () {
                RRImg.saveSetting('quality', $(this).val());
                RRImg.autoScan();
            });

            // Backup toggle
            $('#rr-img-backup-toggle').on('click', function () {
                var $btn   = $(this);
                var newVal = $btn.data('val') === '1' ? '0' : '1';
                $btn.data('val', newVal)
                    .attr('aria-pressed', newVal === '1')
                    .toggleClass('rr-img-toggle--on', newVal === '1');
                RRImg.saveSetting('backup_originals', newVal);
            });
        },

        saveSetting: function (key, val) {
            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_img_save_settings',
                nonce:  rrAdmin.nonce,
                [key]:  val
            });
        },

        // =====================================================================
        // Auto-scan
        // =====================================================================

        autoScan: function () {
            var $tbody = $('#rr-img-tbody');
            $tbody.html(
                '<tr><td colspan="10" class="rr-img-empty-state">' +
                '<div class="rr-img-empty-spinner"><span class="rr-spin rr-spin--lg"></span>' +
                '<span>Afbeeldingen laden...</span></div></td></tr>'
            );

            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_img_scan',
                nonce:  rrAdmin.nonce
            }, function (response) {
                if (!response.success) {
                    $tbody.html('<tr><td colspan="10" class="rr-img-empty-state" style="color:var(--rr-danger)">' +
                        RRImg.esc(response.data || 'Scanfout') + '</td></tr>');
                    return;
                }
                RRImg.allImages   = response.data.images;
                RRImg.selectedIds = [];
                RRImg.renderTable(RRImg.allImages);
                RRImg.updateStats(response.data);
                RRImg.updateFooter();
                $('#rr-img-all-count').text(RRImg.allImages.filter(function (i) { return i.needs_compression; }).length);
            }).fail(function () {
                $tbody.html('<tr><td colspan="10" class="rr-img-empty-state" style="color:var(--rr-danger)">Verbindingsfout. Herlaad de pagina.</td></tr>');
            });
        },

        updateStats: function (data) {
            if (!data) return;
            if (data.total_size_text) {
                var needsComp = RRImg.allImages.filter(function (i) { return i.needs_compression; });
                var estTotal  = needsComp.reduce(function (s, i) { return s + i.est_size; }, 0);
                var estSaved  = needsComp.reduce(function (s, i) { return s + (i.file_size - i.est_size); }, 0);
                $('#rr-img-stat-total-size').text(data.total_size_text);
                $('#rr-img-stat-total-sub').text('\u2192 ' + RRImg.formatBytes(estTotal) + ' na optimalisatie');
                var estPct = data.total_bytes > 0 ? Math.round(estSaved / data.total_bytes * 100) : 0;
                $('#rr-img-stat-saving-pct').text(estPct + '%');
                $('#rr-img-stat-saving-sub').text('\u223C' + RRImg.formatBytes(estSaved) + ' te besparen');
                $('#rr-img-footer-savings').text(RRImg.formatBytes(estSaved));
                $('#rr-img-footer-pct').text(estPct);
            }
        },

        // =====================================================================
        // Table
        // =====================================================================

        renderTable: function (images) {
            var $tbody = $('#rr-img-tbody');
            $tbody.empty();

            if (!images || images.length === 0) {
                $tbody.html('<tr><td colspan="10" class="rr-img-empty-state">' +
                    '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--rr-gray-300)" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '<div style="margin-top:8px;color:var(--rr-gray-400)">Alle afbeeldingen zijn al geoptimaliseerd!</div>' +
                    '</td></tr>');
                return;
            }

            images.forEach(function (img) {
                $tbody.append(RRImg.buildRow(img));
            });
        },

        buildRow: function (img) {
            var isCompressed = img.status === 'done';
            var isProcessing = img.status === 'processing';
            var isQueued     = img.status === 'queued';

            var rowBg  = isCompressed ? ' class="rr-img-row--done"' : '';
            var thumb  = img.thumb_url
                ? '<img src="' + img.thumb_url + '" class="rr-img-thumb" alt="">'
                : '<div class="rr-img-thumb-placeholder"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>';

            // Type badge
            var mime  = img.mime_type || '';
            var label = mime.replace('image/', '').toUpperCase();
            var badgeCls = mime === 'image/png' ? 'rr-img-type-badge--png'
                         : mime === 'image/gif' ? 'rr-img-type-badge--gif'
                         : mime === 'image/webp'? 'rr-img-type-badge--webp'
                         : 'rr-img-type-badge--jpg';

            // Dimensions
            var dimStr = '';
            if (img.width && img.height) {
                if (img.will_resize) {
                    dimStr = '\u2192 ' + img.new_width + ' \u00D7 ' + img.new_height + 'px';
                } else {
                    dimStr = img.width + ' \u00D7 ' + img.height + 'px';
                }
            }

            // Savings bar fill %
            var barPct = Math.min(100, Math.max(0, img.est_savings_pct || 0));
            var barColor = barPct >= 80 ? '#10b981' : barPct >= 50 ? '#f59e0b' : '#6366f1';

            // Status badge
            var statusHtml = RRImg.statusBadge(img);

            // Action button
            var actionHtml = '';
            if (isCompressed) {
                actionHtml = '<button class="rr-img-action-btn rr-img-action-btn--ghost rr-img-restore-btn" data-id="' + img.id + '">' +
                    __('Ongedaan maken') + '</button>';
            } else if (isProcessing) {
                actionHtml = '<button class="rr-img-action-btn rr-img-action-btn--ghost rr-img-cancel-btn" data-id="' + img.id + '">' +
                    __('Annuleren') + '</button>';
            } else {
                actionHtml = '<button class="rr-img-action-btn rr-img-action-btn--indigo rr-img-compress-btn" data-id="' + img.id + '">' +
                    __('Optimaliseer') + '</button>';
            }

            // Uitsluiten — eigen kolom, los van de hoofdactie. Niet tijdens verwerking.
            var exclHtml = isProcessing ? '' :
                '<button class="rr-img-excl-col-btn rr-img-exclude-btn" data-id="' + img.id + '" title="' +
                __('Nooit optimaliseren') + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="6" y1="18" x2="18" y2="6"/></svg>' +
                '</button>';

            var checked = RRImg.selectedIds.indexOf(img.id) !== -1 ? ' checked' : '';

            return '<tr id="rr-img-row-' + img.id + '"' + rowBg + '>' +
                '<td class="rr-img-td rr-img-td--check">' +
                  '<input type="checkbox" class="rr-img-checkbox rr-img-row-check" data-id="' + img.id + '"' + checked + '>' +
                '</td>' +
                '<td class="rr-img-td rr-img-td--thumb">' + thumb + '</td>' +
                '<td class="rr-img-td rr-img-td--name">' +
                  '<div class="rr-img-filename">' + RRImg.esc(img.file_name) + '</div>' +
                  '<div class="rr-img-meta-row">' +
                    '<span class="rr-img-type-badge ' + badgeCls + '">' + label + '</span>' +
                    (img.width ? '<span class="rr-img-dim-original">' + img.width + ' \u00D7 ' + img.height + 'px</span>' : '') +
                  '</div>' +
                '</td>' +
                '<td class="rr-img-td rr-img-td--size rr-img-size--bad" id="rr-img-size-' + img.id + '">' +
                  RRImg.esc(img.size_text) +
                '</td>' +
                '<td class="rr-img-td rr-img-td--after" id="rr-img-after-' + img.id + '">' +
                  '<span class="rr-img-size--good">' + RRImg.esc(img.est_size_text) + '</span>' +
                '</td>' +
                '<td class="rr-img-td rr-img-td--savings">' +
                  '<div class="rr-img-savings-badge" id="rr-img-sav-' + img.id + '">\u2193 ' + (img.est_savings_pct || 0) + '%</div>' +
                  '<div class="rr-img-savings-bar">' +
                    '<div class="rr-img-savings-bar__fill" style="width:' + barPct + '%;background:' + barColor + '"></div>' +
                  '</div>' +
                '</td>' +
                '<td class="rr-img-td rr-img-td--dim">' + (dimStr ? RRImg.esc(dimStr) : '—') + '</td>' +
                '<td class="rr-img-td rr-img-td--status">' + statusHtml + '</td>' +
                '<td class="rr-img-td rr-img-td--excl">' + exclHtml + '</td>' +
                '<td class="rr-img-td rr-img-td--action" id="rr-img-act-' + img.id + '">' + actionHtml + '</td>' +
                '</tr>';
        },

        statusBadge: function (img) {
            var st = img.status || 'waiting';
            if (st === 'done') {
                return '<span class="rr-img-status rr-img-status--done">\u2713 Klaar</span>';
            } else if (st === 'processing') {
                return '<span class="rr-img-status rr-img-status--processing">\u27F3 Bezig...</span>';
            } else if (st === 'queued') {
                return '<span class="rr-img-status rr-img-status--queued">\u2B24 In wachtrij</span>';
            } else if (st === 'error') {
                return '<span class="rr-img-status rr-img-status--error">\u2717 Fout</span>';
            } else {
                return '<span class="rr-img-status rr-img-status--wait">Wacht</span>';
            }
        },

        // =====================================================================
        // Table events
        // =====================================================================

        bindTableEvents: function () {
            // Select all checkbox
            $(document).on('change', '#rr-img-check-all', function () {
                var checked = this.checked;
                $('.rr-img-row-check').prop('checked', checked);
                if (checked) {
                    RRImg.selectedIds = RRImg.allImages.map(function (i) { return i.id; });
                } else {
                    RRImg.selectedIds = [];
                }
                RRImg.updateFooter();
            });

            // Row checkbox
            $(document).on('change', '.rr-img-row-check', function () {
                var id = parseInt($(this).data('id'), 10);
                if (this.checked) {
                    if (RRImg.selectedIds.indexOf(id) === -1) RRImg.selectedIds.push(id);
                } else {
                    RRImg.selectedIds = RRImg.selectedIds.filter(function (x) { return x !== id; });
                }
                $('#rr-img-check-all').prop('indeterminate',
                    RRImg.selectedIds.length > 0 && RRImg.selectedIds.length < RRImg.allImages.length);
                $('#rr-img-check-all').prop('checked',
                    RRImg.selectedIds.length === RRImg.allImages.length && RRImg.allImages.length > 0);
                RRImg.updateFooter();
            });

            // Select all button in header
            $(document).on('click', '#rr-img-select-all-btn', function () {
                var allChecked = RRImg.selectedIds.length === RRImg.allImages.length;
                if (allChecked) {
                    RRImg.selectedIds = [];
                    $('.rr-img-row-check, #rr-img-check-all').prop('checked', false);
                    $(this).text('Selecteer alles');
                } else {
                    RRImg.selectedIds = RRImg.allImages.map(function (i) { return i.id; });
                    $('.rr-img-row-check, #rr-img-check-all').prop('checked', true);
                    $(this).text('Deselecteer alles');
                }
                RRImg.updateFooter();
            });

            // Single compress
            $(document).on('click', '.rr-img-compress-btn', function () {
                RRImg.compressSingle(parseInt($(this).data('id'), 10));
            });

            // Restore
            $(document).on('click', '.rr-img-restore-btn', function () {
                RRImg.restoreSingle(parseInt($(this).data('id'), 10));
            });

            // Uitsluiten vanuit de hoofdtabel (bv. handtekening-afbeeldingen)
            $(document).on('click', '.rr-img-exclude-btn', function () {
                var id = parseInt($(this).data('id'), 10);
                if (!confirm('Deze afbeelding nooit meer optimaliseren? Je kunt dit later terugdraaien via "Uitgesloten".')) return;
                RRImg.excludeImage(id);
            });

            // Export btn (both locations)
            $(document).on('click', '#rr-img-export-btn, #rr-img-export-footer-btn', function () {
                RRImg.exportReport();
            });

            // Stop
            $(document).on('click', '#rr-img-stop-btn', function () {
                RRImg.isStopped = true;
            });

            // Optimize all
            $(document).on('click', '#rr-img-optimize-all', function () {
                if (!confirm(rrImg.strings.confirmBulk)) return;
                var toProcess = RRImg.allImages.filter(function (i) { return i.needs_compression && i.status !== 'done'; });
                RRImg.startBulk(toProcess);
            });
        },

        // =====================================================================
        // Footer bar
        // =====================================================================

        bindFooterBar: function () {
            $(document).on('click', '#rr-img-optimize-sel-btn', function () {
                if (!RRImg.selectedIds.length) return;
                if (!confirm(rrImg.strings.confirmSelection)) return;
                var toProcess = RRImg.allImages.filter(function (i) {
                    return RRImg.selectedIds.indexOf(i.id) !== -1 && i.needs_compression && i.status !== 'done';
                });
                RRImg.startBulk(toProcess);
            });
        },

        updateFooter: function () {
            var count = RRImg.selectedIds.length;
            var $selBtn = $('#rr-img-optimize-sel-btn');
            var $selLbl = $('#rr-img-footer-sel');
            var $selCnt = $('#rr-img-sel-count');

            $selCnt.text(count);
            $selBtn.prop('disabled', count === 0);

            if (count > 0) {
                $selLbl.text(count + ' geselecteerd').show();
            } else {
                $selLbl.hide();
            }
        },

        // =====================================================================
        // Bulk compression
        // =====================================================================

        startBulk: function (images) {
            if (!images || images.length === 0) {
                alert(rrImg.strings.noImages);
                return;
            }

            this.isRunning    = true;
            this.isStopped    = false;
            this.currentIndex = 0;
            this.totalSaved   = 0;
            this.successCount = 0;
            this.errorCount   = 0;
            this.skippedCount = 0;

            // Mark all as queued
            images.forEach(function (img) {
                RRImg.setStatus(img.id, 'queued');
            });

            $('#rr-img-progress-wrap').show();
            $('#rr-img-optimize-all').prop('disabled', true);

            this._bulkImages = images;
            this.updateBulkProgress();
            this.processNext();
        },

        processNext: function () {
            if (this.isStopped || this.currentIndex >= this._bulkImages.length) {
                this.finishBulk();
                return;
            }

            var img = this._bulkImages[this.currentIndex];
            this.setStatus(img.id, 'processing');

            $.post(rrAdmin.ajaxUrl, {
                action:          'rr_img_compress',
                nonce:           rrAdmin.nonce,
                attachment_id:   img.id,
                convert_to_jpg:  rrImg.convertPngToJpg,
                convert_to_webp: rrImg.convertToWebp
            }, function (response) {
                if (response.success) {
                    var result = response.data;
                    if (result.status === 'compressed') {
                        RRImg.successCount++;
                        RRImg.totalSaved += result.saved;
                        RRImg.setStatus(img.id, 'done');
                        RRImg.updateRowAfterCompress(img.id, result);
                    } else {
                        RRImg.skippedCount++;
                        RRImg.setStatus(img.id, 'done');
                    }
                } else {
                    RRImg.errorCount++;
                    RRImg.setStatus(img.id, 'error');
                }
                RRImg.currentIndex++;
                RRImg.updateBulkProgress();
                setTimeout(function () { RRImg.processNext(); }, 300);
            }).fail(function () {
                RRImg.errorCount++;
                RRImg.setStatus(img.id, 'error');
                RRImg.currentIndex++;
                RRImg.updateBulkProgress();
                setTimeout(function () { RRImg.processNext(); }, 1500);
            });
        },

        updateBulkProgress: function () {
            var total = this._bulkImages.length;
            var done  = this.currentIndex;
            var pct   = total > 0 ? Math.round(done / total * 100) : 0;

            $('#rr-img-progress-fill').css('width', pct + '%').find('.rr-img-progress-bar-pct').text(pct + '%');
            $('#rr-img-progress-count').text(done + ' / ' + total);
            $('#rr-img-progress-saved').text(this.totalSaved > 0 ? '\uD83D\uDCBE ' + RRImg.formatBytes(this.totalSaved) + ' bespaard' : '');
        },

        finishBulk: function () {
            this.isRunning = false;
            $('#rr-img-progress-fill').css('width', '100%').find('.rr-img-progress-bar-pct').text('100%');
            $('#rr-img-optimize-all').prop('disabled', false);

            // Update footer savings
            if (this.totalSaved > 0) {
                $('#rr-img-footer-savings').text(RRImg.formatBytes(this.totalSaved));
            }

            setTimeout(function () {
                $('#rr-img-progress-wrap').fadeOut(400);
            }, 2000);
        },

        // =====================================================================
        // Single compress / restore
        // =====================================================================

        compressSingle: function (id) {
            this.setStatus(id, 'processing');
            $('#rr-img-act-' + id).html(
                '<button class="rr-img-action-btn rr-img-action-btn--ghost rr-img-cancel-btn" data-id="' + id + '">Annuleren</button>'
            );

            $.post(rrAdmin.ajaxUrl, {
                action:          'rr_img_compress',
                nonce:           rrAdmin.nonce,
                attachment_id:   id,
                convert_to_jpg:  rrImg.convertPngToJpg,
                convert_to_webp: rrImg.convertToWebp
            }, function (response) {
                if (response.success) {
                    RRImg.setStatus(id, 'done');
                    RRImg.updateRowAfterCompress(id, response.data);
                } else {
                    RRImg.setStatus(id, 'error');
                    $('#rr-img-act-' + id).html(
                        '<button class="rr-img-action-btn rr-img-action-btn--indigo rr-img-compress-btn" data-id="' + id + '">Opnieuw</button>'
                    );
                }
            }).fail(function () {
                RRImg.setStatus(id, 'error');
                $('#rr-img-act-' + id).html(
                    '<button class="rr-img-action-btn rr-img-action-btn--indigo rr-img-compress-btn" data-id="' + id + '">Opnieuw</button>'
                );
            });
        },

        restoreSingle: function (id) {
            if (!confirm('Weet je zeker dat je het origineel wilt herstellen?')) return;
            $.post(rrAdmin.ajaxUrl, {
                action:        'rr_img_restore',
                nonce:         rrAdmin.nonce,
                attachment_id: id
            }, function (response) {
                if (response.success) {
                    RRImg.autoScan();
                } else {
                    alert(response.data || 'Fout bij herstellen.');
                }
            });
        },

        // =====================================================================
        // Row update after compression
        // =====================================================================

        updateRowAfterCompress: function (id, result) {
            if (result.status !== 'compressed') return;

            var $row = $('#rr-img-row-' + id);
            $row.addClass('rr-img-row--done');

            // Update size cells
            $('#rr-img-size-' + id).text(RRImg.formatBytes(result.original_size))
                .removeClass('rr-img-size--bad').addClass('rr-img-size--muted');
            $('#rr-img-after-' + id).html('<span class="rr-img-size--good">' + RRImg.formatBytes(result.new_size) + '</span>');
            $('#rr-img-sav-' + id).text('\u2193 ' + result.percentage + '%');

            // Action button → restore
            $('#rr-img-act-' + id).html(
                '<button class="rr-img-action-btn rr-img-action-btn--ghost rr-img-restore-btn" data-id="' + id + '">Ongedaan maken</button>'
            );
        },

        setStatus: function (id, status) {
            var img = RRImg.allImages.find(function (i) { return i.id === id; });
            if (img) img.status = status;
            // Re-render just the status cell
            var $statusCell = $('#rr-img-row-' + id + ' .rr-img-td--status');
            $statusCell.html(RRImg.statusBadge(img || {status: status}));
        },

        // =====================================================================
        // Export report
        // =====================================================================

        exportReport: function () {
            if (!RRImg.allImages.length) {
                alert('Geen afbeeldingen geladen. Wacht tot de scan klaar is.');
                return;
            }
            var rows = [['Bestandsnaam', 'Huidig (bytes)', 'Na optimalisatie (est.)', 'Besparing %', 'Breedte', 'Hoogte', 'Status']];
            RRImg.allImages.forEach(function (img) {
                rows.push([img.file_name, img.file_size, img.est_size, img.est_savings_pct, img.width, img.height, img.status || 'wacht']);
            });
            var csv = rows.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
            var blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href = url; a.download = 'rankrepair-afbeeldingen-rapport.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        },

        // =====================================================================
        // Display-Size Scanner
        // =====================================================================

        bindDisplayScanner: function () {
            $(document).on('click', '#rr-img-ds-scan-btn', function () {
                RRImg.runDisplayScan();
            });

            $(document).on('click', '.rr-img-ds-resize-btn', function () {
                var $btn = $(this);
                var id   = parseInt($btn.data('id'), 10);
                var w    = parseInt($btn.data('width'), 10);
                if (!id || !w) return;
                if (!confirm('Verkleinen naar ' + w + 'px breed? Origineel wordt gebackupt.')) return;
                $btn.prop('disabled', true).text('Bezig...');

                $.post(rrAdmin.ajaxUrl, {
                    action:        'rr_img_ds_resize',
                    nonce:         rrAdmin.nonce,
                    attachment_id: id,
                    target_width:  w
                }).done(function (resp) {
                    if (resp.success) {
                        var msg = resp.data.message || 'Klaar';
                        $btn.closest('tr').find('.rr-img-ds-status').html('<span style="color:#10b981;font-weight:600">✓ ' + RRImg.esc(msg) + '</span>');
                        $btn.text('Klaar').css({background: '#10b981'});
                    } else {
                        $btn.prop('disabled', false).text('Verkleinen');
                        alert('Fout: ' + (resp.data || 'onbekend'));
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Verkleinen');
                    alert('Verbindingsfout.');
                });
            });
        },

        runDisplayScan: function () {
            var urls = $('#rr-img-ds-urls').val();
            var $btn = $('#rr-img-ds-scan-btn');
            $btn.prop('disabled', true).text('Scannen...');
            $('#rr-img-ds-empty').text('Bezig met scannen — kan tot 30 sec duren per pagina...').show();
            $('#rr-img-ds-table, #rr-img-ds-summary').hide();

            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_img_ds_scan',
                nonce:  rrAdmin.nonce,
                urls:   urls
            }).done(function (resp) {
                $btn.prop('disabled', false).text('🔍 Scan pagina(s)');
                if (!resp.success) {
                    $('#rr-img-ds-empty').text('Fout: ' + (resp.data || 'onbekend')).show();
                    return;
                }
                RRImg.renderDisplayScanResults(resp.data);
            }).fail(function () {
                $btn.prop('disabled', false).text('🔍 Scan pagina(s)');
                $('#rr-img-ds-empty').text('Verbindingsfout.').show();
            });
        },

        renderDisplayScanResults: function (data) {
            var imgs = data.images || [];
            $('#rr-img-ds-count').text(imgs.length);
            $('#rr-img-ds-savings').text(data.total_potential_text || '—');
            $('#rr-img-ds-summary').show();

            if (!imgs.length) {
                $('#rr-img-ds-table').hide();
                $('#rr-img-ds-empty').text('Geen te grote afbeeldingen gevonden op deze pagina(s). 👍').show();
                return;
            }

            var $tbody = $('#rr-img-ds-tbody').empty();
            imgs.forEach(function (img) {
                var pagesText = img.pages_count > 1 ? ' (op ' + img.pages_count + ' pagina\'s)' : '';
                var $row = $('<tr></tr>');
                $row.html(
                    '<td><img src="' + RRImg.esc(img.src) + '" style="max-width:48px;max-height:48px;border-radius:4px"></td>' +
                    '<td><strong>' + RRImg.esc(img.file_name) + '</strong>' +
                        '<div style="font-size:11px;color:#6b7280">' + RRImg.esc(img.natural_size_text) + pagesText + '</div></td>' +
                    '<td>' + img.natural_w + ' × ' + img.natural_h + 'px</td>' +
                    '<td>' + img.displayed_w + ' × ' + img.displayed_h + 'px</td>' +
                    '<td><span style="color:#ef4444;font-weight:600">' + img.ratio + '×</span></td>' +
                    '<td>' + img.target_w + 'px</td>' +
                    '<td class="rr-img-ds-action">' +
                        '<button class="rr-img-btn rr-img-btn--green rr-img-btn--sm rr-img-ds-resize-btn" ' +
                            'data-id="' + img.attachment_id + '" data-width="' + img.target_w + '">' +
                            'Verkleinen</button>' +
                        '<div class="rr-img-ds-status" style="margin-top:4px;font-size:11px"></div>' +
                    '</td>'
                );
                $tbody.append($row);
            });

            $('#rr-img-ds-table').show();
            $('#rr-img-ds-empty').hide();
        },

        // =====================================================================
        // Uitsluitingen (bv. handtekening-afbeeldingen die nooit mee mogen doen)
        // =====================================================================

        bindExclusions: function () {
            $(document).on('click', '#rr-img-excluded-btn', function () {
                RRImg.openExclusionsModal();
            });

            $(document).on('click', '#rr-img-excl-close', function () {
                $('#rr-img-excl-overlay').hide();
            });

            // Klik op de donkere achtergrond sluit de modal, klik in de modal zelf niet
            $(document).on('click', '#rr-img-excl-overlay', function (e) {
                if (e.target.id === 'rr-img-excl-overlay') $('#rr-img-excl-overlay').hide();
            });

            $(document).on('click', '.rr-img-excl-restore-btn', function () {
                var id = parseInt($(this).data('id'), 10);
                RRImg.unexcludeImage(id);
            });

            $(document).on('click', '#rr-img-excl-add-btn', function () {
                RRImg.openMediaPickerForExclusion();
            });
        },

        refreshExcludedCount: function () {
            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_img_excluded_list',
                nonce:  rrAdmin.nonce
            }, function (response) {
                if (response.success) {
                    $('#rr-img-excluded-count').text(response.data.total);
                }
            });
        },

        excludeImage: function (id) {
            $.post(rrAdmin.ajaxUrl, {
                action:        'rr_img_exclude',
                nonce:         rrAdmin.nonce,
                attachment_id: id
            }, function (response) {
                if (response.success) {
                    // Uit de huidige scanlijst halen — mag hier nooit meer in verschijnen
                    RRImg.allImages = RRImg.allImages.filter(function (i) { return i.id !== id; });
                    $('#rr-img-row-' + id).remove();
                    RRImg.refreshExcludedCount();
                } else {
                    alert(response.data || 'Fout bij uitsluiten.');
                }
            }).fail(function () {
                alert('Verbindingsfout.');
            });
        },

        unexcludeImage: function (id) {
            $.post(rrAdmin.ajaxUrl, {
                action:        'rr_img_unexclude',
                nonce:         rrAdmin.nonce,
                attachment_id: id
            }, function (response) {
                if (response.success) {
                    RRImg.openExclusionsModal(); // lijst verversen
                    RRImg.autoScan();            // kan weer terugkomen in de hoofdlijst
                } else {
                    alert(response.data || 'Fout bij terugzetten.');
                }
            }).fail(function () {
                alert('Verbindingsfout.');
            });
        },

        openExclusionsModal: function () {
            var $overlay = $('#rr-img-excl-overlay');
            var $list    = $('#rr-img-excl-list');
            $list.html('<div class="rr-img-excl-empty">Laden...</div>');
            $overlay.css('display', 'flex');

            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_img_excluded_list',
                nonce:  rrAdmin.nonce
            }, function (response) {
                if (!response.success) {
                    $list.html('<div class="rr-img-excl-empty">Fout bij laden.</div>');
                    return;
                }
                $('#rr-img-excluded-count').text(response.data.total);
                RRImg.renderExclusionsList(response.data.images);
            }).fail(function () {
                $list.html('<div class="rr-img-excl-empty">Verbindingsfout.</div>');
            });
        },

        renderExclusionsList: function (images) {
            var $list = $('#rr-img-excl-list');
            if (!images || images.length === 0) {
                $list.html('<div class="rr-img-excl-empty">Nog geen afbeeldingen uitgesloten.</div>');
                return;
            }
            $list.empty();
            images.forEach(function (img) {
                var thumb = img.thumb_url
                    ? '<img src="' + img.thumb_url + '" class="rr-img-excl-thumb" alt="">'
                    : '<div class="rr-img-excl-thumb rr-img-excl-thumb--empty"></div>';
                $list.append(
                    '<div class="rr-img-excl-row">' +
                        thumb +
                        '<div class="rr-img-excl-name">' + RRImg.esc(img.file_name || img.title) + '</div>' +
                        '<button class="rr-img-btn rr-img-btn--outline rr-img-btn--sm rr-img-excl-restore-btn" data-id="' + img.id + '">' +
                            'Terugzetten' +
                        '</button>' +
                    '</div>'
                );
            });
        },

        openMediaPickerForExclusion: function () {
            if (typeof wp === 'undefined' || !wp.media) {
                alert('Media-bibliotheek kon niet geladen worden.');
                return;
            }
            var frame = wp.media({
                title: 'Afbeelding(en) uitsluiten van optimalisatie',
                library: { type: 'image' },
                multiple: true,
                button: { text: 'Uitsluiten' }
            });
            frame.on('select', function () {
                var selection = frame.state().get('selection');
                var ids = selection.map(function (att) { return att.get('id'); });
                if (!ids.length) return;
                var remaining = ids.length;
                ids.forEach(function (id) {
                    $.post(rrAdmin.ajaxUrl, {
                        action:        'rr_img_exclude',
                        nonce:         rrAdmin.nonce,
                        attachment_id: id
                    }, function () {
                        RRImg.allImages = RRImg.allImages.filter(function (i) { return i.id !== id; });
                        $('#rr-img-row-' + id).remove();
                    }).always(function () {
                        remaining--;
                        if (remaining === 0) {
                            RRImg.openExclusionsModal();
                        }
                    });
                });
            });
            frame.open();
        },

        // =====================================================================
        // Helpers
        // =====================================================================

        formatBytes: function (bytes) {
            if (!bytes || bytes <= 0) return '0 B';
            var k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        esc: function (text) {
            if (!text && text !== 0) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(String(text)));
            return div.innerHTML;
        }
    };

    // Simple i18n stub – uses rrImg.strings when available
    function __(text) { return text; }

    $(document).ready(function () {
        if ($('.rr-img-wrap').length) {
            RRImg.init();
        }
    });

})(jQuery);
