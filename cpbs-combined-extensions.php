<?php
/*
Plugin Name: CPBS Combined Extensions
Description: Combines "End Booking Early", "Step 4 Space Type Override", and "Booking Receipt Override" extensions for Car Park Booking System.
Version: 1.1.0
Author: CodesFix
*/

if (!defined('ABSPATH')) {
    exit;
}

final class CPBSCombinedEndBookingEarly
{
    const AJAX_ACTION = 'cpbs_combined_end_booking_early';
    const NONCE_ACTION = 'cpbs_combined_end_booking_early';
    const CAPABILITY = 'manage_options';
    const DEFAULT_CPT = 'cpbs_booking';
    const DEFAULT_META_PREFIX = 'cpbs_';
    const COLUMN_KEY = 'cpbs_end_booking_action';
    const VERSION = '1.1.0';

    public function __construct()
    {
        if (!$this->is_feature_enabled('end_booking_early', true)) {
            return;
        }

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_action_column'), 20);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_action_column'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_end_booking'));
    }

    public function register_action_column($columns)
    {
        $updated = array();
        $column_key = $this->get_column_key();
        $column_label = $this->get_action_column_label();

        foreach ($columns as $key => $label) {
            $updated[$key] = $label;

            if ($key === 'status') {
                $updated[$column_key] = $column_label;
            }
        }

        if (!isset($updated[$column_key])) {
            $updated[$column_key] = $column_label;
        }

        return $updated;
    }

    public function render_action_column($column, $post_id)
    {
        if ($column !== $this->get_column_key()) {
            return;
        }

        if (!$this->current_user_can_end_bookings()) {
            return;
        }

        if (!$this->is_active_booking($post_id)) {
            return;
        }

        echo '<button type="button" class="button cpbs-end-booking-button" data-booking-id="' . esc_attr($post_id) . '">' . esc_html__('End Booking', 'car-park-booking-system') . '</button>';
    }

    public function enqueue_assets($hook_suffix)
    {
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        if (!$this->current_user_can_end_bookings()) {
            return;
        }

        $screen = get_current_screen();
        if (!is_object($screen) || $screen->id !== 'edit-' . $this->get_booking_post_type()) {
            return;
        }

        $handle = apply_filters('cpbs_combined_end_booking_admin_script_handle', 'cpbs-combined-end-booking-early-admin');

        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-combined-end-booking-early-admin.js',
            array('jquery'),
            self::VERSION,
            true
        );

