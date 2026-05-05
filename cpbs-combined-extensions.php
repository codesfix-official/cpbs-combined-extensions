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

/**
 * Registers the single top-level "CPBS Extensions" admin menu.
 * All feature settings pages register as children of this menu.
 */
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
        add_submenu_page(
            CPBSCombinedAdminMenu::MENU_SLUG,
            __('CPBS Booking SMS', 'cpbs-combined-extensions'),
            __('Booking SMS', 'cpbs-combined-extensions'),
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
        add_submenu_page(
            CPBSCombinedAdminMenu::MENU_SLUG,
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
        add_submenu_page(
            CPBSCombinedAdminMenu::MENU_SLUG,
            __('CPBS Booking Automation', 'cpbs-combined-extensions'),
            __('Booking Automation', 'cpbs-combined-extensions'),
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
            'extension_page_id' => absint(isset($input['extension_page_id']) ? $input['extension_page_id'] : 0),
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
            <p><?php echo esc_html__('Automate booking reminders and occupancy tracking link flow. Placeholders: {customer_name}, {booking_id}, {booking_start}, {booking_end}, {tracking_link}, {extension_link}, {timestamp}.', 'cpbs-combined-extensions'); ?></p>

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
                    <tr>
                        <th scope="row"><label for="cpbs-extension-page"><?php echo esc_html__('Booking Extension Page', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(array(
                                'name'              => self::OPTION_KEY . '[extension_page_id]',
                                'id'                => 'cpbs-extension-page',
                                'selected'          => (int) $settings['extension_page_id'],
                                'show_option_none'  => __('— Not set —', 'cpbs-combined-extensions'),
                                'option_none_value' => 0,
                            )); ?>
                            <p class="description"><?php echo esc_html__('Page where the [cpbs_booking_extend] shortcode is placed. Used to build the {extension_link} placeholder in Before End messages.', 'cpbs-combined-extensions'); ?></p>
                        </td>
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
        $track_flag = $this->get_request_value(array(self::TRACK_QUERY_KEY, 'cpbstrack', 'cpbs booking track'));
        if ($track_flag === null) {
            return;
        }

        $booking_id_raw = $this->get_request_value(array('booking_id', 'bookingid', 'booking id'));
        $booking_id = $booking_id_raw !== null ? absint(wp_unslash($booking_id_raw)) : 0;

        $token_raw = $this->get_request_value(array('token'));
        $token = $token_raw !== null ? sanitize_text_field(wp_unslash($token_raw)) : '';

        if ($booking_id <= 0 || $token === '') {
            $this->render_tracking_message_page(__('Invalid tracking link.', 'cpbs-combined-extensions'), 400);
        }

        if (!$this->is_booking_post($booking_id)) {
            $this->render_tracking_message_page(__('Booking not found.', 'cpbs-combined-extensions'), 404);
        }

        $stored_token = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_token');
        if ($stored_token === '' || !hash_equals($stored_token, $token)) {
            $this->render_tracking_message_page(__('Tracking link is invalid or expired.', 'cpbs-combined-extensions'), 403);
        }

        $settings = $this->get_settings();
        $clicked_at = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_clicked_at');
        $meta = $this->get_booking_meta($booking_id);

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

        $track_page_message = str_replace(
            array('{customer_name}', '[customer_name]'),
            $customer_name,
            (string) $settings['track_page_message']
        );

        $booking_title = get_the_title($booking_id);
        if (!is_string($booking_title) || $booking_title === '') {
            $booking_title = '#' . $booking_id;
        }

        $customer_email = isset($meta['client_contact_detail_email_address']) ? sanitize_email((string) $meta['client_contact_detail_email_address']) : '';

        $entry_label = '';
        $entry_datetime = isset($meta['entry_datetime_2']) ? (string) $meta['entry_datetime_2'] : '';
        if ($entry_datetime !== '' && $entry_datetime !== '0000-00-00 00:00') {
            $entry_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $entry_datetime, wp_timezone());
            if ($entry_dt instanceof \DateTimeImmutable) {
                $entry_label = $entry_dt->format('d-m-Y H:i');
            }
        }
        if ($entry_label === '') {
            $entry_date = isset($meta['entry_date']) ? (string) $meta['entry_date'] : '';
            $entry_time = isset($meta['entry_time']) ? (string) $meta['entry_time'] : '';
            if ($entry_date !== '' && $entry_time !== '') {
                $entry_label = trim($entry_date . ' ' . $entry_time);
            }
        }

        $exit_label = '';
        $exit_datetime = isset($meta['exit_datetime_2']) ? (string) $meta['exit_datetime_2'] : '';
        if ($exit_datetime !== '' && $exit_datetime !== '0000-00-00 00:00') {
            $exit_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $exit_datetime, wp_timezone());
            if ($exit_dt instanceof \DateTimeImmutable) {
                $exit_label = $exit_dt->format('d-m-Y H:i');
            }
        }
        if ($exit_label === '') {
            $exit_date = isset($meta['exit_date']) ? (string) $meta['exit_date'] : '';
            $exit_time = isset($meta['exit_time']) ? (string) $meta['exit_time'] : '';
            if ($exit_date !== '' && $exit_time !== '') {
                $exit_label = trim($exit_date . ' ' . $exit_time);
            }
        }

        $details_html = '<div class="cpbs-track-meta">';
        $details_html .= '<h2>' . esc_html__('Booking Details', 'cpbs-combined-extensions') . '</h2>';
        $details_html .= '<table>';
        $details_html .= '<tr><th>' . esc_html__('Booking', 'cpbs-combined-extensions') . '</th><td>' . esc_html($booking_title) . '</td></tr>';
        $details_html .= '<tr><th>' . esc_html__('Customer', 'cpbs-combined-extensions') . '</th><td>' . esc_html($customer_name) . '</td></tr>';
        if ($customer_email !== '') {
            $details_html .= '<tr><th>' . esc_html__('Email', 'cpbs-combined-extensions') . '</th><td>' . esc_html($customer_email) . '</td></tr>';
        }
        if ($entry_label !== '') {
            $details_html .= '<tr><th>' . esc_html__('Start Time', 'cpbs-combined-extensions') . '</th><td>' . esc_html($entry_label) . '</td></tr>';
        }
        if ($exit_label !== '') {
            $details_html .= '<tr><th>' . esc_html__('End Time', 'cpbs-combined-extensions') . '</th><td>' . esc_html($exit_label) . '</td></tr>';
        }
        $details_html .= '</table>';
        $details_html .= '</div>';

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $button_label = $clicked_at === ''
                ? esc_html__('Confirm Check-In', 'cpbs-combined-extensions')
                : esc_html__('Already Checked In', 'cpbs-combined-extensions');

            $message = $clicked_at === ''
                ? esc_html__('Tap the button below to confirm this booking is occupied.', 'cpbs-combined-extensions')
                : esc_html($track_page_message);

            $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            $html .= '<title>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</title>';
            $html .= '<style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#1d2327;margin:0;padding:32px}';
            $html .= '.cpbs-track-wrap{max-width:560px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.06)}';
            $html .= '.cpbs-track-wrap h1{margin:0 0 12px;font-size:28px}.cpbs-track-wrap p{line-height:1.6;margin:0 0 20px}';
            $html .= '.cpbs-track-meta{background:#f6f7fb;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:0 0 20px}';
            $html .= '.cpbs-track-meta h2{font-size:16px;margin:0 0 10px}.cpbs-track-meta table{width:100%;border-collapse:collapse}';
            $html .= '.cpbs-track-meta th,.cpbs-track-meta td{font-size:14px;padding:6px 0;vertical-align:top;text-align:left}';
            $html .= '.cpbs-track-meta th{width:35%;color:#50575e}';
            $html .= '.cpbs-track-wrap button{background:#2271b1;color:#fff;border:0;border-radius:6px;padding:12px 20px;font-size:16px;cursor:pointer}';
            $html .= '.cpbs-track-wrap button[disabled]{background:#8c8f94;cursor:default}</style></head><body>';
            $html .= '<div class="cpbs-track-wrap">';
            $html .= '<h1>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</h1>';
            $html .= '<p>' . $message . '</p>';
            $html .= $details_html;

            if ($clicked_at === '') {
                $html .= '<form method="post">';
                $html .= wp_nonce_field('cpbs_track_confirm_' . $booking_id, '_cpbs_track_nonce', true, false);
                $html .= '<input type="hidden" name="cpbs_track_confirm" value="1">';
                $html .= '<button type="submit">' . $button_label . '</button>';
                $html .= '</form>';
            } else {
                $html .= '<button type="button" disabled>' . $button_label . '</button>';
            }

            $html .= '</div></body></html>';
            wp_die($html, '', array('response' => 200));
        }

        if (!isset($_POST['cpbs_track_confirm']) || !isset($_POST['_cpbs_track_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_cpbs_track_nonce'])), 'cpbs_track_confirm_' . $booking_id)) {
            $this->render_tracking_message_page(__('Confirmation failed. Please reopen the check-in link and try again.', 'cpbs-combined-extensions'), 403);
        }

        if ($clicked_at === '') {
            $clicked_at = $this->site_now()->format('Y-m-d H:i:s');
            $this->update_booking_meta($booking_id, 'automation_tracking_clicked_at', $clicked_at);
        }

        $this->update_booking_meta($booking_id, 'automation_status', 'occupied');

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#1d2327;margin:0;padding:32px}';
        $html .= '.cpbs-track-wrap{max-width:560px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.06)}';
        $html .= '.cpbs-track-wrap h1{margin:0 0 12px;font-size:28px}.cpbs-track-wrap p{line-height:1.6;margin:0 0 20px}';
        $html .= '.cpbs-track-meta{background:#f6f7fb;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:0 0 20px}';
        $html .= '.cpbs-track-meta h2{font-size:16px;margin:0 0 10px}.cpbs-track-meta table{width:100%;border-collapse:collapse}';
        $html .= '.cpbs-track-meta th,.cpbs-track-meta td{font-size:14px;padding:6px 0;vertical-align:top;text-align:left}';
        $html .= '.cpbs-track-meta th{width:35%;color:#50575e}</style></head><body>';
        $html .= '<div class="cpbs-track-wrap">';
        $html .= '<h1>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</h1>';
        $html .= '<p>' . esc_html($track_page_message) . '</p>';
        $html .= $details_html;
        $html .= '</div></body></html>';

        wp_die($html, '', array('response' => 200));
    }

    private function get_request_value(array $keys)
    {
        foreach ($keys as $key) {
            if (isset($_GET[$key])) {
                return $_GET[$key];
            }
        }

        return null;
    }

    private function render_tracking_message_page($message, $status_code = 200)
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;background:#f6f7fb;color:#1d2327;margin:0;padding:32px}';
        $html .= '.cpbs-track-wrap{max-width:560px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.06)}';
        $html .= '.cpbs-track-wrap h1{margin:0 0 12px;font-size:28px}.cpbs-track-wrap p{line-height:1.6;margin:0}</style></head><body>';
        $html .= '<div class="cpbs-track-wrap">';
        $html .= '<h1>' . esc_html__('Booking Check-In', 'cpbs-combined-extensions') . '</h1>';
        $html .= '<p>' . esc_html((string) $message) . '</p>';
        $html .= '</div></body></html>';

        wp_die($html, '', array('response' => (int) $status_code));
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

                if ($this->is_booking_currently_active($booking_id, $meta, $entry, $exit, $now)) {
                    $this->end_unoccupied_booking($booking_id, $now);
                    continue;
                }
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

    private function is_booking_currently_active($booking_id, $meta, \DateTimeImmutable $entry, \DateTimeImmutable $exit, \DateTimeImmutable $now)
    {
        $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
        $active_statuses = apply_filters('cpbs_combined_end_booking_active_statuses', array(1, 2, 5), $booking_id, $meta);
        $active_statuses = array_map('intval', (array) $active_statuses);

        if (!in_array($status_id, $active_statuses, true)) {
            return false;
        }

        return $now >= $entry && $now < $exit;
    }

    private function end_unoccupied_booking($booking_id, \DateTimeImmutable $current_time)
    {
        $booking_model = class_exists('CPBSBooking') ? new \CPBSBooking() : null;
        if (!($booking_model instanceof \CPBSBooking) || !method_exists($booking_model, 'getBooking')) {
            $this->log_runtime('Auto-end skipped because booking model is unavailable', array('booking_id' => $booking_id));
            return false;
        }

        $booking_old = $booking_model->getBooking($booking_id);
        if ($booking_old === false || !is_array($booking_old)) {
            $this->log_runtime('Auto-end skipped because booking data could not be loaded', array('booking_id' => $booking_id));
            return false;
        }

        $booking_old_meta = isset($booking_old['meta']) && is_array($booking_old['meta']) ? $booking_old['meta'] : array();
        $exit_date = $current_time->format('d-m-Y');
        $exit_time = $current_time->format('H:i');
        $exit_datetime = $exit_date . ' ' . $exit_time;
        $exit_datetime_normalized = $current_time->format('Y-m-d H:i');

        $this->update_booking_meta($booking_id, 'exit_date', $exit_date);
        $this->update_booking_meta($booking_id, 'exit_time', $exit_time);
        $this->update_booking_meta($booking_id, 'exit_datetime', $exit_datetime);
        $this->update_booking_meta($booking_id, 'exit_datetime_2', $exit_datetime_normalized);
        $this->update_booking_meta($booking_id, 'automation_auto_closed_at', $current_time->format('Y-m-d H:i:s'));
        $this->update_booking_meta($booking_id, 'automation_auto_closed_reason', 'unoccupied');

        $status_updated = false;
        $completed_status_id = (int) apply_filters('cpbs_combined_end_booking_completed_status_id', 4, $booking_id, $booking_old);
        $sync_mode = class_exists('CPBSOption') ? (int) \CPBSOption::getOption('booking_status_synchronization') : 1;
        $has_linked_order = !empty($booking_old_meta['woocommerce_booking_id']);
        $booking_status_id = isset($booking_old_meta['booking_status_id']) ? (int) $booking_old_meta['booking_status_id'] : 0;

        do_action('cpbs_combined_before_end_booking_update', $booking_id, $booking_old, $current_time);

        if ($completed_status_id > 0 && !($sync_mode === 2 && $has_linked_order) && $booking_status_id !== $completed_status_id) {
            $this->update_booking_meta($booking_id, 'booking_status_id', $completed_status_id);
            $status_updated = true;
        }

        clean_post_cache($booking_id);

        $booking_new = $booking_model->getBooking($booking_id);

        if ($status_updated) {
            $this->ensure_status_nonblocking($completed_status_id);

            try {
                $this->sync_booking_status($booking_id);
            } catch (\Throwable $exception) {
                do_action('cpbs_combined_end_booking_sync_error', $booking_id, $exception);
            }

            if (method_exists($booking_model, 'sendEmailBookingChangeStatus') && $booking_new !== false) {
                try {
                    $booking_model->sendEmailBookingChangeStatus($booking_old, $booking_new);
                } catch (\Throwable $exception) {
                    do_action('cpbs_combined_end_booking_email_error', $booking_id, $exception);
                }
            }
        }

        do_action('cpbs_combined_after_end_booking_update', $booking_id, $booking_old, $booking_new, $status_updated);
        do_action('cpbs_combined_booking_automation_unoccupied_ended', $booking_id, $booking_old, $booking_new, $current_time, $status_updated);

        $this->log_runtime('Booking auto-ended after no check-in confirmation', array(
            'booking_id' => $booking_id,
            'status_updated' => $status_updated ? 1 : 0,
            'ended_at' => $current_time->format('Y-m-d H:i:s'),
        ));

        return true;
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
            '{extension_link}' => $this->get_extension_link($booking_id),
            '[extension_link]' => $this->get_extension_link($booking_id),
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
                'cpbstrack' => '1',
                'booking_id' => $booking_id,
                'bookingid' => $booking_id,
                'token' => $token,
            ),
            home_url('/')
        );
    }

    private function get_extension_link($booking_id)
    {
        $settings = $this->get_settings();
        $page_id = isset($settings['extension_page_id']) ? (int) $settings['extension_page_id'] : 0;
        if ($page_id <= 0) {
            return '';
        }

        $url = get_permalink($page_id);
        if (empty($url) || !is_string($url)) {
            return '';
        }

        $token = '';
        if (class_exists('CPBSBookingSummary')) {
            $summary = new \CPBSBookingSummary();
            if (method_exists($summary, 'getAccessToken')) {
                $token = (string) $summary->getAccessToken($booking_id);
            }
        }

        return add_query_arg(
            array(
                'booking_id'   => $booking_id,
                'access_token' => $token,
            ),
            $url
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
            'extension_page_id' => 0,
        );
    }

    private function get_settings()
    {
        $stored = get_option(self::OPTION_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        $defaults = $this->get_default_settings();

        return wp_parse_args($stored, $defaults);
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
 * Adds frontend booking extension with Stripe Checkout and admin extension columns.
 */
class CPBSCombinedBookingExtension
{
    const SHORTCODE = 'cpbs_booking_extend';
    const AJAX_ACTION_CREATE_CHECKOUT = 'cpbs_combined_booking_extension_checkout';
    const NONCE_ACTION = 'cpbs_combined_booking_extension_checkout_nonce';
    const META_PENDING = 'cpbs_extension_pending';
    const META_HISTORY = 'cpbs_extension_history';
    const META_TOTAL_HOURS = 'cpbs_extension_total_hours';
    const META_TOTAL_AMOUNT = 'cpbs_extension_total_amount';
    const META_TOTAL_COUNT = 'cpbs_extension_total_count';
    const COLUMN_HOURS = 'cpbs_extension_hours';
    const COLUMN_AMOUNT = 'cpbs_extension_amount';
    const VERSION = '1.0.0';

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_ajax_' . self::AJAX_ACTION_CREATE_CHECKOUT, array($this, 'ajax_create_checkout'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_CREATE_CHECKOUT, array($this, 'ajax_create_checkout'));
        add_action('init', array($this, 'maybe_finalize_checkout_payment'), 1);

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_admin_columns'), 30);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
    }

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'booking_id' => 0,
                'access_token' => '',
            ),
            $atts,
            self::SHORTCODE
        );

        $booking_id = (int) $atts['booking_id'];
        if ($booking_id <= 0) {
            $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        }

        $access_token = (string) $atts['access_token'];
        if ($access_token === '') {
            $access_token = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : '';
        }

        if ($booking_id <= 0 || $access_token === '') {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Booking extension is unavailable because booking details are missing.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking)) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Booking extension is unavailable because this booking could not be loaded.', 'cpbs-combined-extensions') . '</p></div>';
        }

        if (!$this->is_access_token_valid($booking_id, $access_token)) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Booking extension is unavailable because access is invalid.', 'cpbs-combined-extensions') . '</p></div>';
        }

        if (!$this->is_active_booking($booking)) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Only active bookings can be extended.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $price_per_hour = $this->get_price_per_hour($booking);
        if ($price_per_hour <= 0) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Booking extension is unavailable because hourly pricing is not configured for this booking.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $current_exit = $this->get_exit_datetime($booking);
        if (!($current_exit instanceof \DateTimeImmutable)) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('Booking extension is unavailable because the current end time is invalid.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $now = new \DateTimeImmutable('now', wp_timezone());
        if ($current_exit <= $now) {
            return '<div class="cpbs-combined-extension-wrap"><p>' . esc_html__('This booking has already ended and cannot be extended.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $this->enqueue_assets();

        $notice = '';
        $notice_class = '';
        $is_success = false;
        $result = isset($_GET['cpbs_extend_notice']) ? sanitize_key(wp_unslash($_GET['cpbs_extend_notice'])) : '';
        if ($result === 'success') {
            $is_success = true;
            $notice_class = 'success';
            $notice = '<div class="cpbs-combined-extension-notice success">' . esc_html__('Extension payment received. Your booking end time has been updated.', 'cpbs-combined-extensions') . '</div>';
        } elseif ($result === 'cancel') {
            $notice_class = 'error';
            $notice = '<div class="cpbs-combined-extension-notice error">' . esc_html__('Stripe checkout was canceled. Your booking was not changed.', 'cpbs-combined-extensions') . '</div>';
        } elseif ($result === 'failed') {
            $notice_class = 'error';
            $notice = '<div class="cpbs-combined-extension-notice error">' . esc_html__('Payment could not be verified. Please try extending again.', 'cpbs-combined-extensions') . '</div>';
        }

        $formatted_rate = $this->format_price($price_per_hour, $booking);
        $exit_label = $current_exit->format('d-m-Y H:i');
        $booking_title = isset($booking['post']->post_title) ? (string) $booking['post']->post_title : '#' . $booking_id;

        // Resolve customer name.
        $meta = isset($booking['meta']) ? $booking['meta'] : array();
        $customer_name = '';
        if (!empty($meta['client_contact_detail_first_name']) || !empty($meta['client_contact_detail_last_name'])) {
            $customer_name = trim((string) ($meta['client_contact_detail_first_name'] ?? '') . ' ' . (string) ($meta['client_contact_detail_last_name'] ?? ''));
        }
        if ($customer_name === '' && !empty($meta['client_contact_detail_name'])) {
            $customer_name = (string) $meta['client_contact_detail_name'];
        }

        $customer_email = isset($meta['client_contact_detail_email_address']) ? sanitize_email((string) $meta['client_contact_detail_email_address']) : '';

        // Entry datetime for display.
        $entry_label = '';
        $entry_dt = isset($meta['entry_datetime_2']) ? (string) $meta['entry_datetime_2'] : '';
        if ($entry_dt !== '' && $entry_dt !== '0000-00-00 00:00') {
            $entry_parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $entry_dt, wp_timezone());
            if ($entry_parsed instanceof \DateTimeImmutable) {
                $entry_label = $entry_parsed->format('d-m-Y H:i');
            }
        }
        if ($entry_label === '') {
            $entry_date = isset($meta['entry_date']) ? (string) $meta['entry_date'] : '';
            $entry_time = isset($meta['entry_time']) ? (string) $meta['entry_time'] : '';
            if ($entry_date !== '' && $entry_time !== '') {
                $entry_label = $entry_date . ' ' . $entry_time;
            }
        }

        static $style_printed = false;

        ob_start();
        ?>
        <?php if (!$style_printed) : ?>
            <style id="cpbs-combined-extension-style">
                .cpbs-combined-extension-wrap{max-width:760px;margin:24px auto;padding:24px;border:1px solid #dde3ea;border-radius:14px;background:#fff;box-shadow:0 14px 34px rgba(18,38,63,.08);font-family:"Segoe UI",Tahoma,sans-serif;color:#1b2b38}
                .cpbs-combined-extension-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
                .cpbs-combined-extension-title{margin:0;font-size:28px;line-height:1.2;color:#102a43}
                .cpbs-combined-extension-subtitle{margin:6px 0 0;color:#52606d;font-size:14px}
                .cpbs-combined-extension-pill{display:inline-block;background:#ecfdf3;color:#0f766e;border:1px solid #a7f3d0;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
                .cpbs-combined-extension-notice{border-radius:10px;padding:12px 14px;margin:0 0 16px;font-size:14px}
                .cpbs-combined-extension-notice.success{background:#ecfdf3;border:1px solid #a7f3d0;color:#116149}
                .cpbs-combined-extension-notice.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
                .cpbs-combined-extension-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:18px}
                .cpbs-combined-extension-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
                .cpbs-combined-extension-item strong{display:block;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
                .cpbs-combined-extension-item span{font-size:15px;color:#0f172a;word-break:break-word}
                .cpbs-combined-extension-form{margin-top:6px;padding-top:16px;border-top:1px solid #e2e8f0}
                .cpbs-combined-extension-form label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px}
                .cpbs-combined-extension-form input[name="cpbs_extension_hours"]{display:block;width:100%;max-width:180px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:16px}
                .cpbs-combined-extension-estimate{margin:10px 0 14px;font-size:14px;color:#0f766e;font-weight:600}
                .cpbs-combined-extension-feedback{margin:10px 0 0;font-size:13px;color:#b91c1c}
                .cpbs-combined-extension-feedback.cpbs-state-error{font-weight:600}
                .cpbs-combined-extension-wrap .button.button-primary{background:#0f766e;color:#fff;border-color:#0f766e;padding:9px 16px;border-radius:8px}
                .cpbs-combined-extension-wrap .button.button-primary:hover{background:#0d665f;border-color:#0d665f}
                @media (max-width:640px){.cpbs-combined-extension-wrap{padding:18px}.cpbs-combined-extension-title{font-size:24px}}
            </style>
            <?php $style_printed = true; ?>
        <?php endif; ?>

        <div class="cpbs-combined-extension-wrap"
             data-booking-id="<?php echo esc_attr($booking_id); ?>"
             data-access-token="<?php echo esc_attr($access_token); ?>"
             data-price-per-hour="<?php echo esc_attr($this->format_decimal($price_per_hour)); ?>">
            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="cpbs-combined-extension-header">
                <div>
                    <h3 class="cpbs-combined-extension-title"><?php echo esc_html__('Extend Booking', 'cpbs-combined-extensions'); ?></h3>
                    <p class="cpbs-combined-extension-subtitle">
                        <?php
                        if ($customer_name !== '') {
                            echo esc_html(sprintf(__('Hello %s. Review your booking and add extra hours when needed.', 'cpbs-combined-extensions'), $customer_name));
                        } else {
                            echo esc_html__('Review your booking and add extra hours when needed.', 'cpbs-combined-extensions');
                        }
                        ?>
                    </p>
                </div>
                <?php if ($notice_class === 'success' && $is_success) : ?>
                    <span class="cpbs-combined-extension-pill"><?php echo esc_html__('Updated', 'cpbs-combined-extensions'); ?></span>
                <?php endif; ?>
            </div>

            <div class="cpbs-combined-extension-grid">
                <div class="cpbs-combined-extension-item">
                    <strong><?php echo esc_html__('Booking', 'cpbs-combined-extensions'); ?></strong>
                    <span><?php echo esc_html($booking_title); ?></span>
                </div>
                <?php if ($customer_email !== '') : ?>
                <div class="cpbs-combined-extension-item">
                    <strong><?php echo esc_html__('Email', 'cpbs-combined-extensions'); ?></strong>
                    <span><?php echo esc_html($customer_email); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($entry_label !== '') : ?>
                <div class="cpbs-combined-extension-item">
                    <strong><?php echo esc_html__('Start Time', 'cpbs-combined-extensions'); ?></strong>
                    <span><?php echo esc_html($entry_label); ?></span>
                </div>
                <?php endif; ?>
                <div class="cpbs-combined-extension-item">
                    <strong><?php echo esc_html__('Current End Time', 'cpbs-combined-extensions'); ?></strong>
                    <span><?php echo esc_html($exit_label); ?></span>
                </div>
                <div class="cpbs-combined-extension-item">
                    <strong><?php echo esc_html__('Hourly Rate', 'cpbs-combined-extensions'); ?></strong>
                    <span><?php echo esc_html($formatted_rate . ' / ' . __('hour', 'cpbs-combined-extensions')); ?></span>
                </div>
            </div>

            <form class="cpbs-combined-extension-form" method="post" novalidate>
                <label>
                    <?php echo esc_html__('Add hours', 'cpbs-combined-extensions'); ?>
                    <input type="number" min="1" step="1" value="1" name="cpbs_extension_hours" required />
                </label>
                <p class="cpbs-combined-extension-estimate" aria-live="polite"></p>
                <button type="submit" class="button button-primary"><?php echo esc_html__('Pay & Extend via Stripe', 'cpbs-combined-extensions'); ?></button>
                <p class="cpbs-combined-extension-feedback" aria-live="polite"></p>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function ajax_create_checkout()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $booking_id = isset($_POST['booking_id']) ? absint(wp_unslash($_POST['booking_id'])) : 0;
        $access_token = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
        $hours = isset($_POST['hours']) ? (int) wp_unslash($_POST['hours']) : 0;
        $return_url = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : home_url('/');

        if ($booking_id <= 0 || $hours <= 0 || $access_token === '') {
            wp_send_json_error(array('message' => esc_html__('Invalid extension request.', 'cpbs-combined-extensions')), 400);
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking)) {
            wp_send_json_error(array('message' => esc_html__('Booking not found.', 'cpbs-combined-extensions')), 404);
        }

        if (!$this->is_access_token_valid($booking_id, $access_token)) {
            wp_send_json_error(array('message' => esc_html__('Booking access token is invalid.', 'cpbs-combined-extensions')), 403);
        }

        if (!$this->is_active_booking($booking)) {
            wp_send_json_error(array('message' => esc_html__('Only active bookings can be extended.', 'cpbs-combined-extensions')), 409);
        }

        $price_per_hour = $this->get_price_per_hour($booking);
        if ($price_per_hour <= 0) {
            wp_send_json_error(array('message' => esc_html__('Hourly rate was not found for this booking.', 'cpbs-combined-extensions')), 422);
        }

        $tax_rate = $this->get_hour_tax_rate($booking);
        $amount_net = $price_per_hour * $hours;
        $amount_gross = $this->calculate_gross($amount_net, $tax_rate);
        $unit_amount = (int) round($amount_gross * 100);

        if ($unit_amount <= 0) {
            wp_send_json_error(array('message' => esc_html__('Calculated extension amount is invalid.', 'cpbs-combined-extensions')), 422);
        }

        $current_exit = $this->get_exit_datetime($booking);
        if (!($current_exit instanceof \DateTimeImmutable)) {
            wp_send_json_error(array('message' => esc_html__('Current booking end time is invalid.', 'cpbs-combined-extensions')), 422);
        }

        $now = new \DateTimeImmutable('now', wp_timezone());
        if ($current_exit <= $now) {
            wp_send_json_error(array('message' => esc_html__('This booking has already ended and cannot be extended.', 'cpbs-combined-extensions')), 409);
        }

        $new_exit = $current_exit->modify('+' . $hours . ' hours');
        if (!($new_exit instanceof \DateTimeImmutable)) {
            wp_send_json_error(array('message' => esc_html__('Could not calculate extended end time.', 'cpbs-combined-extensions')), 500);
        }

        $stripe_config = $this->get_stripe_config_for_booking($booking);
        if (!$stripe_config['is_valid']) {
            wp_send_json_error(array('message' => esc_html__('Stripe is not configured for this booking location.', 'cpbs-combined-extensions')), 422);
        }

        if (!$this->load_stripe_library()) {
            wp_send_json_error(array('message' => esc_html__('Stripe library is not available.', 'cpbs-combined-extensions')), 500);
        }

        $booking_title = isset($booking['post']->post_title) ? (string) $booking['post']->post_title : ('#' . $booking_id);
        $currency = isset($booking['meta']['currency_id']) ? strtolower((string) $booking['meta']['currency_id']) : 'usd';
        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            $currency = 'usd';
        }

        $base_url = $this->normalize_return_url($return_url);
        $success_url = add_query_arg(
            array(
                'cpbs_extend_result' => 'success',
                'cpbs_extend_session_id' => '{CHECKOUT_SESSION_ID}',
                'booking_id' => $booking_id,
                'access_token' => $access_token,
            ),
            $base_url
        );
        $cancel_url = add_query_arg(
            array(
                'cpbs_extend_result' => 'cancel',
                'booking_id' => $booking_id,
                'access_token' => $access_token,
            ),
            $base_url
        );

        try {
            \Stripe\Stripe::setApiKey($stripe_config['secret_key']);

            $session = \Stripe\Checkout\Session::create(
                array(
                    'mode' => 'payment',
                    'payment_method_types' => $stripe_config['methods'],
                    'line_items' => array(
                        array(
                            'price_data' => array(
                                'currency' => $currency,
                                'unit_amount' => $unit_amount,
                                'product_data' => array(
                                    'name' => sprintf(__('Booking extension for %s', 'cpbs-combined-extensions'), $booking_title),
                                    'description' => sprintf(__('Additional %d hour(s)', 'cpbs-combined-extensions'), $hours),
                                ),
                            ),
                            'quantity' => 1,
                        ),
                    ),
                    'success_url' => $success_url,
                    'cancel_url' => $cancel_url,
                    'customer_email' => isset($booking['meta']['client_contact_detail_email_address']) ? (string) $booking['meta']['client_contact_detail_email_address'] : '',
                    'metadata' => array(
                        'cpbs_extension' => '1',
                        'booking_id' => (string) $booking_id,
                        'hours' => (string) $hours,
                        'target_exit_datetime_2' => $new_exit->format('Y-m-d H:i'),
                    ),
                )
            );
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => esc_html__('Stripe checkout session could not be created.', 'cpbs-combined-extensions')), 500);
        }

        if (!is_object($session) || empty($session->id) || empty($session->url)) {
            wp_send_json_error(array('message' => esc_html__('Stripe checkout session is invalid.', 'cpbs-combined-extensions')), 500);
        }

        $pending = $this->get_booking_meta_value($booking_id, self::META_PENDING, array());
        if (!is_array($pending)) {
            $pending = array();
        }

        $pending[$session->id] = array(
            'session_id' => (string) $session->id,
            'hours' => $hours,
            'amount_net' => (float) $amount_net,
            'amount_gross' => (float) $amount_gross,
            'tax_rate' => (float) $tax_rate,
            'old_exit_datetime_2' => $current_exit->format('Y-m-d H:i'),
            'target_exit_datetime_2' => $new_exit->format('Y-m-d H:i'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'status' => 'pending',
        );

        $this->update_booking_meta($booking_id, self::META_PENDING, $pending);

        wp_send_json_success(
            array(
                'checkoutUrl' => (string) $session->url,
                'hours' => $hours,
                'amount' => $this->format_price($amount_gross, $booking),
                'targetExit' => $new_exit->format('d-m-Y H:i'),
            )
        );
    }

    public function maybe_finalize_checkout_payment()
    {
        $result = isset($_GET['cpbs_extend_result']) ? sanitize_key(wp_unslash($_GET['cpbs_extend_result'])) : '';
        if ($result === '') {
            return;
        }

        if ($result === 'cancel') {
            $this->redirect_with_notice('cancel');
        }

        if ($result !== 'success') {
            return;
        }

        $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        $access_token = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : '';
        $session_id = isset($_GET['cpbs_extend_session_id']) ? sanitize_text_field(wp_unslash($_GET['cpbs_extend_session_id'])) : '';

        if ($booking_id <= 0 || $access_token === '' || $session_id === '') {
            $this->redirect_with_notice('failed');
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking) || !$this->is_access_token_valid($booking_id, $access_token)) {
            $this->redirect_with_notice('failed');
        }

        $pending = $this->get_booking_meta_value($booking_id, self::META_PENDING, array());
        if (!is_array($pending) || !isset($pending[$session_id]) || !is_array($pending[$session_id])) {
            $this->redirect_with_notice('failed');
        }

        $pending_item = $pending[$session_id];
        if (($pending_item['status'] ?? '') === 'completed') {
            $this->redirect_with_notice('success');
        }

        $stripe_config = $this->get_stripe_config_for_booking($booking);
        if (!$stripe_config['is_valid'] || !$this->load_stripe_library()) {
            $this->redirect_with_notice('failed');
        }

        try {
            \Stripe\Stripe::setApiKey($stripe_config['secret_key']);
            $session = \Stripe\Checkout\Session::retrieve($session_id);
        } catch (\Throwable $exception) {
            $this->redirect_with_notice('failed');
        }

        if (!is_object($session) || ($session->payment_status ?? '') !== 'paid') {
            $this->redirect_with_notice('failed');
        }

        $meta_booking_id = isset($session->metadata['booking_id']) ? (int) $session->metadata['booking_id'] : 0;
        if ($meta_booking_id !== $booking_id) {
            $this->redirect_with_notice('failed');
        }

        $target_exit = isset($pending_item['target_exit_datetime_2']) ? (string) $pending_item['target_exit_datetime_2'] : '';
        $target_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $target_exit, wp_timezone());
        if (!($target_dt instanceof \DateTimeImmutable)) {
            $this->redirect_with_notice('failed');
        }

        $this->update_booking_meta($booking_id, 'exit_date', $target_dt->format('d-m-Y'));
        $this->update_booking_meta($booking_id, 'exit_time', $target_dt->format('H:i'));
        $this->update_booking_meta($booking_id, 'exit_datetime', $target_dt->format('d-m-Y H:i'));
        $this->update_booking_meta($booking_id, 'exit_datetime_2', $target_dt->format('Y-m-d H:i'));

        $total_hours = (float) $this->get_booking_meta_value($booking_id, self::META_TOTAL_HOURS, 0);
        $total_amount = (float) $this->get_booking_meta_value($booking_id, self::META_TOTAL_AMOUNT, 0);
        $total_count = (int) $this->get_booking_meta_value($booking_id, self::META_TOTAL_COUNT, 0);

        $total_hours += isset($pending_item['hours']) ? (float) $pending_item['hours'] : 0;
        $total_amount += isset($pending_item['amount_gross']) ? (float) $pending_item['amount_gross'] : 0;
        $total_count++;

        $this->update_booking_meta($booking_id, self::META_TOTAL_HOURS, $this->format_decimal($total_hours));
        $this->update_booking_meta($booking_id, self::META_TOTAL_AMOUNT, $this->format_decimal($total_amount));
        $this->update_booking_meta($booking_id, self::META_TOTAL_COUNT, $total_count);

        $history = $this->get_booking_meta_value($booking_id, self::META_HISTORY, array());
        if (!is_array($history)) {
            $history = array();
        }

        $history[] = array(
            'session_id' => $session_id,
            'hours' => isset($pending_item['hours']) ? (int) $pending_item['hours'] : 0,
            'amount_gross' => isset($pending_item['amount_gross']) ? (float) $pending_item['amount_gross'] : 0,
            'old_exit_datetime_2' => isset($pending_item['old_exit_datetime_2']) ? (string) $pending_item['old_exit_datetime_2'] : '',
            'new_exit_datetime_2' => $target_dt->format('Y-m-d H:i'),
            'paid_at' => gmdate('Y-m-d H:i:s'),
        );

        $this->update_booking_meta($booking_id, self::META_HISTORY, $history);

        $pending[$session_id]['status'] = 'completed';
        $pending[$session_id]['completed_at'] = gmdate('Y-m-d H:i:s');
        $this->update_booking_meta($booking_id, self::META_PENDING, $pending);

        do_action('cpbs_combined_booking_extended', $booking_id, $pending[$session_id], $history);

        $this->redirect_with_notice('success');
    }

    public function register_admin_columns($columns)
    {
        $updated = array();

        foreach ($columns as $key => $label) {
            $updated[$key] = $label;

            if ($key === 'status') {
                $updated[self::COLUMN_HOURS] = __('Extended Hours', 'cpbs-combined-extensions');
                $updated[self::COLUMN_AMOUNT] = __('Extension Amount', 'cpbs-combined-extensions');
            }
        }

        if (!isset($updated[self::COLUMN_HOURS])) {
            $updated[self::COLUMN_HOURS] = __('Extended Hours', 'cpbs-combined-extensions');
        }
        if (!isset($updated[self::COLUMN_AMOUNT])) {
            $updated[self::COLUMN_AMOUNT] = __('Extension Amount', 'cpbs-combined-extensions');
        }

        return $updated;
    }

    public function render_admin_columns($column, $post_id)
    {
        if ($column !== self::COLUMN_HOURS && $column !== self::COLUMN_AMOUNT) {
            return;
        }

        if (!$this->is_booking_post($post_id)) {
            echo '&ndash;';
            return;
        }

        $booking = $this->get_booking($post_id);
        if (!is_array($booking)) {
            echo '&ndash;';
            return;
        }

        if ($column === self::COLUMN_HOURS) {
            $hours = (float) $this->get_booking_meta_value($post_id, self::META_TOTAL_HOURS, 0);
            echo esc_html($hours > 0 ? $this->format_decimal($hours) : '0');
            return;
        }

        $amount = (float) $this->get_booking_meta_value($post_id, self::META_TOTAL_AMOUNT, 0);
        echo esc_html($amount > 0 ? $this->format_price($amount, $booking) : $this->format_price(0, $booking));
    }

    private function enqueue_assets()
    {
        wp_enqueue_script(
            'cpbs-combined-booking-extension',
            plugin_dir_url(__FILE__) . 'cpbs-combined-booking-extension.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_localize_script(
            'cpbs-combined-booking-extension',
            'cpbsBookingExtension',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION_CREATE_CHECKOUT,
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'i18n' => array(
                    'processing' => __('Preparing Stripe checkout...', 'cpbs-combined-extensions'),
                    'invalidHours' => __('Please enter at least 1 hour.', 'cpbs-combined-extensions'),
                    'genericError' => __('Booking extension could not be started.', 'cpbs-combined-extensions'),
                    'estimateLabel' => __('Estimated extension charge:', 'cpbs-combined-extensions'),
                ),
            )
        );
    }

    private function get_booking($booking_id)
    {
        if (!class_exists('CPBSBooking')) {
            return null;
        }

        $model = new \CPBSBooking();
        if (!method_exists($model, 'getBooking')) {
            return null;
        }

        $booking = $model->getBooking($booking_id);
        if ($booking === false || !is_array($booking)) {
            return null;
        }

        return $booking;
    }

    private function is_access_token_valid($booking_id, $provided_token)
    {
        if (!class_exists('CPBSBookingSummary')) {
            return false;
        }

        $summary = new \CPBSBookingSummary();
        if (!method_exists($summary, 'getAccessToken')) {
            return false;
        }

        $expected = (string) $summary->getAccessToken($booking_id);
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $provided_token);
    }

    private function is_active_booking($booking)
    {
        $status_id = isset($booking['meta']['booking_status_id']) ? (int) $booking['meta']['booking_status_id'] : 0;
        $active_statuses = apply_filters('cpbs_combined_extension_active_statuses', array(1, 2, 3), $booking);
        if (!is_array($active_statuses)) {
            $active_statuses = array(1, 2, 3);
        }

        $normalized = array();
        foreach ($active_statuses as $status) {
            $status = (int) $status;
            if ($status > 0) {
                $normalized[] = $status;
            }
        }

        return in_array($status_id, $normalized, true);
    }

    private function get_price_per_hour($booking)
    {
        $value = isset($booking['meta']['price_rental_hour_value']) ? (float) $booking['meta']['price_rental_hour_value'] : 0;
        return $value > 0 ? $value : 0;
    }

    private function get_hour_tax_rate($booking)
    {
        $value = isset($booking['meta']['price_rental_hour_tax_rate_value']) ? (float) $booking['meta']['price_rental_hour_tax_rate_value'] : 0;
        return $value > 0 ? $value : 0;
    }

    private function get_exit_datetime($booking)
    {
        $value = isset($booking['meta']['exit_datetime_2']) ? (string) $booking['meta']['exit_datetime_2'] : '';
        $timezone = wp_timezone();

        if ($value !== '' && $value !== '0000-00-00 00:00') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $timezone);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        $exit_date = isset($booking['meta']['exit_date']) ? (string) $booking['meta']['exit_date'] : '';
        $exit_time = isset($booking['meta']['exit_time']) ? (string) $booking['meta']['exit_time'] : '';
        if ($exit_date !== '' && $exit_time !== '') {
            $date = \DateTimeImmutable::createFromFormat('d-m-Y H:i', $exit_date . ' ' . $exit_time, $timezone);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    private function get_stripe_config_for_booking($booking)
    {
        $result = array(
            'is_valid' => false,
            'secret_key' => '',
            'publishable_key' => '',
            'methods' => array('card'),
        );

        if (!class_exists('CPBSLocation')) {
            return $result;
        }

        $location_id = isset($booking['meta']['location_id']) ? (int) $booking['meta']['location_id'] : 0;
        if ($location_id <= 0) {
            return $result;
        }

        $location_model = new \CPBSLocation();
        if (!method_exists($location_model, 'getDictionary')) {
            return $result;
        }

        $dictionary = $location_model->getDictionary();
        if (!is_array($dictionary) || !isset($dictionary[$location_id]['meta']) || !is_array($dictionary[$location_id]['meta'])) {
            return $result;
        }

        $meta = $dictionary[$location_id]['meta'];
        $secret = isset($meta['payment_stripe_api_key_secret']) ? (string) $meta['payment_stripe_api_key_secret'] : '';
        $publishable = isset($meta['payment_stripe_api_key_publishable']) ? (string) $meta['payment_stripe_api_key_publishable'] : '';
        $methods = isset($meta['payment_stripe_method']) && is_array($meta['payment_stripe_method']) ? $meta['payment_stripe_method'] : array('card');

        $sanitized_methods = array();
        foreach ($methods as $method) {
            $method = sanitize_key((string) $method);
            if ($method !== '') {
                $sanitized_methods[] = $method;
            }
        }
        if (empty($sanitized_methods)) {
            $sanitized_methods = array('card');
        }

        $result['secret_key'] = $secret;
        $result['publishable_key'] = $publishable;
        $result['methods'] = $sanitized_methods;
        $result['is_valid'] = ($secret !== '' && $publishable !== '');

        return $result;
    }

    private function load_stripe_library()
    {
        if (class_exists('Stripe\\Stripe')) {
            return true;
        }

        $path = WP_PLUGIN_DIR . '/car-park-booking-system/library/stripe/init.php';
        if (!file_exists($path)) {
            return false;
        }

        require_once $path;
        return class_exists('Stripe\\Stripe');
    }

    private function calculate_gross($net, $tax_rate)
    {
        if (class_exists('CPBSPrice') && method_exists('CPBSPrice', 'calculateGross')) {
            return (float) \CPBSPrice::calculateGross((float) $net, 0, (float) $tax_rate);
        }

        return (float) $net + ((float) $net * ((float) $tax_rate / 100));
    }

    private function normalize_return_url($url)
    {
        if (!is_string($url) || $url === '') {
            return home_url('/');
        }

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $return_host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($home_host) || !is_string($return_host) || strtolower($home_host) !== strtolower($return_host)) {
            return home_url('/');
        }

        return $url;
    }

    private function redirect_with_notice($notice)
    {
        $redirect = remove_query_arg(
            array('cpbs_extend_result', 'cpbs_extend_session_id', 'cpbs_extend_notice')
        );
        $redirect = add_query_arg('cpbs_extend_notice', sanitize_key($notice), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    private function get_booking_meta_value($booking_id, $key, $default = null)
    {
        if (class_exists('CPBSPostMeta') && method_exists('CPBSPostMeta', 'getPostMeta')) {
            $meta = \CPBSPostMeta::getPostMeta($booking_id);
            if (is_array($meta) && array_key_exists($key, $meta)) {
                return $meta[$key];
            }
        }

        $raw = get_post_meta($booking_id, $this->get_meta_prefix() . $key, true);
        return $raw === '' ? $default : $raw;
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        if (class_exists('CPBSPostMeta') && method_exists('CPBSPostMeta', 'updatePostMeta')) {
            \CPBSPostMeta::updatePostMeta($booking_id, $key, $value);
            return;
        }

        update_post_meta($booking_id, $this->get_meta_prefix() . $key, $value);
    }

    private function format_price($value, $booking)
    {
        $currency = isset($booking['meta']['currency_id']) ? (string) $booking['meta']['currency_id'] : '';
        if ($currency !== '' && class_exists('CPBSPrice') && method_exists('CPBSPrice', 'format')) {
            return (string) \CPBSPrice::format((float) $value, $currency);
        }

        return $this->format_decimal($value);
    }

    private function get_meta_prefix()
    {
        return defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_' : 'cpbs_';
    }

    private function get_booking_post_type()
    {
        return 'cpbs_booking';
    }

    private function is_booking_post($post_id)
    {
        return get_post_type($post_id) === $this->get_booking_post_type();
    }

    private function format_decimal($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}

/**
 * Sends booking review invites and stores customer reviews.
 */
final class CPBSCombinedBookingReview
{
    const OPTION_KEY = 'cpbs_combined_booking_review_settings';
    const SETTINGS_GROUP = 'cpbs_combined_booking_review_group';
    const SETTINGS_PAGE_SLUG = 'cpbs-combined-booking-review';
    const SHORTCODE = 'cpbs_booking_review_form';
    const NONCE_ACTION = 'cpbs_combined_booking_review_submit';
    const CRON_HOOK = 'cpbs_combined_booking_review_cron';
    const CRON_INTERVAL = 'cpbs_every_five_minutes_reviews';
    const REVIEW_POST_TYPE = 'cpbs_booking_review';
    const DISPLAY_SHORTCODE = 'cpbs_booking_reviews';
    const SMS_SETTINGS_OPTION_KEY = 'cpbs_combined_booking_sms_settings';

    public function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'maybe_handle_submission'), 1);
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_shortcode(self::DISPLAY_SHORTCODE, array($this, 'render_reviews_shortcode'));

        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'register_review_meta_boxes'));

        add_filter('cron_schedules', array($this, 'register_cron_interval'));
        add_action('init', array($this, 'schedule_cron'));
        add_action(self::CRON_HOOK, array($this, 'process_review_invites'));
    }

    public function register_post_type()
    {
        register_post_type(
            self::REVIEW_POST_TYPE,
            array(
                'labels' => array(
                    'name' => __('Booking Reviews', 'cpbs-combined-extensions'),
                    'singular_name' => __('Booking Review', 'cpbs-combined-extensions'),
                    'menu_name' => __('Booking Reviews', 'cpbs-combined-extensions'),
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'supports' => array('title'),
                'capability_type' => 'post',
                'map_meta_cap' => true,
            )
        );
    }

    public function register_admin_page()
    {
        add_submenu_page(
            CPBSCombinedAdminMenu::MENU_SLUG,
            __('CPBS Booking Review', 'cpbs-combined-extensions'),
            __('Booking Reviews', 'cpbs-combined-extensions'),
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

        $window = (int) (isset($input['send_window_days']) ? $input['send_window_days'] : 7);
        if ($window < 1 || $window > 365) {
            $window = 7;
        }

        $display_count = (int) (isset($input['display_count']) ? $input['display_count'] : 10);
        if ($display_count < -1 || $display_count === 0) {
            $display_count = 10;
        }

        $display_min_rating = (int) (isset($input['display_min_rating']) ? $input['display_min_rating'] : 1);
        if ($display_min_rating < 1 || $display_min_rating > 5) {
            $display_min_rating = 1;
        }

        return array(
            'enable_email'          => (int) (!empty($input['enable_email'])),
            'enable_sms'            => (int) (!empty($input['enable_sms'])),
            'send_after_minutes'    => $this->sanitize_minutes(isset($input['send_after_minutes']) ? $input['send_after_minutes'] : 60, 1, 10080, 60),
            'send_window_days'      => $window,
            'review_page_id'        => absint(isset($input['review_page_id']) ? $input['review_page_id'] : 0),
            'email_subject'         => sanitize_text_field(isset($input['email_subject']) ? wp_unslash($input['email_subject']) : ''),
            'email_body'            => sanitize_textarea_field(isset($input['email_body']) ? wp_unslash($input['email_body']) : ''),
            'sms_body'              => sanitize_textarea_field(isset($input['sms_body']) ? wp_unslash($input['sms_body']) : ''),
            'display_count'         => $display_count,
            'display_min_rating'    => $display_min_rating,
            'display_show_location' => (int) (!empty($input['display_show_location'])),
            'display_show_date'     => (int) (!empty($input['display_show_date'])),
            'display_autoplay'      => (int) (!empty($input['display_autoplay'])),
            'display_autoplay_ms'   => $this->sanitize_minutes(isset($input['display_autoplay_ms']) ? (int) round((int) $input['display_autoplay_ms'] / 1000) : 5, 1, 60, 5) * 1000,
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
            <h1><?php echo esc_html__('CPBS Booking Review', 'cpbs-combined-extensions'); ?></h1>
            <p><?php echo esc_html__('Sends a review form link after booking end. Placeholders: {customer_name}, {booking_id}, {booking_end}, {location_name}, {review_link}.', 'cpbs-combined-extensions'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Review Email', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_email]" value="1" <?php checked((int) $settings['enable_email'], 1); ?> />
                                <?php echo esc_html__('Send review invite by email', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Review SMS', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_sms]" value="1" <?php checked((int) $settings['enable_sms'], 1); ?> />
                                <?php echo esc_html__('Send review invite by SMS', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-send-after"><?php echo esc_html__('Send After End (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-review-send-after" type="number" class="small-text" min="1" max="10080" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_after_minutes]" value="<?php echo esc_attr((string) $settings['send_after_minutes']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-window-days"><?php echo esc_html__('Send Window (days)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-review-window-days" type="number" class="small-text" min="1" max="365" name="<?php echo esc_attr(self::OPTION_KEY); ?>[send_window_days]" value="<?php echo esc_attr((string) $settings['send_window_days']); ?>" />
                            <p class="description"><?php echo esc_html__('Only send review invites for bookings that ended within this many days. Older bookings are skipped. Default: 7.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-page"><?php echo esc_html__('Review Form Page', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(array(
                                'name'              => self::OPTION_KEY . '[review_page_id]',
                                'id'                => 'cpbs-review-page',
                                'selected'          => (int) $settings['review_page_id'],
                                'show_option_none'  => __('— Not set —', 'cpbs-combined-extensions'),
                                'option_none_value' => 0,
                            )); ?>
                            <p class="description"><?php echo esc_html__('Page containing the [cpbs_booking_review_form] shortcode.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-email-subject"><?php echo esc_html__('Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-review-email-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_subject]" value="<?php echo esc_attr($settings['email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-email-body"><?php echo esc_html__('Email Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-review-email-body" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_body]"><?php echo esc_textarea($settings['email_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-sms-body"><?php echo esc_html__('SMS Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-review-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sms_body]"><?php echo esc_textarea($settings['sms_body']); ?></textarea></td>
                    </tr>
                </table>

                <h2 style="margin-top:24px"><?php echo esc_html__('Review Carousel Display', 'cpbs-combined-extensions'); ?></h2>
                <p><?php echo esc_html__('Default settings for the [cpbs_booking_reviews] shortcode. All attributes can still be overridden per-shortcode.', 'cpbs-combined-extensions'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cpbs-review-display-count"><?php echo esc_html__('Reviews to Show', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-review-display-count" type="number" class="small-text" min="-1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_count]" value="<?php echo esc_attr((string) $settings['display_count']); ?>" />
                            <p class="description"><?php echo esc_html__('-1 = show all reviews.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-min-rating"><?php echo esc_html__('Minimum Rating', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <select id="cpbs-review-min-rating" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_min_rating]">
                                <?php for ($i = 1; $i <= 5; $i++) : ?>
                                    <option value="<?php echo esc_attr((string) $i); ?>" <?php selected((int) $settings['display_min_rating'], $i); ?>><?php echo esc_html($i . ' ' . _n('star', 'stars', $i, 'cpbs-combined-extensions') . ' & above'); ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Show Location', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_show_location]" value="1" <?php checked((int) $settings['display_show_location'], 1); ?> />
                                <?php echo esc_html__('Display location name badge on each review card', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Show Date', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_show_date]" value="1" <?php checked((int) $settings['display_show_date'], 1); ?> />
                                <?php echo esc_html__('Display submission date on each review card', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Autoplay', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_autoplay]" value="1" <?php checked((int) $settings['display_autoplay'], 1); ?> />
                                <?php echo esc_html__('Automatically advance the carousel', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-review-autoplay-ms"><?php echo esc_html__('Autoplay Speed (seconds)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-review-autoplay-ms" type="number" class="small-text" min="1" max="60" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_autoplay_ms]" value="<?php echo esc_attr((string) (int) round((int) $settings['display_autoplay_ms'] / 1000)); ?>" />
                            <p class="description"><?php echo esc_html__('Seconds between slides when autoplay is enabled.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_cron_interval($schedules)
    {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 300,
                'display' => __('Every 5 Minutes (CPBS Booking Review)', 'cpbs-combined-extensions'),
            );
        }

        return $schedules;
    }

    public function schedule_cron()
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_event(time() + 120, self::CRON_INTERVAL, self::CRON_HOOK);
    }

    public function process_review_invites()
    {
        $settings = $this->get_settings();
        if ((int) $settings['review_page_id'] <= 0) {
            return;
        }

        $bookings = get_posts(
            array(
                'post_type' => $this->get_booking_post_type(),
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'suppress_filters' => true,
            )
        );

        $now = $this->site_now();
        $delay_minutes = (int) $settings['send_after_minutes'];

        foreach ((array) $bookings as $booking_id) {
            $booking_id = (int) $booking_id;
            if ($booking_id <= 0) {
                continue;
            }

            $sent_at = (string) $this->get_booking_meta_value($booking_id, 'review_invite_sent_at');
            if ($sent_at !== '') {
                continue;
            }

            if ($this->has_review_for_booking($booking_id)) {
                $this->update_booking_meta($booking_id, 'review_invite_sent_at', $now->format('Y-m-d H:i:s'));
                continue;
            }

            $meta = $this->get_booking_meta($booking_id);
            $exit = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');
            if (!($exit instanceof \DateTimeImmutable)) {
                continue;
            }

            // Skip bookings that ended outside the configured send window (backfill protection).
            $window_days = (int) $settings['send_window_days'];
            if ($window_days > 0) {
                $boundary = $now->modify('-' . $window_days . ' days');
                if ($exit < $boundary) {
                    // Mark as skipped so this booking is not retried on every cron run.
                    $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'skipped-too-old');
                    continue;
                }
            }

            $send_time = $exit->modify('+' . $delay_minutes . ' minutes');
            if (!($send_time instanceof \DateTimeImmutable) || $now < $send_time) {
                continue;
            }

            $contact = $this->get_booking_contact($booking_id, $meta);
            $review_link = $this->get_or_create_review_link($booking_id);
            if ($review_link === '') {
                continue;
            }

            $tokens = $this->build_tokens($booking_id, $meta, $exit, $review_link);
            $email_sent = false;
            $sms_sent = false;

            if ((int) $settings['enable_email'] === 1 && $contact['email'] !== '') {
                $subject = $this->replace_tokens($settings['email_subject'], $tokens);
                $body = $this->replace_tokens($settings['email_body'], $tokens);
                if ($subject !== '' && $body !== '') {
                    $email_sent = (bool) wp_mail($contact['email'], $subject, $body);
                }
            }

            if ((int) $settings['enable_sms'] === 1 && $contact['phone'] !== '') {
                $body = $this->replace_tokens($settings['sms_body'], $tokens);
                if ($body !== '') {
                    $sms_sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
                }
            }

            if ($email_sent || $sms_sent || ($contact['email'] === '' && $contact['phone'] === '')) {
                $this->update_booking_meta($booking_id, 'review_invite_sent_at', $now->format('Y-m-d H:i:s'));
            }
        }
    }

    public function maybe_handle_submission()
    {
        if (!isset($_POST['cpbs_review_submit'])) {
            return;
        }

        $booking_id = isset($_POST['booking_id']) ? absint(wp_unslash($_POST['booking_id'])) : 0;
        $token = isset($_POST['review_token']) ? sanitize_text_field(wp_unslash($_POST['review_token'])) : '';

        if (!isset($_POST['_cpbs_review_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_cpbs_review_nonce'])), self::NONCE_ACTION)) {
            $this->redirect_with_notice('failed', $booking_id, $token);
        }

        if (!$this->is_review_request_valid($booking_id, $token)) {
            $this->redirect_with_notice('failed', $booking_id, $token);
        }

        $existing_review_id = $this->get_review_id_for_booking($booking_id);
        if ($existing_review_id > 0) {
            $this->redirect_with_notice('duplicate', $booking_id, $token);
        }

        $rating = isset($_POST['rating']) ? (int) wp_unslash($_POST['rating']) : 0;
        if ($rating < 1 || $rating > 5) {
            $this->redirect_with_notice('invalid', $booking_id, $token);
        }

        $review_text = isset($_POST['review_text']) ? sanitize_textarea_field(wp_unslash($_POST['review_text'])) : '';

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking)) {
            $this->redirect_with_notice('failed', $booking_id, $token);
        }

        $meta = isset($booking['meta']) && is_array($booking['meta']) ? $booking['meta'] : array();
        $customer_name = $this->resolve_customer_name($meta);
        $customer_email = isset($meta['client_contact_detail_email_address']) ? sanitize_email((string) $meta['client_contact_detail_email_address']) : '';
        $location_id = isset($meta['location_id']) ? (int) $meta['location_id'] : 0;
        $location_name = $location_id > 0 ? (string) get_the_title($location_id) : '';

        $review_id = wp_insert_post(
            array(
                'post_type' => self::REVIEW_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf(__('Booking #%d Review', 'cpbs-combined-extensions'), $booking_id),
            ),
            true
        );

        if (is_wp_error($review_id) || $review_id <= 0) {
            $this->redirect_with_notice('failed', $booking_id, $token);
        }

        update_post_meta($review_id, 'booking_id', $booking_id);
        update_post_meta($review_id, 'rating', $rating);
        update_post_meta($review_id, 'review_text', $review_text);
        update_post_meta($review_id, 'customer_name', $customer_name);
        update_post_meta($review_id, 'customer_email', $customer_email);
        update_post_meta($review_id, 'location_id', $location_id);
        update_post_meta($review_id, 'location_name', $location_name);
        update_post_meta($review_id, 'entry_datetime_2', isset($meta['entry_datetime_2']) ? (string) $meta['entry_datetime_2'] : '');
        update_post_meta($review_id, 'exit_datetime_2', isset($meta['exit_datetime_2']) ? (string) $meta['exit_datetime_2'] : '');
        update_post_meta($review_id, 'review_token', $token);
        update_post_meta($review_id, 'submitted_at', gmdate('Y-m-d H:i:s'));

        $canonical_review_id = $this->enforce_single_review_for_booking($booking_id);
        if ($canonical_review_id !== (int) $review_id) {
            $this->redirect_with_notice('duplicate', $booking_id, $token);
        }

        $submitted_at = gmdate('Y-m-d H:i:s');
        $this->update_booking_meta($booking_id, 'review_submitted_at', $submitted_at);
        $this->update_booking_meta($booking_id, 'review_post_id', (int) $review_id);

        $this->redirect_with_notice('success', $booking_id, $token);
    }

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'booking_id' => 0,
                'review_token' => '',
            ),
            $atts,
            self::SHORTCODE
        );

        $booking_id = (int) $atts['booking_id'];
        if ($booking_id <= 0) {
            $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        }

        $token = (string) $atts['review_token'];
        if ($token === '') {
            $token = isset($_GET['review_token']) ? sanitize_text_field(wp_unslash($_GET['review_token'])) : '';
        }

        if (!$this->is_review_request_valid($booking_id, $token)) {
            return '<div class="cpbs-review-wrap"><p>' . esc_html__('This review link is invalid or expired.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking)) {
            return '<div class="cpbs-review-wrap"><p>' . esc_html__('Booking not found for this review request.', 'cpbs-combined-extensions') . '</p></div>';
        }

        $meta = isset($booking['meta']) && is_array($booking['meta']) ? $booking['meta'] : array();
        $name = $this->resolve_customer_name($meta);
        $email = isset($meta['client_contact_detail_email_address']) ? sanitize_email((string) $meta['client_contact_detail_email_address']) : '';
        $location_id = isset($meta['location_id']) ? (int) $meta['location_id'] : 0;
        $location_name = $location_id > 0 ? (string) get_the_title($location_id) : '';

        $notice_html = '';
        $notice = isset($_GET['cpbs_review_notice']) ? sanitize_key(wp_unslash($_GET['cpbs_review_notice'])) : '';
        if ($notice === 'success') {
            $notice_html = '<div class="cpbs-review-notice success">' . esc_html__('Thank you. Your review has been submitted.', 'cpbs-combined-extensions') . '</div>';
        } elseif ($notice === 'duplicate') {
            $notice_html = '<div class="cpbs-review-notice info">' . esc_html__('A review has already been submitted for this booking.', 'cpbs-combined-extensions') . '</div>';
        } elseif ($notice === 'invalid') {
            $notice_html = '<div class="cpbs-review-notice error">' . esc_html__('Please select a rating before submitting.', 'cpbs-combined-extensions') . '</div>';
        } elseif ($notice === 'failed') {
            $notice_html = '<div class="cpbs-review-notice error">' . esc_html__('We could not save your review. Please try again.', 'cpbs-combined-extensions') . '</div>';
        }

        if ($this->has_review_for_booking($booking_id)) {
            if ($notice === 'success') {
                $success_card = '<style>'
                    . '.cpbs-review-wrap{max-width:720px;margin:24px auto;font-family:"Segoe UI",Tahoma,sans-serif}'
                    . '.cpbs-review-success-wrap{padding:0;border:none;background:transparent;box-shadow:none}'
                    . '.cpbs-review-success-card{padding:32px 28px;border-radius:20px;background:linear-gradient(135deg,#2F5277 0%,#1e3a56 55%,#162d45 100%);box-shadow:0 20px 40px rgba(47,82,119,.28);color:#fff;text-align:center}'
                    . '.cpbs-review-success-icon{width:68px;height:68px;border-radius:999px;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;background:#FFCC00;border:2px solid #FFCC00;font-size:34px;font-weight:700;color:#2F5277}'
                    . '.cpbs-review-success-card h3{margin:0 0 10px;color:#FFCC00;font-size:30px;line-height:1.2}'
                    . '.cpbs-review-success-lead{margin:0 0 18px;font-size:16px;color:rgba(255,255,255,.92)}'
                    . '.cpbs-review-success-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:0 0 16px}'
                    . '.cpbs-review-success-meta span{padding:8px 12px;border-radius:999px;background:rgba(255,204,0,.18);border:1px solid rgba(255,204,0,.48);font-size:13px;font-weight:600;color:#FFCC00}'
                    . '.cpbs-review-success-note{margin:0;font-size:14px;color:rgba(255,255,255,.82)}'
                    . '</style>'
                    . '<div class="cpbs-review-wrap cpbs-review-success-wrap">'
                    . '<div class="cpbs-review-success-card">'
                    . '<div class="cpbs-review-success-icon" aria-hidden="true">&#10003;</div>'
                    . '<h3>' . esc_html__('Thank You For Your Review', 'cpbs-combined-extensions') . '</h3>'
                    . '<p class="cpbs-review-success-lead">' . esc_html__('Your feedback has been received successfully.', 'cpbs-combined-extensions') . '</p>'
                    . '<div class="cpbs-review-success-meta">'
                    . '<span>' . sprintf(esc_html__('Booking #%d', 'cpbs-combined-extensions'), $booking_id) . '</span>'
                    . ($location_name !== '' ? '<span>' . esc_html($location_name) . '</span>' : '')
                    . ($name !== '' ? '<span>' . esc_html($name) . '</span>' : '')
                    . '</div>'
                    . '<p class="cpbs-review-success-note">' . esc_html__('We appreciate you taking the time to share your experience.', 'cpbs-combined-extensions') . '</p>'
                    . '</div>'
                    . '</div>';

                return $success_card;
            }

            return '<div class="cpbs-review-wrap">' . $notice_html . '<p>' . esc_html__('A review has already been submitted for this booking. Thank you!', 'cpbs-combined-extensions') . '</p></div>';
        }

        ob_start();
        ?>
        <style>
            .cpbs-review-wrap{max-width:720px;margin:24px auto;padding:20px;border:1px solid #dde3ea;border-radius:12px;background:#fff;box-shadow:0 12px 28px rgba(18,38,63,.08);font-family:"Segoe UI",Tahoma,sans-serif}
            .cpbs-review-wrap h3{margin:0 0 12px;color:#102a43}
            .cpbs-review-success-wrap{padding:0;border:none;background:transparent;box-shadow:none}
            .cpbs-review-success-card{padding:32px 28px;border-radius:20px;background:linear-gradient(135deg,#2F5277 0%,#1e3a56 55%,#162d45 100%);box-shadow:0 20px 40px rgba(47,82,119,.28);color:#fff;text-align:center}
            .cpbs-review-success-icon{width:68px;height:68px;border-radius:999px;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;background:#FFCC00;border:2px solid #FFCC00;font-size:34px;font-weight:700;color:#2F5277}
            .cpbs-review-success-card h3{margin:0 0 10px;color:#FFCC00;font-size:30px;line-height:1.2}
            .cpbs-review-success-lead{margin:0 0 18px;font-size:16px;color:rgba(255,255,255,.92)}
            .cpbs-review-success-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:0 0 16px}
            .cpbs-review-success-meta span{padding:8px 12px;border-radius:999px;background:rgba(255,204,0,.18);border:1px solid rgba(255,204,0,.48);font-size:13px;font-weight:600;color:#FFCC00}
            .cpbs-review-success-note{margin:0;font-size:14px;color:rgba(255,255,255,.82)}
            .cpbs-review-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:16px}
            .cpbs-review-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px}
            .cpbs-review-item label{display:block;font-size:12px;color:#64748b;text-transform:uppercase;margin-bottom:4px}
            .cpbs-review-item input{width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:8px;background:#f1f5f9;color:#334155}
            .cpbs-review-rate{margin-bottom:4px}
            .cpbs-review-rating-select{width:100%;max-width:320px;border:1px solid #cbd5e1;border-radius:6px;padding:9px 12px;font-size:15px;background:#f1f5f9;color:#334155;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;margin-top:6px}
            .cpbs-review-rating-select:focus{outline:2px solid #2F5277;outline-offset:2px}
            .cpbs-review-comment textarea{width:100%;max-width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:8px;min-height:110px}
            .cpbs-review-notice{border-radius:8px;padding:10px 12px;margin:0 0 12px}
            .cpbs-review-notice.success{background:#eef6ff;border-left:4px solid #2F5277;color:#1e3a56}
            .cpbs-review-notice.error{background:#fff8e1;border-left:4px solid #e6a800;color:#7a5000}
            .cpbs-review-notice.info{background:#eef6ff;border-left:4px solid #2F5277;color:#1e3a56}
        </style>

        <div class="cpbs-review-wrap">
            <?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <h3><?php echo esc_html__('Share Your Experience', 'cpbs-combined-extensions'); ?></h3>
            <p><?php echo esc_html__('Your booking details are pre-filled and locked. Please rate your experience and leave a short review.', 'cpbs-combined-extensions'); ?></p>

            <form method="post" novalidate>
                <?php wp_nonce_field(self::NONCE_ACTION, '_cpbs_review_nonce'); ?>
                <input type="hidden" name="cpbs_review_submit" value="1" />
                <input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $booking_id); ?>" />
                <input type="hidden" name="review_token" value="<?php echo esc_attr($token); ?>" />

                <div class="cpbs-review-grid">
                    <div class="cpbs-review-item">
                        <label><?php echo esc_html__('Customer Name', 'cpbs-combined-extensions'); ?></label>
                        <input type="text" value="<?php echo esc_attr($name); ?>" readonly disabled />
                    </div>
                    <div class="cpbs-review-item">
                        <label><?php echo esc_html__('Email', 'cpbs-combined-extensions'); ?></label>
                        <input type="text" value="<?php echo esc_attr($email); ?>" readonly disabled />
                    </div>
                    <div class="cpbs-review-item">
                        <label><?php echo esc_html__('Location Booked', 'cpbs-combined-extensions'); ?></label>
                        <input type="text" value="<?php echo esc_attr($location_name); ?>" readonly disabled />
                    </div>
                    <div class="cpbs-review-item">
                        <label><?php echo esc_html__('Booking ID', 'cpbs-combined-extensions'); ?></label>
                        <input type="text" value="<?php echo esc_attr((string) $booking_id); ?>" readonly disabled />
                    </div>
                </div>

                <div class="cpbs-review-rate">
                    <label for="cpbs-review-rating"><?php echo esc_html__('Your Rating', 'cpbs-combined-extensions'); ?></label>
                    <select id="cpbs-review-rating" name="rating" class="cpbs-review-rating-select" required>
                        <option value=""><?php echo esc_html__('-- Select a Rating --', 'cpbs-combined-extensions'); ?></option>
                        <option value="5"><?php echo esc_html__('★★★★★', 'cpbs-combined-extensions'); ?></option>
                        <option value="4"><?php echo esc_html__('★★★★☆ ', 'cpbs-combined-extensions'); ?></option>
                        <option value="3"><?php echo esc_html__('★★★☆☆', 'cpbs-combined-extensions'); ?></option>
                        <option value="2"><?php echo esc_html__('★★☆☆☆', 'cpbs-combined-extensions'); ?></option>
                        <option value="1"><?php echo esc_html__('★☆☆☆☆', 'cpbs-combined-extensions'); ?></option>
                    </select>
                </div>

                <div class="cpbs-review-comment" style="margin-top:12px;">
                    <label for="cpbs-review-text"><?php echo esc_html__('Your Review', 'cpbs-combined-extensions'); ?></label>
                    <textarea id="cpbs-review-text" name="review_text" placeholder="<?php echo esc_attr__('Tell us about your experience...', 'cpbs-combined-extensions'); ?>"></textarea>
                </div>

                <p style="margin-top:14px;">
                    <button type="submit" style="background:#2F5277;color:#FFCC00;border:none;padding:11px 28px;border-radius:7px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.3px;transition:background .15s" onmouseover="this.style.background='#1e3a56'" onmouseout="this.style.background='#2F5277'"><?php echo esc_html__('Submit Review', 'cpbs-combined-extensions'); ?></button>
                </p>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Shortcode: [cpbs_booking_reviews count="10" location_id="0" min_rating="1" show_location="yes" show_date="yes" autoplay="yes" autoplay_ms="5000"]
     *
     * Renders published customer reviews as a carousel/slider on any page or widget area.
     * All attributes fall back to the defaults configured in CPBS Extensions > Booking Reviews.
     *   count         – number of reviews to display (-1 = all)
     *   location_id   – filter by a specific location post ID (0 = all)
     *   min_rating    – hide reviews below this star value, 1–5
     *   show_location – show location badge (yes/no)
     *   show_date     – show submission date (yes/no)
     *   autoplay      – auto-advance slides (yes/no)
     *   autoplay_ms   – milliseconds between slides when autoplay is on
     */
    public function render_reviews_shortcode($atts)
    {
        $settings = $this->get_settings();

        $atts = shortcode_atts(
            array(
                'count'        => (string) (int) $settings['display_count'],
                'location_id'  => '0',
                'min_rating'   => (string) (int) $settings['display_min_rating'],
                'show_location'=> (int) $settings['display_show_location'] ? 'yes' : 'no',
                'show_date'    => (int) $settings['display_show_date'] ? 'yes' : 'no',
                'autoplay'     => (int) $settings['display_autoplay'] ? 'yes' : 'no',
                'autoplay_ms'  => (string) (int) $settings['display_autoplay_ms'],
            ),
            $atts,
            self::DISPLAY_SHORTCODE
        );

        $count       = (int) $atts['count'];
        $location_id = (int) $atts['location_id'];
        $min_rating  = max(1, min(5, (int) $atts['min_rating']));
        $show_loc    = strtolower((string) $atts['show_location']) !== 'no';
        $show_date   = strtolower((string) $atts['show_date']) !== 'no';
        $autoplay    = strtolower((string) $atts['autoplay']) !== 'no';
        $autoplay_ms = max(1000, (int) $atts['autoplay_ms']);

        $query_args = array(
            'post_type'        => self::REVIEW_POST_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => $count < 1 ? -1 : $count,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        );

        $meta_query = array('relation' => 'AND');
        if ($min_rating > 1) {
            $meta_query[] = array('key' => 'rating', 'value' => $min_rating, 'compare' => '>=', 'type' => 'NUMERIC');
        }
        if ($location_id > 0) {
            $meta_query[] = array('key' => 'location_id', 'value' => $location_id, 'compare' => '=', 'type' => 'NUMERIC');
        }
        if (count($meta_query) > 1) {
            $query_args['meta_query'] = $meta_query;
        }

        $posts = get_posts($query_args);

        if (empty($posts)) {
            return '<div class="cpbs-reviews-carousel-wrap"><p class="cpbs-reviews-empty">' . esc_html__('No reviews yet. Be the first to share your experience!', 'cpbs-combined-extensions') . '</p></div>';
        }

        $uid         = 'cpbs-rc-' . substr(md5(serialize($atts)), 0, 8);
        $autoplay_js = $autoplay ? 'true' : 'false';

        ob_start();
        ?>
        <style>
        #<?php echo esc_attr($uid); ?>{--cpbs-slide-gap:20px;font-family:"Segoe UI",Tahoma,sans-serif;position:relative;margin:24px 0;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-track-outer{overflow:hidden;border-radius:16px;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-track{display:flex;gap:var(--cpbs-slide-gap);transition:transform .45s cubic-bezier(.25,.46,.45,.94);will-change:transform;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-slide{flex:0 0 calc(33.333% - var(--cpbs-slide-gap));min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 6px 20px rgba(18,38,63,.07);box-sizing:border-box;}
        @media(max-width:900px){#<?php echo esc_attr($uid); ?> .cpbs-rc-slide{flex:0 0 calc(50% - var(--cpbs-slide-gap));}}
        @media(max-width:580px){#<?php echo esc_attr($uid); ?> .cpbs-rc-slide{flex:0 0 100%;}}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-name{font-size:16px;font-weight:700;color:#102a43;margin:0 0 4px;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-stars{color:#f5a623;font-size:20px;letter-spacing:2px;margin:0 0 10px;display:block;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-badge{font-size:12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:20px;padding:2px 10px;color:#64748b;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-body{color:#334155;font-size:14px;line-height:1.7;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-controls{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:18px;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-btn{background:#fff;border:1px solid #cbd5e1;border-radius:50%;width:38px;height:38px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.08);transition:background .2s,border-color .2s;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-btn:hover{background:#f8fafc;border-color:#94a3b8;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-dots{display:flex;gap:7px;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-dot{width:9px;height:9px;border-radius:50%;background:#cbd5e1;border:none;padding:0;cursor:pointer;transition:background .2s,transform .2s;}
        #<?php echo esc_attr($uid); ?> .cpbs-rc-dot.active{background:#3b82f6;transform:scale(1.25);}
        </style>

        <div id="<?php echo esc_attr($uid); ?>" class="cpbs-reviews-carousel-wrap" role="region" aria-label="<?php echo esc_attr__('Customer Reviews', 'cpbs-combined-extensions'); ?>">
            <div class="cpbs-rc-track-outer">
                <div class="cpbs-rc-track" aria-live="polite">
                    <?php foreach ($posts as $review_post) :
                        $rid           = (int) $review_post->ID;
                        $rating        = (int) get_post_meta($rid, 'rating', true);
                        $review_text   = (string) get_post_meta($rid, 'review_text', true);
                        $customer_name = (string) get_post_meta($rid, 'customer_name', true);
                        $location_name = (string) get_post_meta($rid, 'location_name', true);
                        $submitted_at  = (string) get_post_meta($rid, 'submitted_at', true);

                        $customer_name = $customer_name !== '' ? $customer_name : __('Anonymous', 'cpbs-combined-extensions');
                        $stars_filled  = str_repeat('&#9733;', $rating);
                        $stars_empty   = str_repeat('&#9734;', max(0, 5 - $rating));

                        $date_display = '';
                        if ($show_date && $submitted_at !== '') {
                            $ts = strtotime($submitted_at);
                            if ($ts !== false) {
                                $date_display = date_i18n(get_option('date_format'), $ts);
                            }
                        }
                    ?>
                    <div class="cpbs-rc-slide" role="group" aria-label="<?php echo esc_attr(sprintf(__('Review by %s', 'cpbs-combined-extensions'), $customer_name)); ?>">
                        <p class="cpbs-rc-name"><?php echo esc_html($customer_name); ?></p>
                        <span class="cpbs-rc-stars" aria-label="<?php echo esc_attr($rating . ' out of 5 stars'); ?>"><?php echo wp_kses_post($stars_filled . $stars_empty); ?></span>
                        <?php if ($show_loc && $location_name !== '' || $show_date && $date_display !== '') : ?>
                        <div class="cpbs-rc-meta">
                            <?php if ($show_loc && $location_name !== '') : ?>
                                <span class="cpbs-rc-badge"><?php echo esc_html($location_name); ?></span>
                            <?php endif; ?>
                            <?php if ($show_date && $date_display !== '') : ?>
                                <span class="cpbs-rc-badge"><?php echo esc_html($date_display); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($review_text !== '') : ?>
                            <p class="cpbs-rc-body"><?php echo nl2br(esc_html($review_text)); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="cpbs-rc-controls">
                <button class="cpbs-rc-btn cpbs-rc-prev" aria-label="<?php echo esc_attr__('Previous reviews', 'cpbs-combined-extensions'); ?>">&#8592;</button>
                <div class="cpbs-rc-dots" role="tablist"></div>
                <button class="cpbs-rc-btn cpbs-rc-next" aria-label="<?php echo esc_attr__('Next reviews', 'cpbs-combined-extensions'); ?>">&#8594;</button>
            </div>
        </div>

        <script>
        (function () {
            'use strict';
            var wrap    = document.getElementById(<?php echo wp_json_encode($uid); ?>);
            if (!wrap) return;

            var track     = wrap.querySelector('.cpbs-rc-track');
            var slides    = wrap.querySelectorAll('.cpbs-rc-slide');
            var dotsWrap  = wrap.querySelector('.cpbs-rc-dots');
            var btnPrev   = wrap.querySelector('.cpbs-rc-prev');
            var btnNext   = wrap.querySelector('.cpbs-rc-next');
            var total     = slides.length;
            var current   = 0;
            var autoplay  = <?php echo $autoplay_js; ?>;
            var delay     = <?php echo (int) $autoplay_ms; ?>;
            var timer     = null;
            var gap       = parseInt(getComputedStyle(wrap).getPropertyValue('--cpbs-slide-gap')) || 20;

            function slidesVisible() {
                var w = wrap.querySelector('.cpbs-rc-track-outer').offsetWidth;
                if (w <= 580) return 1;
                if (w <= 900) return 2;
                return 3;
            }

            function maxIndex() {
                return Math.max(0, total - slidesVisible());
            }

            function goTo(idx) {
                idx = Math.max(0, Math.min(idx, maxIndex()));
                current = idx;
                var slideW = slides[0] ? slides[0].offsetWidth + gap : 0;
                track.style.transform = 'translateX(-' + (slideW * current) + 'px)';
                dots.forEach(function (d, i) {
                    d.classList.toggle('active', i === current);
                    d.setAttribute('aria-selected', i === current ? 'true' : 'false');
                });
            }

            function buildDots() {
                dotsWrap.innerHTML = '';
                dots = [];
                var pages = maxIndex() + 1;
                for (var i = 0; i < pages; i++) {
                    (function (idx) {
                        var d = document.createElement('button');
                        d.className = 'cpbs-rc-dot' + (idx === 0 ? ' active' : '');
                        d.setAttribute('role', 'tab');
                        d.setAttribute('aria-label', 'Go to slide ' + (idx + 1));
                        d.setAttribute('aria-selected', idx === 0 ? 'true' : 'false');
                        d.addEventListener('click', function () { stopAuto(); goTo(idx); startAuto(); });
                        dotsWrap.appendChild(d);
                        dots.push(d);
                    })(i);
                }
            }

            var dots = [];
            buildDots();
            goTo(0);

            btnPrev.addEventListener('click', function () { stopAuto(); goTo(current - 1); startAuto(); });
            btnNext.addEventListener('click', function () { stopAuto(); goTo(current < maxIndex() ? current + 1 : 0); startAuto(); });

            function startAuto() {
                if (!autoplay) return;
                stopAuto();
                timer = setTimeout(function tick() {
                    goTo(current < maxIndex() ? current + 1 : 0);
                    timer = setTimeout(tick, delay);
                }, delay);
            }

            function stopAuto() {
                if (timer) { clearTimeout(timer); timer = null; }
            }

            startAuto();
            wrap.addEventListener('mouseenter', stopAuto);
            wrap.addEventListener('mouseleave', startAuto);

            // Swipe / touch support.
            var touchStartX = null;
            track.addEventListener('touchstart', function (e) { touchStartX = e.touches[0].clientX; }, { passive: true });
            track.addEventListener('touchend', function (e) {
                if (touchStartX === null) return;
                var dx = e.changedTouches[0].clientX - touchStartX;
                touchStartX = null;
                if (Math.abs(dx) < 40) return;
                stopAuto();
                goTo(dx < 0 ? current + 1 : current - 1);
                startAuto();
            }, { passive: true });

            // Rebuild on resize.
            var resizeTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () { buildDots(); goTo(Math.min(current, maxIndex())); }, 120);
            });
        })();
        </script>
        <?php

        return (string) ob_get_clean();
    }

    private function get_default_settings()
    {
        return array(
            'enable_email'          => 1,
            'enable_sms'            => 0,
            'send_after_minutes'    => 60,
            'send_window_days'      => 7,
            'review_page_id'        => 0,
            'email_subject'         => 'How was your booking experience?',
            'email_body'            => 'Hi {customer_name}, we would love your feedback for booking #{booking_id} at {location_name}. Please review here: {review_link}',
            'sms_body'              => 'Please share your booking feedback: {review_link}',
            'display_count'         => 10,
            'display_min_rating'    => 1,
            'display_show_location' => 1,
            'display_show_date'     => 1,
            'display_autoplay'      => 1,
            'display_autoplay_ms'   => 5000,
        );
    }

    private function get_settings()
    {
        $stored = get_option(self::OPTION_KEY, array());
        $stored = is_array($stored) ? $stored : array();
        return wp_parse_args($stored, $this->get_default_settings());
    }

    public function register_review_meta_boxes()
    {
        add_meta_box(
            'cpbs_booking_review_details',
            __('Review Details', 'cpbs-combined-extensions'),
            array($this, 'render_review_meta_box'),
            self::REVIEW_POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_review_meta_box($post)
    {
        $review_id      = (int) $post->ID;
        $booking_id     = (int) get_post_meta($review_id, 'booking_id', true);
        $rating         = (int) get_post_meta($review_id, 'rating', true);
        $review_text    = (string) get_post_meta($review_id, 'review_text', true);
        $customer_name  = (string) get_post_meta($review_id, 'customer_name', true);
        $customer_email = (string) get_post_meta($review_id, 'customer_email', true);
        $location_name  = (string) get_post_meta($review_id, 'location_name', true);
        $entry_dt       = (string) get_post_meta($review_id, 'entry_datetime_2', true);
        $exit_dt        = (string) get_post_meta($review_id, 'exit_datetime_2', true);
        $submitted_at   = (string) get_post_meta($review_id, 'submitted_at', true);

        $stars = str_repeat('&#9733;', $rating) . str_repeat('&#9734;', max(0, 5 - $rating));

        $booking_edit_link = $booking_id > 0 ? get_edit_post_link($booking_id) : '';
        ?>
        <style>
            .cpbs-review-meta-table{width:100%;border-collapse:collapse;}
            .cpbs-review-meta-table th{width:180px;font-weight:600;text-align:left;padding:8px 12px;background:#f6f7f7;border:1px solid #e0e0e0;vertical-align:top;}
            .cpbs-review-meta-table td{padding:8px 12px;border:1px solid #e0e0e0;vertical-align:top;}
            .cpbs-review-meta-table .cpbs-stars{color:#f5a623;font-size:20px;letter-spacing:2px;}
            .cpbs-review-meta-table .cpbs-review-body{white-space:pre-wrap;}
        </style>
        <table class="cpbs-review-meta-table">
            <tr>
                <th><?php echo esc_html__('Rating', 'cpbs-combined-extensions'); ?></th>
                <td><span class="cpbs-stars"><?php echo wp_kses_post($stars); ?></span> <?php echo esc_html($rating . '/5'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Review', 'cpbs-combined-extensions'); ?></th>
                <td><span class="cpbs-review-body"><?php echo nl2br(esc_html($review_text !== '' ? $review_text : '—')); ?></span></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Customer Name', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($customer_name !== '' ? $customer_name : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Customer Email', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($customer_email !== '' ? $customer_email : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Location', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($location_name !== '' ? $location_name : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Entry Date/Time', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($entry_dt !== '' ? $entry_dt : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Exit Date/Time', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($exit_dt !== '' ? $exit_dt : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Submitted At', 'cpbs-combined-extensions'); ?></th>
                <td><?php echo esc_html($submitted_at !== '' ? $submitted_at : '—'); ?></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Booking', 'cpbs-combined-extensions'); ?></th>
                <td>
                    <?php if ($booking_id > 0 && $booking_edit_link) : ?>
                        <a href="<?php echo esc_url($booking_edit_link); ?>" target="_blank">
                            <?php echo esc_html(sprintf(__('Booking #%d', 'cpbs-combined-extensions'), $booking_id)); ?>
                        </a>
                    <?php elseif ($booking_id > 0) : ?>
                        <?php echo esc_html(sprintf(__('Booking #%d', 'cpbs-combined-extensions'), $booking_id)); ?>
                    <?php else : ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    private function sanitize_minutes($value, $min, $max, $fallback)
    {
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private function get_booking($booking_id)
    {
        if (!class_exists('CPBSBooking')) {
            return null;
        }

        $model = new \CPBSBooking();
        if (!method_exists($model, 'getBooking')) {
            return null;
        }

        $booking = $model->getBooking($booking_id);
        if ($booking === false || !is_array($booking)) {
            return null;
        }

        return $booking;
    }

    private function has_review_for_booking($booking_id)
    {
        return $this->get_review_id_for_booking($booking_id) > 0;
    }

    private function get_review_id_for_booking($booking_id)
    {
        $items = $this->get_review_ids_for_booking($booking_id, 1);

        return !empty($items) ? (int) $items[0] : 0;
    }

    private function get_review_ids_for_booking($booking_id, $limit = -1)
    {
        $numberposts = (int) $limit;
        if ($numberposts === 0) {
            $numberposts = 1;
        }

        return get_posts(
            array(
                'post_type' => self::REVIEW_POST_TYPE,
                'post_status' => 'any',
                'numberposts' => $numberposts,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_query' => array(
                    array(
                        'key' => 'booking_id',
                        'value' => (int) $booking_id,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
            )
        );
    }

    private function enforce_single_review_for_booking($booking_id)
    {
        $review_ids = $this->get_review_ids_for_booking($booking_id, -1);
        if (empty($review_ids)) {
            return 0;
        }

        $canonical_review_id = (int) $review_ids[0];

        foreach ($review_ids as $review_id) {
            $review_id = (int) $review_id;
            if ($review_id <= 0 || $review_id === $canonical_review_id) {
                continue;
            }

            wp_delete_post($review_id, true);
        }

        $this->update_booking_meta($booking_id, 'review_post_id', $canonical_review_id);

        return $canonical_review_id;
    }

    private function is_review_request_valid($booking_id, $token)
    {
        if ($booking_id <= 0 || !is_string($token) || $token === '') {
            return false;
        }

        if (!$this->is_booking_post($booking_id)) {
            return false;
        }

        $stored = (string) $this->get_booking_meta_value($booking_id, 'review_request_token');
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    private function get_or_create_review_link($booking_id)
    {
        $settings = $this->get_settings();
        $page_id = isset($settings['review_page_id']) ? (int) $settings['review_page_id'] : 0;
        if ($page_id <= 0) {
            return '';
        }

        $url = get_permalink($page_id);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $token = (string) $this->get_booking_meta_value($booking_id, 'review_request_token');
        if ($token === '') {
            $token = wp_generate_password(24, false, false);
            $this->update_booking_meta($booking_id, 'review_request_token', $token);
        }

        return add_query_arg(
            array(
                'booking_id' => (int) $booking_id,
                'review_token' => $token,
            ),
            $url
        );
    }

    private function build_tokens($booking_id, $meta, \DateTimeImmutable $exit, $review_link)
    {
        $location_id = isset($meta['location_id']) ? (int) $meta['location_id'] : 0;
        $location_name = $location_id > 0 ? (string) get_the_title($location_id) : '';

        $customer_name = $this->resolve_customer_name($meta);
        if ($customer_name === '') {
            $customer_name = __('Customer', 'cpbs-combined-extensions');
        }

        return array(
            '{customer_name}' => $customer_name,
            '[customer_name]' => $customer_name,
            '{booking_id}' => (string) $booking_id,
            '[booking_id]' => (string) $booking_id,
            '{booking_end}' => $exit->format('Y-m-d H:i:s'),
            '[booking_end]' => $exit->format('Y-m-d H:i:s'),
            '{location_name}' => $location_name,
            '[location_name]' => $location_name,
            '{review_link}' => (string) $review_link,
            '[review_link]' => (string) $review_link,
        );
    }

    private function replace_tokens($template, $tokens)
    {
        return str_replace(array_keys($tokens), array_values($tokens), (string) $template);
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
            get_post_meta($booking_id, 'cpbs_client_contact_detail_phone_number', true),
        );

        foreach ($phone_sources as $candidate) {
            $candidate = $this->normalize_phone_number((string) $candidate);
            if ($candidate !== '') {
                $phone = $candidate;
                break;
            }
        }

        return array(
            'email' => $email,
            'phone' => $phone,
        );
    }

    private function resolve_customer_name($meta)
    {
        $name = '';
        if (!empty($meta['client_contact_detail_first_name']) || !empty($meta['client_contact_detail_last_name'])) {
            $name = trim((string) $meta['client_contact_detail_first_name'] . ' ' . (string) $meta['client_contact_detail_last_name']);
        }

        if ($name === '' && !empty($meta['client_contact_detail_name'])) {
            $name = (string) $meta['client_contact_detail_name'];
        }

        return $name;
    }

    private function redirect_with_notice($notice, $booking_id, $token)
    {
        $redirect = remove_query_arg(array('cpbs_review_notice'));
        $redirect = add_query_arg(
            array(
                'booking_id' => (int) $booking_id,
                'review_token' => (string) $token,
                'cpbs_review_notice' => sanitize_key($notice),
            ),
            $redirect
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function send_twilio_sms($to_phone, $message_body)
    {
        $sms_settings = get_option(self::SMS_SETTINGS_OPTION_KEY, array());
        $sms_settings = is_array($sms_settings) ? $sms_settings : array();

        $account_sid = isset($sms_settings['twilio_account_sid']) ? trim((string) $sms_settings['twilio_account_sid']) : '';
        $auth_token = isset($sms_settings['twilio_auth_token']) ? trim((string) $sms_settings['twilio_auth_token']) : '';
        $from_phone = $this->normalize_phone_number(isset($sms_settings['twilio_from_number']) ? (string) $sms_settings['twilio_from_number'] : '');

        if ($account_sid === '' || $auth_token === '' || $from_phone === '' || trim((string) $message_body) === '') {
            return false;
        }

        $response = wp_remote_post(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($account_sid) . '/Messages.json',
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

    private function normalize_phone_number($raw_phone)
    {
        $raw_phone = trim((string) $raw_phone);
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

        return \DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized_datetime, wp_timezone());
    }

    private function site_now()
    {
        return new \DateTimeImmutable('now', wp_timezone());
    }

    private function get_booking_post_type()
    {
        return defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_booking' : 'cpbs_booking';
    }

    private function get_meta_prefix()
    {
        return defined('PLUGIN_CPBS_CONTEXT') ? PLUGIN_CPBS_CONTEXT . '_' : 'cpbs_';
    }

    private function is_booking_post($post_id)
    {
        return get_post_type($post_id) === $this->get_booking_post_type();
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

    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
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

register_deactivation_hook(__FILE__, array('CPBSCombinedBookingAutomation', 'unschedule_cron'));
register_deactivation_hook(__FILE__, array('CPBSCombinedBookingReview', 'unschedule_cron'));
