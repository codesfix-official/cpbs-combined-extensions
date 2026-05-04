(function ($, window) {
    'use strict';

    var config = window.cpbsBookingExtension || {};
    var i18n = config.i18n || {};

    function asMoney(value) {
        var number = Number(value);
        if (!Number.isFinite(number)) {
            return '0.00';
        }

        return number.toFixed(2);
    }

    function updateEstimate($wrap) {
        var perHour = Number($wrap.attr('data-price-per-hour') || 0);
        var hours = Number($wrap.find('input[name="cpbs_extension_hours"]').val() || 0);

        if (!Number.isFinite(perHour) || !Number.isFinite(hours) || perHour <= 0 || hours <= 0) {
            $wrap.find('.cpbs-combined-extension-estimate').text('');
            return;
        }

        var estimate = perHour * hours;
        var label = i18n.estimateLabel || 'Estimated extension charge:';
        $wrap.find('.cpbs-combined-extension-estimate').text(label + ' ' + asMoney(estimate));
    }

    function setFeedback($wrap, message, isError) {
        var $feedback = $wrap.find('.cpbs-combined-extension-feedback');
        $feedback.text(message || '');
        $feedback.toggleClass('cpbs-state-error', !!isError);
    }

    function handleSubmit(event) {
        event.preventDefault();

        var $form = $(event.currentTarget);
        var $wrap = $form.closest('.cpbs-combined-extension-wrap');
        var $button = $form.find('button[type="submit"]');

        var bookingId = Number($wrap.attr('data-booking-id') || 0);
        var accessToken = String($wrap.attr('data-access-token') || '');
        var hours = Number($form.find('input[name="cpbs_extension_hours"]').val() || 0);

        if (!Number.isInteger(hours) || hours < 1) {
            setFeedback($wrap, i18n.invalidHours || 'Please enter at least 1 hour.', true);
            return;
        }

        setFeedback($wrap, '', false);
        $button.prop('disabled', true).text(i18n.processing || 'Preparing Stripe checkout...');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: config.action,
                nonce: config.nonce,
                booking_id: bookingId,
                access_token: accessToken,
                hours: hours,
                return_url: window.location.href
            }
        }).done(function (response) {
            if (response && response.success && response.data && response.data.checkoutUrl) {
                window.location.href = response.data.checkoutUrl;
                return;
            }

            var message = i18n.genericError || 'Booking extension could not be started.';
            if (response && response.data && response.data.message) {
                message = response.data.message;
            }
            setFeedback($wrap, message, true);
            $button.prop('disabled', false).text('Pay & Extend via Stripe');
        }).fail(function (xhr) {
            var message = i18n.genericError || 'Booking extension could not be started.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            }
            setFeedback($wrap, message, true);
            $button.prop('disabled', false).text('Pay & Extend via Stripe');
        });
    }

    $(document).on('input change', '.cpbs-combined-extension-wrap input[name="cpbs_extension_hours"]', function () {
        updateEstimate($(this).closest('.cpbs-combined-extension-wrap'));
    });

    $(document).on('submit', '.cpbs-combined-extension-form', handleSubmit);

    $(function () {
        $('.cpbs-combined-extension-wrap').each(function () {
            updateEstimate($(this));
        });
    });
})(window.jQuery, window);