        $script_config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => array(
                'confirm' => esc_html__('End this booking now? The exit time will be changed to the current site time.', 'car-park-booking-system'),
                'processing' => esc_html__('Ending...', 'car-park-booking-system'),
                'button' => esc_html__('End Booking', 'car-park-booking-system'),
                'genericError' => esc_html__('The booking could not be ended.', 'car-park-booking-system'),
            ),
        );

        wp_localize_script(
            $handle,
            'cpbsEndBookingEarly',
            apply_filters('cpbs_combined_end_booking_admin_script_config', $script_config)
        );
    }

    public function ajax_end_booking()
    {
        if (!$this->current_user_can_end_bookings()) {
            wp_send_json_error(array('message' => esc_html__('You are not allowed to end bookings.', 'car-park-booking-system')), 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $booking_id = isset($_POST['booking_id']) ? absint(wp_unslash($_POST['booking_id'])) : 0;
        if ($booking_id <= 0) {
            wp_send_json_error(array('message' => esc_html__('Invalid booking ID.', 'car-park-booking-system')), 400);
        }

        if (!$this->is_booking_post($booking_id)) {
            wp_send_json_error(array('message' => esc_html__('Booking not found.', 'car-park-booking-system')), 404);
        }

        if (!$this->is_active_booking($booking_id)) {
            wp_send_json_error(array('message' => esc_html__('Only active bookings can be ended early.', 'car-park-booking-system')), 409);
        }

        $booking_model = class_exists('CPBSBooking') ? new \CPBSBooking() : null;
        if (!($booking_model instanceof \CPBSBooking) || !method_exists($booking_model, 'getBooking')) {
            wp_send_json_error(array('message' => esc_html__('Booking model is not available.', 'car-park-booking-system')), 500);
        }

        $booking_old = $booking_model->getBooking($booking_id);
        if ($booking_old === false) {
            wp_send_json_error(array('message' => esc_html__('Booking details could not be loaded.', 'car-park-booking-system')), 500);
        }

        $booking_old_meta = $this->get_booking_meta_payload($booking_old);

        $current_time = new \DateTimeImmutable('now', wp_timezone());
        $exit_date = $current_time->format('d-m-Y');
        $exit_time = $current_time->format('H:i');
        $exit_datetime = $exit_date . ' ' . $exit_time;
        $exit_datetime_normalized = $current_time->format('Y-m-d H:i');

        $this->update_booking_meta($booking_id, 'exit_date', $exit_date);
        $this->update_booking_meta($booking_id, 'exit_time', $exit_time);
        $this->update_booking_meta($booking_id, 'exit_datetime', $exit_datetime);
        $this->update_booking_meta($booking_id, 'exit_datetime_2', $exit_datetime_normalized);

        $status_updated = false;
        $completed_status_id = (int) apply_filters('cpbs_combined_end_booking_completed_status_id', 4, $booking_id, $booking_old);
        $sync_mode = $this->get_booking_status_sync_mode();
        $has_linked_order = !empty($booking_old_meta['woocommerce_booking_id']);
        $booking_status_id = isset($booking_old_meta['booking_status_id']) ? (int) $booking_old_meta['booking_status_id'] : 0;

        do_action('cpbs_combined_before_end_booking_update', $booking_id, $booking_old, $current_time);

        if ($completed_status_id > 0 && !($sync_mode === 2 && $has_linked_order) && $booking_status_id !== $completed_status_id) {
            $this->update_booking_meta($booking_id, 'booking_status_id', $completed_status_id);
            $status_updated = true;
        }

        clean_post_cache($booking_id);

        $booking_new = $booking_model->getBooking($booking_id);
        if ($booking_new === false) {
            wp_send_json_error(array('message' => esc_html__('The booking was updated, but the refreshed booking data could not be loaded.', 'car-park-booking-system')), 500);
        }

        if ($status_updated) {
            $this->ensure_status_nonblocking($completed_status_id);
            try {
                $this->sync_booking_status($booking_id);
            } catch (\Throwable $exception) {
                do_action('cpbs_combined_end_booking_sync_error', $booking_id, $exception);
            }

            if (method_exists($booking_model, 'sendEmailBookingChangeStatus')) {
                try {
                    $booking_model->sendEmailBookingChangeStatus($booking_old, $booking_new);
                } catch (\Throwable $exception) {
                    do_action('cpbs_combined_end_booking_email_error', $booking_id, $exception);
                }
            }
        }

        do_action('cpbs_combined_after_end_booking_update', $booking_id, $booking_old, $booking_new, $status_updated);

        wp_send_json_success(
            apply_filters('cpbs_combined_end_booking_ajax_response', array(
                'bookingId' => $booking_id,
                'exitDate' => $exit_date,
                'exitTime' => $exit_time,
                'statusUpdated' => $status_updated,
                'message' => $status_updated
                    ? esc_html__('The booking was ended early and marked as completed.', 'car-park-booking-system')
                    : esc_html__('The booking was ended early.', 'car-park-booking-system'),
            ), $booking_id, $booking_old, $booking_new, $status_updated)
        );
    }

    private function current_user_can_end_bookings()
    {
        $capability = apply_filters('cpbs_combined_end_booking_capability', self::CAPABILITY);

        return current_user_can($capability);
    }

    private function get_action_column_label()
    {
        return (string) apply_filters('cpbs_combined_end_booking_action_column_label', esc_html__('Actions', 'car-park-booking-system'));
    }

    private function get_column_key()
    {
        return (string) apply_filters('cpbs_combined_end_booking_action_column_key', self::COLUMN_KEY);
    }

    private function is_booking_post($booking_id)
    {
        $post = get_post($booking_id);

        return $post instanceof \WP_Post && $post->post_type === $this->get_booking_post_type();
    }

    private function is_active_booking($booking_id)
    {
        if (!$this->is_booking_post($booking_id)) {
            return false;
        }

        $meta = $this->get_booking_meta($booking_id);
        $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
        $active_statuses = apply_filters('cpbs_combined_end_booking_active_statuses', array(1, 2, 5), $booking_id, $meta);
        $active_statuses = array_map('intval', (array) $active_statuses);
        if (!in_array($status_id, $active_statuses, true)) {
            return false;
        }

        $entry = $this->build_site_datetime(isset($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '');
        $exit = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');
        if (!$entry || !$exit) {
            return false;
        }

        $now = new \DateTimeImmutable('now', wp_timezone());

        return $now >= $entry && $now < $exit;
    }

    private function build_site_datetime($normalized_datetime)
    {
        if (!is_string($normalized_datetime) || $normalized_datetime === '' || $normalized_datetime === '0000-00-00 00:00') {
            return false;
        }

        $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized_datetime, wp_timezone());

        return $datetime ?: false;
    }

    private function get_booking_post_type()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_booking';
        }

        return self::DEFAULT_CPT;
    }

    private function get_meta_prefix()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_';
        }

        return self::DEFAULT_META_PREFIX;
    }

    private function get_booking_meta($booking_id)
    {
        if (class_exists('CPBSPostMeta')) {
            return \CPBSPostMeta::getPostMeta($booking_id);
        }

        $prepared = array();
        $raw_meta = get_post_meta($booking_id);

        foreach ((array) $raw_meta as $key => $values) {
            if (strpos($key, $this->get_meta_prefix()) !== 0) {
                continue;
            }

            $prepared[substr($key, strlen($this->get_meta_prefix()))] = maybe_unserialize(isset($values[0]) ? $values[0] : '');
        }

        return $prepared;
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        if (class_exists('CPBSPostMeta')) {
            \CPBSPostMeta::updatePostMeta($booking_id, $key, $value);
            return;
        }

        update_post_meta($booking_id, $this->get_meta_prefix() . $key, $value);
    }

    private function get_booking_status_sync_mode()
    {
        if (class_exists('CPBSOption')) {
            return (int) \CPBSOption::getOption('booking_status_synchronization');
        }

        return 1;
    }

    private function ensure_status_nonblocking($status_id)
    {
        $status_id = (int) $status_id;
        if ($status_id <= 0 || !class_exists('CPBSOption')) {
            return;
        }

        $should_update = apply_filters('cpbs_combined_end_booking_update_nonblocking_statuses', true, $status_id);
        if (!$should_update) {
            return;
        }

        $nonblocking = \CPBSOption::getOption('booking_status_nonblocking');
        if (!is_array($nonblocking)) {
            $nonblocking = array();
        }

        $normalized = array();
        foreach ($nonblocking as $value) {
            $value = (int) $value;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        if (in_array($status_id, $normalized, true)) {
            return;
        }

        $normalized[] = $status_id;
        $normalized = array_values(array_unique($normalized));

        \CPBSOption::updateOption(
            array(
                'booking_status_nonblocking' => $normalized,
            )
        );
    }

    private function sync_booking_status($booking_id)
    {
        if (!class_exists('CPBSWooCommerce')) {
            return;
        }

        $email_sent = false;
        $woo_commerce = new \CPBSWooCommerce();
        $woo_commerce->changeStatus(-1, $booking_id, $email_sent);
    }

    private function get_booking_meta_payload($booking)
    {
        if (!is_array($booking) || !isset($booking['meta']) || !is_array($booking['meta'])) {
            return array();
        }

        return $booking['meta'];
    }

    private function is_feature_enabled($feature_key, $default)
    {
        $enabled = apply_filters('cpbs_combined_feature_enabled', $default, $feature_key);

        return (bool) $enabled;
    }
}

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
        $enabled = apply_filters('cpbs_combined_feature_enabled', $default, $feature_key);

        return (bool) $enabled;
    }
}

