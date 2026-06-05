# CPBS Combined Extensions

A custom WordPress plugin that extends the Car Park Booking System (CPBS) with operational, UI, and booking-integrity features — without modifying the main plugin.

**Plugin version: 1.8.0**  
**Requires:** WordPress, Car Park Booking System (CPBS)

---

## Admin Menu

A top-level **CPBS Extensions** menu (dashicon: car) is added to the WordPress admin sidebar. All feature settings pages are registered as submenus under it:

- **Booking SMS** — Twilio credentials and check-in/check-out message templates
- **Parking QR Code** — QR code target URL and image settings
- **Booking Automation** — Reminder schedules, occupancy tracking, and message templates
- **Booking Reviews** — Review invite settings and display options

---

## Features

### 1. End Booking Early

Adds an **End Booking** button to the booking list admin screen.

- Available only for users with the `manage_options` capability (filterable via `cpbs_combined_end_booking_capability`).
- Only shown for active bookings (status: Pending, Processing, On Hold).
- Updates all exit date/time meta keys to the current site time.
- Optionally sets the booking status to Completed (status ID 4, filterable).
- Syncs status with WooCommerce if integration is active.
- Fires `sendEmailBookingChangeStatus` on the CPBS booking model when status changes.
- Implemented as an authenticated admin-only AJAX action with nonce and capability checks.

**Check-In / Check-Out SMS via Twilio:**

- Admin buttons in the booking list column to send a check-in or check-out SMS to the customer.
- Credentials and message templates are configured at **CPBS Extensions > Booking SMS**.
- Supported placeholders: `{timestamp}`, `[timestamp]`, `{booking_id}`, `[booking_id]`, `{event}`, `[event]`.
- A **Send Test SMS** button is available on the settings page to verify connectivity.

---

### 2. Step 4 Space Type Override

On the frontend booking form Step 4:

- Replaces the right-column header with the name of the selected space type.
- Hides the original location details block (non-destructive — block is hidden, not removed).
- A MutationObserver re-applies the override after any DOM changes.

---

### 3. Booking Receipt and Reservation Email Override

Replaces **Location** with the **Space Type** value in customer-facing booking summaries and outgoing reservation emails.

Coverage:
- `do_shortcode_tag` filter on `[cpbs_booking_summary]` output.
- Output-buffer fallback for summary pages that contain a valid `booking_id` + `access_token` in the URL.
- `wp_mail` filter that transforms CPBS reservation email HTML before sending.
- Also removes the "Pay for booking" call-to-action block from outgoing emails when present.

---

### 4. Booking Automation and Occupancy Tracking

A WP-Cron–driven automation pipeline. The cron runs every 60 seconds (configurable via the `cpbs_combined_booking_automation_cron_interval_seconds` filter).

**Automated message timeline (all windows configurable):**

| Event | Default window | Action |
|---|---|---|
| Before start | 30 min | Email + SMS with unique tracking link |
| Customer clicks tracking link | — | Sets occupancy to **Occupied** |
| After booking start (if link not clicked) | 15 min | Status becomes **Unoccupied**; booking is auto-ended |
| Before end | 10 min | Email + SMS reminder with booking end time |
| After end | +5 min | Email + SMS follow-up (within a configurable window) |

**Occupancy statuses:** `pending`, `late`, `occupied`, `unoccupied`.

**Tracking link:**
- A unique 32-character alphanumeric token is generated per booking.
- The customer taps a confirm button on the tracking page (CSRF-protected via nonce).
- SMS-safe URL format (`cpbstrack`, `bookingid` — no underscores) is used to avoid GSM-7 encoding issues.

**Admin visibility:**
- **Occupancy** and **Link Clicked** columns are added after the booking status column.

**Settings:** CPBS Extensions > Booking Automation  
**Placeholders:** `{customer_name}`, `{booking_id}`, `{booking_start}`, `{booking_end}`, `{tracking_link}`, `{extension_link}`, `{review_link}`, `{location_name}`, `{timestamp}` (and `[bracket]` variants).

**Runtime log:** When enabled, diagnostics are appended to `wp-content/uploads/cpbs-combined-runtime.log`.

---

### 5. Booking Extension with Stripe Checkout

Allows customers to extend an active booking by paying for additional hours via Stripe Checkout.

**Shortcode:** `[cpbs_booking_extend]`

Place this shortcode on a page. The page receives `booking_id` and `access_token` as query parameters (the automation engine builds the `{extension_link}` token automatically).

