<?php
/*
Plugin Name: CPBS Combined Extensions
Description: Combines "End Booking Early", "Step 4 Space Type Override", and "Booking Receipt Override" extensions for Car Park Booking System.
Version: 1.4.0
Author: CodesFix
*/

if (!defined('ABSPATH')) {
    exit;
}

final class CPBSCombinedEndBookingEarly
{
    const AJAX_ACTION = 'cpbs_combined_end_booking_early';
    const AJAX_ACTION_CHECK_IN = 'cpbs_combined_booking_check_in_sms';
    const AJAX_ACTION_CHECK_OUT = 'cpbs_combined_booking_check_out_sms';
    const NONCE_ACTION = 'cpbs_combined_end_booking_early';
    const CAPABILITY = 'manage_options';
    const DEFAULT_CPT = 'cpbs_booking';
    const DEFAULT_META_PREFIX = 'cpbs_';
    const COLUMN_KEY = 'cpbs_end_booking_action';
    const VERSION = '1.4.0';
    const SMS_SETTINGS_OPTION_KEY = 'cpbs_combined_booking_sms_settings';
    const SMS_SETTINGS_GROUP = 'cpbs_combined_booking_sms_group';
    const SMS_TEST_ADMIN_ACTION = 'cpbs_combined_send_test_sms';
    const SMS_TEST_NONCE_ACTION = 'cpbs_combined_send_test_sms_nonce';

    public function __construct()
    {
        if (!$this->is_feature_enabled('end_booking_early', true)) {
            return;
        }

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_action_column'), 20);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_action_column'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_end_booking'));
        add_action('wp_ajax_' . self::AJAX_ACTION_CHECK_IN, array($this, 'ajax_send_check_in'));
        add_action('wp_ajax_' . self::AJAX_ACTION_CHECK_OUT, array($this, 'ajax_send_check_out'));
        add_action('admin_menu', array($this, 'register_sms_admin_page'));
        add_action('admin_init', array($this, 'register_sms_settings'));
        add_action('admin_post_' . self::SMS_TEST_ADMIN_ACTION, array($this, 'handle_test_sms_admin_action'));
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

    public function ajax_send_check_in()
    {
        $this->ajax_send_booking_sms('check_in');
    }

    public function ajax_send_check_out()
    {
        $this->ajax_send_booking_sms('check_out');
    }

    public function register_sms_admin_page()
    {
        add_options_page(
            __('CPBS Booking SMS', 'cpbs-combined-extensions'),
            __('CPBS Booking SMS', 'cpbs-combined-extensions'),
            self::CAPABILITY,
            'cpbs-combined-booking-sms',
            array($this, 'render_sms_admin_page')
        );
    }

    public function register_sms_settings()
    {
        register_setting(
            self::SMS_SETTINGS_GROUP,
            self::SMS_SETTINGS_OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_sms_settings'),
                'default' => $this->get_default_sms_settings(),
            )
        );
    }

    public function sanitize_sms_settings($input)
    {
        $input = is_array($input) ? $input : array();

        return array(
            'twilio_account_sid' => sanitize_text_field(isset($input['twilio_account_sid']) ? wp_unslash($input['twilio_account_sid']) : ''),
            'twilio_auth_token' => sanitize_text_field(isset($input['twilio_auth_token']) ? wp_unslash($input['twilio_auth_token']) : ''),
            'twilio_from_number' => sanitize_text_field(isset($input['twilio_from_number']) ? wp_unslash($input['twilio_from_number']) : ''),
            'check_in_template' => sanitize_textarea_field(isset($input['check_in_template']) ? wp_unslash($input['check_in_template']) : ''),
            'check_out_template' => sanitize_textarea_field(isset($input['check_out_template']) ? wp_unslash($input['check_out_template']) : ''),
            'test_recipient_number' => sanitize_text_field(isset($input['test_recipient_number']) ? wp_unslash($input['test_recipient_number']) : ''),
            'test_sms_template' => sanitize_textarea_field(isset($input['test_sms_template']) ? wp_unslash($input['test_sms_template']) : ''),
        );
    }

