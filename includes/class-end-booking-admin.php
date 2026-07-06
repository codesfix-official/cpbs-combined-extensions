<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// End Booking Early
// ============================================================================
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
    const AJAX_ACTION_CONFIRM = 'cpbs_combined_confirm_booking';

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
        add_action('wp_ajax_' . self::AJAX_ACTION_CONFIRM, array($this, 'ajax_confirm_booking'));
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

        if (!$this->is_booking_confirmed($post_id)) {
            echo '<button type="button" class="button cpbs-confirm-booking-button" style="margin-bottom:4px;display:block" data-booking-id="' . esc_attr($post_id) . '">' . esc_html__('Confirm Booking', 'car-park-booking-system') . '</button>';
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
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'action'        => self::AJAX_ACTION,
            'nonce'         => wp_create_nonce(self::NONCE_ACTION),
            'confirmAction' => self::AJAX_ACTION_CONFIRM,
            'confirmNonce'  => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => array(
                'confirm'             => esc_html__('End this booking now? The exit time will be changed to the current site time.', 'car-park-booking-system'),
                'processing'          => esc_html__('Ending...', 'car-park-booking-system'),
                'button'              => esc_html__('End Booking', 'car-park-booking-system'),
                'genericError'        => esc_html__('The booking could not be ended.', 'car-park-booking-system'),
                'confirmBooking'      => esc_html__('Confirm this booking as occupied?', 'car-park-booking-system'),
                'confirmProcessing'   => esc_html__('Confirming...', 'car-park-booking-system'),
                'confirmButton'       => esc_html__('Confirm Booking', 'car-park-booking-system'),
                'confirmGenericError' => esc_html__('The booking could not be confirmed.', 'car-park-booking-system'),
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

    public function ajax_confirm_booking()
    {
        if (!$this->current_user_can_end_bookings()) {
            wp_send_json_error(array('message' => esc_html__('You are not allowed to confirm bookings.', 'car-park-booking-system')), 403);
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
            wp_send_json_error(array('message' => esc_html__('Only active bookings can be confirmed.', 'car-park-booking-system')), 409);
        }

        if ($this->is_booking_confirmed($booking_id)) {
            wp_send_json_error(array('message' => esc_html__('This booking is already confirmed.', 'car-park-booking-system')), 409);
        }

        $now          = new \DateTimeImmutable('now', wp_timezone());
        $now_formatted = $now->format('Y-m-d H:i:s');

        $this->update_booking_meta($booking_id, 'automation_confirm_source', 'admin');
        $this->update_booking_meta($booking_id, 'automation_confirmed_at', $now_formatted);
        $this->update_booking_meta($booking_id, 'automation_status', 'occupied');
        $this->update_booking_meta($booking_id, 'automation_tracking_clicked_at', $now_formatted);

        do_action('cpbs_combined_booking_confirmed_by_admin', $booking_id, $now);

        wp_send_json_success(
            array(
                'bookingId'   => $booking_id,
                'confirmedAt' => $now_formatted,
                'message'     => esc_html__('Booking confirmed successfully.', 'car-park-booking-system'),
            )
        );
    }

    private function is_booking_confirmed($booking_id)
    {
        $meta           = $this->get_booking_meta($booking_id);
        $clicked_at     = isset($meta['automation_tracking_clicked_at']) ? (string) $meta['automation_tracking_clicked_at'] : '';
        $confirm_source = isset($meta['automation_confirm_source']) ? (string) $meta['automation_confirm_source'] : '';

        return $clicked_at !== '' || $confirm_source !== '';
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
        return CPBSCombinedHelpers::build_site_datetime($normalized_datetime);
    }

    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
    }

    private function get_meta_prefix()
    {
        return CPBSCombinedHelpers::get_meta_prefix();
    }

    private function get_booking_meta($booking_id)
    {
        return CPBSCombinedHelpers::get_booking_meta($booking_id);
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        CPBSCombinedHelpers::update_booking_meta($booking_id, $key, $value);
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
        return CPBSCombinedHelpers::extract_phone_from_mixed($value);
    }

    private function looks_like_phone_number($value)
    {
        return CPBSCombinedHelpers::looks_like_phone_number($value);
    }

    private function normalize_phone_number($raw_phone)
    {
        return CPBSCombinedHelpers::normalize_phone_number($raw_phone);
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
        return CPBSCombinedHelpers::send_twilio_sms($to_phone, $message_body);
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
        return CPBSCombinedHelpers::get_sms_settings();
    }

    private function is_feature_enabled($feature_key, $default)
    {
        return CPBSCombinedHelpers::is_feature_enabled($feature_key, $default);
    }
}



