<?php
/*
Plugin Name: CPBS Combined Extensions
Description: Combines "End Booking Early", "Step 4 Space Type Override", and "Booking Receipt Override" extensions for Car Park Booking System.
Version: 1.2.0
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
    const VERSION = '1.2.0';

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
        $has_pay_for_booking_cta = preg_match('/pay(?:\s|&nbsp;|&#160;|&#xA0;)+for(?:\s|&nbsp;|&#160;|&#xA0;)+booking/iu', $message);

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

        $contains_pay_for_booking = static function ($markup) {
            $text = html_entity_decode(wp_strip_all_tags((string) $markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\xc2\xa0", ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            return (bool) preg_match('/\bpay\s*for\s*booking\b/i', (string) $text);
        };

        $container_patterns = array(
            '/<tr\b[^>]*>.*?<\/tr>/is',
            '/<p\b[^>]*>.*?<\/p>/is',
            '/<div\b[^>]*>.*?<\/div>/is',
            '/<li\b[^>]*>.*?<\/li>/is',
            '/<td\b[^>]*>.*?<\/td>/is',
            '/<a\b[^>]*>.*?<\/a>/is',
            '/<button\b[^>]*>.*?<\/button>/is',
        );

        foreach ($container_patterns as $pattern) {
            $html = (string) preg_replace_callback(
                $pattern,
                static function ($matches) use ($contains_pay_for_booking) {
                    $segment = isset($matches[0]) ? (string) $matches[0] : '';

                    return $contains_pay_for_booking($segment) ? '' : $segment;
                },
                $html
            );
        }

        $html = (string) preg_replace('/\bPay(?:\s|&nbsp;|&#160;|&#xA0;)+for(?:\s|&nbsp;|&#160;|&#xA0;)+booking\b/iu', '', $html);
        $html = (string) preg_replace('/<(p|div|span|li|td)\b[^>]*>\s*<\/\1>/is', '', $html);

        return $html;
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

final class CPBSCombinedParkingQRCode
{
    const OPTION_KEY = 'cpbs_parking_qr_settings';
    const DEFAULT_URL = 'https://spotapark.co/book-a-spot/';
    const DEFAULT_SIZE = 1200;
    const DEFAULT_MARGIN = 4;
    const DEFAULT_FORMAT = 'png';
    const NONCE_ACTION = 'cpbs_parking_qr_download';
    const DOWNLOAD_QUERY_KEY = 'cpbs_parking_qr_download';

    public function __construct()
    {
        add_action('init', array($this, 'register_shortcode'));
        add_action('init', array($this, 'maybe_handle_download'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_shortcode()
    {
        add_shortcode('parking_qr_code', array($this, 'render_shortcode'));
    }

    public function register_admin_page()
    {
        add_options_page(
            __('Parking QR Code', 'cpbs-combined-extensions'),
            __('Parking QR Code', 'cpbs-combined-extensions'),
            'manage_options',
            'cpbs-parking-qr-code',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'cpbs_parking_qr_group',
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->get_default_settings(),
            )
        );
    }

    public function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : array();

        return array(
            'url' => $this->sanitize_target_url(isset($input['url']) ? $input['url'] : ''),
            'size' => $this->sanitize_size(isset($input['size']) ? $input['size'] : self::DEFAULT_SIZE),
            'margin' => $this->sanitize_margin(isset($input['margin']) ? $input['margin'] : self::DEFAULT_MARGIN),
            'format' => $this->sanitize_format(isset($input['format']) ? $input['format'] : self::DEFAULT_FORMAT),
        );
    }

    public function render_shortcode($atts)
    {
        $settings = $this->get_settings();
        $atts = shortcode_atts(
            array(
                'url' => $settings['url'],
                'size' => $settings['size'],
                'margin' => $settings['margin'],
                'format' => $settings['format'],
                'download' => 'yes',
                'download_label' => __('Download QR Code', 'cpbs-combined-extensions'),
                'class' => '',
            ),
            $atts,
            'parking_qr_code'
        );

        $url = $this->sanitize_target_url($atts['url']);
        $size = $this->sanitize_size($atts['size']);
        $margin = $this->sanitize_margin($atts['margin']);
        $format = $this->sanitize_format($atts['format']);
        $download = in_array(strtolower((string) $atts['download']), array('1', 'true', 'yes', 'on'), true);
        $download_label = is_string($atts['download_label']) ? $atts['download_label'] : '';
        $wrapper_class = sanitize_html_class((string) $atts['class']);

        $qr_url = $this->build_qr_image_url($url, $size, $margin, $format);
        $download_url = $this->build_download_url($url, $size, $margin, $format);

        ob_start();
        ?>
        <div class="cpbs-parking-qr-code <?php echo esc_attr($wrapper_class); ?>">
            <img
                src="<?php echo esc_url($qr_url); ?>"
                alt="<?php echo esc_attr__('Parking reservation QR code', 'cpbs-combined-extensions'); ?>"
                width="<?php echo esc_attr((string) $size); ?>"
                height="<?php echo esc_attr((string) $size); ?>"
                style="max-width:100%;height:auto;display:block"
                loading="lazy"
            />
            <?php if ($download) : ?>
                <p style="margin-top:10px">
                    <a class="button" href="<?php echo esc_url($download_url); ?>"><?php echo esc_html($download_label); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $preview_qr_url = $this->build_qr_image_url($settings['url'], $settings['size'], $settings['margin'], $settings['format']);
        $download_url = $this->build_download_url($settings['url'], $settings['size'], $settings['margin'], $settings['format']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Parking QR Code', 'cpbs-combined-extensions'); ?></h1>
            <p><?php echo esc_html__('Configure a print-ready QR code for your reservation page and embed it with [parking_qr_code].', 'cpbs-combined-extensions'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('cpbs_parking_qr_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cpbs-parking-qr-url"><?php echo esc_html__('Target URL', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input
                                id="cpbs-parking-qr-url"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[url]"
                                type="url"
                                class="regular-text code"
                                value="<?php echo esc_attr($settings['url']); ?>"
                                placeholder="https://spotapark.co/book-a-spot/"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-parking-qr-size"><?php echo esc_html__('Size (px)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input
                                id="cpbs-parking-qr-size"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[size]"
                                type="number"
                                class="small-text"
                                min="200"
                                max="2000"
                                step="10"
                                value="<?php echo esc_attr((string) $settings['size']); ?>"
                            />
                            <p class="description"><?php echo esc_html__('Use 1000+ for print-quality signs.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-parking-qr-margin"><?php echo esc_html__('Margin', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input
                                id="cpbs-parking-qr-margin"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[margin]"
                                type="number"
                                class="small-text"
                                min="0"
                                max="20"
                                step="1"
                                value="<?php echo esc_attr((string) $settings['margin']); ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-parking-qr-format"><?php echo esc_html__('Format', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <select id="cpbs-parking-qr-format" name="<?php echo esc_attr(self::OPTION_KEY); ?>[format]">
                                <option value="png" <?php selected($settings['format'], 'png'); ?>>PNG</option>
                                <option value="svg" <?php selected($settings['format'], 'svg'); ?>>SVG</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('Preview', 'cpbs-combined-extensions'); ?></h2>
            <p>
                <img
                    src="<?php echo esc_url($preview_qr_url); ?>"
                    alt="<?php echo esc_attr__('Parking reservation QR code preview', 'cpbs-combined-extensions'); ?>"
                    style="max-width:320px;height:auto;border:1px solid #ccd0d4;padding:8px;background:#fff"
                />
            </p>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url($download_url); ?>"><?php echo esc_html__('Download QR Code', 'cpbs-combined-extensions'); ?></a>
            </p>
        </div>
        <?php
    }

    public function maybe_handle_download()
    {
        if (!isset($_GET[self::DOWNLOAD_QUERY_KEY])) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Invalid QR download request.', 'cpbs-combined-extensions'), 403);
        }

        $url = $this->sanitize_target_url(isset($_GET['url']) ? wp_unslash($_GET['url']) : '');
        $size = $this->sanitize_size(isset($_GET['size']) ? wp_unslash($_GET['size']) : self::DEFAULT_SIZE);
        $margin = $this->sanitize_margin(isset($_GET['margin']) ? wp_unslash($_GET['margin']) : self::DEFAULT_MARGIN);
        $format = $this->sanitize_format(isset($_GET['format']) ? wp_unslash($_GET['format']) : self::DEFAULT_FORMAT);

        $remote_url = $this->build_qr_image_url($url, $size, $margin, $format);
        $response = wp_remote_get(
            $remote_url,
            array(
                'timeout' => 20,
                'redirection' => 3,
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_die(esc_html__('Unable to generate QR code image.', 'cpbs-combined-extensions'), 500);
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            wp_die(esc_html__('QR code image is empty.', 'cpbs-combined-extensions'), 500);
        }

        $mime = $format === 'svg' ? 'image/svg+xml' : 'image/png';
        $filename = 'parking-reservation-qr-' . $size . '.' . $format;

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    private function get_default_settings()
    {
        return array(
            'url' => self::DEFAULT_URL,
            'size' => self::DEFAULT_SIZE,
            'margin' => self::DEFAULT_MARGIN,
            'format' => self::DEFAULT_FORMAT,
        );
    }

    private function get_settings()
    {
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $defaults = $this->get_default_settings();
        $merged = wp_parse_args($stored, $defaults);

        return array(
            'url' => $this->sanitize_target_url($merged['url']),
            'size' => $this->sanitize_size($merged['size']),
            'margin' => $this->sanitize_margin($merged['margin']),
            'format' => $this->sanitize_format($merged['format']),
        );
    }

    private function sanitize_target_url($value)
    {
        $value = is_string($value) ? trim($value) : '';
        $sanitized = esc_url_raw($value, array('http', 'https'));

        return $sanitized !== '' ? $sanitized : self::DEFAULT_URL;
    }

    private function sanitize_size($value)
    {
        $size = (int) $value;

        if ($size < 200) {
            return 200;
        }

        if ($size > 2000) {
            return 2000;
        }

        return $size;
    }

    private function sanitize_margin($value)
    {
        $margin = (int) $value;

        if ($margin < 0) {
            return 0;
        }

        if ($margin > 20) {
            return 20;
        }

        return $margin;
    }

    private function sanitize_format($value)
    {
        $format = strtolower((string) $value);

        return in_array($format, array('png', 'svg'), true) ? $format : self::DEFAULT_FORMAT;
    }

    private function build_qr_image_url($url, $size, $margin, $format)
    {
        $query = array(
            'data' => $url,
            'size' => $size . 'x' . $size,
            'margin' => $margin,
            'format' => $format,
        );

        return add_query_arg($query, 'https://api.qrserver.com/v1/create-qr-code/');
    }

    private function build_download_url($url, $size, $margin, $format)
    {
        return add_query_arg(
            array(
                self::DOWNLOAD_QUERY_KEY => '1',
                'url' => $url,
                'size' => $size,
                'margin' => $margin,
                'format' => $format,
                '_wpnonce' => wp_create_nonce(self::NONCE_ACTION),
            ),
            home_url('/')
        );
    }
}

final class CPBSCombinedServiceFeeSummary
{
    const VERSION = '1.0.0';
    const NONCE_ACTION = 'cpbs_combined_service_fee_save';
    const META_KEY = 'service_fee_amount';

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('add_meta_boxes_' . $this->get_place_type_post_type(), array($this, 'register_meta_box'));
        add_action('save_post', array($this, 'save_service_fee_meta'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 110);
        add_filter('wp_mail', array($this, 'replace_tax_label_in_email'), 30, 1);
    }

    public function register_meta_box()
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        add_meta_box(
            'cpbs_combined_service_fee_meta_box',
            esc_html__('Service Fee', 'car-park-booking-system'),
            array($this, 'render_meta_box'),
            $this->get_place_type_post_type(),
            'normal',
            'high'
        );

        $registered = true;
    }

    public function render_meta_box($post)
    {
        $value = $this->get_service_fee_amount((int) $post->ID);
        wp_nonce_field(self::NONCE_ACTION, 'cpbs_combined_service_fee_nonce');
        ?>
        <p>
            <label for="cpbs-combined-service-fee-amount"><strong><?php echo esc_html__('Service Fee', 'car-park-booking-system'); ?></strong></label>
        </p>
        <p>
            <input
                id="cpbs-combined-service-fee-amount"
                name="cpbs_combined_service_fee_amount"
                type="number"
                class="small-text"
                min="0"
                step="0.01"
                value="<?php echo esc_attr($this->format_decimal($value)); ?>"
            />
        </p>
        <p class="description">
            <?php echo esc_html__('Fixed fee amount applied to the selected space type and shown as a separate Service Fee line in booking totals.', 'car-park-booking-system'); ?>
        </p>
        <?php
    }

    public function save_service_fee_meta($post_id, $post)
    {
        if (!($post instanceof \WP_Post) || $post->post_type !== $this->get_place_type_post_type()) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['cpbs_combined_service_fee_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_combined_service_fee_nonce'])), self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = isset($_POST['cpbs_combined_service_fee_amount'])
            ? wp_unslash($_POST['cpbs_combined_service_fee_amount'])
            : (isset($_POST['cpbs_combined_service_fee_percentage']) ? wp_unslash($_POST['cpbs_combined_service_fee_percentage']) : '0');
        $value = $this->sanitize_amount($raw);

        update_post_meta($post_id, $this->get_meta_key(), $this->format_decimal($value));
    }

    public function enqueue_assets()
    {
        if (is_admin()) {
            return;
        }

        if (!$this->is_cpbs_available()) {
            return;
        }

        $handle = apply_filters('cpbs_combined_service_fee_script_handle', 'cpbs-combined-service-fee-summary');
        wp_enqueue_script(
            $handle,
            plugin_dir_url(__FILE__) . 'cpbs-combined-service-fee-summary.js',
            array('jquery'),
            self::VERSION,
            true
        );

        $config = array(
            'fees' => $this->get_service_fee_map(),
            'labels' => array(
                'tax' => esc_html__('Tax', 'car-park-booking-system'),
                'taxes' => esc_html__('Taxes', 'car-park-booking-system'),
                'serviceFee' => esc_html__('Service Fee', 'car-park-booking-system'),
                'parking' => esc_html__('Parking', 'car-park-booking-system'),
                'space' => esc_html__('Space', 'car-park-booking-system'),
            ),
            'selectors' => array(
                'summaryRoot' => '.cpbs-summary-price-element',
                'totalBlock' => '.cpbs-summary-price-element-total',
                'placeTypeInput' => 'input[name="cpbs_place_type_id"]',
                'selectedPlace' => '.cpbs-place-select-button.cpbs-state-selected',
                'placeCard' => '.cpbs-place',
            ),
        );

        wp_localize_script(
            $handle,
            'cpbsServiceFeeConfig',
            apply_filters('cpbs_combined_service_fee_script_config', $config)
        );
    }

    public function replace_tax_label_in_email($mail_args)
    {
        if (!is_array($mail_args) || empty($mail_args['message']) || !is_string($mail_args['message'])) {
            return $mail_args;
        }

        $message = $mail_args['message'];
        $updated = preg_replace('/>(\s*)Tax(es)?(\s*)</iu', '>$1Service Fee$3<', $message);

        if (is_string($updated) && $updated !== '') {
            $mail_args['message'] = $updated;
        }

        return $mail_args;
    }

    private function get_service_fee_map()
    {
        $map = array();
        $posts = get_posts(
            array(
                'post_type' => $this->get_place_type_post_type(),
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'suppress_filters' => true,
            )
        );

        foreach ((array) $posts as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }

            $map[$post_id] = $this->get_service_fee_amount($post_id);
        }

        return $map;
    }

    private function get_service_fee_amount($post_id)
    {
        $value = get_post_meta($post_id, $this->get_meta_key(), true);
        if ($value === '') {
            $legacy_key = (defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_' : 'cpbs_') . 'service_fee_percentage';
            $value = get_post_meta($post_id, $legacy_key, true);
        }

        return $this->sanitize_amount($value);
    }

    private function get_place_type_post_type()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_place_type';
        }

        return 'cpbs_place_type';
    }

    private function get_meta_key()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_' . self::META_KEY;
        }

        return 'cpbs_' . self::META_KEY;
    }

    private function sanitize_amount($value)
    {
        $value = is_string($value) ? str_replace(',', '.', $value) : $value;
        $number = (float) $value;

        if ($number < 0) {
            return 0.0;
        }

        if ($number > 999999.99) {
            return 999999.99;
        }

        return round($number, 2);
    }

    private function format_decimal($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function is_cpbs_available()
    {
        return defined('PLUGIN_CPBS_CONTEXT') || shortcode_exists('cpbs_booking_form');
    }
}

new CPBSCombinedEndBookingEarly();
new CPBSCombinedStep4SpaceTypeOverride();
new CPBSCombinedBookingReceiptOverride();
new CPBSCombinedParkingQRCode();
new CPBSCombinedServiceFeeSummary();
