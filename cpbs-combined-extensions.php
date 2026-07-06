<?php
/*
Plugin Name: CPBS Combined Extensions
Description: Combines "End Booking Early", "Step 4 Space Type Override", and "Booking Receipt Override" extensions for Car Park Booking System.
Version: 1.8.0
Author: CodesFix
*/

if (!defined('ABSPATH')) {
    exit;
}

// Load all feature classes
require_once plugin_dir_path(__FILE__) . 'includes/class-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-end-booking-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-booking-receipt.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-step-overrides.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-booking-automation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-duplicate-prevention.php';

// Initialize plugin features
add_action('plugins_loaded', function() {
    new CPBSCombinedAdminMenu();
    new CPBSCombinedEndBookingEarly();
    new CPBSCombinedStep4SpaceTypeOverride();
    new CPBSCombinedBookingReceiptOverride();
    new CPBSCombinedParkingQRCode();
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
