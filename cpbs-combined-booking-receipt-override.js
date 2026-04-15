(function($) {
    'use strict';

    $(document).ready(function() {
        if (typeof window.cpbsReceiptOverrideConfig === 'undefined') {
            return;
        }

        var config = window.cpbsReceiptOverrideConfig;
        var selectors = config.selectors || {};
        var hideSpaceTypeSection = config.hideSpaceTypeSection !== false;
        var hiddenClass = config.hiddenClass || 'cpbs-receipt-location-hidden';

        function normalizeText(value) {
            return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        function labelMatches(text, labels) {
            var normalizedText = normalizeText(text);
            for (var i = 0; i < labels.length; i += 1) {
                if (normalizedText.indexOf(normalizeText(labels[i])) !== -1) {
                    return true;
                }
            }

            return false;
        }

        function hideRow(row) {
            row.addClass(hiddenClass).attr('aria-hidden', 'true').hide();
        }

        function findCellPairRows(root) {
            return root.find('tr').filter(function() {
                var cells = $(this).children('td,th');
                return cells.length >= 2;
            });
        }

        function processReceipt() {
            var receiptContainer = $(selectors.receiptContainer || '.cpbs-booking-summary-page, .cpbs-receipt-container');
            if (receiptContainer.length === 0) {
                return;
            }

            var locationLabels = selectors.locationLabels || [selectors.locationHeaderText || 'Location'];
            var spaceTypeLabels = selectors.spaceTypeLabels || [selectors.spaceTypeHeaderText || 'Space type', selectors.spaceTypeLabel || 'Space type name', 'Space Type'];
            var spaceTypeHeaderLabels = selectors.spaceTypeHeaderLabels || [selectors.spaceTypeHeaderText || 'Space type', 'Space Type'];

            receiptContainer.each(function() {
                var root = $(this);
                var locationValueCell = null;
                var spaceTypeValueCell = null;
                var rows = findCellPairRows(root);

                rows.each(function() {
                    var row = $(this);
                    var cells = row.children('td,th');
                    var labelCell = cells.eq(0);
                    var valueCell = cells.eq(1);
                    var labelText = labelCell.text();

                    if (!locationValueCell && labelMatches(labelText, locationLabels)) {
                        locationValueCell = valueCell;
                    }

                    if (!spaceTypeValueCell && labelMatches(labelText, spaceTypeLabels)) {
                        spaceTypeValueCell = valueCell;
                    }
                });

                if (locationValueCell && spaceTypeValueCell) {
                    // Keep "Location" row and replace its value with the selected space type.
                    locationValueCell.html(spaceTypeValueCell.html());
                }

                if (!hideSpaceTypeSection) {
                    return;
                }

                root.find('tr').each(function() {
                    var row = $(this);
                    var cells = row.children('td,th');

                    if (cells.length === 1 && labelMatches(cells.eq(0).text(), spaceTypeHeaderLabels)) {
                        hideRow(row);
                        return;
                    }

                    if (cells.length >= 2 && labelMatches(cells.eq(0).text(), spaceTypeLabels)) {
                        hideRow(row);
                        return;
                    }

                    var nestedSpaceTypeRow = row.find('tr').filter(function() {
                        var nestedCells = $(this).children('td,th');
                        return nestedCells.length >= 2 && labelMatches(nestedCells.eq(0).text(), spaceTypeLabels);
                    }).first();

                    if (nestedSpaceTypeRow.length) {
                        hideRow(row);
                    }
                });
            });
        }

        // Initial processing
        processReceipt();

        // Watch for dynamic content changes
        if (typeof MutationObserver !== 'undefined') {
            try {
                var receiptContainer = document.querySelector(selectors.receiptContainer || '.cpbs-booking-summary-page, .cpbs-receipt-container');
                if (receiptContainer) {
                    var observer = new MutationObserver(function(mutations) {
                        // Debounce rapid mutations
                        clearTimeout(window.cpbsReceiptOverrideTimeout);
                        window.cpbsReceiptOverrideTimeout = setTimeout(function() {
                            processReceipt();
                        }, 300);
                    });

                    observer.observe(receiptContainer, {
                        childList: true,
                        subtree: true,
                        characterData: false,
                        attributes: true,
                        attributeFilter: ['class', 'style', 'aria-hidden']
                    });

                    // Cleanup on page unload
                    $(window).on('beforeunload', function() {
                        observer.disconnect();
                        clearTimeout(window.cpbsReceiptOverrideTimeout);
                    });
                }
            } catch (e) {
                console.warn('CPBS Receipt Override: MutationObserver error', e);
            }
        }

        // Reprocess on custom events
        $(document).on('cpbs:receipt:updated cpbs:booking:complete cpbs.receipt.updated', function() {
            processReceipt();
        });
    });
})(jQuery);