**Frontend flow:**
1. Customer views their booking details (start/end time, hourly rate).
2. Customer selects the number of extra hours; an estimated charge is shown in real time.
3. Customer is redirected to a Stripe Checkout session for the extension amount only.
4. After successful payment, all exit date/time meta keys are updated.
5. A success/cancel/failed notice is displayed on return.

**Amount calculation:** `price_rental_hour_value × hours`, tax applied from `price_rental_hour_tax_rate_value`.

**Admin visibility:**
- **Extended Hours** and **Extension Amount** columns in the booking list.
- Full extension history and cumulative totals are stored in booking meta.

---

### 6. Parking QR Code

Generates and embeds a print-ready QR code image for any target URL.

**Shortcode:** `[parking_qr_code]`

Shortcode attributes (all optional, fall back to admin settings):

| Attribute | Default | Description |
|---|---|---|
| `url` | _(configured in admin)_ | Target URL encoded in the QR code |
| `size` | `1200` | Image size in pixels (200–2000) |
| `margin` | `4` | Quiet zone margin (0–20) |
| `format` | `png` | Image format: `png` or `svg` |
| `download` | `yes` | Show a download button |
| `download_label` | `Download QR Code` | Label for the download button |

**Settings:** CPBS Extensions > Parking QR Code  
A live preview and download button are available on the settings page.

---

### 7. Service Fee Summary

Adds a configurable **Service Fee** line to the booking price summary shown on the frontend.

- A **Service Fee** meta box is added to each Space Type (cpbs_place_type) post in the admin.
- The fee amount is a fixed value stored per space type.
- The frontend JS inserts the service fee row into the CPBS price summary element, reads the active space type from the booking form state, and formats the amount to match the existing currency display.
- An outgoing email filter replaces "Tax"/"Taxes" labels with "Service Fee" in the booking confirmation email.

---

### 8. Booking Reviews

Sends post-stay review invitations to customers and collects their ratings and comments.

**Invite delivery** is triggered by the Booking Automation cron after the booking ends (configurable delay and send window). Invites are sent once per booking; retried automatically on failure.

**Shortcodes:**

- `[cpbs_booking_review_form]` — Renders a review submission form. The page receives `booking_id` and `review_token` query parameters (sent in the invite link). Token is validated server-side; one review per booking is enforced.
- `[cpbs_booking_reviews]` — Renders a responsive auto-playing carousel of published customer reviews.

**`[cpbs_booking_reviews]` attributes:**

| Attribute | Default | Description |
|---|---|---|
| `count` | `10` | Number of reviews to display (`-1` = all) |
| `location_id` | `0` | Filter by location post ID (`0` = all) |
| `min_rating` | `1` | Minimum star rating to show (1–5) |
| `show_location` | `yes` | Show location badge on each review card |
| `show_date` | `yes` | Show submission date |
| `autoplay` | `yes` | Auto-advance slides |
| `autoplay_ms` | `5000` | Milliseconds between slides |

Reviews are stored as a custom post type (`cpbs_booking_review`) and are visible in the WordPress admin.

**Placeholders (in invite templates):** `{customer_name}`, `{booking_id}`, `{booking_end}`, `{location_name}`, `{review_link}`, `{timestamp}` (and `[bracket]` variants).

**Settings:** CPBS Extensions > Booking Reviews

---

### 9. Step 1 Car Park Reorder

On the frontend booking form Step 1, moves the **Select Car Park** dropdown to a full-width row above the Entry Date/Time and Exit Date/Time sections.

- Applied via JavaScript after DOM ready, with two delayed retries to handle late-rendering themes.
- Non-destructive: uses `insertBefore` and CSS flex overrides only.

---

### 10. Booking Form Compatibility Shim

Injects a JavaScript polyfill for `window.helper.handleFormCheckBox()` if it is not already defined by the CPBS booking form script.

- Only active when the CPBS booking form script (`cpbs-booking-form`) is registered or enqueued.
- Prevents JS errors on sites where a corrupted or legacy CPBS version omits this helper.

---

### 11. AJAX Request Guard

Normalizes missing POST fields before the CPBS booking form AJAX actions (`cpbs_go_to_step`, `cpbs_create_summary_price_element`, `cpbs_coupon_code_check`, `cpbs_user_sign_in`) run.

- Populates contact, billing, and payment fields with safe empty-string defaults when absent.
- Prevents PHP notices and CPBS validation failures caused by incomplete requests from older browser form states.

---

### 12. Duplicate Booking Prevention

