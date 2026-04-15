(function ($) {
    'use strict';

    var config = window.cpbsStep4OverrideConfig || {};
    var selectors = config.selectors || {};

    function getSelector(name, fallback) {
        return selectors[name] || fallback;
    }

    function getSelectedSpaceTypeName($form) {
        var selectedButton = $form.find(getSelector('selectedPlaceButton', '.cpbs-place-select-button.cpbs-state-selected')).first();
        if (selectedButton.length) {
            var fromSelectedCard = $.trim(selectedButton.closest(getSelector('placeCard', '.cpbs-place')).find(getSelector('placeName', '.cpbs-place-name')).first().text());
            if (fromSelectedCard) {
                return fromSelectedCard;
            }
        }

        var selectedPlaceTypeId = parseInt($form.find(getSelector('placeTypeInput', 'input[name="cpbs_place_type_id"]')).val(), 10);
        if (!isNaN(selectedPlaceTypeId) && selectedPlaceTypeId > 0) {
            var fromPlaceTypeId = $.trim($form.find(getSelector('placeCard', '.cpbs-place') + '[data-place_type_id="' + selectedPlaceTypeId + '"] ' + getSelector('placeName', '.cpbs-place-name')).first().text());
            if (fromPlaceTypeId) {
                return fromPlaceTypeId;
            }
        }

        return '';
    }

    function replaceStep4LocationWithSpaceType($form) {
        var step4Right = $form.find(getSelector('step4RightColumn', '.cpbs-main-content-step-4 > .cpbs-layout-50x50 > .cpbs-layout-column-right')).first();
        if (!step4Right.length) {
            return;
        }

        var spaceTypeName = getSelectedSpaceTypeName($form);
        if (!spaceTypeName) {
            return;
        }

        var title = step4Right.find(getSelector('step4Header', '.cpbs-header.cpbs-header-style-3')).first();
        if (title.length) {
            var currentTitle = $.trim(title.text());
            if (currentTitle !== spaceTypeName) {
                title.text(spaceTypeName);
            }
        }

        var locationDetails = step4Right.find(getSelector('locationDetails', '.cpbs-attribute-field')).first();
        if (locationDetails.length && config.hideLocationDetails !== false) {
            var hiddenClass = config.hiddenClass || 'cpbs-step4-space-type-override-hidden';
            // Hide the original block instead of removing it to avoid breaking CPBS internals.
            if (!locationDetails.hasClass(hiddenClass)) {
                locationDetails
                    .addClass(hiddenClass)
                    .attr('aria-hidden', 'true')
                    .hide();
            }
        }
    }

    function bindForm($form) {
        if (!$form.length || $form.data('cpbsStep4OverrideBound')) {
            return;
        }

        $form.data('cpbsStep4OverrideBound', true);

        var step4Right = $form.find(getSelector('step4RightColumn', '.cpbs-main-content-step-4 > .cpbs-layout-50x50 > .cpbs-layout-column-right')).first();
        if (!step4Right.length || typeof MutationObserver === 'undefined') {
            replaceStep4LocationWithSpaceType($form);
            return;
        }

        var updateScheduled = false;
        var observer = new MutationObserver(function () {
            if (updateScheduled) {
                return;
            }

            updateScheduled = true;
            (window.requestAnimationFrame || window.setTimeout)(function () {
                replaceStep4LocationWithSpaceType($form);
                updateScheduled = false;
            }, 16);
        });

        observer.observe(step4Right.get(0), {
            childList: true,
            subtree: true
        });

        replaceStep4LocationWithSpaceType($form);
    }

    $(function () {
        $(getSelector('form', '.cpbs-main')).each(function () {
            bindForm($(this));
        });
    });
}(jQuery));
