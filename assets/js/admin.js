/**
 * WooCommerce Watermark Remover — Admin JavaScript
 *
 * Multi-select with parallel worker-pool processing.
 * Fires up to `maxConcurrent` tasks simultaneously; each finished
 * task immediately pulls the next from the queue.
 *
 * @since 1.2.0
 */
(function ($, wwr) {
    'use strict';

    // ── DOM refs ───────────────────────────────────────────────────
    var $grid          = $('#wwr-image-grid');
    var $cards         = $('.wwr-image-card');
    var $checkboxes    = $('.wwr-checkbox');
    var $selectAllBtn  = $('#wwr-select-all-btn');
    var $startBtn      = $('#wwr-start-btn');
    var $stopBtn       = $('#wwr-stop-btn');
    var $status        = $('#wwr-status');
    var $queueCounter  = $('#wwr-queue-counter');
    var $progress      = $('#wwr-progress-bar');
    var $progressFill  = $('.wwr-progress-fill');
    var $progressText  = $('.wwr-progress-text');

    // ── Config ─────────────────────────────────────────────────────
    var maxConcurrent  = parseInt(wwr.parallel_tasks, 10) || 2;

    // ── State ──────────────────────────────────────────────────────
    var selectedIds    = [];       // attachment IDs the user checked
    var queue          = [];       // IDs still waiting to start
    var activeTasks    = 0;        // how many are uploading/polling right now
    var isProcessing   = false;    // batch is in progress
    var stopRequested  = false;
    var completedCount = 0;
    var errorCount     = 0;
    var totalQueued    = 0;

    // Per-task tracking.
    var pollTimers     = {};       // attachmentId → setTimeout handle
    var activeXhrs     = [];       // all in-flight jQuery XHR objects

    // ── Helper: update start button ────────────────────────────────
    function updateStartButton() {
        $startBtn.prop('disabled', selectedIds.length === 0 || isProcessing);
        if (selectedIds.length > 1) {
            $startBtn.text(wwr.i18n.start_btn + ' (' + selectedIds.length + ')');
        } else {
            $startBtn.text(wwr.i18n.start_btn);
        }
    }

    // ── Helper: queue counter display ──────────────────────────────
    function updateQueueCounter() {
        if (!isProcessing) {
            $queueCounter.text('').hide();
            return;
        }
        var remaining = queue.length;
        var active    = activeTasks;
        $queueCounter
            .text(wwr.i18n.queue_progress.replace('%d', active).replace('%d', remaining))
            .show();
    }

    // ── Helper: per-card status label ──────────────────────────────
    function setCardStatus(attachmentId, text, cssClass) {
        var $card  = $cards.filter('[data-attachment-id="' + attachmentId + '"]');
        var $label = $card.find('.wwr-card-status');
        $label
            .text(text)
            .attr('class', 'wwr-card-status wwr-card-status-' + cssClass)
            .show();
    }

    // ── Helper: overall batch progress ─────────────────────────────
    function updateOverallProgress() {
        if (totalQueued === 0) return;
        var done = completedCount + errorCount;
        setProgress(Math.round(done / totalQueued * 100));
    }

    // ── Checkbox selection ─────────────────────────────────────────
    $checkboxes.on('change', function () {
        var val   = parseInt($(this).val(), 10);
        var $card = $cards.filter('[data-attachment-id="' + val + '"]');

        if ($(this).is(':checked')) {
            if (selectedIds.indexOf(val) === -1) selectedIds.push(val);
            $card.addClass('wwr-selected');
        } else {
            selectedIds = selectedIds.filter(function (id) { return id !== val; });
            $card.removeClass('wwr-selected');
        }

        updateStartButton();
        updateSelectAllButton();
    });

    // ── Card click toggles checkbox ─────────────────────────────────
    $cards.on('click', function (e) {
        if ($(e.target).is('input[type="checkbox"]') || isProcessing) return;
        var $cb = $(this).find('.wwr-checkbox');
        $cb.prop('checked', !$cb.is(':checked')).trigger('change');
    });

    // ── Select All / Deselect All ──────────────────────────────────
    function updateSelectAllButton() {
        if (selectedIds.length === $checkboxes.length && $checkboxes.length > 0) {
            $selectAllBtn.text(wwr.i18n.deselect_all);
        } else {
            $selectAllBtn.text(wwr.i18n.select_all);
        }
    }

    $selectAllBtn.on('click', function () {
        if (isProcessing) return;
        if (selectedIds.length === $checkboxes.length) {
            $checkboxes.prop('checked', false).trigger('change');
        } else {
            $checkboxes.prop('checked', true).trigger('change');
        }
    });

    // ── Start button ───────────────────────────────────────────────
    $startBtn.on('click', function () {
        if (isProcessing) return;
        if (selectedIds.length === 0) { alert(wwr.i18n.select_image); return; }
        if (!confirm(wwr.i18n.confirm_start)) return;
        startBatch();
    });

    // ── Stop button ────────────────────────────────────────────────
    $stopBtn.on('click', function () {
        if (!isProcessing) return;
        stopRequested = true;
        $stopBtn.prop('disabled', true).text(wwr.i18n.stopping);

        // Abort all in-flight XHRs.
        activeXhrs.forEach(function (xhr) { try { xhr.abort(); } catch (e) {} });
        activeXhrs = [];

        // Clear all poll timers.
        Object.keys(pollTimers).forEach(function (id) {
            clearTimeout(pollTimers[id]);
            delete pollTimers[id];
        });

        // Revert active cards.
        $cards.filter('.wwr-processing').each(function () {
            var $card = $(this);
            var attId = $card.data('attachment-id');
            $card.removeClass('wwr-processing');
            $card.find('.wwr-spinner-overlay').fadeOut(300, function () { $(this).remove(); });
            setCardStatus(attId, '', '');
        });

        // Reset queued cards.
        $cards.filter('.wwr-queued').removeClass('wwr-queued');
        $cards.find('.wwr-card-status').hide().text('');

        finishBatch(true);
    });

    // ═══════════════════════════════════════════════════════════════
    //  BATCH PROCESSING — Worker pool
    // ═══════════════════════════════════════════════════════════════

    function startBatch() {
        isProcessing    = true;
        stopRequested   = false;
        completedCount  = 0;
        errorCount      = 0;
        activeTasks     = 0;
        pollTimers      = {};
        activeXhrs      = [];

        queue      = selectedIds.slice();
        totalQueued = queue.length;

        // UI setup.
        $startBtn.prop('disabled', true).text(wwr.i18n.starting);
        $stopBtn.show().prop('disabled', false).text(wwr.i18n.stop_btn);
        $selectAllBtn.prop('disabled', true);
        $checkboxes.prop('disabled', true);
        $progress.show();
        setProgress(0);
        $status.removeClass('wwr-status-error wwr-status-success').text('');

        // Mark all queued.
        queue.forEach(function (id) {
            var $card = $cards.filter('[data-attachment-id="' + id + '"]');
            $card.addClass('wwr-queued');
            setCardStatus(id, wwr.i18n.queued, 'queued');
        });

        updateQueueCounter();

        // Fill all worker slots.
        for (var i = 0; i < maxConcurrent; i++) {
            processNext();
        }
    }

    /**
     * Start the next queued image if a slot is free.
     * Called initially to fill slots, and from onImageComplete/onImageError
     * when a slot frees up.
     */
    function processNext() {
        // Guard: no more work or stopped.
        if (stopRequested) {
            // If all active tasks have drained, finish.
            if (activeTasks === 0) finishBatch(true);
            return;
        }

        // Nothing left to process.
        if (queue.length === 0) {
            if (activeTasks === 0) finishBatch(false);
            return;
        }

        // All slots busy.
        if (activeTasks >= maxConcurrent) return;

        activeTasks++;
        var attachmentId = queue.shift();
        var $card = $cards.filter('[data-attachment-id="' + attachmentId + '"]');

        // Transition card state.
        $card.removeClass('wwr-queued').addClass('wwr-processing');
        setCardStatus(attachmentId, wwr.i18n.uploading, 'processing');
        if ($card.find('.wwr-spinner-overlay').length === 0) {
            $card.append('<div class="wwr-spinner-overlay"><div class="wwr-spinner"></div></div>');
        }

        updateQueueCounter();
        $status.removeClass('wwr-status-error wwr-status-success')
               .text(wwr.i18n.uploading);

        // Step 1 — Upload & create task.
        var xhr1 = $.post(wwr.ajax_url, {
            action: 'wwr_start_process',
            attachment_id: attachmentId,
            _ajax_nonce: wwr.nonce_start
        })
        .done(function (res) {
            // Remove this XHR from tracking.
            activeXhrs = activeXhrs.filter(function (x) { return x !== xhr1; });

            if (stopRequested) { onTaskAborted(attachmentId, $card); return; }

            if (!res.success) {
                onImageError(attachmentId, res.data.message || wwr.i18n.error, $card);
                return;
            }

            setCardStatus(attachmentId, wwr.i18n.ai_working, 'processing');
            updateOverallProgress();

            // Step 2 — Poll until complete (per-task independent loop).
            var taskId = res.data.task_id;
            startPolling(taskId, attachmentId, $card);
        })
        .fail(function (jqXHR, textStatus) {
            activeXhrs = activeXhrs.filter(function (x) { return x !== xhr1; });
            if (textStatus === 'abort') { onTaskAborted(attachmentId, $card); return; }
            if (!stopRequested) onImageError(attachmentId, wwr.i18n.error, $card);
        });

        activeXhrs.push(xhr1);
    }

    // ── Per-task polling (independent closure) ─────────────────────

    function startPolling(taskId, attachmentId, $card) {
        var attempts = 0;

        function poll() {
            if (stopRequested) { onTaskAborted(attachmentId, $card); return; }

            var xhr2 = $.post(wwr.ajax_url, {
                action: 'wwr_poll_task',
                task_id: taskId,
                _ajax_nonce: wwr.nonce_poll
            })
            .done(function (res) {
                activeXhrs = activeXhrs.filter(function (x) { return x !== xhr2; });

                if (stopRequested) { onTaskAborted(attachmentId, $card); return; }

                if (!res.success) {
                    onImageError(attachmentId, res.data.message || wwr.i18n.error, $card);
                    return;
                }

                if (res.data.finished) {
                    onImageComplete(attachmentId, res.data, $card);
                    return;
                }

                // Still going — reschedule.
                attempts++;
                pollTimers[attachmentId] = setTimeout(poll, wwr.poll_interval);
            })
            .fail(function (jqXHR, textStatus) {
                activeXhrs = activeXhrs.filter(function (x) { return x !== xhr2; });
                if (textStatus === 'abort') { onTaskAborted(attachmentId, $card); return; }
                attempts++;
                if (attempts > 5) {
                    onImageError(attachmentId, wwr.i18n.error, $card);
                } else {
                    pollTimers[attachmentId] = setTimeout(poll, wwr.poll_interval * 2);
                }
            });

            activeXhrs.push(xhr2);
        }

        poll();
    }

    // ── Image complete (fills freed slot) ──────────────────────────

    function onImageComplete(attachmentId, data, $card) {
        clearPollTimer(attachmentId);
        activeTasks--;
        completedCount++;

        $card.removeClass('wwr-queued wwr-processing').addClass('wwr-done');
        $card.find('.wwr-spinner-overlay').fadeOut(300, function () { $(this).remove(); });
        if ($card.find('.wwr-done-checkmark').length === 0) {
            $card.append('<div class="wwr-done-checkmark"></div>');
        }
        if (data.new_url) {
            $card.find('.wwr-image-wrap img').attr('src', data.new_url + '?t=' + Date.now());
        }
        setCardStatus(attachmentId, '✓', 'done');

        updateQueueCounter();
        updateOverallProgress();

        // Fill the freed slot and check if batch is done.
        processNext();
        if (activeTasks === 0 && queue.length === 0 && !stopRequested) {
            finishBatch(false);
        }
    }

    // ── Image error (fills freed slot) ─────────────────────────────

    function onImageError(attachmentId, message, $card) {
        clearPollTimer(attachmentId);
        activeTasks--;
        errorCount++;

        $card.removeClass('wwr-queued wwr-processing').addClass('wwr-error-card');
        $card.find('.wwr-spinner-overlay').fadeOut(300, function () { $(this).remove(); });
        setCardStatus(attachmentId, wwr.i18n.skipped, 'error');

        updateQueueCounter();
        updateOverallProgress();

        // Fill freed slot and check if batch done.
        processNext();
        if (activeTasks === 0 && queue.length === 0 && !stopRequested) {
            finishBatch(false);
        }
    }

    // ── Task aborted (stop requested) ──────────────────────────────

    function onTaskAborted(attachmentId, $card) {
        clearPollTimer(attachmentId);
        activeTasks--;
        $card.removeClass('wwr-processing');
        $card.find('.wwr-spinner-overlay').fadeOut(300, function () { $(this).remove(); });
        setCardStatus(attachmentId, '', '');
        // When all active tasks have drained, finish.
        if (activeTasks === 0) finishBatch(true);
    }

    // ── Clear a single poll timer ──────────────────────────────────

    function clearPollTimer(attachmentId) {
        if (pollTimers[attachmentId]) {
            clearTimeout(pollTimers[attachmentId]);
            delete pollTimers[attachmentId];
        }
    }

    // ── Batch finish ───────────────────────────────────────────────

    function finishBatch(wasStopped) {
        // Clear any remaining timers and XHRs.
        Object.keys(pollTimers).forEach(function (id) { clearTimeout(pollTimers[id]); });
        pollTimers = {};
        activeXhrs.forEach(function (xhr) { try { xhr.abort(); } catch (e) {} });
        activeXhrs = [];
        activeTasks = 0;

        isProcessing = false;

        // Status message.
        if (wasStopped) {
            $status.addClass('wwr-status-error').text(
                wwr.i18n.stopped_msg
                    .replace('%d', completedCount)
                    .replace('%d', errorCount)
                    .replace('%d', queue.length)
            );
        } else if (completedCount > 0 && errorCount === 0) {
            $status.addClass('wwr-status-success').text(
                wwr.i18n.queue_done.replace('%d', completedCount)
            );
        } else if (completedCount > 0 || errorCount > 0) {
            $status.addClass('wwr-status-error').text(
                wwr.i18n.mixed_result.replace('%d', completedCount).replace('%d', errorCount)
            );
        } else {
            $status.text('');
        }

        setProgress(wasStopped ? 0 : 100);

        // Restore controls.
        $checkboxes.prop('disabled', false);
        $selectAllBtn.prop('disabled', false);
        $stopBtn.hide();
        updateStartButton();

        // Clean up queued cards that never started.
        $cards.filter('.wwr-queued').removeClass('wwr-queued');
        $cards.find('.wwr-card-status').not('.wwr-card-status-done').not('.wwr-card-status-error').hide().text('');

        // Auto-hide UI.
        setTimeout(function () {
            $queueCounter.fadeOut(500);
            $status.removeClass('wwr-status-success wwr-status-error').text('');
        }, 5000);

        setTimeout(function () {
            $progress.fadeOut(500);
            setProgress(0);
        }, 4000);

        // Reload when all done.
        if (!wasStopped && completedCount > 0) {
            setTimeout(function () { location.reload(); }, 3000);
        }
    }

    // ── Progress bar helper ────────────────────────────────────────
    function setProgress(pct) {
        pct = Math.max(0, Math.min(100, pct));
        $progressFill.css('width', pct + '%');
        $progressText.text(Math.round(pct) + '%');
        if (pct > 0) $progress.show();
    }

})(jQuery, WWR_Admin);
