/**
 * CPBS Combined - Step 1 Car Park Reorder
 *
 * Moves the "Select Car Park" section to its own full-width row above
 * "Entry Date / Entry Time" and "Exit Date / Exit Time" on booking form
 * step 1, without modifying the original CPBS plugin.
 *
 * Layout result:
 *   Row 1 (full width) : Select Car Park
 *   Row 2 (side by side): Entry Date & Time | Exit Date & Time
 */
(function ($) {
    'use strict';

    function reorderInScope($scope) {
        // Scope selectors to one booking form instance so pages with multiple
        // forms, or different page templates, are handled consistently.
        var $locationSelect = $scope.find('select[name="cpbs_location_id"]').first();
        if (!$locationSelect.length) return;

        var $entryDateInput = $scope.find('input[name="cpbs_entry_date"]').first();
        if (!$entryDateInput.length) return;

        // Section wrappers inside step 1:
        // section > fields-wrap > .cpbs-form-field > input/select
        var $locationSection = $locationSelect.closest('.cpbs-form-field').parent().parent();
        var $entryDateSection = $entryDateInput.closest('.cpbs-form-field').parent().parent();

        if (
            !$locationSection.length ||
            !$entryDateSection.length ||
            !$locationSection.parent().is($entryDateSection.parent())
        ) {
            return;
        }

        $locationSection.insertBefore($entryDateSection);

        $locationSection.css({
            'flex-basis': '100%',
            'width': '100%',
            'max-width': '100%'
        });
    }

    function reorderStep1Fields() {
        $('.cpbs-main-content-step-1').each(function () {
            reorderInScope($(this));
        });
    }

    $(document).ready(function () {
        reorderStep1Fields();

        // Some themes/pages initialize booking form markup slightly later.
        // Retry once shortly after ready to catch delayed rendering.
        setTimeout(reorderStep1Fields, 400);
        setTimeout(reorderStep1Fields, 1200);
    });

})(jQuery);
