# CPBS Combined Extensions

Combines three custom extensions for Car Park Booking System (CPBS):
- End Booking Early (admin action)
- Space Type Override (frontend booking form)
- Booking Receipt/Email Override (summary + reservation email output)

## Overview

This plugin extends CPBS with operational and UI improvements:
- Adds an admin button to end active bookings immediately.
- Replaces Step 4 location display with selected space type.
- Rewrites booking summary and reservation email output so Location shows Space Type value and Space Type section is removed.
- Automates booking reminder emails/SMS and occupancy tracking with a unique link flow.

## Features

### 1) End Booking Early

In booking list admin screen:
- Adds an Actions column button: End Booking.
- Available only for users with capability (default: manage_options).
- Works only for active bookings.

When executed:
- Updates exit date/time and normalized datetime to current site time.
- Optionally updates booking status to completed (default status ID: 4).
- Syncs status with WooCommerce if available.
- Triggers CPBS status-change email if status changed.

Security and safety:
- Authenticated admin-only AJAX action.
- Nonce verification.
- Capability check.
- Booking type/status validation before mutation.

Check-In / Check-Out SMS behavior:
- Twilio SMS integration is available in plugin code for custom workflow logic.
- Uses booking phone number from CPBS booking metadata.
- Message templates are editable from WordPress admin settings.
- Supports placeholders: {timestamp}, [timestamp], {booking_id}, [booking_id], {event}, [event].

### 2) Step 4 Space Type Override

On frontend booking form Step 4:
- Replaces right-column title with selected space type name.
- Hides original location details block (non-destructive hide).
- Observes DOM changes and reapplies automatically.

### 3) Booking Receipt and Reservation Email Override

For booking summary pages and outgoing reservation emails:
- Reads Space type name value.
- Replaces Location value with Space type value.
- Removes Space type section rows.

Coverage:
- Shortcode output filter for booking summary shortcode.
- Output buffer fallback on summary page URLs with booking token.
- Email body transformation through wp_mail filter for CPBS reservation template markup.

### 4) Booking Automation and Occupancy Tracking

Automated timeline:
- Before booking start (default: 30 minutes), sends customer a unique tracking link by email and SMS.
- If customer clicks the link, booking occupancy becomes Occupied.
- If link is not clicked after start, booking becomes Late, then Unoccupied after configurable grace period.
- Before booking end (default: 10 minutes), sends reminder email/SMS with booking end time.
- After booking ends (default: +5 minutes), sends follow-up email/SMS (default: "Waiting for your next visit.").

Admin visibility:
- Adds Occupancy and Link Clicked columns in booking list admin.

Editable settings:
- WordPress Admin > Settings > CPBS Booking Automation
- Configure all timing windows and message templates.
- Placeholders supported: {customer_name}, {booking_id}, {booking_start}, {booking_end}, {tracking_link}, {timestamp}

## Files

- cpbs-combined-extensions.php
  - Main plugin bootstrap and all PHP classes.
- cpbs-combined-end-booking-early-admin.js
  - Admin AJAX button behavior.
- cpbs-combined-step4-space-type-override.js
  - Step 4 frontend override logic.
- cpbs-combined-booking-receipt-override.js
  - Summary/receipt frontend DOM override logic.

## Requirements

- WordPress
- Car Park Booking System plugin
- jQuery (bundled in WordPress admin/frontend)

Optional integrations:
- WooCommerce (for booking status sync behavior)

## Installation

1. Copy folder cpbs-combined-extensions into wp-content/plugins/.
2. Activate CPBS Combined Extensions in WordPress admin.
3. Keep base CPBS plugin active.

## Configuration

- End Booking + SMS:
  - WordPress Admin > Settings > CPBS Booking SMS
  - Configure Twilio Account SID, Auth Token, From Number, and both SMS templates.
- Booking Automation:
  - WordPress Admin > Settings > CPBS Booking Automation
  - Configure reminder times, late/unoccupied thresholds, and email/SMS templates.
- Parking QR Code:
  - WordPress Admin > Settings > Parking QR Code
- Other behavior is also controllable via filters/hooks.

## Extension Hooks

### Feature toggles

- cpbs_combined_feature_enabled
  - Args: $default, $feature_key
  - Feature keys:
    - end_booking_early
    - step4_space_type_override
    - booking_receipt_override

### End Booking Early hooks

- cpbs_combined_end_booking_capability
- cpbs_combined_end_booking_action_column_label
- cpbs_combined_end_booking_action_column_key
- cpbs_combined_end_booking_active_statuses
- cpbs_combined_end_booking_completed_status_id
- cpbs_combined_end_booking_update_nonblocking_statuses
- cpbs_combined_end_booking_admin_script_handle
- cpbs_combined_end_booking_admin_script_config
- cpbs_combined_end_booking_ajax_response

Actions:
- cpbs_combined_before_end_booking_update
- cpbs_combined_after_end_booking_update
- cpbs_combined_end_booking_sync_error
- cpbs_combined_end_booking_email_error

### Step 4 override hooks

- cpbs_combined_step4_override_script_handle
- cpbs_combined_step4_override_script_config

### Receipt override hooks

- cpbs_combined_booking_receipt_script_handle
- cpbs_combined_booking_receipt_script_config

## Security Notes

- End Booking Early uses nonce + capability + booking validation checks.
- Summary output buffering is constrained to singular pages with valid booking token pattern and summary shortcode presence.
- Email transformation targets CPBS reservation template row pattern before applying replacements.

## Troubleshooting

- If old frontend behavior appears, clear browser cache and CDN cache.
- If summary changes do not apply:
  - Ensure the page includes [cpbs_booking_summary booking_form_id="..."] shortcode.
  - Ensure booking_id and access_token query params are present.
- If email changes do not apply:
  - Test with a newly generated reservation email.
  - Confirm email is using CPBS reservation template (contains Space type name row).

## Changelog

### 1.1.x

- Combined three CPBS customizations into one plugin.
- Added robust summary and email Location/Space Type transformation.
- Added hardened checks around summary output transformation scope.
