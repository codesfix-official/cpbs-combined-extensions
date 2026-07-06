<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Step 4 Space Type Override
// ============================================================================
final class CPBSCombinedStep4SpaceTypeOverride
{
    const VERSION = '1.1.0';

    public function __construct()
    {
        if (!$this->is_feature_enabled('step4_space_type_override', true)) {
            return;
        }

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 100);
    }

    public function enqueue_assets()
    {
        if (is_admin()) {
            return;
        }

        if (!$this->is_cpbs_available()) {
            return;
        }

        $handle = apply_filters('cpbs_combined_step4_override_script_handle', 'cpbs-combined-step4-space-type-override');
        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-combined-step4-space-type-override.js',
            array('jquery'),
            self::VERSION,
            true
        );

        $config = array(
            'selectors' => array(
                'form' => '.cpbs-main',
                'selectedPlaceButton' => '.cpbs-place-select-button.cpbs-state-selected',
                'placeCard' => '.cpbs-place',
                'placeName' => '.cpbs-place-name',
                'placeTypeInput' => 'input[name="cpbs_place_type_id"]',
                'step4RightColumn' => '.cpbs-main-content-step-4 > .cpbs-layout-50x50 > .cpbs-layout-column-right',
                'step4Header' => '.cpbs-header.cpbs-header-style-3',
                'locationDetails' => '.cpbs-attribute-field',
            ),
            'hideLocationDetails' => true,
            'hiddenClass' => 'cpbs-step4-space-type-override-hidden',
        );

        wp_localize_script(
            $handle,
            'cpbsStep4OverrideConfig',
            apply_filters('cpbs_combined_step4_override_script_config', $config)
        );
    }

    private function is_cpbs_available()
    {
        return defined('PLUGIN_CPBS_CONTEXT') || shortcode_exists('cpbs_booking_form');
    }

    private function is_feature_enabled($feature_key, $default)
    {
        return CPBSCombinedHelpers::is_feature_enabled($feature_key, $default);
    }
}


// ============================================================================
// Step 1 Car Park Reorder
// ============================================================================
class CPBSCombinedStep1CarParkReorder
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 120);
    }

    public function enqueue_assets()
    {
        if (!wp_script_is('jquery', 'registered')) {
            return;
        }

        $handle = 'cpbs-combined-step1-car-park-reorder';

        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-combined-step1-car-park-reorder.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }
}

/**
 * Compatibility shim for corrupted/legacy CPBS booking form scripts that call
 * helper.handleFormCheckBox($this) directly.
 */


