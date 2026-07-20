<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Admin Menu
// ============================================================================
final class CPBSCombinedAdminMenu
{
    const MENU_SLUG = 'cpbs-extensions';
    const CAPABILITY = 'manage_options';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'), 5);
    }

    public function register_menu()
    {
        add_menu_page(
            __('CPBS Extensions', 'cpbs-combined-extensions'),
            __('CPBS Extensions', 'cpbs-combined-extensions'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_overview'),
            'dashicons-car',
            58
        );
    }

    public function render_overview()
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('CPBS Extensions', 'cpbs-combined-extensions'); ?></h1>
            <p><?php echo esc_html__('Select a section from the submenu to configure it.', 'cpbs-combined-extensions'); ?></p>
            <ul style="list-style:disc;margin-left:20px;line-height:2">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cpbs-combined-booking-sms')); ?>"><?php echo esc_html__('Booking SMS', 'cpbs-combined-extensions'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cpbs-parking-qr-code')); ?>"><?php echo esc_html__('Parking QR Code', 'cpbs-combined-extensions'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cpbs-combined-booking-automation')); ?>"><?php echo esc_html__('Booking Automation', 'cpbs-combined-extensions'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=cpbs-combined-booking-review')); ?>"><?php echo esc_html__('Booking Reviews', 'cpbs-combined-extensions'); ?></a></li>
            </ul>
        </div>
        <?php
    }
}

/**
 * Shared utility methods used across multiple CPBS Combined feature classes.
 * Eliminates ~800 lines of duplicated code.
 */

// ============================================================================
// Helpers
// ============================================================================
final class CPBSCombinedHelpers
{
    /**
     * Get booking post type with context prefix support.
     *
     * @return string
     */
    public static function get_booking_post_type()
    {
        return defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_booking' : 'cpbs_booking';
    }

    /**
     * Get booking meta prefix with context support.
     *
     * @return string
     */
    public static function get_meta_prefix()
    {
        return defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_' : 'cpbs_';
    }

    /**
     * Check if post is a booking post type.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_booking_post($post_id)
    {
        return get_post_type($post_id) === self::get_booking_post_type();
    }

    /**
     * Get all booking meta for a post.
     *
     * @param int $booking_id
     * @return array
     */
    public static function get_booking_meta($booking_id)
    {
        $prepared = array();
        $raw_meta = get_post_meta($booking_id);

        foreach ((array) $raw_meta as $key => $values) {
            if (strpos($key, self::get_meta_prefix()) !== 0) {
                continue;
            }

            $prepared[substr($key, strlen(self::get_meta_prefix()))] = maybe_unserialize(isset($values[0]) ? $values[0] : '');
        }

        return $prepared;
    }

    /**
     * Get a single booking meta value.
     *
     * @param int    $booking_id
     * @param string $key
     * @return mixed
     */
    public static function get_booking_meta_value($booking_id, $key)
    {
        $meta = self::get_booking_meta($booking_id);
        return isset($meta[$key]) ? $meta[$key] : '';
    }

    /**
     * Update a booking meta value.
     *
     * @param int    $booking_id
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function update_booking_meta($booking_id, $key, $value)
    {
        $key = self::normalize_booking_meta_key($key);
        update_post_meta($booking_id, self::get_meta_prefix() . $key, $value);
    }

    /**
     * Normalize a booking meta key so CPBS storage only sees the unprefixed name.
     *
     * @param string $key
     * @return string
     */
    public static function normalize_booking_meta_key($key)
    {
        $key = (string) $key;
        $prefix = self::get_meta_prefix();

        if ($prefix !== '' && strpos($key, $prefix) === 0) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    /**
     * Normalize phone number to E.164 format.
     *
     * @param mixed $raw_phone
     * @return string Empty string if invalid
     */
    public static function normalize_phone_number($raw_phone)
    {
        if (!is_string($raw_phone)) {
            return '';
        }

        $raw_phone = trim($raw_phone);
        if ($raw_phone === '') {
            return '';
        }

        $normalized = preg_replace('/[^0-9\+]/', '', $raw_phone);
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        if (strpos($normalized, '00') === 0) {
            $normalized = '+' . substr($normalized, 2);
        }

        if ($normalized[0] !== '+') {
            $normalized = '+' . $normalized;
        }

        return preg_match('/^\+[1-9][0-9]{6,14}$/', $normalized) ? $normalized : '';
    }

    /**
     * Check if a string looks like a phone number.
     *
     * @param mixed $value
     * @return bool
     */
    public static function looks_like_phone_number($value)
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/(\+|00)?[0-9][0-9\s\-\(\)]{6,20}/', $value);
    }