Intercepts the `cpbs_go_to_step` AJAX action (priority 1, before CPBS runs) to block duplicate or fake bookings.

- Runs on the step 4 → 5 transition (just before a new booking is created).
- Checks for an existing active booking with the same **email**, **license plate**, and **location** in an overlapping time slot.
- Returns a user-facing error to the booking form if a conflict is found; the original CPBS handler is never reached.

---

## Filters Reference

| Filter | Description |
|---|---|
| `cpbs_combined_feature_enabled` | Enable or disable individual features by key |
| `cpbs_combined_end_booking_capability` | Capability required to end bookings (default `manage_options`) |
| `cpbs_combined_end_booking_active_statuses` | Status IDs considered "active" for End Booking |
| `cpbs_combined_end_booking_completed_status_id` | Status ID to set after ending a booking (default `4`) |
| `cpbs_combined_end_booking_update_nonblocking_statuses` | Whether to add completed status to the nonblocking list |
| `cpbs_combined_end_booking_ajax_response` | Customize the AJAX success response payload |
| `cpbs_combined_end_booking_action_column_label` | Label for the Actions column |
| `cpbs_combined_booking_automation_cron_interval_seconds` | Cron tick frequency in seconds (min 60) |
| `cpbs_combined_extension_active_statuses` | Status IDs that allow a booking to be extended |
| `cpbs_combined_step4_override_script_config` | Override JS config for Step 4 space type override |
| `cpbs_combined_booking_receipt_script_config` | Override JS config for receipt/email override |
| `cpbs_combined_service_fee_script_config` | Override JS config for service fee summary |
| `cpbs_combined_booking_extended` | Action fired after a successful booking extension payment |
| `cpbs_combined_before_end_booking_update` | Action fired before exit meta is written |
| `cpbs_combined_after_end_booking_update` | Action fired after booking end completes |
| `cpbs_combined_booking_automation_unoccupied_ended` | Action fired when an unoccupied booking is auto-ended |

---

### 8) Booking Review

- Sends customers a post-stay review request by email and/or SMS.
- Review link included in the notification.
- Configurable send timing and message template.

---

### 9) Step 1 Car Park Reorder

- Allows admins to control the display order of car park locations on Step 1 of the booking form.

---

### 10) Adjust Booking Date & Time *(new)*

Adds an **"Adjust Booking Date & Time"** meta box to the sidebar of the booking admin edit screen, allowing admins to manually correct entry and exit date/time (e.g. to compensate a customer who missed their original slot).

Behaviour:
- Shows two `datetime-local` inputs pre-filled with the current entry and exit values.
- Saved when the admin clicks the standard WordPress **Update** button.
- Updates **all 8 date/time meta keys** the main plugin relies on so the booking stays fully consistent:
  - `cpbs_entry_date` / `cpbs_exit_date` — `d-m-Y`
  - `cpbs_entry_time` / `cpbs_exit_time` — `H:i`
  - `cpbs_entry_datetime` / `cpbs_exit_datetime` — `d-m-Y H:i`
  - `cpbs_entry_datetime_2` / `cpbs_exit_datetime_2` — `Y-m-d H:i`
- **Price is not recalculated.**
- If exit ≤ entry, the update is blocked and a red admin notice is shown.
- No restrictions by status — all bookings are editable (useful for compensating past missed slots).

Security:
- Nonce-protected, `edit_post` capability check.

---

### 11) Duplicate Booking Prevention + Mandatory License Plate *(new)*

Intercepts the booking form AJAX handler at **priority 1** (before the main plugin runs) to enforce two rules:

**a) License plate is always mandatory**
- Enforced from step 3 onwards (client details step).
- If the field is empty, a field-level tooltip error is returned and the user stays on step 3.

**b) Duplicate booking detection**
- Runs on the step 4 → 5 transition, just before the booking is created.
- Checks for an existing active booking (status Pending=1 or Processing=2) at the **same location** that **overlaps** the requested time window, where **either** the email address **or** the license plate matches.
- Overlap condition: `existing.entry < new.exit AND existing.exit > new.entry`
- If a duplicate is found, a global error is returned and the booking is not created.
- Bookings at a **different location** are always allowed.

Identity match rules:

| Email | License Plate | Result |
|-------|--------------|--------|
| Same | Same | Blocked |
| Same | Different | Blocked |
| Different | Same | Blocked |
| Different | Different | Allowed |

---

## Files

