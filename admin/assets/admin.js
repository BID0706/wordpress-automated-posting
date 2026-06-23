/* ILLE Post Generator V2 — Admin JS */
(function ($) {
    'use strict';

    // =========================================================================
    // Tab navigation
    // =========================================================================

    function activateTab( target ) {
        $('.ille-pg-tab').removeClass('active');
        $('[data-tab="' + target + '"]').addClass('active');
        $('.ille-pg-tab-panel').removeClass('active');
        $('[data-panel="' + target + '"]').addClass('active');
    }

    $(document).on('click', '.ille-pg-tab', function () {
        const target = $(this).data('tab');
        activateTab( target );
        try { localStorage.setItem('ille_pg_tab', target); } catch(e) {}
    });

    // Restore last active tab on page load
    (function () {
        try {
            const saved = localStorage.getItem('ille_pg_tab');
            if ( saved && $('[data-tab="' + saved + '"]').length ) {
                activateTab( saved );
            }
        } catch(e) {}
    })();

    // =========================================================================
    // Generate Post form
    // =========================================================================

    $('#ille-pg-generate-form').on('submit', function (e) {
        e.preventDefault();

        const $form    = $(this);
        const $btn     = $('#ille-pg-submit');
        const $result  = $('#ille-pg-result');
        const $error   = $('#ille-pg-error');

        // Hide previous result/error
        $result.attr('hidden', true);
        $error.attr('hidden', true);

        // Show loading state
        $btn.prop('disabled', true);
        $btn.find('.ille-pg-btn__label').text('Generating…');
        $btn.find('.ille-pg-btn__icon').attr('hidden', true);
        $btn.find('.ille-pg-btn__spinner').removeAttr('hidden');

        // Hide schedule field when publishing immediately
        const postStatus = $form.find('[name="post_status"]:checked').val();

        $.ajax({
            url:    ILLE_PG.ajax_url,
            method: 'POST',
            data: {
                action:         'ille_pg_generate',
                nonce:          ILLE_PG.nonce,
                topic:          $form.find('[name="topic"]').val(),
                focus_keyword:  $form.find('[name="focus_keyword"]').val(),
                featured_image: $form.find('[name="featured_image"]:checked').val(),
                post_status:    postStatus,
                scheduled_date: $form.find('[name="scheduled_date"]').val(),
            },
            success: function (res) {
                if (res.success) {
                    showResult(res.data);
                } else {
                    showError(res.data.message || 'Something went wrong.');
                }
            },
            error: function () {
                showError('Request failed. Please try again.');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $btn.find('.ille-pg-btn__label').text('Generate Post');
                $btn.find('.ille-pg-btn__icon').removeAttr('hidden');
                $btn.find('.ille-pg-btn__spinner').attr('hidden', true);
            }
        });
    });

    function showResult(data) {
        const $result  = $('#ille-pg-result');
        const statusLabel = data.post_status === 'draft'
            ? 'Saved as draft'
            : (data.post_status === 'future' ? 'Scheduled' : 'Published');

        $('#ille-pg-result-title').text(data.title || 'Post generated');
        $('#ille-pg-result-meta').text('Post ID ' + data.post_id + ' · ' + statusLabel);

        const $actions = $('#ille-pg-result-actions').empty();

        if (data.edit_url) {
            $actions.append(
                $('<a>').addClass('ille-pg-btn ille-pg-btn--primary ille-pg-btn--sm')
                        .attr('href', data.edit_url)
                        .attr('target', '_blank')
                        .text('Edit Post')
            );
        }

        if (data.post_url && data.post_status === 'publish') {
            $actions.append(
                $('<a>').addClass('ille-pg-btn ille-pg-btn--ghost ille-pg-btn--sm')
                        .attr('href', data.post_url)
                        .attr('target', '_blank')
                        .text('View Post →')
            );
        }

        $result.removeAttr('hidden');
        $result[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showError(msg) {
        $('#ille-pg-error-msg').text(msg);
        $('#ille-pg-error').removeAttr('hidden');
        $('#ille-pg-error')[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Hide schedule field when post_status is draft
    $(document).on('change', '[name="post_status"]', function () {
        const isDraft = $(this).val() === 'draft';
        $('#ille-schedule-field').toggle(!isDraft);
        if (isDraft) $('#ille-scheduled-date').val('');
    });

    // =========================================================================
    // Settings form
    // =========================================================================

    $('#ille-pg-settings-form').on('submit', function (e) {
        e.preventDefault();

        const $btn    = $('#ille-pg-save');
        const $status = $('#ille-save-status');

        $btn.prop('disabled', true);
        $btn.find('.ille-pg-btn__label').text('Saving…');
        $btn.find('.ille-pg-btn__spinner').removeAttr('hidden');
        $status.text('').removeClass('error');

        // Collect all form data, including unchecked checkboxes correctly
        const formData = collectSettings();

        $.ajax({
            url:    ILLE_PG.ajax_url,
            method: 'POST',
            data: {
                action:   'ille_pg_save_settings',
                nonce:    ILLE_PG.nonce,
                settings: formData,
            },
            success: function (res) {
                if (res.success) {
                    if (res.data.endpoint_changed) {
                        // Reload so rest_api_init re-registers the new slug,
                        // then admin_init flushes rewrite rules
                        $status.text('✓ Saved — reloading to apply new endpoint…').removeClass('error');
                        setTimeout(() => window.location.reload(), 1200);
                    } else {
                        $status.text('✓ Saved').removeClass('error');
                        setTimeout(() => $status.text(''), 3000);
                        updateActiveModelIndicator();
                    }
                } else {
                    $status.text(res.data.message || 'Save failed.').addClass('error');
                }
            },
            error: function () {
                $status.text('Request failed.').addClass('error');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $btn.find('.ille-pg-btn__label').text('Save Settings');
                $btn.find('.ille-pg-btn__spinner').attr('hidden', true);
            }
        });
    });

    function collectSettings() {
        const data = {};
        const $form = $('#ille-pg-settings-form');

        // Text/textarea/select/hidden inputs
        $form.find('input[type="text"], input[type="password"], input[type="time"], input[type="hidden"], textarea, select').each(function () {
            const name = $(this).attr('name');
            if (!name) return;
            setNestedValue(data, name, $(this).val());
        });

        // Radios (only checked ones)
        $form.find('input[type="radio"]:checked').each(function () {
            const name = $(this).attr('name');
            if (!name) return;
            setNestedValue(data, name, $(this).val());
        });

        // Checkboxes — handle arrays and booleans
        // First, find all unique checkbox names and reset them to empty
        const checkboxNames = {};
        $form.find('input[type="checkbox"]').each(function () {
            const name = $(this).attr('name');
            if (!name) return;
            if (name.endsWith('[]')) {
                if (!checkboxNames[name]) {
                    checkboxNames[name] = true;
                    setNestedValue(data, name, []);
                }
                if ($(this).is(':checked')) {
                    appendNestedValue(data, name, $(this).val());
                }
            } else {
                setNestedValue(data, name, $(this).is(':checked') ? '1' : '');
            }
        });

        // All field names are prefixed settings[...], so the actual payload
        // lives under data.settings — return that to avoid double-nesting
        // when the AJAX call wraps it as { settings: formData }
        return data.settings || {};
    }

    // Translate "settings[foo][bar]" → nested object
    function parseName(name) {
        return name.replace(/\[\]/g, '').split(/[\[\]]+/).filter(Boolean);
    }

    function setNestedValue(obj, name, val) {
        const keys = parseName(name);
        let cur = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (cur[keys[i]] === undefined || typeof cur[keys[i]] !== 'object') {
                cur[keys[i]] = {};
            }
            cur = cur[keys[i]];
        }
        cur[keys[keys.length - 1]] = val;
    }

    function appendNestedValue(obj, name, val) {
        const keys = parseName(name);
        let cur = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (!cur[keys[i]]) cur[keys[i]] = {};
            cur = cur[keys[i]];
        }
        const last = keys[keys.length - 1];
        if (!Array.isArray(cur[last])) cur[last] = [];
        cur[last].push(val);
    }

    // =========================================================================
    // Per-user API key regeneration
    // =========================================================================

    $(document).on('click', '.ille-pg-regen-user-key', function () {
        const $btn      = $(this);
        const userId    = $btn.data('user-id');
        const userName  = $btn.data('user-name');
        const isRegen   = $btn.text().trim() === 'Regenerate';

        if ( isRegen && !$btn.data('confirmed') ) {
            $btn.data('confirmed', true).text('Confirm?').addClass('ille-pg-btn--warning');
            setTimeout(() => {
                $btn.data('confirmed', false).text('Regenerate').removeClass('ille-pg-btn--warning');
            }, 3000);
            return;
        }

        $btn.prop('disabled', true).text('…');

        $.ajax({
            url:    ILLE_PG.ajax_url,
            method: 'POST',
            data:   { action: 'ille_pg_regenerate_key', nonce: ILLE_PG.nonce, user_id: userId },
            success: function (res) {
                if (res.success) {
                    const inputId = 'ille-user-key-' + userId;
                    let $input = $('#' + inputId);

                    if ($input.length) {
                        $input.val(res.data.api_key);
                    } else {
                        // First-time generation — replace "No key generated" span
                        const $row = $btn.closest('.ille-pg-user-key-row__actions');
                        $row.find('.ille-pg-hint').replaceWith(
                            $('<input>').attr({
                                type: 'text', id: inputId, readonly: true,
                                class: 'ille-pg-input ille-pg-input--mono ille-pg-user-key-input',
                                value: res.data.api_key
                            })
                        );
                        $btn.before(
                            $('<button>').attr({type: 'button'})
                                .addClass('ille-pg-btn ille-pg-btn--sm ille-pg-copy-btn')
                                .attr('data-copy-input', inputId)
                                .text('Copy')
                        );
                    }
                    $btn.data('confirmed', false).text('Regenerate').removeClass('ille-pg-btn--warning');
                }
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    // =========================================================================
    // Copy buttons
    // =========================================================================

    $(document).on('click', '.ille-pg-copy-btn', function () {
        const $btn    = $(this);
        const codeId  = $btn.data('copy');
        const inputId = $btn.data('copy-input');

        let text = '';
        if (codeId)  text = $('#' + codeId).text();
        if (inputId) text = $('#' + inputId).val();

        if (!text) return;

        navigator.clipboard.writeText(text).then(function () {
            const prev = $btn.text();
            $btn.text('Copied!');
            setTimeout(() => $btn.text(prev), 2000);
        });
    });

    // =========================================================================
    // Schedule toggle — show/hide schedule body
    // =========================================================================

    $(document).on('change', '.ille-pg-schedule-toggle', function () {
        const $schedule = $(this).closest('.ille-pg-schedule');
        $schedule.toggleClass('enabled', $(this).is(':checked'));
    });

    // =========================================================================
    // Custom endpoint slug preview
    // =========================================================================

    $('#ille-custom-endpoint').on('input', function () {
        const val = $(this).val().trim().replace(/^\/|\/$/g, '') || 'generate-post';
        $('#ille-slug-preview').text(val);
    });

    // =========================================================================
    // Active model indicator — update client-side after save
    // =========================================================================

    function updateActiveModelIndicator() {
        const $indicator = $('#ille-active-model-indicator');
        if ( !$indicator.length ) return;

        // Determine which model has a key set and is selected (preferred)
        let preferredId = $('input[name="settings[ille_pg_active_model]"]:checked').val();
        const v = name => ( $('input[name="settings[' + name + ']"]').val() || '' ).trim();
        const modelKeys = {
            'gemini-2.0-flash': v( 'ille_pg_gemini_api_key' ),
            'gpt-4o-mini':      v( 'ille_pg_openai_api_key' ),
            'grok-3-mini':      v( 'ille_pg_xai_api_key' ),
        };
        const modelNames = {
            'gemini-2.0-flash': 'Gemini 2.0 Flash',
            'gpt-4o-mini':      'GPT-4o Mini',
            'grok-3-mini':      'Grok 3 Mini',
        };
        const fallbackOrder = [ 'gemini-2.0-flash', 'gpt-4o-mini', 'grok-3-mini' ];

        // Try preferred first, then fallback order
        let resolved = null;
        if ( preferredId && modelKeys[ preferredId ] ) {
            resolved = preferredId;
        } else {
            for ( const id of fallbackOrder ) {
                if ( modelKeys[ id ] ) { resolved = id; break; }
            }
        }

        if ( resolved ) {
            const isFallback = resolved !== preferredId;
            $indicator
                .removeClass('ille-pg-active-model--none')
                .html(
                    ( isFallback ? '<em>Fallback: </em>' : '' ) +
                    '<strong>' + ( modelNames[ resolved ] || resolved ) + '</strong>' +
                    ( isFallback ? ' (preferred has no key)' : '' )
                );
        } else {
            $indicator
                .addClass('ille-pg-active-model--none')
                .html('<strong>None — add a key to enable generation</strong>');
        }

        // Refresh key badges
        Object.keys( modelKeys ).forEach(function ( id ) {
            const $card = $('[data-model-id="' + id + '"]');
            if ( !$card.length ) return;
            const hasKey = !!modelKeys[ id ];
            $card.find('.ille-pg-key-badge')
                .toggleClass('ille-pg-key-badge--set', hasKey)
                .toggleClass('ille-pg-key-badge--missing', !hasKey)
                .text( hasKey ? 'Key set ✓' : 'No key' );
        });
    }

    // Also update indicator when radio or key inputs change
    $(document).on('change', 'input[name="settings[ille_pg_active_model]"]', updateActiveModelIndicator);
    $(document).on('input', 'input[name="settings[ille_pg_gemini_key]"], input[name="settings[ille_pg_openai_key]"], input[name="settings[ille_pg_xai_key]"]', updateActiveModelIndicator);

    // =========================================================================
    // Model card radio — update active class
    // =========================================================================

    $(document).on('change', '.ille-pg-model-card input[type="radio"]', function () {
        $('.ille-pg-model-card').removeClass('active');
        $(this).closest('.ille-pg-model-card').addClass('active');
    });

    // =========================================================================
    // Reset endpoint slug to default
    // =========================================================================

    $(document).on('click', '#ille-reset-endpoint', function () {
        $('#ille-custom-endpoint').val('');
        $('#ille-slug-preview').text('generate-post');
        $(this).remove();
        $('#ille-pg-settings-form').trigger('submit');
    });

    // =========================================================================
    // Test Endpoint
    // =========================================================================

    $('#ille-test-endpoint').on('click', function () {
        const $btn    = $(this);
        const $result = $('#ille-endpoint-test-result');
        const $dot    = $('#ille-endpoint-status-dot');

        $btn.prop('disabled', true).text('Testing…');
        $result.text('').removeClass('ille-pg-hint--ok ille-pg-hint--err');

        $.ajax({
            url:    ILLE_PG.ajax_url,
            method: 'POST',
            data:   { action: 'ille_pg_test_endpoint', nonce: ILLE_PG.nonce },
            success: function (res) {
                if (res.success && res.data.active) {
                    $dot.addClass('active').removeClass('inactive');
                    $result.text('✓ Route registered and active: ' + res.data.route)
                           .addClass('ille-pg-hint--ok');
                    $('#ille-active-endpoint-url').text(res.data.endpoint_url);
                } else {
                    $dot.addClass('inactive').removeClass('active');
                    $result.text('✗ Route not found. Save a slug change and wait for the page to reload.')
                           .addClass('ille-pg-hint--err');
                }
            },
            error: function () {
                $result.text('Request failed.').addClass('ille-pg-hint--err');
            },
            complete: function () {
                $btn.prop('disabled', false).text('Test');
            }
        });
    });

    // =========================================================================
    // Activity log actions
    // =========================================================================

    function logStatus( msg, isError ) {
        const $el = $('#ille-log-action-status');
        $el.text( msg )
           .removeClass('ille-pg-alert--error')
           .removeAttr('hidden');
        if ( isError ) $el.addClass('ille-pg-alert--error');
    }

    $('#ille-log-refresh').on('click', function () {
        const $btn = $(this);
        $btn.addClass('spin').prop('disabled', true);
        setTimeout(() => { location.reload(); }, 300);
    });

    $('#ille-log-export').on('click', function () {
        const $btn = $(this).prop('disabled', true).addClass('spin');
        $.ajax({
            url: ILLE_PG.ajax_url, method: 'POST',
            data: { action: 'ille_pg_log_export', nonce: ILLE_PG.nonce },
            success: function (res) {
                if (!res.success) { logStatus(res.data.message, true); return; }
                const bytes    = atob(res.data.csv);
                const arr      = new Uint8Array(bytes.length);
                for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
                const blob     = new Blob([arr], { type: 'text/csv' });
                const url      = URL.createObjectURL(blob);
                const a        = document.createElement('a');
                a.href = url; a.download = res.data.filename;
                document.body.appendChild(a); a.click();
                document.body.removeChild(a); URL.revokeObjectURL(url);
            },
            error: function () { logStatus('Export failed.', true); },
            complete: function () { $btn.prop('disabled', false).removeClass('spin'); }
        });
    });

    $('#ille-log-truncate').on('click', function () {
        const $btn = $(this);
        if (!$btn.data('confirmed')) {
            $btn.data('confirmed', true).addClass('ille-pg-icon-btn--warning-active');
            $btn.attr('title', 'Click again to confirm truncate');
            setTimeout(() => {
                $btn.data('confirmed', false)
                    .removeClass('ille-pg-icon-btn--warning-active')
                    .attr('title', 'Truncate log');
            }, 3000);
            return;
        }
        $btn.prop('disabled', true).addClass('spin');
        $.ajax({
            url: ILLE_PG.ajax_url, method: 'POST',
            data: { action: 'ille_pg_log_truncate', nonce: ILLE_PG.nonce },
            success: function (res) {
                if (res.success) { logStatus('Log truncated.'); setTimeout(() => location.reload(), 800); }
                else { logStatus(res.data.message, true); }
            },
            complete: function () { $btn.prop('disabled', false).removeClass('spin').attr('title', 'Truncate log'); }
        });
    });

    $('#ille-log-delete').on('click', function () {
        const $btn = $(this);
        if (!$btn.data('confirmed')) {
            $btn.data('confirmed', true).addClass('ille-pg-icon-btn--danger-active');
            $btn.attr('title', 'Click again to confirm delete');
            setTimeout(() => {
                $btn.data('confirmed', false)
                    .removeClass('ille-pg-icon-btn--danger-active')
                    .attr('title', 'Delete log file');
            }, 3000);
            return;
        }
        $btn.prop('disabled', true).addClass('spin');
        $.ajax({
            url: ILLE_PG.ajax_url, method: 'POST',
            data: { action: 'ille_pg_log_delete', nonce: ILLE_PG.nonce },
            success: function (res) {
                if (res.success) { logStatus('Log deleted.'); setTimeout(() => location.reload(), 800); }
                else { logStatus(res.data.message, true); }
            },
            complete: function () { $btn.prop('disabled', false).removeClass('spin').attr('title', 'Delete log file'); }
        });
    });

    // =========================================================================
    // Reset prompt buttons
    // =========================================================================

    $(document).on('click', '.ille-pg-reset-prompt', function () {
        const $btn     = $(this);
        const target   = $btn.data('target');
        const defValue = $btn.data('default');
        $('[name="settings[' + target + ']"]').val(defValue);
    });

    // =========================================================================
    // Default placeholder image — WordPress media library
    // =========================================================================

    let mediaFrame;

    $('#ille-default-image-select').on('click', function (e) {
        e.preventDefault();

        if (mediaFrame) { mediaFrame.open(); return; }

        mediaFrame = wp.media({
            title:    'Select Default Placeholder Image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: 'image' },
        });

        mediaFrame.on('select', function () {
            const attachment = mediaFrame.state().get('selection').first().toJSON();
            $('#ille-default-image-id').val(attachment.id);

            const src = attachment.sizes && attachment.sizes.medium
                ? attachment.sizes.medium.url
                : attachment.url;

            const $preview = $('#ille-default-image-preview');
            $preview.removeClass('ille-pg-default-image-preview--empty')
                    .html('<img src="' + src + '" alt="Default placeholder" />');

            $('#ille-default-image-select').text('Change Image');

            if (!$('#ille-default-image-remove').length) {
                $('#ille-default-image-select').after(
                    '<button type="button" id="ille-default-image-remove" class="ille-pg-btn ille-pg-btn--sm ille-pg-btn--ghost">Remove</button>'
                );
                bindRemove();
            }
        });

        mediaFrame.open();
    });

    function bindRemove() {
        $(document).on('click', '#ille-default-image-remove', function () {
            $('#ille-default-image-id').val('');
            $('#ille-default-image-preview')
                .addClass('ille-pg-default-image-preview--empty')
                .html('<span>No image selected</span>');
            $('#ille-default-image-select').text('Select Image');
            $(this).remove();
        });
    }
    bindRemove();

})(jQuery);
