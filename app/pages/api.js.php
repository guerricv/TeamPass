<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This file is part of the TeamPass project.
 * 
 * TeamPass is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 * 
 * TeamPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * 
 * Certain components of this file may be under different licenses. For
 * details, see the `licenses` directory or individual file headers.
 * ---
 * @file      api.js.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2026 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

use TeampassClasses\PerformChecks\PerformChecks;
use TeampassClasses\ConfigManager\ConfigManager;
use TeampassClasses\SessionManager\SessionManager;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use TeampassClasses\Language\Language;

// Load functions
require_once __DIR__.'/../sources/main.functions.php';

// init
loadClasses();
$session = SessionManager::getSession();
$request = SymfonyRequest::createFromGlobals();
$lang = new Language($session->get('user-language') ?? 'english');

if ($session->get('key') === null) {
    die('Hacking attempt...');
}

// Load config
$configManager = new ConfigManager();
$SETTINGS = $configManager->getAllSettings();

// Do checks
$checkUserAccess = new PerformChecks(
    dataSanitizer(
        [
            'type' => htmlspecialchars($request->request->get('type', ''), ENT_QUOTES, 'UTF-8'),
        ],
        [
            'type' => 'trim|escape',
        ],
    ),
    [
        'user_id' => returnIfSet($session->get('user-id'), null),
        'user_key' => returnIfSet($session->get('key'), null),
    ]
);
// Handle the case
echo $checkUserAccess->caseHandler();
if ($checkUserAccess->checkSession() === false || $checkUserAccess->userAccessPage('api') === false) {
    // Not allowed page
    $session->set('system-error_code', ERR_NOT_ALLOWED);
    include TEAMPASS_ROOT . '/public/error.php';
    exit;
}
?>