    /**
     * Recursively extract phone number from mixed (string/array) value.
     *
     * @param mixed $value
     * @return string
     */
    public static function extract_phone_from_mixed($value)
    {
        if (is_string($value)) {
            return self::looks_like_phone_number($value) ? $value : '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $child_value) {
            $phone = self::extract_phone_from_mixed($child_value);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    /**
     * Recursively extract email from mixed (string/array) value.
     *
     * @param mixed $value
     * @return string
     */
    public static function extract_email_from_mixed($value)
    {
        if (is_string($value)) {
            $candidate = sanitize_email($value);
            return ($candidate !== '' && is_email($candidate)) ? $candidate : '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $child_value) {
            $email = self::extract_email_from_mixed($child_value);
            if ($email !== '') {
                return $email;
            }
        }

        return '';
    }

    /**
     * Build DateTimeImmutable from normalized datetime string with fallback formats.
     * Compatible with PHP 8.2+ which returns false on trailing characters.
     *
     * @param string $normalized_datetime
     * @return \DateTimeImmutable|false
     */
    public static function build_site_datetime($normalized_datetime)
    {
        if (!is_string($normalized_datetime) || $normalized_datetime === '' || $normalized_datetime === '0000-00-00 00:00') {
            return false;
        }

        // Try Y-m-d H:i:s first, then Y-m-d H:i — PHP 8.2+ returns false on trailing chars
        $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized_datetime, wp_timezone());
        if (!$datetime) {
            $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized_datetime, wp_timezone());
        }

        return $datetime ?: false;
    }

    /**
     * Get current site time as DateTimeImmutable.
     *
     * @return \DateTimeImmutable
     */
    public static function site_now()
    {
        return new \DateTimeImmutable('now', wp_timezone());
    }

    /**
     * Get SMS settings from WordPress option.
     *
     * @return array
     */
    public static function get_sms_settings()
    {
        $stored = get_option('cpbs_combined_booking_sms_settings', array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $defaults = array(
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'twilio_from_number' => '',
        );

        return wp_parse_args($stored, $defaults);
    }

    /**
     * Send SMS via Twilio.
     * Standardized to return bool (false on any error).
     *
     * @param string $to_phone
     * @param string $message_body
     * @return bool
     */
    public static function send_twilio_sms($to_phone, $message_body)
    {
        $sms_settings = self::get_sms_settings();

        $account_sid = isset($sms_settings['twilio_account_sid']) ? trim((string) $sms_settings['twilio_account_sid']) : '';
        $auth_token = isset($sms_settings['twilio_auth_token']) ? trim((string) $sms_settings['twilio_auth_token']) : '';
        $from_phone = self::normalize_phone_number(isset($sms_settings['twilio_from_number']) ? $sms_settings['twilio_from_number'] : '');

        if ($account_sid === '' || $auth_token === '' || $from_phone === '' || trim((string) $message_body) === '') {
            return false;
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($account_sid) . '/Messages.json';
        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token),
                ),
                'body' => array(
                    'To' => $to_phone,
                    'From' => $from_phone,
                    'Body' => $message_body,
                ),
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        return $status === 200 || $status === 201;
    }

    /**
     * Check if a feature is enabled via filter.
     *
     * @param string $feature_key
     * @param bool   $default
     * @return bool
     */
    public static function is_feature_enabled($feature_key, $default = true)
    {
        $enabled = apply_filters('cpbs_combined_feature_enabled', $default, $feature_key);
        return (bool) $enabled;
    }

    /**
     * Check whether runtime logging is enabled in the booking automation settings.
     *
     * @return bool
     */
    public static function is_runtime_logging_enabled()
    {
        $settings = get_option('cpbs_combined_booking_automation_settings', array());
        if (!is_array($settings)) {
            return false;
        }

        return !empty($settings['enable_runtime_log']);
    }
}
