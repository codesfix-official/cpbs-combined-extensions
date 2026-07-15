<?php
/*
Plugin Name: CPBS Combined Extensions
Description: Combines "End Booking Early", "Step 4 Space Type Override", and "Booking Receipt Override" extensions for Car Park Booking System.
Version: 1.9.2
Author: CodesFix
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('CPBS_COMBINED_VERSION')) {
    define('CPBS_COMBINED_VERSION', '1.9.2');
}
if (!defined('CPBS_COMBINED_PLUGIN_FILE')) {
    define('CPBS_COMBINED_PLUGIN_FILE', __FILE__);
}
if (!defined('CPBS_COMBINED_PLUGIN_DIR')) {
    define('CPBS_COMBINED_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('CPBS_COMBINED_PLUGIN_URL')) {
    define('CPBS_COMBINED_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('CPBS_COMBINED_TEXT_DOMAIN')) {
    define('CPBS_COMBINED_TEXT_DOMAIN', 'cpbs-combined-extensions');
}

// Load plugin text domain for translations
add_action('init', function() {
    load_plugin_textdomain(
        CPBS_COMBINED_TEXT_DOMAIN,
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

// Load all feature classes
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-helpers.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-end-booking-admin.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-booking-receipt.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-booking-extension.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-step-overrides.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-customer-portal.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-booking-automation.php';
require_once CPBS_COMBINED_PLUGIN_DIR . 'includes/class-duplicate-prevention.php';

// Enqueue JavaScript files - conditional loading to avoid 404 errors
add_action('wp_enqueue_scripts', function() {
    // Skip on admin
    if (is_admin()) {
        return;
    }
    
    $plugin_url = CPBS_COMBINED_PLUGIN_URL;
    $version = CPBS_COMBINED_VERSION;
    
    // Core scripts - always load on frontend
    wp_enqueue_script('cpbs-booking-extension', $plugin_url . 'cpbs-combined-booking-extension.js', array(), $version, true);
    wp_enqueue_script('cpbs-booking-receipt-override', $plugin_url . 'cpbs-combined-booking-receipt-override.js', array(), $version, true);
    wp_enqueue_script('cpbs-customer-portal', $plugin_url . 'cpbs-combined-customer-portal.js', array(), $version, true);
    
    // Feature scripts - load with deferred execution (set to false to load in header if needed)
    wp_register_script('cpbs-service-fee-summary', $plugin_url . 'cpbs-combined-service-fee-summary.js', array(), $version, true);
    wp_register_script('cpbs-step1-car-park-reorder', $plugin_url . 'cpbs-combined-step1-car-park-reorder.js', array(), $version, true);
    wp_register_script('cpbs-step4-space-type-override', $plugin_url . 'cpbs-combined-step4-space-type-override.js', array(), $version, true);
    
    // Enqueue only if needed (will be enqueued by their respective classes)
    wp_enqueue_script('cpbs-service-fee-summary');
    wp_enqueue_script('cpbs-step1-car-park-reorder');
    wp_enqueue_script('cpbs-step4-space-type-override');
}, 20);

// Enqueue admin JavaScript files
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('cpbs-end-booking-early-admin', CPBS_COMBINED_PLUGIN_URL . 'cpbs-combined-end-booking-early-admin.js', array(), CPBS_COMBINED_VERSION, true);
}, 20);

// Initialize plugin features
add_action('plugins_loaded', function() {
    new CPBSCombinedAdminMenu();
    new CPBSCombinedEndBookingEarly();
    new CPBSCombinedStep4SpaceTypeOverride();
    new CPBSCombinedBookingReceiptOverride();
    new CPBSCombinedParkingQRCode();
    new CPBSCombinedCustomerPortal();
    new CPBSCombinedBookingAutomation();
    new CPBSCombinedServiceFeeSummary();
    new CPBSCombinedBookingExtension();
    new CPBSCombinedBookingReview();
    new CPBSCombinedStep1CarParkReorder();
    new CPBSCombinedBookingFormCompatibility();
    new CPBSCombinedCPBSAjaxRequestGuard();
    new CPBSCombinedBookingCancellation();
    new CPBSCombinedDuplicateBookingPrevention();
});

register_deactivation_hook(__FILE__, array('CPBSCombinedBookingAutomation', 'unschedule_cron'));
register_deactivation_hook(__FILE__, array('CPBSCombinedBookingReview', 'unschedule_cron'));