    public function render_sms_admin_page()
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $settings = $this->get_sms_settings();
        $notice_status = isset($_GET['cpbs_sms_test']) ? sanitize_key(wp_unslash($_GET['cpbs_sms_test'])) : '';
        $notice_message = isset($_GET['cpbs_sms_test_message']) ? sanitize_text_field(wp_unslash($_GET['cpbs_sms_test_message'])) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('CPBS Check-In/Check-Out SMS', 'cpbs-combined-extensions'); ?></h1>
            <p><?php echo esc_html__('Configure Twilio credentials and editable message templates. You can use {timestamp} or [timestamp], plus {booking_id} and {event} placeholders.', 'cpbs-combined-extensions'); ?></p>
            <?php if ($notice_status === 'success' && $notice_message !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice_message); ?></p></div>
            <?php elseif ($notice_status === 'error' && $notice_message !== '') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($notice_message); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::SMS_SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cpbs-twilio-account-sid"><?php echo esc_html__('Twilio Account SID', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-twilio-account-sid" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[twilio_account_sid]" type="text" class="regular-text code" value="<?php echo esc_attr($settings['twilio_account_sid']); ?>" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-twilio-auth-token"><?php echo esc_html__('Twilio Auth Token', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-twilio-auth-token" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[twilio_auth_token]" type="password" class="regular-text code" value="<?php echo esc_attr($settings['twilio_auth_token']); ?>" autocomplete="new-password" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-twilio-from-number"><?php echo esc_html__('Twilio From Number', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-twilio-from-number" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[twilio_from_number]" type="text" class="regular-text" value="<?php echo esc_attr($settings['twilio_from_number']); ?>" placeholder="+15551234567" />
                            <p class="description"><?php echo esc_html__('Use E.164 format, e.g. +15551234567.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-check-in-template"><?php echo esc_html__('Check-In Message Template', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-check-in-template" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[check_in_template]" class="large-text" rows="4"><?php echo esc_textarea($settings['check_in_template']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-check-out-template"><?php echo esc_html__('Check-Out Message Template', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-check-out-template" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[check_out_template]" class="large-text" rows="4"><?php echo esc_textarea($settings['check_out_template']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-test-recipient-number"><?php echo esc_html__('Test SMS Recipient', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-test-recipient-number" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[test_recipient_number]" type="text" class="regular-text" value="<?php echo esc_attr($settings['test_recipient_number']); ?>" placeholder="+15551234567" />
                            <p class="description"><?php echo esc_html__('Used by the Send Test SMS button below. Use E.164 format.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-test-sms-template"><?php echo esc_html__('Test SMS Template', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-test-sms-template" name="<?php echo esc_attr(self::SMS_SETTINGS_OPTION_KEY); ?>[test_sms_template]" class="large-text" rows="3"><?php echo esc_textarea($settings['test_sms_template']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Placeholders: {timestamp}, [timestamp], {site_name}, [site_name].', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('Twilio Connectivity Test', 'cpbs-combined-extensions'); ?></h2>
            <p><?php echo esc_html__('Click once to send a test SMS to the configured Test SMS Recipient.', 'cpbs-combined-extensions'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::SMS_TEST_NONCE_ACTION, 'cpbs_sms_test_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SMS_TEST_ADMIN_ACTION); ?>" />
                <?php submit_button(esc_html__('Send Test SMS', 'cpbs-combined-extensions'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public function handle_test_sms_admin_action()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'cpbs-combined-extensions'), 403);
        }

        check_admin_referer(self::SMS_TEST_NONCE_ACTION, 'cpbs_sms_test_nonce');

        $settings = $this->get_sms_settings();
        $recipient = $this->normalize_phone_number($settings['test_recipient_number']);

        if ($recipient === '') {
            $this->redirect_sms_settings_notice('error', __('Test recipient number is missing or invalid. Use E.164 format, e.g. +15551234567.', 'cpbs-combined-extensions'));
        }

        $timestamp = (new \DateTimeImmutable('now', wp_timezone()))->format('Y-m-d H:i:s');
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $template = trim((string) $settings['test_sms_template']);
        $message = str_replace(
            array('{timestamp}', '[timestamp]', '{site_name}', '[site_name]'),
            array($timestamp, $timestamp, $site_name, $site_name),
            $template
        );
        $message = trim((string) $message);

        if ($message === '') {
            $this->redirect_sms_settings_notice('error', __('Test SMS template is empty. Please update it in settings.', 'cpbs-combined-extensions'));
        }

        $result = $this->send_twilio_sms($recipient, $message);

        if (is_wp_error($result)) {
            $this->redirect_sms_settings_notice('error', $result->get_error_message());
        }

        $this->redirect_sms_settings_notice('success', __('Test SMS sent successfully.', 'cpbs-combined-extensions'));
    }

    private function redirect_sms_settings_notice($status, $message)
    {
        $url = add_query_arg(
            array(
                'page' => 'cpbs-combined-booking-sms',
                'cpbs_sms_test' => $status,
                'cpbs_sms_test_message' => sanitize_text_field((string) $message),
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($url);
        exit;
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

    private function ajax_send_booking_sms($event)
    {
        if (!$this->current_user_can_end_bookings()) {
            wp_send_json_error(array('message' => esc_html__('You are not allowed to send booking SMS messages.', 'car-park-booking-system')), 403);
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
            wp_send_json_error(array('message' => esc_html__('Only active bookings support Check-In/Check-Out SMS.', 'car-park-booking-system')), 409);
        }

        $booking_model = class_exists('CPBSBooking') ? new \CPBSBooking() : null;
        if (!($booking_model instanceof \CPBSBooking) || !method_exists($booking_model, 'getBooking')) {
            wp_send_json_error(array('message' => esc_html__('Booking model is not available.', 'car-park-booking-system')), 500);
        }

        $booking = $booking_model->getBooking($booking_id);
        if ($booking === false) {
            wp_send_json_error(array('message' => esc_html__('Booking details could not be loaded.', 'car-park-booking-system')), 500);
        }

        $booking_meta = $this->get_booking_meta_payload($booking);
        $phone_number = $this->get_customer_phone_number($booking_id, $booking_meta);
        if ($phone_number === '') {
            wp_send_json_error(array('message' => esc_html__('Customer phone number was not found for this booking.', 'car-park-booking-system')), 422);
        }

        $current_time = new \DateTimeImmutable('now', wp_timezone());
        $timestamp = $current_time->format('Y-m-d H:i:s');
        $message_body = $this->build_sms_message_body($event, $timestamp, $booking_id);

        $result = $this->send_twilio_sms($phone_number, $message_body);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        $success_message = $event === 'check_in'
            ? esc_html__('Check-In SMS was sent successfully.', 'car-park-booking-system')
            : esc_html__('Check-Out SMS was sent successfully.', 'car-park-booking-system');

        wp_send_json_success(
            array(
                'bookingId' => $booking_id,
                'timestamp' => $timestamp,
                'message' => $success_message,
            )
        );
    }

    private function get_customer_phone_number($booking_id, $booking_meta)
    {
        $phone_sources = array(
            isset($booking_meta['client_contact_detail_phone_number']) ? $booking_meta['client_contact_detail_phone_number'] : '',
            isset($booking_meta['phone_number']) ? $booking_meta['phone_number'] : '',
            isset($booking_meta['phone']) ? $booking_meta['phone'] : '',
            isset($booking_meta['billing_phone']) ? $booking_meta['billing_phone'] : '',
            isset($booking_meta['customer_phone']) ? $booking_meta['customer_phone'] : '',
            isset($booking_meta['contact_phone']) ? $booking_meta['contact_phone'] : '',
            isset($booking_meta['form_element_field']) ? $this->extract_phone_from_mixed($booking_meta['form_element_field']) : '',
        );

        $post_meta_phone_sources = array(
            get_post_meta($booking_id, 'cpbs_client_contact_detail_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone', true),
            get_post_meta($booking_id, 'cpbs_billing_phone', true),
            get_post_meta($booking_id, 'cpbs_customer_phone', true),
        );

        $phone_sources = array_merge($phone_sources, $post_meta_phone_sources);

        foreach ($phone_sources as $raw_phone) {
            $normalized = $this->normalize_phone_number($raw_phone);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $fallback = $this->extract_phone_from_mixed($booking_meta);

        return $this->normalize_phone_number($fallback);
    }

    private function extract_phone_from_mixed($value)
    {
        if (is_string($value)) {
            return $this->looks_like_phone_number($value) ? $value : '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $child_value) {
            $phone = $this->extract_phone_from_mixed($child_value);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    private function looks_like_phone_number($value)
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/(\+|00)?[0-9][0-9\s\-\(\)]{6,20}/', $value);
    }

    private function normalize_phone_number($raw_phone)
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

    private function build_sms_message_body($event, $timestamp, $booking_id)
    {
        $settings = $this->get_sms_settings();
        $template = $event === 'check_in' ? $settings['check_in_template'] : $settings['check_out_template'];
        $event_text = $event === 'check_in' ? 'check-in' : 'check-out';

        $message = str_replace(
            array('{timestamp}', '[timestamp]', '{booking_id}', '[booking_id]', '{event}', '[event]'),
            array($timestamp, $timestamp, (string) $booking_id, (string) $booking_id, $event_text, $event_text),
            $template
        );

        return trim((string) $message);
    }

    private function send_twilio_sms($to_phone, $message_body)
    {
        $settings = $this->get_sms_settings();

        $account_sid = trim((string) $settings['twilio_account_sid']);
        $auth_token = trim((string) $settings['twilio_auth_token']);
        $from_phone = $this->normalize_phone_number($settings['twilio_from_number']);

        if ($account_sid === '' || $auth_token === '' || $from_phone === '') {
            return new \WP_Error('cpbs_twilio_settings_missing', esc_html__('Twilio settings are incomplete. Please configure Account SID, Auth Token, and From Number.', 'cpbs-combined-extensions'));
        }

        if ($message_body === '') {
            return new \WP_Error('cpbs_twilio_message_missing', esc_html__('SMS message template is empty. Update the message template in settings.', 'cpbs-combined-extensions'));
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
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200 && $status !== 201) {
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode(is_string($body) ? $body : '', true);
            $api_message = is_array($decoded) && !empty($decoded['message']) ? $decoded['message'] : '';
            $error_message = $api_message !== ''
                ? sprintf(esc_html__('Twilio error: %s', 'cpbs-combined-extensions'), sanitize_text_field($api_message))
                : esc_html__('Twilio request failed. Please verify credentials and phone numbers.', 'cpbs-combined-extensions');

            return new \WP_Error('cpbs_twilio_request_failed', $error_message);
        }

        return true;
    }

    private function get_default_sms_settings()
    {
        return array(
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'twilio_from_number' => '',
            'check_in_template' => 'You check-IN at [timestamp].',
            'check_out_template' => 'You check-out at [timestamp].',
            'test_recipient_number' => '',
            'test_sms_template' => 'Test SMS from {site_name} at {timestamp}.',
        );
    }

    private function get_sms_settings()
    {
        $stored = get_option(self::SMS_SETTINGS_OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $defaults = $this->get_default_sms_settings();
        $merged = wp_parse_args($stored, $defaults);

        return array(
            'twilio_account_sid' => sanitize_text_field((string) $merged['twilio_account_sid']),
            'twilio_auth_token' => sanitize_text_field((string) $merged['twilio_auth_token']),
            'twilio_from_number' => sanitize_text_field((string) $merged['twilio_from_number']),
            'check_in_template' => sanitize_textarea_field((string) $merged['check_in_template']),
            'check_out_template' => sanitize_textarea_field((string) $merged['check_out_template']),
            'test_recipient_number' => sanitize_text_field((string) $merged['test_recipient_number']),
            'test_sms_template' => sanitize_textarea_field((string) $merged['test_sms_template']),
        );
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

final class CPBSCombinedBookingAutomation
{
    const OPTION_KEY = 'cpbs_combined_booking_automation_settings';
    const SETTINGS_GROUP = 'cpbs_combined_booking_automation_group';
    const SETTINGS_PAGE_SLUG = 'cpbs-combined-booking-automation';
    const CRON_HOOK = 'cpbs_combined_booking_automation_cron';
    const CRON_INTERVAL = 'cpbs_every_five_minutes';
    const TRACK_QUERY_KEY = 'cpbs_booking_track';
    const TRACK_COLUMN_KEY = 'cpbs_booking_tracking_status';
    const CLICKED_COLUMN_KEY = 'cpbs_booking_tracking_clicked';
    const SMS_SETTINGS_OPTION_KEY = 'cpbs_combined_booking_sms_settings';
    const LOG_FILE_NAME = 'cpbs-combined-runtime.log';

    public function __construct()
    {
        add_filter('cron_schedules', array($this, 'register_cron_interval'));
        add_action('init', array($this, 'schedule_cron'));
        add_action('init', array($this, 'maybe_handle_tracking_link'));
        add_action(self::CRON_HOOK, array($this, 'process_booking_automation'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_tracking_columns'), 30);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_tracking_columns'), 10, 2);
    }

    public function register_cron_interval($schedules)
    {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 300,
                'display' => __('Every 5 Minutes (CPBS Booking Automation)', 'cpbs-combined-extensions'),
            );
        }

        return $schedules;
    }

    public function schedule_cron()
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
    }

    public function register_admin_page()
    {
        add_options_page(
            __('CPBS Booking Automation', 'cpbs-combined-extensions'),
            __('CPBS Booking Automation', 'cpbs-combined-extensions'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            self::SETTINGS_GROUP,
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
            'enable_email' => $this->sanitize_checkbox(isset($input['enable_email']) ? $input['enable_email'] : 0),
            'enable_sms' => $this->sanitize_checkbox(isset($input['enable_sms']) ? $input['enable_sms'] : 0),
            'enable_runtime_log' => $this->sanitize_checkbox(isset($input['enable_runtime_log']) ? $input['enable_runtime_log'] : 0),
            'start_notice_minutes_before' => $this->sanitize_minutes(isset($input['start_notice_minutes_before']) ? $input['start_notice_minutes_before'] : 30, 1, 720, 30),
            'end_notice_minutes_before' => $this->sanitize_minutes(isset($input['end_notice_minutes_before']) ? $input['end_notice_minutes_before'] : 10, 1, 720, 10),
            'after_end_followup_minutes' => $this->sanitize_minutes(isset($input['after_end_followup_minutes']) ? $input['after_end_followup_minutes'] : 5, 1, 720, 5),
            'followup_send_window_minutes' => $this->sanitize_minutes(isset($input['followup_send_window_minutes']) ? $input['followup_send_window_minutes'] : 120, 1, 1440, 120),
            'late_to_unoccupied_minutes' => $this->sanitize_minutes(isset($input['late_to_unoccupied_minutes']) ? $input['late_to_unoccupied_minutes'] : 15, 1, 720, 15),
            'start_email_subject' => sanitize_text_field(isset($input['start_email_subject']) ? wp_unslash($input['start_email_subject']) : ''),
            'start_email_body' => sanitize_textarea_field(isset($input['start_email_body']) ? wp_unslash($input['start_email_body']) : ''),
            'start_sms_body' => sanitize_textarea_field(isset($input['start_sms_body']) ? wp_unslash($input['start_sms_body']) : ''),
            'end_email_subject' => sanitize_text_field(isset($input['end_email_subject']) ? wp_unslash($input['end_email_subject']) : ''),
            'end_email_body' => sanitize_textarea_field(isset($input['end_email_body']) ? wp_unslash($input['end_email_body']) : ''),
            'end_sms_body' => sanitize_textarea_field(isset($input['end_sms_body']) ? wp_unslash($input['end_sms_body']) : ''),
            'follow_email_subject' => sanitize_text_field(isset($input['follow_email_subject']) ? wp_unslash($input['follow_email_subject']) : ''),
            'follow_email_body' => sanitize_textarea_field(isset($input['follow_email_body']) ? wp_unslash($input['follow_email_body']) : ''),
            'follow_sms_body' => sanitize_textarea_field(isset($input['follow_sms_body']) ? wp_unslash($input['follow_sms_body']) : ''),
            'track_page_message' => sanitize_textarea_field(isset($input['track_page_message']) ? wp_unslash($input['track_page_message']) : ''),
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('CPBS Booking Automation', 'cpbs-combined-extensions'); ?></h1>
            <p><?php echo esc_html__('Automate booking reminders and occupancy tracking link flow. Placeholders: {customer_name}, {booking_id}, {booking_start}, {booking_end}, {tracking_link}, {timestamp}.', 'cpbs-combined-extensions'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Email Notifications', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_email]" value="1" <?php checked((int) $settings['enable_email'], 1); ?> />
                                <?php echo esc_html__('Send automation emails', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable SMS Notifications', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_sms]" value="1" <?php checked((int) $settings['enable_sms'], 1); ?> />
                                <?php echo esc_html__('Send automation SMS via Twilio settings', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Runtime Log', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_runtime_log]" value="1" <?php checked((int) $settings['enable_runtime_log'], 1); ?> />
                                <?php echo esc_html__('Write diagnostics to wp-content/uploads/cpbs-combined-runtime.log', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-start-before"><?php echo esc_html__('Before Start Reminder (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-start-before" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[start_notice_minutes_before]" value="<?php echo esc_attr((string) $settings['start_notice_minutes_before']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-end-before"><?php echo esc_html__('Before End Reminder (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-end-before" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[end_notice_minutes_before]" value="<?php echo esc_attr((string) $settings['end_notice_minutes_before']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-after-end"><?php echo esc_html__('After End Follow-Up (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-after-end" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[after_end_followup_minutes]" value="<?php echo esc_attr((string) $settings['after_end_followup_minutes']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-follow-window"><?php echo esc_html__('Follow-Up Send Window (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-follow-window" type="number" class="small-text" min="1" max="1440" name="<?php echo esc_attr(self::OPTION_KEY); ?>[followup_send_window_minutes]" value="<?php echo esc_attr((string) $settings['followup_send_window_minutes']); ?>" />
                            <p class="description"><?php echo esc_html__('Follow-up is sent only during this window after the follow-up time. Older bookings are marked as processed to prevent bulk backlog emails.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-late-unoccupied"><?php echo esc_html__('Late to Unoccupied (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-late-unoccupied" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[late_to_unoccupied_minutes]" value="<?php echo esc_attr((string) $settings['late_to_unoccupied_minutes']); ?>" />
                            <p class="description"><?php echo esc_html__('If tracking link is still not clicked after this many minutes from booking start, status becomes Unoccupied.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('Before Start Message', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-start-email-subject"><?php echo esc_html__('Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-start-email-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[start_email_subject]" value="<?php echo esc_attr($settings['start_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-start-email-body"><?php echo esc_html__('Email Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-start-email-body" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[start_email_body]"><?php echo esc_textarea($settings['start_email_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-start-sms-body"><?php echo esc_html__('SMS Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-start-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[start_sms_body]"><?php echo esc_textarea($settings['start_sms_body']); ?></textarea></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('Before End Message', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-end-email-subject"><?php echo esc_html__('Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-end-email-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[end_email_subject]" value="<?php echo esc_attr($settings['end_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-end-email-body"><?php echo esc_html__('Email Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-end-email-body" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[end_email_body]"><?php echo esc_textarea($settings['end_email_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-end-sms-body"><?php echo esc_html__('SMS Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-end-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[end_sms_body]"><?php echo esc_textarea($settings['end_sms_body']); ?></textarea></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('After End Follow-Up Message', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-follow-email-subject"><?php echo esc_html__('Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-follow-email-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[follow_email_subject]" value="<?php echo esc_attr($settings['follow_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-follow-email-body"><?php echo esc_html__('Email Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-follow-email-body" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[follow_email_body]"><?php echo esc_textarea($settings['follow_email_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-follow-sms-body"><?php echo esc_html__('SMS Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-follow-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[follow_sms_body]"><?php echo esc_textarea($settings['follow_sms_body']); ?></textarea></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cpbs-track-page-message"><?php echo esc_html__('Tracking Page Success Message', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-track-page-message" class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[track_page_message]"><?php echo esc_textarea($settings['track_page_message']); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_tracking_columns($columns)
    {
        $updated = array();

        foreach ($columns as $key => $label) {
            $updated[$key] = $label;
            if ($key === 'status') {
                $updated[self::TRACK_COLUMN_KEY] = esc_html__('Occupancy', 'cpbs-combined-extensions');
                $updated[self::CLICKED_COLUMN_KEY] = esc_html__('Link Clicked', 'cpbs-combined-extensions');
            }
        }

        if (!isset($updated[self::TRACK_COLUMN_KEY])) {
            $updated[self::TRACK_COLUMN_KEY] = esc_html__('Occupancy', 'cpbs-combined-extensions');
        }

        if (!isset($updated[self::CLICKED_COLUMN_KEY])) {
            $updated[self::CLICKED_COLUMN_KEY] = esc_html__('Link Clicked', 'cpbs-combined-extensions');
        }

        return $updated;
    }

    public function render_tracking_columns($column, $post_id)
    {
        if ($column === self::TRACK_COLUMN_KEY) {
            $status = (string) $this->get_booking_meta_value($post_id, 'automation_status');
            if ($status === '') {
                $status = 'pending';
            }

            echo esc_html(ucfirst(str_replace('_', ' ', $status)));
            return;
        }

        if ($column === self::CLICKED_COLUMN_KEY) {
            $clicked = (string) $this->get_booking_meta_value($post_id, 'automation_tracking_clicked_at');
            echo $clicked !== '' ? esc_html($clicked) : esc_html__('No', 'cpbs-combined-extensions');
        }
    }

    public function maybe_handle_tracking_link()
    {
        if (!isset($_GET[self::TRACK_QUERY_KEY])) {
            return;
        }

        $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if ($booking_id <= 0 || $token === '') {
            wp_die(esc_html__('Invalid tracking link.', 'cpbs-combined-extensions'), 400);
        }

        if (!$this->is_booking_post($booking_id)) {
            wp_die(esc_html__('Booking not found.', 'cpbs-combined-extensions'), 404);
        }

        $stored_token = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_token');
        if ($stored_token === '' || !hash_equals($stored_token, $token)) {
            wp_die(esc_html__('Tracking link is invalid or expired.', 'cpbs-combined-extensions'), 403);
        }

        $clicked_at = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_clicked_at');
        if ($clicked_at === '') {
            $clicked_at = $this->site_now()->format('Y-m-d H:i:s');
            $this->update_booking_meta($booking_id, 'automation_tracking_clicked_at', $clicked_at);
        }

        $this->update_booking_meta($booking_id, 'automation_status', 'occupied');

        $settings = $this->get_settings();
        wp_die(esc_html($settings['track_page_message']), 200);
    }

    public function process_booking_automation()
    {
        $settings = $this->get_settings();
        $bookings = $this->get_bookings_for_processing();

        foreach ($bookings as $booking_id) {
            $booking_id = (int) $booking_id;
            if ($booking_id <= 0) {
                continue;
            }

            $meta = $this->get_booking_meta($booking_id);
            $entry = $this->build_site_datetime(isset($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '');
            $exit = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');

            if (!$entry || !$exit) {
                continue;
            }

            $now = $this->site_now();
            $start_notice_time = $entry->modify('-' . $settings['start_notice_minutes_before'] . ' minutes');
            $end_notice_time = $exit->modify('-' . $settings['end_notice_minutes_before'] . ' minutes');
            $followup_time = $exit->modify('+' . $settings['after_end_followup_minutes'] . ' minutes');
            $followup_window_end = $followup_time->modify('+' . $settings['followup_send_window_minutes'] . ' minutes');
            $unoccupied_time = $entry->modify('+' . $settings['late_to_unoccupied_minutes'] . ' minutes');

            if ($now >= $start_notice_time && $now < $entry && (string) $this->get_booking_meta_value($booking_id, 'automation_start_notice_sent_at') === '') {
                $this->send_automation_message($booking_id, $meta, $entry, $exit, 'start');
                $this->update_booking_meta($booking_id, 'automation_start_notice_sent_at', $now->format('Y-m-d H:i:s'));
            }

            $clicked_at = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_clicked_at');
            if ($clicked_at !== '') {
                $this->update_booking_meta($booking_id, 'automation_status', 'occupied');
            } elseif ($now >= $entry && $now < $unoccupied_time) {
                $this->update_booking_meta($booking_id, 'automation_status', 'late');
            } elseif ($now >= $unoccupied_time) {
                $this->update_booking_meta($booking_id, 'automation_status', 'unoccupied');
            }

            if ($now >= $end_notice_time && $now < $exit && (string) $this->get_booking_meta_value($booking_id, 'automation_end_notice_sent_at') === '') {
                $this->send_automation_message($booking_id, $meta, $entry, $exit, 'end');
                $this->update_booking_meta($booking_id, 'automation_end_notice_sent_at', $now->format('Y-m-d H:i:s'));
            }

            if ((string) $this->get_booking_meta_value($booking_id, 'automation_follow_notice_sent_at') === '') {
                if ($now >= $followup_time && $now <= $followup_window_end) {
                    $this->send_automation_message($booking_id, $meta, $entry, $exit, 'follow');
                    $this->update_booking_meta($booking_id, 'automation_follow_notice_sent_at', $now->format('Y-m-d H:i:s'));
                } elseif ($now > $followup_window_end) {
                    $this->update_booking_meta($booking_id, 'automation_follow_notice_sent_at', $now->format('Y-m-d H:i:s'));
                    $this->log_runtime('Follow-up skipped for stale booking', array('booking_id' => $booking_id));
                }
            }
        }
    }

    private function get_bookings_for_processing()
    {
        return get_posts(
            array(
                'post_type' => $this->get_booking_post_type(),
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'suppress_filters' => true,
            )
        );
    }

    private function send_automation_message($booking_id, $meta, \DateTimeImmutable $entry, \DateTimeImmutable $exit, $type)
    {
        $settings = $this->get_settings();
        $contact = $this->get_booking_contact($booking_id, $meta);
        $tokens = $this->build_message_tokens($booking_id, $meta, $entry, $exit);
        $email_sent = false;
        $sms_sent = false;

        if ((int) $settings['enable_email'] === 1 && $contact['email'] !== '') {
            $subject = $this->replace_tokens($settings[$type . '_email_subject'], $tokens);
            $body = $this->replace_tokens($settings[$type . '_email_body'], $tokens);
            if ($subject !== '' && $body !== '') {
                $email_sent = (bool) wp_mail($contact['email'], $subject, $body);
            }
        }

        if ((int) $settings['enable_sms'] === 1 && $contact['phone'] !== '') {
            $body = $this->replace_tokens($settings[$type . '_sms_body'], $tokens);
            if ($body !== '') {
                $sms_sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
            }
        }

        $this->log_runtime('Automation message processed', array(
            'booking_id' => (int) $booking_id,
            'type' => (string) $type,
            'email_sent' => $email_sent ? 1 : 0,
            'sms_sent' => $sms_sent ? 1 : 0,
            'email_present' => $contact['email'] !== '' ? 1 : 0,
            'phone_present' => $contact['phone'] !== '' ? 1 : 0,
        ));
    }

    private function build_message_tokens($booking_id, $meta, \DateTimeImmutable $entry, \DateTimeImmutable $exit)
    {
        $customer_name = '';
        if (!empty($meta['client_contact_detail_first_name']) || !empty($meta['client_contact_detail_last_name'])) {
            $customer_name = trim((string) $meta['client_contact_detail_first_name'] . ' ' . (string) $meta['client_contact_detail_last_name']);
        }

        if ($customer_name === '' && !empty($meta['client_contact_detail_name'])) {
            $customer_name = (string) $meta['client_contact_detail_name'];
        }

        if ($customer_name === '') {
            $customer_name = __('Customer', 'cpbs-combined-extensions');
        }

        return array(
            '{customer_name}' => $customer_name,
            '[customer_name]' => $customer_name,
            '{booking_id}' => (string) $booking_id,
            '[booking_id]' => (string) $booking_id,
            '{booking_start}' => $entry->format('Y-m-d H:i:s'),
            '[booking_start]' => $entry->format('Y-m-d H:i:s'),
            '{booking_end}' => $exit->format('Y-m-d H:i:s'),
            '[booking_end]' => $exit->format('Y-m-d H:i:s'),
            '{tracking_link}' => $this->get_or_create_tracking_link($booking_id),
            '[tracking_link]' => $this->get_or_create_tracking_link($booking_id),
            '{timestamp}' => $this->site_now()->format('Y-m-d H:i:s'),
            '[timestamp]' => $this->site_now()->format('Y-m-d H:i:s'),
        );
    }

    private function replace_tokens($template, $tokens)
    {
        $template = (string) $template;

        return str_replace(array_keys($tokens), array_values($tokens), $template);
    }

    private function get_or_create_tracking_link($booking_id)
    {
        $token = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_token');
        if ($token === '') {
            $token = wp_generate_password(24, false, false);
            $this->update_booking_meta($booking_id, 'automation_tracking_token', $token);
        }

        return add_query_arg(
            array(
                self::TRACK_QUERY_KEY => '1',
                'booking_id' => $booking_id,
                'token' => $token,
            ),
            home_url('/')
        );
    }

    private function get_booking_contact($booking_id, $meta)
    {
        $email = '';
        $email_sources = array(
            isset($meta['client_contact_detail_email_address']) ? $meta['client_contact_detail_email_address'] : '',
            isset($meta['email_address']) ? $meta['email_address'] : '',
            isset($meta['email']) ? $meta['email'] : '',
            get_post_meta($booking_id, 'cpbs_client_contact_detail_email_address', true),
            get_post_meta($booking_id, 'cpbs_email_address', true),
            get_post_meta($booking_id, 'cpbs_email', true),
        );

        foreach ($email_sources as $candidate) {
            $candidate = sanitize_email((string) $candidate);
            if ($candidate !== '' && is_email($candidate)) {
                $email = $candidate;
                break;
            }
        }

        $phone = '';
        $phone_sources = array(
            isset($meta['client_contact_detail_phone_number']) ? $meta['client_contact_detail_phone_number'] : '',
            isset($meta['phone_number']) ? $meta['phone_number'] : '',
            isset($meta['phone']) ? $meta['phone'] : '',
            isset($meta['billing_phone']) ? $meta['billing_phone'] : '',
            isset($meta['customer_phone']) ? $meta['customer_phone'] : '',
            get_post_meta($booking_id, 'cpbs_client_contact_detail_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone', true),
            get_post_meta($booking_id, 'cpbs_billing_phone', true),
            get_post_meta($booking_id, 'cpbs_customer_phone', true),
        );

        foreach ($phone_sources as $raw_phone) {
            $normalized = $this->normalize_phone_number($raw_phone);
            if ($normalized !== '') {
                $phone = $normalized;
                break;
            }
        }

        return array(
            'email' => $email,
            'phone' => $phone,
        );
    }

    private function send_twilio_sms($to_phone, $message_body)
    {
        $sms_settings = get_option(self::SMS_SETTINGS_OPTION_KEY, array());
        $sms_settings = is_array($sms_settings) ? $sms_settings : array();

        $account_sid = isset($sms_settings['twilio_account_sid']) ? trim((string) $sms_settings['twilio_account_sid']) : '';
        $auth_token = isset($sms_settings['twilio_auth_token']) ? trim((string) $sms_settings['twilio_auth_token']) : '';
        $from_phone = $this->normalize_phone_number(isset($sms_settings['twilio_from_number']) ? $sms_settings['twilio_from_number'] : '');

        if ($account_sid === '' || $auth_token === '' || $from_phone === '' || trim((string) $message_body) === '') {
            $this->log_runtime('Twilio SMS skipped due to missing credentials/phone/body', array(
                'to_phone' => (string) $to_phone,
            ));
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
            $this->log_runtime('Twilio API WP_Error', array(
                'to_phone' => (string) $to_phone,
                'error' => $response->get_error_message(),
            ));
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200 && $status !== 201) {
            $this->log_runtime('Twilio API non-success response', array(
                'to_phone' => (string) $to_phone,
                'status' => $status,
                'body' => (string) wp_remote_retrieve_body($response),
            ));
        }

        return $status === 200 || $status === 201;
    }

    private function normalize_phone_number($raw_phone)
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

    private function build_site_datetime($normalized_datetime)
    {
        if (!is_string($normalized_datetime) || $normalized_datetime === '' || $normalized_datetime === '0000-00-00 00:00') {
            return false;
        }

        $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized_datetime, wp_timezone());

        return $datetime ?: false;
    }

    private function site_now()
    {
        return new \DateTimeImmutable('now', wp_timezone());
    }

    private function get_booking_post_type()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_booking';
        }

        return 'cpbs_booking';
    }

    private function get_meta_prefix()
    {
        if (defined('PLUGIN_CPBS_CONTEXT')) {
            return PLUGIN_CPBS_CONTEXT . '_';
        }

        return 'cpbs_';
    }

    private function is_booking_post($booking_id)
    {
        $post = get_post($booking_id);

        return $post instanceof \WP_Post && $post->post_type === $this->get_booking_post_type();
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

    private function get_booking_meta_value($booking_id, $key)
    {
        $meta = $this->get_booking_meta($booking_id);

        return isset($meta[$key]) ? $meta[$key] : '';
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        if (class_exists('CPBSPostMeta')) {
            \CPBSPostMeta::updatePostMeta($booking_id, $key, $value);
            return;
        }

        update_post_meta($booking_id, $this->get_meta_prefix() . $key, $value);
    }

    private function sanitize_checkbox($value)
    {
        return (int) (!empty($value));
    }

    private function sanitize_minutes($value, $min, $max, $fallback)
    {
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private function get_default_settings()
    {
        return array(
            'enable_email' => 1,
            'enable_sms' => 1,
            'enable_runtime_log' => 1,
            'start_notice_minutes_before' => 30,
            'end_notice_minutes_before' => 10,
            'after_end_followup_minutes' => 5,
            'followup_send_window_minutes' => 120,
            'late_to_unoccupied_minutes' => 15,
            'start_email_subject' => 'Your booking starts soon',
            'start_email_body' => 'Hi {customer_name}, please check this link when your booking starts: {tracking_link}',
            'start_sms_body' => 'Please check this link when your booking starts: {tracking_link}',
            'end_email_subject' => 'Your booking is ending soon',
            'end_email_body' => 'Your booking will end at {booking_end}.',
            'end_sms_body' => 'Your booking will end at {booking_end}.',
            'follow_email_subject' => 'Thank you for visiting',
            'follow_email_body' => 'Waiting for your next visit.',
            'follow_sms_body' => 'Waiting for your next visit.',
            'track_page_message' => 'Thank you. Your parking spot is now marked as occupied.',
        );
    }

    private function get_settings()
    {
        $stored = get_option(self::OPTION_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        $defaults = $this->get_default_settings();

        return wp_parse_args($stored, $defaults);
    }

    private function log_runtime($message, array $context = array())
    {
        $settings = $this->get_settings();
        if ((int) $settings['enable_runtime_log'] !== 1) {
            return;
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . (string) $message;
        if (!empty($context)) {
            $encoded = wp_json_encode($context);
            if (is_string($encoded) && $encoded !== '') {
                $line .= ' ' . $encoded;
            }
        }
        $line .= PHP_EOL;

        $upload = wp_upload_dir();
        $dir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';

        if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
            @file_put_contents(trailingslashit($dir) . self::LOG_FILE_NAME, $line, FILE_APPEND | LOCK_EX);
            return;
        }

        error_log('[cpbs-combined] ' . trim($line));
    }

    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
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

/**
 * Reorders booking form step 1 so that "Select Car Park" appears
 * before "Entry Date" and "Entry Time" via frontend JavaScript.
 */
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
class CPBSCombinedBookingFormCompatibility
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'inject_booking_form_helper_shim'), 120);
    }

    public function inject_booking_form_helper_shim()
    {
        if (!wp_script_is('cpbs-booking-form', 'registered') && !wp_script_is('cpbs-booking-form', 'enqueued')) {
            return;
        }

        $shim = <<<'JS'
(function (window, $) {
    'use strict';

    if (!window || !$) {
        return;
    }

    if (typeof window.helper !== 'object' || window.helper === null) {
        window.helper = {};
    }

    if (typeof window.helper.handleFormCheckBox === 'function') {
        return;
    }

    window.helper.handleFormCheckBox = function ($target) {
        if (!$target || !$target.length) {
            return;
        }

        var $text = $target.nextAll('input[type="hidden"]');
        if (!$text.length) {
            return;
        }

        var value = $target.attr('data-value');

        if (!$target.hasClass('cpbs-state-selected-mandatory')) {
            if ($text.val() !== '0') {
                value = 0;
            }
        }

        var group = $target.attr('data-group');
        if (group) {
            var $parent = $target.closest('.cpbs-main');
            var $groupElements = $parent.length
                ? $parent.find('.cpbs-form-checkbox[data-group="' + group + '"]')
                : $('.cpbs-form-checkbox[data-group="' + group + '"]');

            $groupElements.each(function () {
                var $el = $(this);
                $el.removeClass('cpbs-state-selected');

                var $hidden = $el.nextAll('input[type="hidden"]');
                $hidden.val(0);

                var relField = $hidden.attr('data-rel-field');
                if (relField) {
                    $('input[name="' + relField + '"]').val(0);
                }
            });
        }

        if (String(value) === '0') {
            $target.removeClass('cpbs-state-selected');
        } else {
            $target.addClass('cpbs-state-selected');
        }

        var rel = $text.attr('data-rel-field');
        if (rel) {
            $('input[name="' + rel + '"]').val(value);
        }

        $text.val(value).trigger('change');
    };
})(window, window.jQuery);
JS;

        wp_add_inline_script('cpbs-booking-form', $shim, 'before');
    }
}

/**
 * Guards CPBS booking AJAX requests by adding missing payload keys.
 * This prevents undefined-array-key warnings from polluting JSON responses.
 */
class CPBSCombinedCPBSAjaxRequestGuard
{
    public function __construct()
    {
        add_action('init', array($this, 'normalize_booking_ajax_request'), 0);
    }

    public function normalize_booking_ajax_request()
    {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
        if ($action === '') {
            return;
        }

        $context = defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT : 'cpbs';
        $allowed = array(
            $context . '_go_to_step',
            $context . '_create_summary_price_element',
            $context . '_coupon_code_check',
            $context . '_user_sign_in',
        );

        if (!in_array($action, $allowed, true)) {
            return;
        }

        $prefix = $context . '_';
        $defaults = array(
            'client_contact_detail_first_name' => '',
            'client_contact_detail_last_name' => '',
            'client_contact_detail_email_address' => '',
            'client_contact_detail_phone_number' => '',
            'client_contact_detail_license_plate' => '',
            'comment' => '',
            'client_billing_detail_enable' => '0',
            'client_billing_detail_company_name' => '',
            'client_billing_detail_tax_number' => '',
            'client_billing_detail_street_name' => '',
            'client_billing_detail_street_number' => '',
            'client_billing_detail_city' => '',
            'client_billing_detail_state' => '',
            'client_billing_detail_postal_code' => '',
            'client_billing_detail_country_code' => '',
            'payment_mandatory_enable' => '0',
        );

        foreach ($defaults as $field => $value) {
            $key = $prefix . $field;

            if (!isset($_POST[$key])) {
                $_POST[$key] = $value;
            }

            if (!isset($_REQUEST[$key])) {
                $_REQUEST[$key] = $value;
            }
        }
    }
}

new CPBSCombinedEndBookingEarly();
new CPBSCombinedStep4SpaceTypeOverride();
new CPBSCombinedBookingReceiptOverride();
new CPBSCombinedParkingQRCode();
new CPBSCombinedBookingAutomation();
new CPBSCombinedServiceFeeSummary();
new CPBSCombinedStep1CarParkReorder();
new CPBSCombinedBookingFormCompatibility();
new CPBSCombinedCPBSAjaxRequestGuard();

register_deactivation_hook(__FILE__, array('CPBSCombinedBookingAutomation', 'unschedule_cron'));