| File | Purpose |
|------|---------|
| `cpbs-combined-extensions.php` | Main plugin bootstrap and all PHP classes |
| `cpbs-combined-end-booking-early-admin.js` | Admin AJAX button behaviour |
| `cpbs-combined-step4-space-type-override.js` | Step 4 frontend override logic |
| `cpbs-combined-booking-receipt-override.js` | Summary/receipt frontend DOM override |
| `cpbs-combined-booking-extension.js` | Frontend extend-booking checkout behaviour |
| `cpbs-combined-service-fee-summary.js` | Service fee summary frontend logic |
| `cpbs-combined-step1-car-park-reorder.js` | Step 1 location reorder logic |

---

## Requirements

- WordPress
- Car Park Booking System plugin (v2.9 by QuanticaLabs)
- jQuery (bundled in WordPress)

Optional:
- WooCommerce (for booking status sync)
- Twilio account (for SMS notifications)
- Stripe account (for booking extension payments)

---

## Installation

1. Copy the `cpbs-combined-extensions` folder into `wp-content/plugins/`.
2. Activate **CPBS Combined Extensions** in WordPress admin.
3. Keep the base CPBS plugin active.

---

## Configuration

| Feature | Settings location |
|---------|------------------|
| End Booking + SMS | WordPress Admin > Settings > CPBS Booking SMS |
| Booking Automation | WordPress Admin > Settings > CPBS Booking Automation |
| Parking QR Code | WordPress Admin > Settings > Parking QR Code |
| Adjust Date/Time | No settings — available directly on each booking edit screen |
| Duplicate Prevention | No settings — always active |

---

## Extension Hooks

### Feature toggles

- `cpbs_combined_feature_enabled` — Args: `$default`, `$feature_key`
  - Keys: `end_booking_early`, `step4_space_type_override`, `booking_receipt_override`

### End Booking Early

Filters: `cpbs_combined_end_booking_capability`, `cpbs_combined_end_booking_action_column_label`, `cpbs_combined_end_booking_action_column_key`, `cpbs_combined_end_booking_active_statuses`, `cpbs_combined_end_booking_completed_status_id`, `cpbs_combined_end_booking_update_nonblocking_statuses`, `cpbs_combined_end_booking_admin_script_handle`, `cpbs_combined_end_booking_admin_script_config`, `cpbs_combined_end_booking_ajax_response`

Actions: `cpbs_combined_before_end_booking_update`, `cpbs_combined_after_end_booking_update`, `cpbs_combined_end_booking_sync_error`, `cpbs_combined_end_booking_email_error`

### Step 4 override

Filters: `cpbs_combined_step4_override_script_handle`, `cpbs_combined_step4_override_script_config`

### Receipt override

Filters: `cpbs_combined_booking_receipt_script_handle`, `cpbs_combined_booking_receipt_script_config`

---

## Security Notes

- All admin AJAX actions use nonce verification and capability checks.
- Duplicate prevention hooks run at priority 1 and call `exit` only when blocking — legitimate requests fall through to the main plugin unchanged.
- Admin date/time adjustment uses a dedicated nonce field separate from the main plugin's booking nonce.
- Summary output buffering is constrained to singular pages with a valid booking token pattern and summary shortcode presence.
- All user-supplied input is sanitised before use (`sanitize_text_field`, `sanitize_email`, `absint`, `wp_unslash`).

---

## Troubleshooting

- **Frontend cache:** Clear browser and CDN cache if old JS behaviour appears.
- **Summary changes not applying:** Ensure the page includes `[cpbs_booking_summary booking_form_id="..."]` and that `booking_id` + `access_token` query params are present.
- **Email changes not applying:** Test with a fresh reservation email; confirm it uses the CPBS reservation template (contains a Space type name row).
- **Duplicate check not triggering:** Confirm the booking being tested has status 1 or 2. Cancelled/completed bookings are not checked.
- **Date/time adjustment not saving:** Ensure both entry and exit fields are filled and exit is after entry. Check for the red admin notice for a specific error message.

---

## Changelog

### 1.7.0

- Added **Adjust Booking Date & Time** meta box (admin sidebar) — allows admins to manually update entry/exit date and time on any booking without recalculating the price.
- Added **Duplicate Booking Prevention** — intercepts the booking AJAX handler before the main plugin to block duplicate reservations (same email or license plate, same location, overlapping time) and enforces license plate as a mandatory field.

### 1.1.x

- Combined End Booking Early, Space Type Override, and Booking Receipt Override into one plugin.
- Added robust summary and email Location/Space Type transformation.
- Added hardened checks around summary output transformation scope.
