(function () {
    'use strict';

    if (
        typeof cpbsFacilityVendor === 'undefined'
    ) {
        return;
    }


    var dashboard =
        document.querySelector(
            '.cpbs-facility-dashboard:not(.cpbs-facility-auth)'
        );


    if (!dashboard) {
        return;
    }


    var body =
        dashboard.querySelector(
            '[data-bookings-body]'
        );


    var filters =
        dashboard.querySelectorAll(
            '.cpbs-facility-filter'
        );


   var paginationElement = 
   dashboard.querySelector('[data-pagination]');


    var pageNumbers =
        dashboard.querySelector(
            '[data-page-numbers]'
        );


    var pageInfo =
        dashboard.querySelector(
            '[data-page-info]'
        );


    var previousButton =
        dashboard.querySelector(
            '[data-page-action="previous"]'
        );


    var nextButton =
        dashboard.querySelector(
            '[data-page-action="next"]'
        );


    var lastUpdated =
        dashboard.querySelector(
            '[data-last-updated]'
        );


    var activeFilter = 'all';

    var currentPage = 1;

    var totalPages = 1;

    var isLoading = false;


    /*
    |--------------------------------------------------------------------------
    | Utility
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        if (
            value === null ||
            typeof value === 'undefined'
        ) {
            return '';
        }


        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    function getStatusClass(statusId, category) {

        statusId =
            parseInt(statusId, 10) || 0;


        if (statusId === 4) {
            return 'completed';
        }


        if (statusId === 7) {
            return 'failed';
        }


        if (statusId === 3) {
            return 'cancelled';
        }


        if (category === 'upcoming') {
            return 'upcoming';
        }


        return 'previous';
    }


    /*
    |--------------------------------------------------------------------------
    | Render Booking Rows
    |--------------------------------------------------------------------------
    */

    function renderBookings(bookings) {

        if (
            !bookings ||
            !bookings.length
        ) {

            body.innerHTML =
                '<tr class="cpbs-facility-empty-row">' +
                    '<td colspan="5">' +
                        escapeHtml(
                            cpbsFacilityVendor.strings.empty
                        ) +
                    '</td>' +
                '</tr>';

            return;
        }


        var html = '';


        bookings.forEach(
            function (booking) {

                var customer =
                    booking.customer || {};

                var vehicle =
                    booking.vehicle || {};

                var status =
                    booking.status || {};


                var customerName =
                    customer.name || '—';


                var email =
                    customer.email || '';


                var phone =
                    customer.phone || '';


                var licensePlate =
                    vehicle.licensePlate || '—';


                var spaceType =
                    vehicle.spaceType || '';


                var statusName =
                    status.name || '—';


                var statusClass =
                    getStatusClass(
                        status.id,
                        booking.category
                    );


                var customerMeta = '';


                if (email) {

                    customerMeta +=
                        '<span>' +
                        escapeHtml(email) +
                        '</span>';
                }


                if (phone) {

                    customerMeta +=
                        '<span>' +
                        escapeHtml(phone) +
                        '</span>';
                }


                html +=
                    '<tr class="cpbs-facility-booking-row">' +

                        '<td data-label="Customer">' +

                            '<div class="cpbs-booking-customer">' +

                                '<strong>' +
                                    escapeHtml(customerName) +
                                '</strong>' +

                                (
                                    customerMeta
                                        ? '<div class="cpbs-booking-customer-meta">' +
                                            customerMeta +
                                          '</div>'
                                        : ''
                                ) +

                            '</div>' +

                        '</td>' +


                        '<td data-label="Vehicle">' +

                            '<div class="cpbs-booking-vehicle">' +

                                '<strong>' +
                                    escapeHtml(licensePlate) +
                                '</strong>' +

                                (
                                    spaceType
                                        ? '<span>' +
                                            escapeHtml(spaceType) +
                                          '</span>'
                                        : ''
                                ) +

                            '</div>' +

                        '</td>' +


                        '<td data-label="Entry">' +

                            '<span class="cpbs-booking-date">' +
                                escapeHtml(
                                    booking.entry || '—'
                                ) +
                            '</span>' +

                        '</td>' +


                        '<td data-label="Exit">' +

                            '<span class="cpbs-booking-date">' +
                                escapeHtml(
                                    booking.exit || '—'
                                ) +
                            '</span>' +

                        '</td>' +


                        '<td data-label="Status">' +

                            '<span class="cpbs-facility-status cpbs-status-' +
                                escapeHtml(statusClass) +
                            '">' +

                                escapeHtml(statusName) +

                            '</span>' +

                        '</td>' +

                    '</tr>';
            }
        );


        body.innerHTML = html;
    }


    /*
    |--------------------------------------------------------------------------
    | Summary
    |--------------------------------------------------------------------------
    */

    function renderCounts(counts) {

        counts =
            counts || {};


        var keys = [
            'upcoming',
            'completed',
            'failed'
        ];


        keys.forEach(
            function (key) {

                var element =
                    dashboard.querySelector(
                        '[data-count="' +
                        key +
                        '"]'
                    );


                if (!element) {
                    return;
                }


                element.textContent =
                    counts[key] || 0;
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    function createPageButton(
        page,
        label,
        isActive
    ) {

        var button =
            document.createElement('button');


        button.type = 'button';

        button.className =
            'cpbs-facility-page-number' +
            (
                isActive
                    ? ' is-active'
                    : ''
            );

        button.setAttribute(
            'data-page',
            page
        );

        button.setAttribute(
            'aria-label',
            cpbsFacilityVendor.strings.page +
            ' ' +
            page
        );


        if (isActive) {

            button.setAttribute(
                'aria-current',
                'page'
            );
        }


        button.textContent =
            label;


        return button;
    }


    function createEllipsis() {

        var span =
            document.createElement('span');


        span.className =
            'cpbs-facility-page-ellipsis';


        span.textContent = '…';


        return span;
    }


function renderPagination(paginationData) {
    if (!paginationElement) {
        return;
    }

    paginationData = paginationData || {};

    var totalItems = parseInt(paginationData.totalItems, 10) || 0;
    var perPage = parseInt(paginationData.perPage, 10) || 10;
    var newCurrentPage = parseInt(paginationData.currentPage, 10) || 1;
    var newTotalPages = parseInt(paginationData.totalPages, 10) || 1;

    currentPage = newCurrentPage;
    totalPages = newTotalPages;

    /*
     * Pagination is only visible when more than 10
     * filtered bookings exist.
     */
    if (totalItems <= perPage || totalPages <= 1) {

        paginationElement.classList.add('is-hidden');

        if (pageNumbers) {
            pageNumbers.innerHTML = '';
        }

        if (pageInfo) {
            pageInfo.textContent = '';
        }

        if (previousButton) {
            previousButton.disabled = true;
        }

        if (nextButton) {
            nextButton.disabled = true;
        }

        return;
    }

    /*
     * More than 10 bookings:
     * show pagination controls.
     */
    paginationElement.classList.remove('is-hidden');

    if (pageNumbers) {
        pageNumbers.innerHTML = '';
    }

    if (previousButton) {
        previousButton.disabled = currentPage <= 1;
    }

    if (nextButton) {
        nextButton.disabled = currentPage >= totalPages;
    }

    /*
     * Previous button
     */
    if (previousButton) {
        previousButton.setAttribute(
            'data-page',
            String(Math.max(1, currentPage - 1))
        );
    }

    /*
     * Page numbers
     */
    if (pageNumbers) {

        var pages = [];

        if (totalPages <= 7) {

            for (var i = 1; i <= totalPages; i++) {
                pages.push(i);
            }

        } else {

            pages.push(1);

            if (currentPage > 4) {
                pages.push('ellipsis');
            }

            var startPage = Math.max(2, currentPage - 1);
            var endPage = Math.min(totalPages - 1, currentPage + 1);

            for (var p = startPage; p <= endPage; p++) {
                pages.push(p);
            }

            if (currentPage < totalPages - 3) {
                pages.push('ellipsis');
            }

            pages.push(totalPages);
        }

        pages.forEach(function (page) {

            if (page === 'ellipsis') {
                var ellipsis = document.createElement('span');

                ellipsis.className = 'cpbs-facility-pagination-ellipsis';
                ellipsis.textContent = '…';

                pageNumbers.appendChild(ellipsis);

                return;
            }

            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'cpbs-facility-page-button';

            if (page === currentPage) {
                button.classList.add('is-active');
            }

            button.setAttribute('data-page', String(page));
            button.textContent = String(page);

            pageNumbers.appendChild(button);
        });
    }

    /*
     * Page information
     */
    if (pageInfo) {
        pageInfo.textContent =
            'Page ' +
            currentPage +
            ' of ' +
            totalPages;
    }
}


    /*
    |--------------------------------------------------------------------------
    | Loading
    |--------------------------------------------------------------------------
    */

    function showLoading() {

        body.innerHTML =
            '<tr class="cpbs-facility-loading-row">' +
                '<td colspan="5">' +
                    escapeHtml(
                        cpbsFacilityVendor.strings.loading
                    ) +
                '</td>' +
            '</tr>';
    }


    /*
    |--------------------------------------------------------------------------
    | Error
    |--------------------------------------------------------------------------
    */

    function showError(message) {

        body.innerHTML =
            '<tr class="cpbs-facility-error-row">' +
                '<td colspan="5">' +
                    escapeHtml(
                        message ||
                        cpbsFacilityVendor.strings.error
                    ) +
                '</td>' +
            '</tr>';
    }


    /*
    |--------------------------------------------------------------------------
    | AJAX Load
    |--------------------------------------------------------------------------
    */

    function loadBookings(
        showLoader
    ) {

        if (isLoading) {
            return;
        }


        isLoading = true;


        if (showLoader) {
            showLoading();
        }


        var formData =
            new FormData();


        formData.append(
            'action',
            cpbsFacilityVendor.action
        );


        formData.append(
            'nonce',
            cpbsFacilityVendor.nonce
        );


        formData.append(
            'category',
            activeFilter
        );


        formData.append(
            'page',
            currentPage
        );


        fetch(
            cpbsFacilityVendor.ajaxUrl,
            {
                method: 'POST',

                credentials: 'same-origin',

                cache: 'no-store',

                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },

                body: formData
            }
        )

        /*
         * Read text first. This prevents the generic JSON.parse error
         * from hiding PHP warnings, HTML errors, or a stale nonce.
         */
        .then(
            function (response) {

                return response.text().then(
                    function (text) {

                        var trimmed =
                            (text || '').trim();

                        var parsed;

                        try {
                            parsed = JSON.parse(trimmed);
                        } catch (parseError) {

                            console.error(
                                'CPBS Facility Dashboard: invalid AJAX response:',
                                text
                            );

                            throw new Error(
                                'The server returned an invalid response. ' +
                                'Please refresh the page. ' +
                                (
                                    trimmed
                                        ? 'Server response: ' +
                                          trimmed.substring(0, 300)
                                        : 'The server returned an empty response.'
                                )
                            );
                        }

                        return {
                            httpStatus: response.status,
                            data: parsed
                        };
                    }
                );
            }
        )

        .then(
            function (result) {

                var response =
                    result.data;

                if (
                    !response ||
                    !response.success
                ) {

                    throw new Error(
                        response &&
                        response.data &&
                        response.data.message
                            ? response.data.message
                            : cpbsFacilityVendor.strings.error
                    );
                }

                var data =
                    response.data || {};

                renderBookings(
                    data.bookings || []
                );

                renderCounts(
                    data.counts || {}
                );

                renderPagination(
                    data.pagination || {}
                );

                /*
                 * Backend may have corrected page
                 * if current page became invalid.
                 */
                if (
                    data.pagination &&
                    data.pagination.currentPage
                ) {

                    currentPage =
                        parseInt(
                            data.pagination.currentPage,
                            10
                        ) || 1;
                }

                if (lastUpdated) {

                    var date =
                        new Date();

                    lastUpdated.textContent =
                        'Last updated: ' +
                        date.toLocaleTimeString();
                }
            }
        )

        .catch(
            function (error) {

                console.error(
                    'CPBS Facility Dashboard:',
                    error
                );


                showError(
                    error.message
                );
            }
        )

        .finally(
            function () {

                isLoading = false;
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Filter Click
    |--------------------------------------------------------------------------
    */

    filters.forEach(
        function (filter) {

            filter.addEventListener(
                'click',
                function () {

                    var newFilter =
                        filter.getAttribute(
                            'data-filter'
                        ) || 'all';


                    if (
                        newFilter ===
                        activeFilter
                    ) {
                        return;
                    }


                    activeFilter =
                        newFilter;


                    /*
                     * Reset pagination whenever
                     * filter changes.
                     */
                    currentPage = 1;


                    filters.forEach(
                        function (item) {

                            item.classList.remove(
                                'is-active'
                            );
                        }
                    );


                    filter.classList.add(
                        'is-active'
                    );


                    loadBookings(true);
                }
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Pagination Clicks
    |--------------------------------------------------------------------------
    */

    if (previousButton) {

        previousButton.addEventListener(
            'click',
            function () {

                if (
                    currentPage <= 1
                ) {
                    return;
                }


                currentPage--;

                loadBookings(true);
            }
        );
    }


    if (nextButton) {

        nextButton.addEventListener(
            'click',
            function () {

                if (
                    currentPage >=
                    totalPages
                ) {
                    return;
                }


                currentPage++;

                loadBookings(true);
            }
        );
    }


    if (pageNumbers) {

        pageNumbers.addEventListener(
            'click',
            function (event) {

                var button =
                    event.target.closest(
                        '[data-page]'
                    );


                if (!button) {
                    return;
                }


                var page =
                    parseInt(
                        button.getAttribute(
                            'data-page'
                        ),
                        10
                    );


                if (
                    !page ||
                    page === currentPage
                ) {
                    return;
                }


                currentPage = page;

                loadBookings(true);
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initial Load
    |--------------------------------------------------------------------------
    */

    loadBookings(true);


    /*
    |--------------------------------------------------------------------------
    | Auto Refresh
    |--------------------------------------------------------------------------
    *
    * Current filter and current page are preserved.
    */
    var refreshInterval =
        parseInt(
            cpbsFacilityVendor.refreshInterval,
            10
        ) || 10000;


    window.setInterval(
        function () {

            /*
             * Don't replace current page with
             * a loader every 10 seconds.
             */
            loadBookings(false);

        },
        refreshInterval
    );

})();