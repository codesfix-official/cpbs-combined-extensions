(function ($) {
    'use strict';

    $(document).on('click', '.cpbs-end-booking-button', function () {
        var button = $(this);
        var bookingId = button.data('booking-id');
        var config = window.cpbsEndBookingEarly || {};
        var i18n = config.i18n || {};

        if (!bookingId) {
            window.alert(i18n.genericError || 'The booking could not be ended.');
            return;
        }

        if (!window.confirm(i18n.confirm || 'End this booking now?')) {
            return;
        }

        button.prop('disabled', true).text(i18n.processing || 'Ending...');

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
            button.prop('disabled', false).text(i18n.button || 'End Booking');
        }).fail(function (xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            var message = response && response.data && response.data.message ? response.data.message : (i18n.genericError || 'The booking could not be ended.');
            window.alert(message);
            button.prop('disabled', false).text(i18n.button || 'End Booking');
        });
    });
}(jQuery));