<script type='text/javascript'>
    //<![CDATA[

    $('[data-mask]').inputmask();

    function filterApiUsersTable() {
        const criteria = ($('#api-users-search').val() || '').toString().trim().toLowerCase();
        const $rows = $('#table-api-keys tbody tr');
        let visibleRows = 0;

        $rows.each(function() {
            const userIdentity = $(this).find('td:first').text().toLowerCase();
            const isVisible = criteria === '' || userIdentity.indexOf(criteria) !== -1;

            $(this).toggleClass('hidden', isVisible === false);
            if (isVisible === true) {
                visibleRows++;
            }
        });

        $('#api-search-no-results').toggleClass(
            'hidden',
            criteria === '' || visibleRows > 0 || $rows.length === 0
        );
    }

    $(document).on('input keyup search', '#api-users-search', filterApiUsersTable);

    /**
     * TOGGLE API STATUS (ENABLED/DISABLED)
     */
    $(document).on('click', '.api-clickme-action', function() {
        toastr.remove();
        toastr.info('<?php echo $lang->get('in_progress'); ?> ... <i class="fas fa-circle-notch fa-spin fa-2x"></i>');

        // prepare data
        var data = {
            'increment_id': $(this).data('increment-id'),
            'field': $(this).data('field'),
            'value': $(this).hasClass('fa-toggle-off') === true ? 1 : 0,
        },
        selectedIcon = $(this);

        $.post(
            'sources/admin.queries.php', {
                type: 'save_user_change',
                data: prepareExchangedData(JSON.stringify(data), "encode", "<?php echo $session->get('key'); ?>"),
                key: "<?php echo $session->get('key'); ?>"
            },
            function(data) {
                data = prepareExchangedData(data, 'decode', '<?php echo $session->get('key'); ?>');
                if (debugJavascript === true) console.log(data);

                if (data.error !== false) {
                    // Show error
                    toastr.remove();
                    toastr.error(
                        data.message,
                        '<?php echo $lang->get('caution'); ?>', {
                            timeOut: 5000,
                            progressBar: true
                        }
                    );
                } else {
                    // CHange icon format
                    if (selectedIcon.hasClass('fa-toggle-off') === true) {
                        selectedIcon
                            .removeClass('fa-toggle-off text-danger')
                            .addClass('fa-toggle-on text-info')
                            .prop('data-user-auth-type', 'ldap');
                    } else {
                        selectedIcon
                            .removeClass('fa-toggle-on text-info')
                            .addClass('fa-toggle-off')
                            .prop('data-user-auth-type', 'local');
                    }

                    $('.infotip').tooltip();

                    // Inform user
                    toastr.remove();
                    toastr.success(
                        '<?php echo $lang->get('done'); ?>',
                        '', {
                            timeOut: 1000
                        }
                    );
                }
            }
        );
    });

    $(document).on('click', '#button-refresh-users-api', function() {
        toastr.remove();
        toastr.info(
            '<i class="fa-solid fa-circle-notch fa-spin fa-2x"></i>',
            '<?php echo $lang->get('please_wait'); ?>'
        );

        // Launch action
        $.post(
            'sources/admin.queries.php', {
                type: 'admin_action_refresh-users-api',
                key: '<?php echo $session->get('key'); ?>'
            },
            function(data) {
                //decrypt data
                data = decodeQueryReturn(data, '<?php echo $session->get('key'); ?>');

                if (data.error === true) {
                    // ERROR
                    toastr.remove();
                    toastr.warning(
                        '<?php echo $lang->get('none_selected_text'); ?>',
                        '', {
                            timeOut: 5000,
                            progressBar: true
                        }
                    );
                } else {
                    if (data.countUpdatedUsers > 0) {
                        // Inform user
                        toastr.remove();
                        toastr.success(
                            data.message,
                            '<?php echo $lang->get('alert_page_will_reload'); ?>', {
                                timeOut: 3000,
                                progressBar: true
                            }
                        );

                        // Delay page submit
                        $(this).delay(2000).queue(function() {
                            document.location.reload(true);
                            $(this).dequeue();
                        });
                    } else {
                        toastr.remove();
                        toastr.info(
                            '<?php echo $lang->get('done'); ?>',
                            '', {
                                timeOut: 1000
                            }
                        );
                    }
                }
            }
        );

    });

    

    // Handle the copy in clipboard button for api key
    $(document).on('click', '#copy-extension-key', function() {
        const apiKey = $('#browser_extension_key').val();
        // Shared helper: falls back to execCommand when the async Clipboard API is
        // unavailable, which is the case on an instance served over plain HTTP.
        tpClipboardCopy(apiKey).then(function(copied) {
            if (copied === false) {
                return;
            }
            // Display message.
            toastr.remove();
            toastr.info(
                '<?php echo $lang->get('copy_to_clipboard'); ?>',
                '', {
                    timeOut: 2000,
                    progressBar: true,
                    positionClass: 'toast-bottom-right'
                }
            );
        }, function(err) {
            // nothing
        });
    });

    // Handle generate new extension key
    $(document).on('click', '#generate-extension-key', function() {
        toastr.remove();
        toastr.info('<?php echo $lang->get('in_progress'); ?> ... <i class="fas fa-circle-notch fa-spin fa-2x"></i>');

        // generate a token
        $.post(
            'sources/main.queries.php', {
                type: 'generate_token',
                type_category: 'action_system',
                size: 64,
                capital: true,
                secure: false,
                numeric: true,
                symbols: false,
                lowercase: true,
                unique_names: false,
                reason: 'extension_key_generation',
                duration: 10,
                key: '<?php echo $session->get('key'); ?>'
            },
            function(data) {
                //decrypt data
                data = decodeQueryReturn(data, '<?php echo $session->get('key'); ?>');

                // Update key value
                $('#browser_extension_key').val(data.token);

                // The key exists now - generating a new one would invalidate every
                // extension already configured with it.
                $('#generate-extension-key').remove();

                // Store in DB
                var data = {
                    "field": 'browser_extension_key',
                    "value": data.token,
                }
                $.post(
                    "sources/admin.queries.php", {
                        type: "save_option_change",
                        data: prepareExchangedData(JSON.stringify(data), "encode", "<?php echo $session->get('key'); ?>"),
                        key: "<?php echo $session->get('key'); ?>"
                    },
                    function(data) {
                        // Handle server answer
                        try {
                            data = prepareExchangedData(data, "decode", "<?php echo $session->get('key'); ?>");
                        } catch (e) {
                            // error
                            toastr.remove();
                            toastr.error(
                                '<?php echo $lang->get('server_answer_error') . '<br />' . $lang->get('server_returned_data') . ':<br />'; ?>' + data.error,
                                '', {
                                    closeButton: true,
                                    positionClass: 'toast-bottom-right'
                                }
                            );
                            return false;
                        }
                        if (debugJavascript === true) {
                            console.log('Response from server:');
                            console.log(data);
                        }
                        if (data.error === false) {
                            toastr.remove();
                            toastr.success(
                                '<?php echo $lang->get('saved'); ?>',
                                '', {
                                    timeOut: 2000,
                                    progressBar: true
                                }
                            );
                        }
                    }
                );
            }
        );
    });



    /**
     * LICENCE TAB - SELF-SERVICE TRIAL
     *
     * The licence server is reached from PHP, never from here: its answers are RSA-signed and
     * the verification has to run on the raw body, server-side. This code only renders the
     * view model the handler returns and arms the two throttled buttons.
     */

    // Every label goes through json_encode() once. addslashes() escapes quotes and nothing
    // else, so a translation carrying a newline or a closing script tag would break the page
    // (guarded by tests/Unit/ConfirmDialogSentinelTest.php).
    const licenceLang = <?php echo json_encode(array(
        'no_licence' => $lang->get('licence_no_licence_registered'),
        'trial_title' => $lang->get('licence_trial_title'),
        'trial_intro' => $lang->get('licence_trial_intro'),
        'contact_email' => $lang->get('licence_trial_contact_email'),
        'contact_email_tip' => $lang->get('licence_trial_contact_email_tip'),
        'email_domain_warning' => $lang->get('licence_trial_email_domain_warning'),
        'privacy_notice' => $lang->get('licence_trial_privacy_notice'),
        'request_button' => $lang->get('licence_trial_request_button'),
        'confirm_title' => $lang->get('licence_trial_confirm_title'),
        'confirm_body' => $lang->get('licence_trial_confirm_body'),
        'confirm_warning' => $lang->get('licence_trial_confirm_warning'),
        'identity_unusable' => $lang->get('licence_trial_identity_unusable'),
        'error_fqdn' => $lang->get('licence_trial_error_fqdn'),
        'error_token' => $lang->get('licence_trial_error_token'),
        'pending_title' => $lang->get('licence_trial_pending_title'),
        'pending_body' => $lang->get('licence_trial_pending_body'),
        'pending_link_validity' => $lang->get('licence_trial_pending_link_validity'),
        'pending_nothing_consumed' => $lang->get('licence_trial_pending_nothing_consumed'),
        'pending_resend_warning' => $lang->get('licence_trial_pending_resend_warning'),
        'fqdn_changed' => $lang->get('licence_trial_fqdn_changed'),
        'check_button' => $lang->get('licence_trial_check_button'),
        'resend_button' => $lang->get('licence_trial_resend_button'),
        'trial_active' => $lang->get('licence_trial_active'),
        'subscription_active' => $lang->get('licence_subscription_active'),
        'refresh_button' => $lang->get('licence_refresh_button'),
        'no_grace_warning' => $lang->get('licence_trial_no_grace_warning'),
        'refused_title' => $lang->get('licence_trial_refused_title'),
        'contact_sales' => $lang->get('licence_trial_contact_sales'),
        'error_already_used' => $lang->get('licence_trial_error_already_used'),
        'error_already_licensed' => $lang->get('licence_trial_error_already_licensed'),
        'error_unauthorized' => $lang->get('licence_trial_error_unauthorized'),
        'error_revoked' => $lang->get('licence_trial_error_revoked'),
        'error_unexpected' => $lang->get('licence_trial_error_unexpected'),
        'error_disabled' => $lang->get('licence_trial_error_disabled'),
        'error_untrusted' => $lang->get('licence_trial_error_untrusted'),
        'key_rotated' => $lang->get('licence_server_key_rotated'),
        'budget_exhausted' => $lang->get('licence_trial_budget_exhausted'),
        'unreachable_title' => $lang->get('licence_server_unreachable_title'),
        'unreachable_body' => $lang->get('licence_server_unreachable_body'),
        'fallback_link' => $lang->get('licence_trial_fallback_link'),
        'fallback_tip' => $lang->get('licence_trial_fallback_tip'),
        'fqdn_label' => $lang->get('browser_extension_fqdn'),
        'valid_until' => $lang->get('valid_until'),
        'users' => $lang->get('users'),
        'days' => $lang->get('days'),
        'in_progress' => $lang->get('in_progress'),
        'caution' => $lang->get('caution'),
        'copied' => $lang->get('copy_to_clipboard'),
        'answer_error' => $lang->get('server_answer_error'),
    ), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>

    const licenceSessionKey = '<?php echo $session->get('key'); ?>'
    let licenceCountdownTimer = null
    let licenceLoadedAt = 0
    let licenceRequestRunning = false

    // Escape everything coming from the licence server before it reaches the DOM.
    function licenceText(value) {
        return $('<span>').text(value === undefined || value === null ? '' : value).html()
    }

    // The countdowns are short by design: "45 s" then "14 min", no date library needed.
    function licenceCountdownLabel(seconds) {
        if (seconds <= 60) {
            return seconds + ' s'
        }
        return Math.ceil(seconds / 60) + ' min'
    }

    function licenceStopCountdown() {
        if (licenceCountdownTimer !== null) {
            clearInterval(licenceCountdownTimer)
            licenceCountdownTimer = null
        }
    }

    /**
     * Tick the disabled buttons back to life. A single interval serves the whole panel and it
     * is cleared on every re-render, so switching tabs cannot leave timers behind.
     */
    function licenceStartCountdown() {
        licenceStopCountdown()

        const $targets = $('.licence-countdown')
        if ($targets.length === 0) {
            return
        }

        licenceCountdownTimer = setInterval(function() {
            let stillWaiting = false

            $targets.each(function() {
                const $target = $(this)
                let remaining = parseInt($target.data('remaining'), 10) - 1

                if (isNaN(remaining) === true || remaining <= 0) {
                    remaining = 0
                }
                $target.data('remaining', remaining)

                const $button = $('#' + $target.data('button'))
                if (remaining === 0) {
                    $target.text('')
                    $button.prop('disabled', false)
                    return
                }

                stillWaiting = true
                $target.text('(' + licenceCountdownLabel(remaining) + ')')
                $button.prop('disabled', true)
            })

            if (stillWaiting === false) {
                licenceStopCountdown()
            }
        }, 1000)
    }

    function licenceCountdownSpan(seconds, buttonId) {
        if (seconds <= 0) {
            return ''
        }
        return '<small class="ml-2 text-muted licence-countdown" data-remaining="' + seconds
            + '" data-button="' + buttonId + '">(' + licenceCountdownLabel(seconds) + ')</small>'
    }

    function licenceContactLink(vm) {
        return '<a href="' + licenceText(vm.contact_url) + '">'
            + licenceText(vm.contact_email_support) + '</a>'
    }

    // Panel E - this server has no outbound access, or the answer could not be authenticated.
    function licenceRenderUnreachable(vm) {
        let reason = licenceLang.unreachable_body
        if (vm.key_rotated === true) {
            reason = licenceLang.key_rotated
        } else if (vm.untrusted === true) {
            reason = licenceLang.error_untrusted
        }

        let html = '<div class="callout callout-warning">'
            + '<h5><i class="fas fa-plug-circle-xmark mr-2"></i>' + licenceLang.unreachable_title + '</h5>'
            + '<p class="mb-2">' + reason + '</p>'

        if (vm.trial_available === true || vm.state === 'none') {
            html += '<p class="mb-1"><a href="' + licenceText(vm.fallback_url) + '" target="_blank" rel="noopener">'
                + licenceLang.fallback_link + ' <i class="fas fa-external-link-alt fa-xs"></i></a></p>'
                + '<small class="text-muted">' + licenceLang.fallback_tip + '</small>'
        }

        return html + '</div>'
    }

    // Panel A' - the FQDN or the key would waste the one and only trial.
    function licenceRenderInvalidIdentity(vm) {
        return '<div class="callout callout-danger">'
            + '<h5><i class="fas fa-triangle-exclamation mr-2"></i>' + licenceLang.identity_unusable + '</h5>'
            + '<p class="mb-0">'
            + (vm.token_valid === false ? licenceLang.error_token : licenceLang.error_fqdn)
            + '</p></div>'
    }

    // Panel A - no licence yet, a trial can be requested.
    function licenceRenderTrialForm(vm) {
        let html = '<div class="callout callout-info">'
            + '<i class="fas fa-info-circle mr-2"></i>' + licenceLang.no_licence + '</div>'

        html += '<h5 class="mt-4">' + licenceLang.trial_title
            + ' (' + licenceText(vm.trial_days) + ' ' + licenceLang.days + ')</h5>'
            + '<p class="text-muted">' + licenceLang.trial_intro + '</p>'

        html += '<div class="row mt-3 mb-2">'
            + '<div class="col-5">' + licenceLang.contact_email
            + '<small class="form-text text-muted">' + licenceLang.contact_email_tip
            + ' <strong>' + licenceText(vm.instance_domain) + '</strong></small></div>'
            + '<div class="col-7">'
            + '<input type="email" class="form-control form-control-sm no-save" id="licence-trial-email" value="'
            + licenceText(vm.contact_email_suggestion) + '" maxlength="255">'
            + '</div></div>'

        if (vm.email_domain_aligned === false) {
            html += '<div class="text-warning mb-2" id="licence-email-domain-warning">'
                + '<i class="fas fa-triangle-exclamation mr-1"></i>' + licenceLang.email_domain_warning + '</div>'
        }

        html += '<p class="text-muted small mt-3">' + licenceLang.privacy_notice + '</p>'
            + '<button class="btn btn-primary" id="licence-request-trial">'
            + '<i class="fas fa-gift mr-2"></i>' + licenceLang.request_button + '</button>'

        return html
    }

    // Panel B - the confirmation e-mail is out, nothing is consumed yet.
    function licenceRenderPending(vm) {
        let html = '<div class="callout callout-info">'
            + '<h5><i class="fas fa-envelope-open-text mr-2"></i>' + licenceLang.pending_title + '</h5>'
            + '<p class="mb-2">' + licenceLang.pending_body
            + ' <strong>' + licenceText(vm.contact_email) + '</strong>.</p>'

        if (vm.link_expires_at_display !== '') {
            html += '<p class="mb-2">' + licenceLang.pending_link_validity
                + ' <strong>' + licenceText(vm.link_expires_at_display) + '</strong>.</p>'
        }

        html += '<p class="mb-2">' + licenceLang.pending_nothing_consumed + '</p>'
            + '<p class="text-warning mb-3"><i class="fas fa-triangle-exclamation mr-1"></i>'
            + licenceLang.pending_resend_warning + '</p>'

        if (vm.fqdn_changed_since_request === true) {
            html += '<p class="text-danger"><i class="fas fa-triangle-exclamation mr-1"></i>'
                + licenceLang.fqdn_changed + '</p>'
        }

        const checkWait = vm.budget_exhausted === true ? vm.budget_retry_after : 0

        html += '<button class="btn btn-primary mr-2" id="licence-check-confirmed"'
            + (checkWait > 0 ? ' disabled' : '') + '>'
            + '<i class="fas fa-rotate mr-2"></i>' + licenceLang.check_button + '</button>'
            + licenceCountdownSpan(checkWait, 'licence-check-confirmed')

        html += '<button class="btn btn-default ml-3" id="licence-resend-trial" data-email="'
            + licenceText(vm.contact_email) + '"'
            + (vm.resend_in > 0 ? ' disabled' : '') + '>'
            + '<i class="fas fa-paper-plane mr-2"></i>' + licenceLang.resend_button + '</button>'
            + licenceCountdownSpan(vm.resend_in, 'licence-resend-trial')

        return html + '</div>'
    }

    // Panel C - a licence is active. A trial gets no grace period, so the deadline is stated.
    function licenceRenderGranted(vm) {
        const isTrial = vm.licence.trial === true

        let html = '<div class="callout callout-success">'
            + '<h5><i class="fas fa-circle-check mr-2"></i>'
            + (isTrial === true ? licenceLang.trial_active : licenceLang.subscription_active)
            + '</h5>'

        if (vm.licence.expiration_display !== '') {
            html += '<p class="mb-1"><i class="fas fa-calendar-alt mr-1"></i>'
                + licenceLang.valid_until + ': <strong>'
                + licenceText(vm.licence.expiration_display) + '</strong>'
            if (vm.licence.days_left !== null) {
                html += ' <span class="text-muted">(' + licenceText(vm.licence.days_left)
                    + ' ' + licenceLang.days + ')</span>'
            }
            html += '</p>'
        }

        html += '<p class="mb-2"><i class="fas fa-users mr-1"></i>'
            + licenceText(vm.licence.consumed) + ' / ' + licenceText(vm.licence.max_users)
            + ' ' + licenceLang.users + '</p>'
            + '<button class="btn btn-default btn-sm" id="licence-refresh">'
            + '<i class="fas fa-rotate mr-2"></i>' + licenceLang.refresh_button + '</button>'
            + licenceCountdownSpan(vm.budget_exhausted === true ? vm.budget_retry_after : 0, 'licence-refresh')
            + '</div>'

        if (isTrial === true && vm.licence.expiring_soon === true) {
            html += '<div class="callout callout-warning">'
                + '<i class="fas fa-triangle-exclamation mr-2"></i>'
                + licenceLang.no_grace_warning + ' ' + licenceContactLink(vm) + '</div>'
        }

        return html
    }

    // Panel D - terminal refusals. None offers a retry: only support can reopen them.
    function licenceRenderRefused(vm) {
        const messages = {
            'TRIAL_ALREADY_USED': licenceLang.error_already_used,
            'PRODUCT_ALREADY_LICENSED': licenceLang.error_already_licensed,
            'UNAUTHORIZED': licenceLang.error_unauthorized,
            'LICENCE_REVOKED': licenceLang.error_revoked
        }

        return '<div class="callout callout-danger">'
            + '<h5><i class="fas fa-circle-xmark mr-2"></i>' + licenceLang.refused_title + '</h5>'
            + '<p class="mb-2">' + (messages[vm.status] || licenceLang.error_unexpected) + '</p>'
            + '<p class="mb-0">' + licenceLang.contact_sales + ' ' + licenceContactLink(vm) + '</p>'
            + '</div>'
    }

    function licenceRenderPanel(vm) {
        licenceStopCountdown()

        let html = ''
        switch (vm.panel) {
            case 'granted':
                html = licenceRenderGranted(vm)
                break
            case 'pending':
                html = licenceRenderPending(vm)
                break
            case 'refused':
                html = licenceRenderRefused(vm)
                break
            case 'unreachable':
                html = licenceRenderUnreachable(vm)
                break
            case 'closed':
                html = '<div class="callout callout-info"><i class="fas fa-info-circle mr-2"></i>'
                    + licenceLang.error_disabled + '</div>'
                break
            case 'invalid_fqdn':
                html = licenceRenderInvalidIdentity(vm)
                break
            default:
                html = licenceRenderTrialForm(vm)
        }

        $('#licence-panel').html(html)
        licenceStartCountdown()
    }

    /**
     * Load the panel. The throttle is a courtesy to the licence server budget: switching tabs
     * repeatedly must not trigger a request every time. The real guard is server-side.
     */
    function licenceLoadPanel(force) {
        const now = Date.now()
        if (force !== true && licenceLoadedAt > 0 && (now - licenceLoadedAt) < 30000) {
            return
        }
        licenceLoadedAt = now

        $.post(
            'sources/admin.queries.php', {
                type: 'get_licence_panel',
                key: licenceSessionKey
            },
            function(data) {
                data = decodeQueryReturn(data, licenceSessionKey)
                if (data === undefined || data.error === true) {
                    return
                }
                licenceRenderPanel(data.panel)
            }
        )
    }

    $('a[href="#licence"]').on('shown.bs.tab', function() {
        licenceLoadPanel(false)
    })

    $(document).on('click', '#copy-licence-key', function(event) {
        event.preventDefault()
        tpClipboardCopy($(this).data('key'))
        toastr.remove()
        toastr.info(licenceLang.copied, '', {
            timeOut: 2000,
            progressBar: true,
            positionClass: 'toast-bottom-right'
        })
    })

    /**
     * Send the trial request. The first request and a resend go through the same call: the
     * licence server does not create a second demand, it refreshes the existing one and sends
     * a new link - which is exactly why the panel warns that the previous one stops working.
     */
    function licenceSendTrialRequest(email) {
        if (licenceRequestRunning === true) {
            return
        }
        licenceRequestRunning = true

        toastr.remove()
        toastr.info(licenceLang.in_progress + ' ... <i class="fas fa-circle-notch fa-spin fa-2x"></i>')

        $.post(
            'sources/admin.queries.php', {
                type: 'request_licence_trial',
                data: prepareExchangedData(
                    JSON.stringify({ product: 'extension', contact_email: email }),
                    'encode',
                    licenceSessionKey
                ),
                key: licenceSessionKey
            },
            function(data) {
                licenceRequestRunning = false
                data = decodeQueryReturn(data, licenceSessionKey)
                if (data === undefined) {
                    return
                }

                toastr.remove()
                if (data.error === true) {
                    let detail = data.message
                    if (Array.isArray(data.errors) === true && data.errors.length > 0) {
                        detail += '<br>' + data.errors.map(licenceText).join('<br>')
                    }
                    toastr.error(detail, licenceLang.caution, {
                        timeOut: 8000,
                        closeButton: true,
                        progressBar: true
                    })
                } else {
                    toastr.success(data.message, '', { timeOut: 6000, progressBar: true })
                }

                if (data.panel !== undefined) {
                    licenceRenderPanel(data.panel)
                }
            }
        ).fail(function() {
            licenceRequestRunning = false
            toastr.remove()
            toastr.error(licenceLang.answer_error, '', { closeButton: true })
        })
    }

    $(document).on('click', '#licence-request-trial', function(event) {
        event.preventDefault()

        const email = ($('#licence-trial-email').val() || '').toString().trim()
        const confirmBody = licenceLang.confirm_body
            + '<ul class="mt-2 mb-2">'
            + '<li>' + licenceLang.fqdn_label + ': <strong>'
            + licenceText($('#licence-identity-fqdn').text()) + '</strong></li>'
            + '<li>' + licenceLang.contact_email + ': <strong>'
            + licenceText(email) + '</strong></li>'
            + '</ul>'
            + '<p class="text-warning mb-0">' + licenceLang.confirm_warning + '</p>'

        launchConfirmDialog(
            licenceLang.confirm_title,
            confirmBody,
            function() {
                licenceSendTrialRequest(email)
            }
        )
    })

    $(document).on('click', '#licence-resend-trial', function(event) {
        event.preventDefault()
        licenceSendTrialRequest(($(this).data('email') || '').toString().trim())
    })

    $(document).on('click', '#licence-check-confirmed, #licence-refresh', function(event) {
        event.preventDefault()

        const $button = $(this)
        $button.prop('disabled', true)

        toastr.remove()
        toastr.info(licenceLang.in_progress + ' ... <i class="fas fa-circle-notch fa-spin fa-2x"></i>')

        $.post(
            'sources/admin.queries.php', {
                type: 'refresh_licence_status',
                key: licenceSessionKey
            },
            function(data) {
                data = decodeQueryReturn(data, licenceSessionKey)
                toastr.remove()
                if (data === undefined || data.error === true) {
                    $button.prop('disabled', false)
                    return
                }

                if (data.panel.budget_exhausted === true) {
                    toastr.warning(licenceLang.budget_exhausted, '', { timeOut: 6000, progressBar: true })
                }
                licenceRenderPanel(data.panel)
            }
        ).fail(function() {
            $button.prop('disabled', false)
            toastr.remove()
        })
    })

    // The Licence tab may already be the one shown when the page loads.
    $(function() {
        if ($('#licence-panel').length > 0) {
            licenceLoadPanel(true)
        }
    })
    //]]>
</script>