final class CPBSCombinedBookingReceiptOverride
{
    const VERSION = '1.1.1';

    public function __construct()
    {
        if (!$this->is_feature_enabled('booking_receipt_override', true)) {
            return;
        }

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 100);
        add_filter('do_shortcode_tag', array($this, 'filter_booking_summary_shortcode_output'), 10, 4);
        add_action('template_redirect', array($this, 'maybe_start_summary_buffer'), 1);
        add_filter('wp_mail', array($this, 'filter_reservation_email_html'), 20, 1);
    }

    public function enqueue_assets()
    {
        if (is_admin()) {
            return;
        }

        if (!$this->is_cpbs_available()) {
            return;
        }

        $handle = apply_filters('cpbs_combined_booking_receipt_script_handle', 'cpbs-combined-booking-receipt-override');
        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-combined-booking-receipt-override.js',
            array('jquery'),
            self::VERSION,
            true
        );

        $config = array(
            'selectors' => array(
                'receiptContainer' => '.cpbs-booking-summary-page, .cpbs-receipt-container',
                'locationHeaderText' => 'Location',
                'spaceTypeHeaderText' => 'Space type',
                'spaceTypeLabel' => 'Space type name',
                'locationLabels' => array('Location'),
                'spaceTypeLabels' => array('Space type', 'Space type name'),
                'spaceTypeHeaderLabels' => array('Space type'),
            ),
            'hideSpaceTypeSection' => true,
            'hiddenClass' => 'cpbs-receipt-location-hidden',
        );

        wp_localize_script(
            $handle,
            'cpbsReceiptOverrideConfig',
            apply_filters('cpbs_combined_booking_receipt_script_config', $config)
        );
    }

    public function filter_booking_summary_shortcode_output($output, $tag, $attr, $m)
    {
        $summary_tag = defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_booking_summary' : 'cpbs_booking_summary';
        if ($tag !== $summary_tag && $tag !== 'cpbs_booking_summary') {
            return $output;
        }

        return $this->replace_location_with_space_type_in_summary($output);
    }

    public function maybe_start_summary_buffer()
    {
        if (is_admin() || wp_doing_ajax() || !empty($_POST)) {
            return;
        }

        $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        $access_token = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : '';

        if ($booking_id <= 0 || !preg_match('/^[A-F0-9]{32}$/', strtoupper($access_token))) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        $post = get_queried_object();
        if (!($post instanceof \WP_Post) || !has_shortcode((string) $post->post_content, 'cpbs_booking_summary')) {
            return;
        }

        ob_start(array($this, 'filter_summary_page_output'));
    }

    public function filter_summary_page_output($html)
    {
        return $this->replace_location_with_space_type_in_summary($html);
    }

    public function filter_reservation_email_html($mail_args)
    {
        if (!is_array($mail_args) || empty($mail_args['message']) || !is_string($mail_args['message'])) {
            return $mail_args;
        }

        $message = $mail_args['message'];
        $has_space_type_row = preg_match('/<td[^>]*>\s*Space\s*type\s*name\s*<\/td>\s*<td[^>]*>/is', $message);
        $has_pay_for_booking_cta = preg_match('/>\s*Pay\s*for\s*booking\s*</is', $message);

        if (!$has_space_type_row && !$has_pay_for_booking_cta) {
            return $mail_args;
        }

        if ($has_space_type_row) {
            $message = $this->replace_location_with_space_type_in_summary($message);
        }

        if ($has_pay_for_booking_cta) {
            $message = $this->remove_pay_for_booking_cta($message);
        }

        $mail_args['message'] = $message;

        return $mail_args;
    }

    private function remove_pay_for_booking_cta($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        $patterns = array(
            '/<tr\b[^>]*>\s*<td\b[^>]*>.*?<a\b[^>]*>\s*Pay\s*for\s*booking\s*<\/a>.*?<\/td>\s*<\/tr>/is',
            '/<p\b[^>]*>\s*<a\b[^>]*>\s*Pay\s*for\s*booking\s*<\/a>\s*<\/p>/is',
            '/<a\b[^>]*>\s*Pay\s*for\s*booking\s*<\/a>/is',
        );

        return (string) preg_replace($patterns, '', $html);
    }

    private function replace_location_with_space_type_in_summary($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        $space_type_value = '';
        if (preg_match('/(<tr>\s*<td[^>]*>\s*Space\s*type\s*name\s*<\/td>\s*<td[^>]*>)(.*?)(<\/td>\s*<\/tr>)/is', $html, $match)) {
            $space_type_value = $match[2];
        }

        if ($space_type_value !== '') {
            $html = preg_replace_callback(
                '/(<tr>\s*<td[^>]*>\s*Location\s*<\/td>\s*<td[^>]*>)(.*?)(<\/td>\s*<\/tr>)/is',
                function ($matches) use ($space_type_value) {
                    return $matches[1] . $space_type_value . $matches[3];
                },
                $html,
                1
            );
        }

        $html = preg_replace(
            '/<tr>\s*<td[^>]*>\s*Space\s*type\s*<\/td>\s*<\/tr>\s*<tr><td[^>]*><\/td><\/tr>\s*<tr>\s*<td>\s*<table[^>]*>.*?<\/table>\s*<\/td>\s*<\/tr>/is',
            '',
            $html
        );

        return $html;
    }

    private function is_cpbs_available()
    {
        return defined('PLUGIN_CPBS_CONTEXT') || shortcode_exists('cpbs_booking_form');
    }

    private function is_feature_enabled($feature_key, $default)
    {
        $enabled = apply_filters('cpbs_combined_feature_enabled', $default, $feature_key);

        return (bool) $enabled;
    }
}

new CPBSCombinedEndBookingEarly();
new CPBSCombinedStep4SpaceTypeOverride();
new CPBSCombinedBookingReceiptOverride();
