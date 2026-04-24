(function ($) {
    'use strict';

    var config = window.cpbsServiceFeeConfig || {};
    var selectors = config.selectors || {};
    var labels = config.labels || {};
    var fees = config.fees || {};

    function getSelector(name, fallback) {
        return selectors[name] || fallback;
    }

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function parseAmount(value) {
        var text = String(value || '').trim();
        var numericMatch = text.match(/-?[0-9][0-9\s.,]*/);

        if (!numericMatch) {
            return null;
        }

        var numberText = numericMatch[0].replace(/\s+/g, '');
        var hasComma = numberText.indexOf(',') !== -1;
        var hasDot = numberText.indexOf('.') !== -1;

        if (hasComma && hasDot) {
            if (numberText.lastIndexOf(',') > numberText.lastIndexOf('.')) {
                numberText = numberText.replace(/\./g, '').replace(',', '.');
            } else {
                numberText = numberText.replace(/,/g, '');
            }
        } else if (hasComma) {
            numberText = numberText.replace(',', '.');
        }

        var valueNumber = parseFloat(numberText);
        if (isNaN(valueNumber)) {
            return null;
        }

        return {
            value: valueNumber,
            token: numericMatch[0],
            source: text
        };
    }

    function toFixedAmount(value, source) {
        var amount = Math.round((value + Number.EPSILON) * 100) / 100;
        var sourceText = String(source || '');
        var decimals = 2;

        var dotIndex = sourceText.lastIndexOf('.');
        var commaIndex = sourceText.lastIndexOf(',');
        var splitIndex = Math.max(dotIndex, commaIndex);

        if (splitIndex !== -1) {
            var decimalPart = sourceText.substring(splitIndex + 1).replace(/[^0-9]/g, '');
            if (decimalPart.length > 0 && decimalPart.length <= 4) {
                decimals = decimalPart.length;
            }
        }

        return amount.toFixed(decimals);
    }

    function formatLike(sourceText, amount) {
        var parsed = parseAmount(sourceText);
        if (!parsed) {
            return String(amount.toFixed(2));
        }

        var token = parsed.token;
        var start = sourceText.indexOf(token);
        if (start === -1) {
            return String(amount.toFixed(2));
        }

        var end = start + token.length;
        var prefix = sourceText.substring(0, start);
        var suffix = sourceText.substring(end);
        var numeric = toFixedAmount(amount, token);

        if (token.indexOf(',') !== -1 && token.indexOf('.') === -1) {
            numeric = numeric.replace('.', ',');
        }

        return prefix + numeric + suffix;
    }

    function getSelectedPlaceTypeId($root) {
        var $context = $root && $root.length ? $root : $(document.body);

        var inputSelector = getSelector('placeTypeInput', 'input[name="cpbs_place_type_id"]');
        var valueFromInput = parseInt($context.find(inputSelector).first().val(), 10);
        if (!isNaN(valueFromInput) && valueFromInput > 0) {
            return valueFromInput;
        }

        var selectedButtonSelector = getSelector('selectedPlace', '.cpbs-place-select-button.cpbs-state-selected');
        var placeSelector = getSelector('placeCard', '.cpbs-place');
        var selectedButton = $context.find(selectedButtonSelector).first();
        if (selectedButton.length) {
            var selectedPlace = selectedButton.closest(placeSelector);
            var dataValue = parseInt(selectedPlace.attr('data-place_type_id') || selectedPlace.data('place_type_id'), 10);
            if (!isNaN(dataValue) && dataValue > 0) {
                return dataValue;
            }
        }

        return 0;
    }

    function renameTaxLabels($root) {
        var taxLabel = normalizeText(labels.tax || 'Tax');
        var taxesLabel = normalizeText(labels.taxes || 'Taxes');
        var replacement = labels.serviceFee || 'Service Fee';

        $root.find('span,th,td,div').each(function () {
            var $el = $(this);
            if ($el.children().length > 0) {
                return;
            }

            var normalized = normalizeText($el.text());
            if (normalized === taxLabel || normalized === taxesLabel) {
                $el.text(replacement);
            }
        });
    }

    function processSummaryBlock($summary, feeAmount) {
        var serviceFeeLabel = labels.serviceFee || 'Service Fee';
        var parkingLabel = labels.parking || 'Parking';
        var spaceLabel = normalizeText(labels.space || 'Space');

        var $rows = $summary.children('div').not(getSelector('totalBlock', '.cpbs-summary-price-element-total'));
        if (!$rows.length) {
            return;
        }

        var $spaceRow = null;
        var $taxRow = null;

        $rows.each(function () {
            var $row = $(this);
            var $spans = $row.find('> span');
            if ($spans.length < 2) {
                return;
            }

            var left = normalizeText($spans.eq(0).text());
            if (left.indexOf(spaceLabel) !== -1) {
                $spaceRow = $row;
            }

            if (left === normalizeText(labels.tax || 'Tax') || left === normalizeText(labels.taxes || 'Taxes')) {
                $taxRow = $row;
            }
        });

        if ($taxRow && $taxRow.length) {
            $taxRow.find('> span').eq(0).text(serviceFeeLabel);
        }

        if (!$spaceRow || !$spaceRow.length) {
            return;
        }

        var $spaceValue = $spaceRow.find('> span').eq(1);
        var originalSpaceText = $spaceValue.attr('data-cpbs-original-space-text');
        if (!originalSpaceText) {
            originalSpaceText = $spaceValue.text();
            $spaceValue.attr('data-cpbs-original-space-text', originalSpaceText);
        }

        var parsedSpace = parseAmount(originalSpaceText);
        if (!parsedSpace) {
            return;
        }

        var normalizedFeeAmount = feeAmount > 0 ? feeAmount : 0;
        var serviceFeeAmount = Math.min(normalizedFeeAmount, parsedSpace.value);
        var parkingAmount = Math.max(parsedSpace.value - serviceFeeAmount, 0);
        var serviceFeeDisplay = formatLike(originalSpaceText, serviceFeeAmount);

        if (serviceFeeAmount > 0) {
            // var breakdown = formatLike(originalSpaceText, parkingAmount) + ' ' + parkingLabel + ' + ' + serviceFeeDisplay + ' ' + serviceFeeLabel;
            // $spaceValue.text(breakdown);

            $spaceValue.text(formatLike(originalSpaceText, parkingAmount));

            if ($taxRow && $taxRow.length) {
                $taxRow.find('> span').eq(1).text(serviceFeeDisplay);
            } else {
                var $serviceRow = $summary.children('.cpbs-summary-price-element-service-fee').first();
                if (!$serviceRow.length) {
                    $serviceRow = $('<div class="cpbs-summary-price-element-service-fee"><span></span><span></span></div>');
                }
                $serviceRow.find('span').eq(0).text(serviceFeeLabel);
                $serviceRow.find('span').eq(1).text(serviceFeeDisplay);
                var $total = $summary.children(getSelector('totalBlock', '.cpbs-summary-price-element-total')).first();
                if ($total.length) {
                    $serviceRow.insertBefore($total);
                } else {
                    $summary.append($serviceRow);
                }
            }
        } else {
            $summary.children('.cpbs-summary-price-element-service-fee').remove();

            if (originalSpaceText) {
                $spaceValue.text(originalSpaceText);
            }
        }
    }

    function processAll() {
        var $document = $(document.body);
        renameTaxLabels($document);

        var placeTypeId = getSelectedPlaceTypeId($document);
        var feeAmount = parseFloat(fees[String(placeTypeId)] || 0);
        if (isNaN(feeAmount) || feeAmount < 0) {
            feeAmount = 0;
        }

        $(getSelector('summaryRoot', '.cpbs-summary-price-element')).each(function () {
            processSummaryBlock($(this), feeAmount);
        });
    }

    $(function () {
        processAll();

        if (typeof MutationObserver !== 'undefined') {
            var scheduled = false;
            var observer = new MutationObserver(function () {
                if (scheduled) {
                    return;
                }

                scheduled = true;
                (window.requestAnimationFrame || window.setTimeout)(function () {
                    processAll();
                    scheduled = false;
                }, 16);
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: false,
                characterData: false
            });
        }

        $(document).on('cpbs:summary:updated cpbs.booking.form.updated cpbs:booking:complete', function () {
            processAll();
        });
    });
}(jQuery));
