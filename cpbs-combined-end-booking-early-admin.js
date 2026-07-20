(function ($) {
    'use strict';

    window.CPBSCombinedEndBookingEarly = window.CPBSCombinedEndBookingEarly || {};

    function getConfig() {
        return window.cpbsEndBookingEarly || {};
    }

    function getI18n() {
        var config = getConfig();
        return config.i18n || {};
    }

    function setBusy(button, busy, label) {
        button.data('cpbs-busy', !!busy);
        button.prop('disabled', !!busy);
        if (label) {
            button.text(label);
        }
    }

    function handleEndBooking(buttonEl) {
        var button = $(buttonEl);
        var bookingId = button.data('booking-id');
        var config = getConfig();
        var i18n = getI18n();

        if (!bookingId) {
            window.alert(i18n.genericError || 'The booking could not be ended.');
            return false;
        }

        if (button.data('cpbs-busy')) {
            return false;
        }

        if (!window.confirm(i18n.confirm || 'End this booking now?')) {
            return false;
        }

        setBusy(button, true, i18n.processing || 'Ending...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: config.action,
                nonce: config.nonce,
                booking_id: bookingId
            }
        }).done(function (response) {
            if (response && response.success) {
                window.location.reload();
                return;
            }

            var message = response && response.data && response.data.message ? response.data.message : (i18n.genericError || 'The booking could not be ended.');
            window.alert(message);
            setBusy(button, false, i18n.button || 'End Booking');
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            var message = response && response.data && response.data.message ? response.data.message : (i18n.genericError || 'The booking could not be ended.');
            window.alert(message);
            setBusy(button, false, i18n.button || 'End Booking');
        });

        return false;
    }

    function handleConfirmBooking(buttonEl) {
        var button = $(buttonEl);
        var bookingId = button.data('booking-id');
        var config = getConfig();
        var i18n = getI18n();

        if (!bookingId) {
            window.alert(i18n.confirmGenericError || 'The booking could not be confirmed.');
            return false;
        }

        if (button.data('cpbs-busy')) {
            return false;
        }

        if (!window.confirm(i18n.confirmBooking || 'Confirm this booking as occupied?')) {
            return false;
        }

        setBusy(button, true, i18n.confirmProcessing || 'Confirming...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: config.confirmAction,
                nonce: config.confirmNonce,
                booking_id: bookingId
            }
        }).done(function (response) {
            if (response && response.success) {
                var endButton = $('<button type="button" class="button cpbs-end-booking-button" data-booking-id="' + bookingId + '" onclick="return window.CPBSCombinedEndBookingEarly && window.CPBSCombinedEndBookingEarly.handleEndBooking(this);"></button>');
                endButton.text(i18n.button || 'End Booking');
                button.replaceWith(endButton);
                return;
            }

            var message = response && response.data && response.data.message ? response.data.message : (i18n.confirmGenericError || 'The booking could not be confirmed.');
            window.alert(message);
            setBusy(button, false, i18n.confirmButton || 'Confirm Booking');
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            var message = response && response.data && response.data.message ? response.data.message : (i18n.confirmGenericError || 'The booking could not be confirmed.');
            window.alert(message);
            setBusy(button, false, i18n.confirmButton || 'Confirm Booking');
        });

        return false;
    }

    window.CPBSCombinedEndBookingEarly.handleEndBooking = handleEndBooking;
    window.CPBSCombinedEndBookingEarly.handleConfirmBooking = handleConfirmBooking;
}(jQuery));
