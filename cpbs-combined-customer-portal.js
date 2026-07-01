(function ($, window) {
    'use strict';

    function getConfig() {
        return window.cpbsCustomerPortal || {};
    }

    function ensureNotice(message, isError) {
        var $portal = $('.cpbs-customer-portal').first();
        if (!$portal.length) {
            return;
        }

        var $notice = $portal.find('.cpbs-portal-notice').first();
        if (!$notice.length) {
            $notice = $('<div class="cpbs-portal-notice" />').prependTo($portal);
        }

        $notice
            .toggleClass('error', !!isError)
            .text(message || '');
    }

    function setBusy($button, busy) {
        if (busy) {
            $button.prop('disabled', true).data('cpbs-original-label', $button.text()).text(getConfig().i18n.processing || 'Cancelling...');
            return;
        }

        $button.prop('disabled', false);
        var original = $button.data('cpbs-original-label');
        if (original) {
            $button.text(original);
        }
    }

    $(document).on('click', '.cpbs-cancel-booking-btn', function (event) {
        event.preventDefault();

        var config = getConfig();
        var $button = $(this);
        var bookingId = Number($button.data('booking-id') || 0);
        var nonce = String($button.data('nonce') || '');

        if (!bookingId || !nonce) {
            ensureNotice(config.i18n.genericError || 'The reservation could not be cancelled.', true);
            return;
        }

        if (window.confirm(config.i18n.confirm || 'Cancel this reservation?') !== true) {
            return;
        }

        setBusy($button, true);
        ensureNotice('', false);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: config.cancelAction || 'cpbs_cancel_booking',
                booking_id: bookingId,
                nonce: nonce
            }
        }).done(function (response) {
            if (response && response.success) {
                ensureNotice((response.data && response.data.message) ? response.data.message : (config.i18n.success || 'Reservation cancelled.'), false);
                window.setTimeout(function () {
                    window.location.href = config.redirectUrl || window.location.href;
                }, 450);
                return;
            }

            ensureNotice((response && response.data && response.data.message) ? response.data.message : (config.i18n.genericError || 'The reservation could not be cancelled.'), true);
            setBusy($button, false);
        }).fail(function (xhr) {
            var message = config.i18n.genericError || 'The reservation could not be cancelled.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }

            ensureNotice(message, true);
            setBusy($button, false);
        });
    });
})(window.jQuery, window);
