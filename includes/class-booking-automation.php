<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Booking Automation
// ============================================================================
final class CPBSCombinedBookingAutomation
{
    const OPTION_KEY = 'cpbs_combined_booking_automation_settings';
    const SETTINGS_GROUP = 'cpbs_combined_booking_automation_group';
    const SETTINGS_PAGE_SLUG = 'cpbs-combined-booking-automation';
    const CRON_HOOK = 'cpbs_combined_booking_automation_cron';
    const CRON_INTERVAL = 'cpbs_combined_booking_automation_interval';
    const CRON_INTERVAL_DEFAULT_SECONDS = 60;
    const CRON_INTERVAL_MIN_SECONDS = 60;
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
        add_action('init', array($this, 'maybe_handle_stripe_webhooks'));
        add_action(self::CRON_HOOK, array($this, 'process_booking_automation'));
        add_action('wp_mail_failed', array($this, 'handle_wp_mail_failed'), 10, 1);
        add_action('wp_mail_succeeded', array($this, 'handle_wp_mail_succeeded'), 10, 1);
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        // Enforce Status 1 (Pending) for unpaid bookings on save
        add_action('save_post_' . $this->get_booking_post_type(), array($this, 'enforce_pending_status_for_unpaid'), 50, 3);
        add_action('added_post_meta', array($this, 'normalize_booking_status_after_meta_write'), 20, 4);
        add_action('updated_post_meta', array($this, 'normalize_booking_status_after_meta_write'), 20, 4);

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_tracking_columns'), 30);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_tracking_columns'), 10, 2);
        add_action('added_post_meta', array($this, 'maybe_send_initial_sms_on_meta_added'), 10, 4);
    }

    public function register_cron_interval($schedules)
    {
        $interval_seconds = $this->get_cron_interval_seconds();

        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => $interval_seconds,
                'display' => sprintf(
                    /* translators: %d: number of seconds */
                    __('Every %d Seconds (CPBS Booking Automation)', 'cpbs-combined-extensions'),
                    (int) $interval_seconds
                ),
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

    private function get_cron_interval_seconds()
    {
        $seconds = (int) apply_filters(
            'cpbs_combined_booking_automation_cron_interval_seconds',
            self::CRON_INTERVAL_DEFAULT_SECONDS
        );

        if ($seconds < self::CRON_INTERVAL_MIN_SECONDS) {
            return self::CRON_INTERVAL_MIN_SECONDS;
        }

        return $seconds;
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
            'end_notice_minutes_before' => $this->sanitize_minutes(isset($input['end_notice_minutes_before']) ? $input['end_notice_minutes_before'] : 10, 1, 720, 10),
            'after_end_followup_minutes' => $this->sanitize_minutes(isset($input['after_end_followup_minutes']) ? $input['after_end_followup_minutes'] : 5, 1, 720, 5),
            'followup_send_window_minutes' => $this->sanitize_minutes(isset($input['followup_send_window_minutes']) ? $input['followup_send_window_minutes'] : 120, 1, 1440, 120),
            'end_email_subject' => sanitize_text_field(isset($input['end_email_subject']) ? wp_unslash($input['end_email_subject']) : ''),
            'end_email_body' => sanitize_textarea_field(isset($input['end_email_body']) ? wp_unslash($input['end_email_body']) : ''),
            'end_sms_body' => sanitize_textarea_field(isset($input['end_sms_body']) ? wp_unslash($input['end_sms_body']) : ''),
            'follow_email_subject' => sanitize_text_field(isset($input['follow_email_subject']) ? wp_unslash($input['follow_email_subject']) : ''),
            'follow_email_body' => sanitize_textarea_field(isset($input['follow_email_body']) ? wp_unslash($input['follow_email_body']) : ''),
            'follow_sms_body' => sanitize_textarea_field(isset($input['follow_sms_body']) ? wp_unslash($input['follow_sms_body']) : ''),
            'track_page_message' => sanitize_textarea_field(isset($input['track_page_message']) ? wp_unslash($input['track_page_message']) : ''),
            'extension_page_id' => absint(isset($input['extension_page_id']) ? $input['extension_page_id'] : 0),
            'booking_extension_webhook_secret' => sanitize_text_field(isset($input['booking_extension_webhook_secret']) ? wp_unslash($input['booking_extension_webhook_secret']) : ''),
            'initial_sms_body' => sanitize_textarea_field(isset($input['initial_sms_body']) ? wp_unslash($input['initial_sms_body']) : ''),
            'reminder1_delay_minutes' => $this->sanitize_minutes(isset($input['reminder1_delay_minutes']) ? $input['reminder1_delay_minutes'] : 60, 1, 1440, 60),
            'reminder1_sms_body' => sanitize_textarea_field(isset($input['reminder1_sms_body']) ? wp_unslash($input['reminder1_sms_body']) : ''),
            'reminder2_delay_minutes' => $this->sanitize_minutes(isset($input['reminder2_delay_minutes']) ? $input['reminder2_delay_minutes'] : 120, 1, 1440, 120),
            'reminder2_sms_body' => sanitize_textarea_field(isset($input['reminder2_sms_body']) ? wp_unslash($input['reminder2_sms_body']) : ''),
            'pending_booking_timeout_minutes' => $this->sanitize_minutes(isset($input['pending_booking_timeout_minutes']) ? $input['pending_booking_timeout_minutes'] : 15, 1, 720, 15),
            'noshow_grace_period_minutes' => $this->sanitize_minutes(isset($input['noshow_grace_period_minutes']) ? $input['noshow_grace_period_minutes'] : 30, 1, 720, 30),
            'noshow_enable' => $this->sanitize_checkbox(isset($input['noshow_enable']) ? $input['noshow_enable'] : 0),
            'final_warning_enable' => $this->sanitize_checkbox(isset($input['final_warning_enable']) ? $input['final_warning_enable'] : 0),
            'final_warning_delay_minutes' => $this->sanitize_minutes(isset($input['final_warning_delay_minutes']) ? $input['final_warning_delay_minutes'] : 20, 1, 720, 20),
            'final_warning_sms_body' => sanitize_textarea_field(isset($input['final_warning_sms_body']) ? wp_unslash($input['final_warning_sms_body']) : ''),
            'cancellation_cutoff_hours' => $this->sanitize_minutes(isset($input['cancellation_cutoff_hours']) ? $input['cancellation_cutoff_hours'] : 2, 1, 168, 2),
            'cancellation_email_subject' => sanitize_text_field(isset($input['cancellation_email_subject']) ? wp_unslash($input['cancellation_email_subject']) : ''),
            'cancellation_refund_email_body' => sanitize_textarea_field(isset($input['cancellation_refund_email_body']) ? wp_unslash($input['cancellation_refund_email_body']) : ''),
            'cancellation_no_refund_email_body' => sanitize_textarea_field(isset($input['cancellation_no_refund_email_body']) ? wp_unslash($input['cancellation_no_refund_email_body']) : ''),
            'cancellation_admin_email_subject' => sanitize_text_field(isset($input['cancellation_admin_email_subject']) ? wp_unslash($input['cancellation_admin_email_subject']) : ''),
            'cancellation_admin_email_body' => sanitize_textarea_field(isset($input['cancellation_admin_email_body']) ? wp_unslash($input['cancellation_admin_email_body']) : ''),
            'cancellation_refund_sms' => sanitize_textarea_field(isset($input['cancellation_refund_sms']) ? wp_unslash($input['cancellation_refund_sms']) : ''),
            'cancellation_no_refund_sms' => sanitize_textarea_field(isset($input['cancellation_no_refund_sms']) ? wp_unslash($input['cancellation_no_refund_sms']) : ''),
            'new_account_email_subject' => sanitize_text_field(isset($input['new_account_email_subject']) ? wp_unslash($input['new_account_email_subject']) : ''),
            'new_account_email_body' => sanitize_textarea_field(isset($input['new_account_email_body']) ? wp_unslash($input['new_account_email_body']) : ''),
            'new_account_sms_body' => sanitize_textarea_field(isset($input['new_account_sms_body']) ? wp_unslash($input['new_account_sms_body']) : ''),
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

                    <tr><th colspan="2"><h2><?php echo esc_html__('Confirmation SMS Settings', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-initial-sms-body"><?php echo esc_html__('Initial Confirmation SMS', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-initial-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[initial_sms_body]"><?php echo esc_textarea($settings['initial_sms_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Sent instantly when a booking is created. Use {tracking_link} to embed the confirmation link.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-reminder1-delay"><?php echo esc_html__('Reminder 1 Delay (minutes after initial SMS)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-reminder1-delay" type="number" class="small-text" min="1" max="1440" name="<?php echo esc_attr(self::OPTION_KEY); ?>[reminder1_delay_minutes]" value="<?php echo esc_attr((string) $settings['reminder1_delay_minutes']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-reminder1-sms"><?php echo esc_html__('Reminder SMS #1', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-reminder1-sms" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[reminder1_sms_body]"><?php echo esc_textarea($settings['reminder1_sms_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-reminder2-delay"><?php echo esc_html__('Reminder 2 Delay (minutes after initial SMS)', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-reminder2-delay" type="number" class="small-text" min="1" max="1440" name="<?php echo esc_attr(self::OPTION_KEY); ?>[reminder2_delay_minutes]" value="<?php echo esc_attr((string) $settings['reminder2_delay_minutes']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-reminder2-sms"><?php echo esc_html__('Reminder SMS #2', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-reminder2-sms" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[reminder2_sms_body]"><?php echo esc_textarea($settings['reminder2_sms_body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Automatic No-Show Processing', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[noshow_enable]" value="1" <?php checked((int) $settings['noshow_enable'], 1); ?> />
                                <?php echo esc_html__('Automatically mark unconfirmed bookings as No-Show and release the parking space.', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-noshow-grace"><?php echo esc_html__('No-Show Grace Period (minutes after booking start)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-noshow-grace" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[noshow_grace_period_minutes]" value="<?php echo esc_attr((string) $settings['noshow_grace_period_minutes']); ?>" />
                            <p class="description"><?php echo esc_html__('If the confirmation link is not clicked by this many minutes after booking start, the booking is marked as No-Show.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable Final Warning SMS', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[final_warning_enable]" value="1" <?php checked((int) $settings['final_warning_enable'], 1); ?> />
                                <?php echo esc_html__('Send a warning SMS before the No-Show deadline.', 'cpbs-combined-extensions'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-final-warning-delay"><?php echo esc_html__('Final Warning SMS Timing (minutes after booking start)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-final-warning-delay" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[final_warning_delay_minutes]" value="<?php echo esc_attr((string) $settings['final_warning_delay_minutes']); ?>" />
                            <p class="description"><?php echo esc_html__('Must be less than the No-Show Grace Period.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-final-warning-sms"><?php echo esc_html__('Final Warning SMS', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-final-warning-sms" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[final_warning_sms_body]"><?php echo esc_textarea($settings['final_warning_sms_body']); ?></textarea></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('Pending Booking Timeout', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-pending-timeout"><?php echo esc_html__('Abandon Unpaid Bookings After (minutes)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-pending-timeout" type="number" class="small-text" min="1" max="720" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pending_booking_timeout_minutes]" value="<?php echo esc_attr((string) $settings['pending_booking_timeout_minutes']); ?>" />
                            <p class="description"><?php echo esc_html__('Automatically release parking slots for pending bookings that have not received payment confirmation. This allows users who abandon their payment to retry booking. Default: 15 minutes. No notifications are sent.', 'cpbs-combined-extensions'); ?></p>
                        </td>
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
                    <tr>
                        <th scope="row"><?php echo esc_html__('Stripe Webhook Endpoint URL', 'cpbs-combined-extensions'); ?></th>
                        <td>
                            <code style="display:inline-block;padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;"><?php echo esc_html(rest_url('cpbs-combined/v1/booking-extension-webhook')); ?></code>
                            <p class="description"><?php echo esc_html__('Add this URL in Stripe. Do not paste it into the secret field.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-extension-webhook-secret"><?php echo esc_html__('Stripe Webhook Signing Secret', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-extension-webhook-secret" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[booking_extension_webhook_secret]" value="<?php echo esc_attr($settings['booking_extension_webhook_secret']); ?>" autocomplete="off" />
                            <p class="description"><?php echo esc_html__('Paste the Stripe signing secret here. It usually starts with `whsec_`.', 'cpbs-combined-extensions'); ?></p>
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

                    <tr><th colspan="2"><h2><?php echo esc_html__('Booking Cancellation - Email Templates', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-email-subject"><?php echo esc_html__('Cancellation Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-cancellation-email-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_email_subject]" value="<?php echo esc_attr($settings['cancellation_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-refund-email"><?php echo esc_html__('Cancellation Email (Refund Eligible)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-cancellation-refund-email" class="large-text" rows="5" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_refund_email_body]"><?php echo esc_textarea($settings['cancellation_refund_email_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Sent to customer when cancellation is within cutoff period. Placeholders: {customer_name}, {booking_id}, {location_name}, {booking_start}, {booking_end}, {timestamp}', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-no-refund-email"><?php echo esc_html__('Cancellation Email (No Refund)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-cancellation-no-refund-email" class="large-text" rows="5" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_no_refund_email_body]"><?php echo esc_textarea($settings['cancellation_no_refund_email_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Sent to customer when cancellation is after cutoff period. Placeholders: {customer_name}, {booking_id}, {location_name}, {booking_start}, {booking_end}, {timestamp}', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-admin-subject"><?php echo esc_html__('Admin Notification Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-cancellation-admin-subject" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_admin_email_subject]" value="<?php echo esc_attr($settings['cancellation_admin_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-admin-email"><?php echo esc_html__('Admin Notification Email', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-cancellation-admin-email" class="large-text" rows="5" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_admin_email_body]"><?php echo esc_textarea($settings['cancellation_admin_email_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Sent to admin when customer cancels. Placeholders: {customer_name}, {customer_email}, {booking_id}, {location_name}, {booking_start}, {booking_end}, {refund_eligible}, {timestamp}', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('New Account Setup', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-new-account-email-subject"><?php echo esc_html__('Welcome Email Subject', 'cpbs-combined-extensions'); ?></label></th>
                        <td><input id="cpbs-new-account-email-subject" type="text" class="large-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[new_account_email_subject]" value="<?php echo esc_attr($settings['new_account_email_subject']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-new-account-email-body"><?php echo esc_html__('Welcome Email Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-new-account-email-body" class="large-text" rows="6" name="<?php echo esc_attr(self::OPTION_KEY); ?>[new_account_email_body]"><?php echo esc_textarea($settings['new_account_email_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Placeholders: {customer_name}, {password_reset_link}', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-new-account-sms-body"><?php echo esc_html__('Welcome SMS Body', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <textarea id="cpbs-new-account-sms-body" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[new_account_sms_body]"><?php echo esc_textarea($settings['new_account_sms_body']); ?></textarea>
                            <p class="description"><?php echo esc_html__('Placeholders: {customer_name}, {support_link}', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php echo esc_html__('Cancellation SMS Settings', 'cpbs-combined-extensions'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-cutoff"><?php echo esc_html__('Cancellation Cutoff (hours before start)', 'cpbs-combined-extensions'); ?></label></th>
                        <td>
                            <input id="cpbs-cancellation-cutoff" type="number" class="small-text" min="1" max="168" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_cutoff_hours]" value="<?php echo esc_attr((string) $settings['cancellation_cutoff_hours']); ?>" />
                            <p class="description"><?php echo esc_html__('Hours before reservation start time within which cancellation and refund eligibility are no longer available. Default: 2.', 'cpbs-combined-extensions'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-refund-sms"><?php echo esc_html__('Refund Eligible SMS', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-cancellation-refund-sms" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_refund_sms]"><?php echo esc_textarea($settings['cancellation_refund_sms']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpbs-cancellation-no-refund-sms"><?php echo esc_html__('No Refund SMS', 'cpbs-combined-extensions'); ?></label></th>
                        <td><textarea id="cpbs-cancellation-no-refund-sms" class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cancellation_no_refund_sms]"><?php echo esc_textarea($settings['cancellation_no_refund_sms']); ?></textarea></td>
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
            $is_noshow = $this->get_booking_meta_value($post_id, 'automation_noshow') === '1';
            if ($is_noshow) {
                echo esc_html__('No-Show', 'cpbs-combined-extensions');
                return;
            }

            $status = (string) $this->get_booking_meta_value($post_id, 'automation_status');
            if ($status === '') {
                $status = 'pending';
            }

            echo esc_html(ucfirst(str_replace('_', ' ', $status)));
            return;
        }

        if ($column === self::CLICKED_COLUMN_KEY) {
            $clicked        = (string) $this->get_booking_meta_value($post_id, 'automation_tracking_clicked_at');
            $confirm_source = (string) $this->get_booking_meta_value($post_id, 'automation_confirm_source');

            if ($clicked !== '') {
                echo esc_html($clicked);
            } elseif ($confirm_source === 'admin') {
                $confirmed_at = (string) $this->get_booking_meta_value($post_id, 'automation_confirmed_at');
                echo esc_html($confirmed_at !== '' ? $confirmed_at . ' (' . __('Admin', 'cpbs-combined-extensions') . ')' : __('Admin', 'cpbs-combined-extensions'));
            } else {
                echo esc_html__('No', 'cpbs-combined-extensions');
            }
        }
    }

    public function maybe_handle_tracking_link()
    {
        $track_flag = $this->get_request_value(array(self::TRACK_QUERY_KEY, 'cpbstrack'));
        if ($track_flag === null) {
            return;
        }

        $booking_id_raw = $this->get_request_value(array('booking_id', 'bookingid'));
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

//         $booking_title = get_the_title($booking_id);
//         if (!is_string($booking_title) || $booking_title === '') {
//             $booking_title = '#' . $booking_id;
//         }
		$booking_title = '#' . $booking_id;

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
        $details_html .= '<tr><th>' . esc_html__('Booking: ', 'cpbs-combined-extensions') . '</th><td>' . esc_html($booking_title) . '</td></tr>';
        $details_html .= '<tr><th>' . esc_html__('Customer: ', 'cpbs-combined-extensions') . '</th><td>' . esc_html($customer_name) . '</td></tr>';
        if ($customer_email !== '') {
            $details_html .= '<tr><th>' . esc_html__('Email: ', 'cpbs-combined-extensions') . '</th><td>' . esc_html($customer_email) . '</td></tr>';
        }
        if ($entry_label !== '') {
            $details_html .= '<tr><th>' . esc_html__('Start Time: ', 'cpbs-combined-extensions') . '</th><td>' . esc_html($entry_label) . '</td></tr>';
        }
        if ($exit_label !== '') {
            $details_html .= '<tr><th>' . esc_html__('End Time: ', 'cpbs-combined-extensions') . '</th><td>' . esc_html($exit_label) . '</td></tr>';
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
            $html .= '.cpbs-track-wrap button{background:#2F5277;color:#FFCC00;border:0;border-radius:6px;padding:12px 20px;font-size:16px;cursor:pointer;font-weight:700}';
            $html .= '.cpbs-track-wrap button:hover{background:#1e3a56}';
            $html .= '.cpbs-track-wrap button[disabled]{background:#8c8f94;cursor:default;color:#fff}</style></head><body>';
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
            wp_die($html, esc_html__('Booking Check-In', 'cpbs-combined-extensions'), array('response' => 200));
        }

        if (!isset($_POST['cpbs_track_confirm']) || !isset($_POST['_cpbs_track_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_cpbs_track_nonce'])), 'cpbs_track_confirm_' . $booking_id)) {
            $this->render_tracking_message_page(__('Confirmation failed. Please reopen the check-in link and try again.', 'cpbs-combined-extensions'), 403);
        }

        if ($clicked_at === '') {
            $clicked_at = $this->site_now()->format('Y-m-d H:i:s');
            $this->update_booking_meta($booking_id, 'automation_tracking_clicked_at', $clicked_at);
        }

        $this->update_booking_meta($booking_id, 'automation_status', 'occupied');

        // Record confirmation source and timestamp (customer clicked the tracking link).
        if ((string) $this->get_booking_meta_value($booking_id, 'automation_confirm_source') === '') {
            $this->update_booking_meta($booking_id, 'automation_confirm_source', 'customer');
            $this->update_booking_meta($booking_id, 'automation_confirmed_at', $clicked_at);
        }

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

        wp_die($html, esc_html__('Booking Check-In', 'cpbs-combined-extensions'), array('response' => 200));
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

        wp_die($html, esc_html__('Booking Check-In', 'cpbs-combined-extensions'), array('response' => (int) $status_code));
    }

    /**
     * Normalize booking status on save so unpaid bookings stay Pending and
     * confirmed payments advance to Processing.
     */
    public function enforce_pending_status_for_unpaid($booking_id, $post, $update)
    {
        if (!$post || $post->post_type !== $this->get_booking_post_type()) {
            return;
        }

        $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
        $payment_status = isset($meta['payment_status']) ? (string) $meta['payment_status'] : '';
        $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;

        if ($payment_status === 'paid') {
            if (!in_array($booking_status_id, array(2, 3, 4, 6, 7), true)) {
                CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', 2);
                $this->log_runtime('Booking status enforced to Processing (payment confirmed)', array(
                    'booking_id' => $booking_id,
                    'previous_status' => $booking_status_id,
                    'payment_status' => $payment_status,
                ));
            }
            return;
        }

        if (!in_array($booking_status_id, array(1, 3, 4, 6, 7), true)) {
            CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', 1);
            $this->log_runtime('Booking status enforced to Pending (unpaid)', array(
                'booking_id' => $booking_id,
                'previous_status' => $booking_status_id,
                'payment_status' => $payment_status,
            ));
        }
    }

    /**
     * Keep booking status aligned with payment state whenever CPBS writes meta.
     * This closes the gap where the booking is created as Processing before the
     * payment confirmation meta arrives.
     */
    public function normalize_booking_status_after_meta_write($meta_id, $object_id, $meta_key, $meta_value)
    {
        unset($meta_id, $meta_value);

        $meta_key = (string) $meta_key;
        $meta_prefix = CPBSCombinedHelpers::get_meta_prefix();
        if (strpos($meta_key, $meta_prefix) === 0) {
            $meta_key = substr($meta_key, strlen($meta_prefix));
        }

        if ($meta_key !== 'booking_status_id' && $meta_key !== 'payment_status') {
            return;
        }

        if (get_post_type($object_id) !== $this->get_booking_post_type()) {
            return;
        }

        static $guard = array();
        $object_id = (int) $object_id;
        if ($object_id <= 0 || !empty($guard[$object_id])) {
            return;
        }

        $guard[$object_id] = true;

        try {
            $meta = CPBSCombinedHelpers::get_booking_meta($object_id);
            $payment_status = isset($meta['payment_status']) ? (string) $meta['payment_status'] : '';
            $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;

            if ($payment_status === 'paid') {
                if (!in_array($booking_status_id, array(2, 3, 4, 6, 7), true)) {
                    CPBSCombinedHelpers::update_booking_meta($object_id, 'booking_status_id', 2);
                    $this->log_runtime('Booking status normalized to Processing after payment confirmation', array(
                        'booking_id' => $object_id,
                        'previous_status' => $booking_status_id,
                        'payment_status' => $payment_status,
                    ));
                }

                return;
            }

            if (!in_array($booking_status_id, array(1, 3, 4, 6, 7), true)) {
                CPBSCombinedHelpers::update_booking_meta($object_id, 'booking_status_id', 1);
                $this->log_runtime('Booking status normalized to Pending while payment is unpaid', array(
                    'booking_id' => $object_id,
                    'previous_status' => $booking_status_id,
                    'payment_status' => $payment_status,
                ));
            }
        } finally {
            unset($guard[$object_id]);
        }
    }

    /**
     * Handle Stripe webhooks for payment events.
     * Listens for payment_intent.succeeded and payment_intent.payment_failed events.
     */
    public function maybe_handle_stripe_webhooks()
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Check if this is a webhook request
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return;
        }

        // Parse webhook payload
        $event = json_decode($body, true);
        if (!is_array($event) || !isset($event['type'])) {
            return;
        }

        $event_type = $event['type'];

        // Handle payment success
        if ($event_type === 'payment_intent.succeeded') {
            $this->handle_stripe_payment_success($event);
        }

        // Handle payment failure
        if ($event_type === 'payment_intent.payment_failed') {
            $this->handle_stripe_payment_failed($event);
        }
    }

    /**
     * Handle Stripe payment_intent.succeeded webhook event.
     * Updates booking status to Processing (2) when payment is confirmed.
     */
    private function handle_stripe_payment_success($event)
    {
        $payment_intent = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : array();
        $intent_id = isset($payment_intent['id']) ? (string) $payment_intent['id'] : '';

        if ($intent_id === '') {
            $this->log_runtime('Stripe payment_intent.succeeded: missing intent_id', array('event' => $event));
            return;
        }

        // Find booking by payment intent ID
        $bookings = get_posts(array(
            'post_type' => $this->get_booking_post_type(),
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => array(array(
                'key' => 'cpbs_payment_stripe_intent_id',
                'value' => $intent_id,
                'compare' => '=',
            )),
        ));

        if (empty($bookings)) {
            $this->log_runtime('Stripe payment_intent.succeeded: booking not found', array('intent_id' => $intent_id));
            return;
        }

        $booking_id = (int) $bookings[0];
        $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
        $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;

        // Update payment status
        CPBSCombinedHelpers::update_booking_meta($booking_id, 'payment_status', 'paid');

        // Promote any non-final booking to Processing once payment is confirmed.
        if (!in_array($booking_status_id, array(3, 4, 6), true)) {
            CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', 2);
            $this->log_runtime('Booking status updated to Processing (payment confirmed)', array(
                'booking_id' => $booking_id,
                'intent_id' => $intent_id,
                'previous_status' => $booking_status_id,
            ));
        }
    }

    /**
     * Handle Stripe payment_intent.payment_failed webhook event.
     * Updates booking status to Failed (7) when payment fails.
     */
    private function handle_stripe_payment_failed($event)
    {
        $payment_intent = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : array();
        $intent_id = isset($payment_intent['id']) ? (string) $payment_intent['id'] : '';

        if ($intent_id === '') {
            $this->log_runtime('Stripe payment_intent.payment_failed: missing intent_id', array('event' => $event));
            return;
        }

        // Find booking by payment intent ID
        $bookings = get_posts(array(
            'post_type' => $this->get_booking_post_type(),
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => array(array(
                'key' => 'cpbs_payment_stripe_intent_id',
                'value' => $intent_id,
                'compare' => '=',
            )),
        ));

        if (empty($bookings)) {
            $this->log_runtime('Stripe payment_intent.payment_failed: booking not found', array('intent_id' => $intent_id));
            return;
        }

        $booking_id = (int) $bookings[0];
        $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
        $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;

        // Only update if booking is still in Pending (1) or Processing (2) state
        if ($booking_status_id === 1 || $booking_status_id === 2) {
            CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', 7);
            $this->log_runtime('Booking status updated to Failed (payment failed)', array(
                'booking_id' => $booking_id,
                'intent_id' => $intent_id,
                'previous_status' => $booking_status_id,
            ));
        }
    }

    public function maybe_send_initial_sms_on_meta_added($meta_id, $object_id, $meta_key, $meta_value)
    {
        // Trigger only when CPBS saves entry_datetime_2 (indicates booking data is complete)
        if ($meta_key !== 'entry_datetime_2') {
            return;
        }

        // Verify it's a booking post
        if (get_post_type($object_id) !== $this->get_booking_post_type()) {
            return;
        }

        // Guard: only process once per post (CPBS may update meta multiple times)
        if (get_post_meta($object_id, '_cpbs_initial_sms_processed', true)) {
            return;
        }

        update_post_meta($object_id, '_cpbs_initial_sms_processed', '1');
        $this->send_initial_sms_for_booking($object_id);
    }

    private function send_initial_sms_for_booking($post_id)
    {
        // Guard: only fire once — cron will see this flag and skip its own initial send.
        if ((string) $this->get_booking_meta_value($post_id, 'automation_initial_sms_sent_at') !== '') {
            return;
        }

        $settings = $this->get_settings();
        $body_template = trim((string) $settings['initial_sms_body']);
        if ($body_template === '') {
            return;
        }

        $meta  = $this->get_booking_meta($post_id);
        $entry = $this->build_site_datetime(isset($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '');
        $exit  = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');

        if (!$entry || !$exit) {
            return;
        }

        // CRITICAL: Only send initial SMS if payment is confirmed
        // Check payment_status meta key (set by CPBS when payment received)
        $payment_status = isset($meta['payment_status']) ? (string) $meta['payment_status'] : '';
        if ($payment_status !== 'paid') {
            // Payment not yet confirmed, skip for now
            // Cron task will send this SMS once payment is confirmed
            $this->log_runtime(
                'Initial SMS deferred: waiting for payment confirmation',
                array('booking_id' => $post_id, 'payment_status' => $payment_status)
            );
            return;
        }

        // Mark as sent before the network call so any replay cannot double-send.
        $now = $this->site_now();
        $this->update_booking_meta($post_id, 'automation_initial_sms_sent_at', $now->format('Y-m-d H:i:s'));

        $contact = $this->get_booking_contact($post_id, $meta);
        if ($contact['phone'] === '') {
            $this->log_runtime('Initial confirmation SMS skipped: no phone number', array('booking_id' => $post_id));
            return;
        }

        $tokens = $this->build_message_tokens($post_id, $meta, $entry, $exit);
        $body   = $this->replace_tokens($body_template, $tokens);

        $sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
        $this->log_runtime(
            $sent ? 'Initial confirmation SMS sent instantly on booking save' : 'Initial confirmation SMS failed on booking save',
            array('booking_id' => $post_id, 'to' => $contact['phone'])
        );
    }

    public function process_booking_automation()
    {
        $settings = $this->get_settings();
        $bookings = $this->get_bookings_for_processing();

        $this->log_runtime('Automation cron tick', array(
            'bookings_total' => count((array) $bookings),
        ));

        foreach ($bookings as $booking_id) {
            $booking_id = (int) $booking_id;
            if ($booking_id <= 0) {
                continue;
            }

            $meta  = $this->get_booking_meta($booking_id);
            $entry = $this->build_site_datetime(isset($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '');
            $exit  = $this->build_site_datetime(isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '');

            if (!$entry || !$exit) {
                continue;
            }

            $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
            $payment_status = isset($meta['payment_status']) ? (string) $meta['payment_status'] : '';

            // Auto-expire abandoned bookings (unpaid beyond timeout): Status 1 (Pending) or Status 2 (Processing)
            if ($booking_status_id === 1 || $booking_status_id === 2) {
                if ($payment_status !== 'paid') {
                    $post = get_post($booking_id);
                    if ($post instanceof \WP_Post) {
                        $post_time = new \DateTimeImmutable($post->post_date, wp_timezone());
                        $timeout_minutes = (int) $settings['pending_booking_timeout_minutes'];
                        $timeout_threshold = $post_time->modify('+' . $timeout_minutes . ' minutes');
                        $now = $this->site_now();

                        if ($now >= $timeout_threshold) {
                            // Auto-cancel abandoned unpaid booking (whether Status 1 or Status 2)
                            $this->update_booking_meta($booking_id, 'booking_status_id', 7);
                            $this->ensure_status_nonblocking(7);
                            $this->log_runtime('Unpaid booking auto-expired (status ' . $booking_status_id . ', timeout)', array(
                                'booking_id' => $booking_id,
                                'original_status' => $booking_status_id,
                                'post_created' => $post->post_date,
                                'timeout_minutes' => $timeout_minutes,
                                'expired_at' => $now->format('Y-m-d H:i:s'),
                            ));
                            continue;
                        }
                    }
                }
            }

            // Don't send ANY other automation notification until payment is confirmed.
            if ($payment_status !== 'paid') {
                $this->log_runtime('Automation notifications skipped: payment not yet confirmed', array(
                    'booking_id' => $booking_id,
                    'booking_status_id' => $booking_status_id,
                    'payment_status' => $payment_status,
                ));
                continue;
            }

            // Skip cancelled bookings (status 3)
            if ($booking_status_id === 3) {
                continue;
            }

            $now             = $this->site_now();
            $end_notice_time = $exit->modify('-' . $settings['end_notice_minutes_before'] . ' minutes');
            $followup_time       = $exit->modify('+' . $settings['after_end_followup_minutes'] . ' minutes');
            $followup_window_end = $followup_time->modify('+' . $settings['followup_send_window_minutes'] . ' minutes');
            $noshow_grace_time   = $entry->modify('+' . $settings['noshow_grace_period_minutes'] . ' minutes');

            $is_confirmed   = $this->is_booking_confirmed_by_meta($booking_id);
            $confirm_source = (string) $this->get_booking_meta_value($booking_id, 'automation_confirm_source');
            $is_noshow      = $this->get_booking_meta_value($booking_id, 'automation_noshow') === '1';

            // ── CONFIRMATION SMS WORKFLOW ─────────────────────────────────────
            // Run only for bookings not yet confirmed and not yet a no-show.
            if (!$is_confirmed && !$is_noshow) {

                // Initial confirmation SMS: send on first cron tick while window is still open.
                $initial_sent_at = (string) $this->get_booking_meta_value($booking_id, 'automation_initial_sms_sent_at');
                if ($initial_sent_at === '' && $now < $noshow_grace_time) {
                    $this->send_confirmation_sms($booking_id, $meta, $entry, $exit, 'initial');
                    $initial_sent_at = $now->format('Y-m-d H:i:s');
                    $this->update_booking_meta($booking_id, 'automation_initial_sms_sent_at', $initial_sent_at);
                }

                // Reminder 1: sent after configured delay from initial SMS.
                if ($initial_sent_at !== '' && (string) $this->get_booking_meta_value($booking_id, 'automation_reminder1_sent_at') === '') {
                    $initial_dt = $this->build_site_datetime($initial_sent_at);
                    if ($initial_dt instanceof \DateTimeImmutable) {
                        $reminder1_time = $initial_dt->modify('+' . $settings['reminder1_delay_minutes'] . ' minutes');
                        if ($now >= $reminder1_time && $now < $noshow_grace_time) {
                            $this->send_confirmation_sms($booking_id, $meta, $entry, $exit, 'reminder1');
                            $this->update_booking_meta($booking_id, 'automation_reminder1_sent_at', $now->format('Y-m-d H:i:s'));
                        }
                    }
                }

                // Reminder 2: sent after configured delay from initial SMS.
                if ($initial_sent_at !== '' && (string) $this->get_booking_meta_value($booking_id, 'automation_reminder2_sent_at') === '') {
                    $initial_dt = $this->build_site_datetime($initial_sent_at);
                    if ($initial_dt instanceof \DateTimeImmutable) {
                        $reminder2_time = $initial_dt->modify('+' . $settings['reminder2_delay_minutes'] . ' minutes');
                        if ($now >= $reminder2_time && $now < $noshow_grace_time) {
                            $this->send_confirmation_sms($booking_id, $meta, $entry, $exit, 'reminder2');
                            $this->update_booking_meta($booking_id, 'automation_reminder2_sent_at', $now->format('Y-m-d H:i:s'));
                        }
                    }
                }

                // Optional final warning SMS before the no-show deadline.
                if ((int) $settings['final_warning_enable'] === 1 && (string) $this->get_booking_meta_value($booking_id, 'automation_final_warning_sent_at') === '') {
                    $final_warning_time = $entry->modify('+' . $settings['final_warning_delay_minutes'] . ' minutes');
                    if ($now >= $final_warning_time && $now < $noshow_grace_time) {
                        $this->send_confirmation_sms($booking_id, $meta, $entry, $exit, 'final_warning');
                        $this->update_booking_meta($booking_id, 'automation_final_warning_sent_at', $now->format('Y-m-d H:i:s'));
                    }
                }

                // No-show: grace period has expired with no confirmation.
                if ((int) $settings['noshow_enable'] === 1 && $now >= $noshow_grace_time) {
                    $this->update_booking_meta($booking_id, 'automation_status', 'unoccupied');
                    $this->update_booking_meta($booking_id, 'automation_noshow', '1');
                    $this->update_booking_meta($booking_id, 'automation_noshow_at', $now->format('Y-m-d H:i:s'));
                    $is_noshow = true;
                    $this->log_runtime('Booking marked as no-show', array('booking_id' => $booking_id));
                    if ($this->is_booking_currently_active($booking_id, $meta, $entry, $exit, $now)) {
                        $this->end_unoccupied_booking($booking_id, $now);
                        continue;
                    }
                }
            }

            // ── OCCUPANCY STATUS ──────────────────────────────────────────────
            if ($is_confirmed) {
                if ((string) $this->get_booking_meta_value($booking_id, 'automation_status') !== 'occupied') {
                    $this->update_booking_meta($booking_id, 'automation_status', 'occupied');
                }
            } elseif (!$is_noshow && $now >= $entry && $now < $noshow_grace_time) {
                $this->update_booking_meta($booking_id, 'automation_status', 'late');
            }

            // Skip all post-booking SMS for no-show bookings.
            if ($is_noshow) {
                continue;
            }

            // ── BEFORE-END REMINDER: customer-confirmed bookings only ─────────
            if ($confirm_source === 'customer' && $now >= $end_notice_time && $now < $exit && (string) $this->get_booking_meta_value($booking_id, 'automation_end_notice_sent_at') === '') {
                $this->send_automation_message($booking_id, $meta, $entry, $exit, 'end');
                $this->update_booking_meta($booking_id, 'automation_end_notice_sent_at', $now->format('Y-m-d H:i:s'));
            }

            // ── AFTER-END FOLLOW-UP: customer or admin confirmed ──────────────
            if ($confirm_source === 'customer' || $confirm_source === 'admin') {
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

            // ── REVIEW INVITE: customer-confirmed bookings only ───────────────
            if ($confirm_source === 'customer') {
                $this->maybe_send_review_invite($booking_id, $meta, $exit, $now);
            }
        }
    }
	
	private function maybe_send_review_invite($booking_id, $meta, \DateTimeImmutable $exit, \DateTimeImmutable $now)
{
    // Review settings lo
    $review_settings = get_option(CPBSCombinedBookingReview::OPTION_KEY, array());
    $review_settings = is_array($review_settings) ? $review_settings : array();
    $defaults = array(
        'enable_email'       => 1,
        'enable_sms'         => 0,
        'send_after_minutes' => 60,
        'send_window_days'   => 7,
        'email_subject'      => '',
        'email_body'         => '',
        'sms_body'           => '',
        'review_page_id'     => 0,
    );
    $review_settings = wp_parse_args($review_settings, $defaults);

    // Page set hai?
    if ((int) $review_settings['review_page_id'] <= 0) {
        return;
    }

    // Already sent?
    $sent_at = (string) $this->get_booking_meta_value($booking_id, 'review_invite_sent_at');
    if ($sent_at !== '' && $sent_at !== 'failed') {
        return;
    }

    // Already reviewed?
    $review_ids = get_posts(array(
        'post_type'      => CPBSCombinedBookingReview::REVIEW_POST_TYPE,
        'post_status'    => 'any',
        'numberposts'    => 1,
        'fields'         => 'ids',
        'meta_query'     => array(array(
            'key'     => 'booking_id',
            'value'   => $booking_id,
            'compare' => '=',
            'type'    => 'NUMERIC',
        )),
    ));
    if (!empty($review_ids)) {
        $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'already-reviewed');
        return;
    }

    // Send window check
    $window_days = (int) $review_settings['send_window_days'];
    if ($window_days > 0) {
        $boundary = $now->modify('-' . $window_days . ' days');
        if ($exit < $boundary) {
            $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'skipped-too-old');
            $this->log_runtime('Review invite skipped: booking too old', array(
                'booking_id' => $booking_id,
            ));
            return;
        }
    }

    // Time check - Review class ki send_after_minutes use karo
    $send_after   = (int) $review_settings['send_after_minutes'];
    $review_time  = $exit->modify('+' . $send_after . ' minutes');
    if ($now < $review_time) {
        $this->log_runtime('Review invite not yet time', array(
            'booking_id' => $booking_id,
            'send_at'    => $review_time->format('Y-m-d H:i:s'),
            'now'        => $now->format('Y-m-d H:i:s'),
        ));
        return;
    }

    // Review link banao
    $review_link = $this->get_automation_review_link($booking_id);
    if ($review_link === '') {
        $this->log_runtime('Review invite skipped: no review link', array(
            'booking_id' => $booking_id,
        ));
        return;
    }

    // Tokens banao - Review class ki templates mein use honge
    $location_id   = isset($meta['location_id']) ? (int) $meta['location_id'] : 0;
    $location_name = $location_id > 0 ? (string) get_the_title($location_id) : '';
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

    $tokens = array(
        '{customer_name}'  => $customer_name,
        '[customer_name]'  => $customer_name,
        '{booking_id}'     => (string) $booking_id,
        '[booking_id]'     => (string) $booking_id,
        '{booking_end}'    => $exit->format('Y-m-d H:i:s'),
        '[booking_end]'    => $exit->format('Y-m-d H:i:s'),
        '{location_name}'  => $location_name,
        '[location_name]'  => $location_name,
        '{review_link}'    => $review_link,
        '[review_link]'    => $review_link,
    );

    $contact     = $this->get_booking_contact($booking_id, $meta);
    $email_sent  = false;
    $sms_sent    = false;

    // Email - Review class ki subject/body use karo
    if ((int) $review_settings['enable_email'] === 1 && $contact['email'] !== '') {
        $subject = str_replace(array_keys($tokens), array_values($tokens), (string) $review_settings['email_subject']);
        $body    = str_replace(array_keys($tokens), array_values($tokens), (string) $review_settings['email_body']);
        if ($subject !== '' && $body !== '') {
            $email_sent = (bool) wp_mail($contact['email'], $subject, $body);
            $this->log_runtime($email_sent ? 'Review email sent' : 'Review email failed', array(
                'booking_id' => $booking_id,
                'to'         => $contact['email'],
            ));
        }
    }

    // SMS - Review class ki sms_body use karo
    if ((int) $review_settings['enable_sms'] === 1 && $contact['phone'] !== '') {
        $body = str_replace(array_keys($tokens), array_values($tokens), (string) $review_settings['sms_body']);
        if ($body !== '') {
            $sms_sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
            $this->log_runtime($sms_sent ? 'Review SMS sent' : 'Review SMS failed', array(
                'booking_id' => $booking_id,
                'to'         => $contact['phone'],
            ));
        }
    }

    // Mark karo sirf tab jab send hua ho
    if ($email_sent || $sms_sent) {
        $this->update_booking_meta($booking_id, 'review_invite_sent_at', $now->format('Y-m-d H:i:s'));
        $this->log_runtime('Review invite sent successfully', array(
            'booking_id' => $booking_id,
            'email_sent' => $email_sent,
            'sms_sent'   => $sms_sent,
        ));
    } else {
        $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'failed');
        $this->log_runtime('Review invite failed - will retry', array(
            'booking_id' => $booking_id,
        ));
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
                if ($email_sent) {
                    $this->log_runtime('Email sent', array(
                        'booking_id' => (int) $booking_id,
                        'type' => (string) $type,
                        'to_email' => (string) $contact['email'],
                    ));
                }
                if (!$email_sent) {
                    $this->log_runtime('wp_mail returned false', array(
                        'booking_id' => (int) $booking_id,
                        'type' => (string) $type,
                        'to_email' => (string) $contact['email'],
                    ));
                }
            } else {
                $this->log_runtime('Email skipped due to empty template after token replacement', array(
                    'booking_id' => (int) $booking_id,
                    'type' => (string) $type,
                ));
            }
        } elseif ((int) $settings['enable_email'] === 1 && $contact['email'] === '') {
            $this->log_runtime('Email skipped due to missing customer email', array(
                'booking_id' => (int) $booking_id,
                'type' => (string) $type,
            ));
        } elseif ((int) $settings['enable_email'] !== 1) {
            $this->log_runtime('Email skipped because email notifications are disabled', array(
                'booking_id' => (int) $booking_id,
                'type' => (string) $type,
            ));
        }

        if ((int) $settings['enable_sms'] === 1 && $contact['phone'] !== '') {
            $body = $this->replace_tokens($settings[$type . '_sms_body'], $tokens);
            if ($body !== '') {
                $sms_sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
                if ($sms_sent) {
                    $this->log_runtime('SMS sent', array(
                        'booking_id' => (int) $booking_id,
                        'type' => (string) $type,
                        'to_phone' => (string) $contact['phone'],
                    ));
                }
            } else {
                $this->log_runtime('SMS skipped due to empty template after token replacement', array(
                    'booking_id' => (int) $booking_id,
                    'type' => (string) $type,
                ));
            }
        } elseif ((int) $settings['enable_sms'] === 1 && $contact['phone'] === '') {
            $this->log_runtime('SMS skipped due to missing customer phone', array(
                'booking_id' => (int) $booking_id,
                'type' => (string) $type,
            ));
        } elseif ((int) $settings['enable_sms'] !== 1) {
            $this->log_runtime('SMS skipped because SMS notifications are disabled', array(
                'booking_id' => (int) $booking_id,
                'type' => (string) $type,
            ));
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

    private function send_confirmation_sms($booking_id, $meta, \DateTimeImmutable $entry, \DateTimeImmutable $exit, $type)
    {
        $settings = $this->get_settings();
        $contact  = $this->get_booking_contact($booking_id, $meta);
        $tokens   = $this->build_message_tokens($booking_id, $meta, $entry, $exit);

        if ($contact['phone'] === '') {
            $this->log_runtime('Confirmation SMS skipped: no phone number', array(
                'booking_id' => $booking_id,
                'type'       => $type,
            ));
            return;
        }

        $body_key = $type . '_sms_body';
        $body     = isset($settings[$body_key]) ? $this->replace_tokens((string) $settings[$body_key], $tokens) : '';

        if (trim($body) === '') {
            $this->log_runtime('Confirmation SMS skipped: empty template', array(
                'booking_id' => $booking_id,
                'type'       => $type,
            ));
            return;
        }

        $sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
        $this->log_runtime($sent ? 'Confirmation SMS sent' : 'Confirmation SMS failed', array(
            'booking_id' => $booking_id,
            'type'       => $type,
            'to'         => $contact['phone'],
        ));
    }

    private function is_booking_confirmed_by_meta($booking_id)
    {
        $clicked_at     = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_clicked_at');
        $confirm_source = (string) $this->get_booking_meta_value($booking_id, 'automation_confirm_source');

        return $clicked_at !== '' || $confirm_source !== '';
    }

    public function handle_wp_mail_failed($error)
    {
        if (!($error instanceof \WP_Error)) {
            return;
        }

        $this->log_runtime('wp_mail_failed hook', array(
            'message' => (string) $error->get_error_message(),
            'data' => $error->get_error_data(),
        ));
    }

    public function handle_wp_mail_succeeded($mail_data)
    {
        if (!is_array($mail_data)) {
            return;
        }

        $to = isset($mail_data['to']) ? $mail_data['to'] : array();
        if (!is_array($to)) {
            $to = array((string) $to);
        }

        $this->log_runtime('wp_mail_succeeded hook', array(
            'to' => $to,
            'subject' => isset($mail_data['subject']) ? (string) $mail_data['subject'] : '',
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
		
		$location_id   = isset($meta['location_id']) ? (int) $meta['location_id'] : 0;
        $location_name = $location_id > 0 ? (string) get_the_title($location_id) : '';
		
		// Only expose the extension link after payment + customer confirmation.
        $extension_link = '';
        $payment_status = isset($meta['payment_status']) ? (string) $meta['payment_status'] : '';
        $confirm_source = isset($meta['automation_confirm_source']) ? (string) $meta['automation_confirm_source'] : '';
        $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
        $is_noshow = isset($meta['automation_noshow']) && (string) $meta['automation_noshow'] === '1';

        if (
            $payment_status === 'paid' &&
            $confirm_source === 'customer' &&
            $this->is_booking_confirmed_by_meta($booking_id) &&
            !$is_noshow &&
            !in_array($booking_status_id, array(3, 6, 7), true)
        ) {
            $extension_link = $this->get_extension_link($booking_id);
        }

		// ↓ Review link generate karo
    	$review_link = $this->get_automation_review_link($booking_id);

        return array(
            '{customer_name}' => $customer_name,
            '[customer_name]' => $customer_name,
			'{location_name}'    => $location_name,
            '[location_name]'    => $location_name,
            '{booking_id}' => (string) $booking_id,
            '[booking_id]' => (string) $booking_id,
            '{booking_start}' => $entry->format('Y-m-d H:i:s'),
            '[booking_start]' => $entry->format('Y-m-d H:i:s'),
            '{booking_end}' => $exit->format('Y-m-d H:i:s'),
            '[booking_end]' => $exit->format('Y-m-d H:i:s'),
            '{tracking_link}' => $this->get_or_create_tracking_link($booking_id),
            '[tracking_link]' => $this->get_or_create_tracking_link($booking_id),
	            '{extension_link}' => $extension_link,
	            '[extension_link]' => $extension_link,
			'{review_link}'    => $review_link,
        	'[review_link]'    => $review_link,
            '{timestamp}' => $this->site_now()->format('Y-m-d H:i:s'),
            '[timestamp]' => $this->site_now()->format('Y-m-d H:i:s'),
        );
    }
	
	private function get_automation_review_link($booking_id)
		{
			// Review settings se page id lo
			$review_settings = get_option(CPBSCombinedBookingReview::OPTION_KEY, array());
			$review_settings = is_array($review_settings) ? $review_settings : array();
			$page_id = isset($review_settings['review_page_id']) ? (int) $review_settings['review_page_id'] : 0;

			if ($page_id <= 0) {
				return '';
			}

			$url = get_permalink($page_id);
			if (!is_string($url) || $url === '') {
				return '';
			}

			// Token generate karo - same logic jo Review class use karti hai
			$token = (string) $this->get_booking_meta_value($booking_id, 'review_request_token');
			if ($token === '' || strpos($token, '_') !== false || strpos($token, '-') !== false) {
				$token = $this->generate_alphanumeric_token(32);
				$this->update_booking_meta($booking_id, 'review_request_token', $token);
			}

			// http_build_query use karo - spaces nahi aayenge
			$query     = http_build_query(array(
				'booking_id'   => (int) $booking_id,
				'review_token' => $token,
			));
			$separator = (strpos($url, '?') !== false) ? '&' : '?';

			return $url . $separator . $query;
		}

    private function replace_tokens($template, $tokens)
    {
        $template = (string) $template;

        return str_replace(array_keys($tokens), array_values($tokens), $template);
    }

    private function get_or_create_tracking_link($booking_id)
    {
        $token = (string) $this->get_booking_meta_value($booking_id, 'automation_tracking_token');
        if ($token === '' || strpos($token, '_') !== false || strpos($token, '-') !== false) {
            $token = $this->generate_alphanumeric_token(32);
            $this->update_booking_meta($booking_id, 'automation_tracking_token', $token);
        }

        // Use underscore-free param names (cpbstrack, bookingid) so the URL is SMS-safe.
        // GSM-7 encodes underscore as a 2-byte extended char; some carriers replace it with a space,
        // breaking the link. The handler already accepts both forms via get_request_value fallback.
        $base      = home_url('/');
        $separator = (strpos($base, '?') !== false) ? '&' : '?';

        return $base . $separator . http_build_query(array(
            'cpbstrack' => '1',
            'bookingid' => (int) $booking_id,
            'token'     => $token,
        ));
    }

	private function generate_alphanumeric_token($length = 32)
		{
			$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
			$token = '';
			$max   = strlen($chars) - 1;
			for ($i = 0; $i < $length; $i++) {
				$token .= $chars[random_int(0, $max)];
			}
			return $token;
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

			// FIX: manually build karein taake spaces na aayein
			$query = http_build_query(array(
				'booking_id'   => (int) $booking_id,
				'access_token' => $token,
			));

			$separator = (strpos($url, '?') !== false) ? '&' : '?';

			return $url . $separator . $query;
		}

    private function get_booking_contact($booking_id, $meta)
    {
        $email = '';
        $email_sources = array(
            isset($meta['client_contact_detail_email_address']) ? $meta['client_contact_detail_email_address'] : '',
            isset($meta['email_address']) ? $meta['email_address'] : '',
            isset($meta['email']) ? $meta['email'] : '',
            isset($meta['billing_email']) ? $meta['billing_email'] : '',
            isset($meta['customer_email']) ? $meta['customer_email'] : '',
            isset($meta['contact_email']) ? $meta['contact_email'] : '',
            get_post_meta($booking_id, 'cpbs_client_contact_detail_email_address', true),
            get_post_meta($booking_id, 'cpbs_email_address', true),
            get_post_meta($booking_id, 'cpbs_email', true),
            get_post_meta($booking_id, 'cpbs_billing_email', true),
            get_post_meta($booking_id, 'cpbs_customer_email', true),
            get_post_meta($booking_id, 'cpbs_contact_email', true),
        );

        foreach ($email_sources as $candidate) {
            $candidate = sanitize_email((string) $candidate);
            if ($candidate !== '' && is_email($candidate)) {
                $email = $candidate;
                break;
            }
        }

        if ($email === '') {
            $email = $this->extract_email_from_mixed($meta);
        }

        $phone = '';
        $phone_sources = array(
            isset($meta['client_contact_detail_phone_number']) ? $meta['client_contact_detail_phone_number'] : '',
            isset($meta['phone_number']) ? $meta['phone_number'] : '',
            isset($meta['phone']) ? $meta['phone'] : '',
            isset($meta['billing_phone']) ? $meta['billing_phone'] : '',
            isset($meta['customer_phone']) ? $meta['customer_phone'] : '',
            isset($meta['contact_phone']) ? $meta['contact_phone'] : '',
            isset($meta['form_element_field']) ? $this->extract_phone_from_mixed($meta['form_element_field']) : '',
            get_post_meta($booking_id, 'cpbs_client_contact_detail_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone_number', true),
            get_post_meta($booking_id, 'cpbs_phone', true),
            get_post_meta($booking_id, 'cpbs_billing_phone', true),
            get_post_meta($booking_id, 'cpbs_customer_phone', true),
            get_post_meta($booking_id, 'cpbs_contact_phone', true),
        );

        foreach ($phone_sources as $raw_phone) {
            $normalized = $this->normalize_phone_number($raw_phone);
            if ($normalized !== '') {
                $phone = $normalized;
                break;
            }
        }

        if ($phone === '') {
            $fallback = $this->extract_phone_from_mixed($meta);
            $phone = $this->normalize_phone_number($fallback);
        }

        return array(
            'email' => $email,
            'phone' => $phone,
        );
    }

    private function extract_email_from_mixed($value)
    {
        return CPBSCombinedHelpers::extract_email_from_mixed($value);
    }

    private function extract_phone_from_mixed($value)
    {
        return CPBSCombinedHelpers::extract_phone_from_mixed($value);
    }

    private function looks_like_phone_number($value)
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/(\+|00)?[0-9][0-9\s\-\(\)]{6,20}/', $value);
    }

    private function get_sms_settings()
    {
        return CPBSCombinedHelpers::get_sms_settings();
    }

    private function send_twilio_sms($to_phone, $message_body)
    {
        $sms_settings = $this->get_sms_settings();

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
        } else {
            $this->log_runtime('Twilio API success response', array(
                'to_phone' => (string) $to_phone,
                'status' => $status,
            ));
        }

        return $status === 200 || $status === 201;
    }

    private function normalize_phone_number($raw_phone)
    {
        return CPBSCombinedHelpers::normalize_phone_number($raw_phone);
    }

    private function build_site_datetime($normalized_datetime)
    {
        return CPBSCombinedHelpers::build_site_datetime($normalized_datetime);
    }

    private function site_now()
    {
        return CPBSCombinedHelpers::site_now();
    }

    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
    }

    private function get_meta_prefix()
    {
        return CPBSCombinedHelpers::get_meta_prefix();
    }

    private function is_booking_post($booking_id)
    {
        $post = get_post($booking_id);

        return $post instanceof \WP_Post && $post->post_type === $this->get_booking_post_type();
    }

    private function get_booking_meta($booking_id)
    {
        return CPBSCombinedHelpers::get_booking_meta($booking_id);
    }

    private function get_booking_meta_value($booking_id, $key)
    {
        $meta = $this->get_booking_meta($booking_id);

        return isset($meta[$key]) ? $meta[$key] : '';
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        CPBSCombinedHelpers::update_booking_meta($booking_id, $key, $value);
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
            'end_notice_minutes_before' => 10,
            'after_end_followup_minutes' => 5,
            'followup_send_window_minutes' => 120,
            'end_email_subject' => 'Your booking is ending soon',
            'end_email_body' => 'Your booking will end at {booking_end}.',
            'end_sms_body' => 'Your booking will end at {booking_end}.',
            'follow_email_subject' => 'Thank you for visiting',
            'follow_email_body' => 'Waiting for your next visit.',
            'follow_sms_body' => 'Waiting for your next visit.',
            'track_page_message' => 'Thank you. Your parking spot is now marked as occupied.',
            'extension_page_id' => 0,
            'booking_extension_webhook_secret' => '',
            'initial_sms_body' => 'Hi {customer_name}, your booking #{booking_id} starts at {booking_start}. Please confirm your arrival: {tracking_link}',
            'reminder1_delay_minutes' => 60,
            'reminder1_sms_body' => 'Reminder: please confirm your booking #{booking_id}: {tracking_link}',
            'reminder2_delay_minutes' => 120,
            'reminder2_sms_body' => 'Final reminder: please confirm your booking #{booking_id}: {tracking_link}',
            'pending_booking_timeout_minutes' => 15,
            'noshow_grace_period_minutes' => 30,
            'noshow_enable' => 1,
            'final_warning_enable' => 0,
            'final_warning_delay_minutes' => 20,
            'final_warning_sms_body' => 'Warning: your booking #{booking_id} will be cancelled as No-Show if not confirmed shortly.',
            'cancellation_cutoff_hours' => 2,
            'cancellation_refund_sms' => 'SpotAPark: Your reservation #{booking_id} cancellation is confirmed. You are eligible for a refund, which will be processed manually. Thank you.',
            'cancellation_no_refund_sms' => 'SpotAPark: Your reservation #{booking_id} has been cancelled. As cancellation was made within the cutoff window, a refund is not applicable.',
            'new_account_email_subject' => 'Welcome to SpotAPark - Set Your Password',
            'new_account_email_body' => 'Hello {customer_name},\n\nYour account has been created on SpotAPark.\n\nFIRST TIME LOGIN INSTRUCTIONS:\n1. Click the link below to set your password\n2. Set a secure password\n3. Log in with your email and password\n4. Access your booking dashboard to manage reservations\n\nPassword Setup Link: {password_reset_link}\n\nIf you did not create this account or have questions, contact support.\n\nThank you!',
            'new_account_sms_body' => 'Welcome to SpotAPark! Your account created. Check your email for password setup instructions.',
        );
    }

    private function get_password_reset_link($user_id)
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return '';
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return '';
        }

        $reset_url = add_query_arg(array(
            'cpbs_account_action' => 'set_password',
            'key' => $key,
            'login' => $user->user_login,
        ), $this->get_reservations_page_url());

        return $reset_url;
    }

    private function get_reservations_page_url()
    {
        $page = get_page_by_path('reservations');
        if ($page instanceof WP_Post) {
            $permalink = get_permalink($page);
            if (is_string($permalink) && $permalink !== '') {
                return apply_filters('cpbs_combined_customer_reservations_page_url', $permalink);
            }
        }

        return apply_filters('cpbs_combined_customer_reservations_page_url', home_url('/reservations/'));
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

/**
 * Sends booking review invites and stores customer reviews.
 */

// ============================================================================
// Booking Review
// ============================================================================
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
            <p><?php echo esc_html__('Placeholders: {customer_name}, {booking_id}, {booking_start}, {booking_end}, {tracking_link}, {extension_link}, {review_link}, {timestamp}.'); ?></p>

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
				'interval' => 60,  // ← 1 minute
				'display'  => __('Every 1 Minute (CPBS Booking Review)', 'cpbs-combined-extensions'),
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

    
    $this->log_review('Review cron tick', array(
        'review_page_id' => (int) $settings['review_page_id'],
        'enable_email'   => (int) $settings['enable_email'],
        'enable_sms'     => (int) $settings['enable_sms'],
    ));

    if ((int) $settings['review_page_id'] <= 0) {
        $this->log_review('Review cron skipped: review_page_id not set');
        return;
    }

    $bookings = get_posts(array(
        'post_type'        => $this->get_booking_post_type(),
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ));

    $this->log_review('Bookings found', array('total' => count($bookings)));

        $now           = $this->site_now();
        $delay_minutes = (int) $settings['send_after_minutes'];

        foreach ((array) $bookings as $booking_id) {
            $booking_id = (int) $booking_id;
            if ($booking_id <= 0) continue;

        $sent_at = (string) $this->get_booking_meta_value($booking_id, 'review_invite_sent_at');
        if ($sent_at !== '') {
            continue;
        }

        if ($this->has_review_for_booking($booking_id)) {
            $this->update_booking_meta($booking_id, 'review_invite_sent_at', $now->format('Y-m-d H:i:s'));
            continue;
        }

            $meta = $this->get_booking_meta($booking_id);
            $booking_status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
            $confirm_source = isset($meta['automation_confirm_source']) ? (string) $meta['automation_confirm_source'] : '';
            $clicked_at = isset($meta['automation_tracking_clicked_at']) ? (string) $meta['automation_tracking_clicked_at'] : '';
            $is_noshow = isset($meta['automation_noshow']) && (string) $meta['automation_noshow'] === '1';

            if (
                $is_noshow ||
                in_array($booking_status_id, array(3, 6, 7), true) ||
                $confirm_source !== 'customer' ||
                $clicked_at === ''
            ) {
                $this->log_review('Skipped: booking is not customer-confirmed or is inactive', array(
                    'booking_id' => $booking_id,
                    'booking_status_id' => $booking_status_id,
                    'confirm_source' => $confirm_source,
                    'clicked_at' => $clicked_at,
                    'is_noshow' => $is_noshow ? '1' : '0',
                ));
                continue;
            }

            $exit = $this->build_site_datetime(
                isset($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : ''
            );

        if (!($exit instanceof \DateTimeImmutable)) {
            $this->log_review('Skipped: no valid exit datetime', array('booking_id' => $booking_id));
            continue;
        }

        // Window check
        $window_days = (int) $settings['send_window_days'];
        if ($window_days > 0) {
            $boundary = $now->modify('-' . $window_days . ' days');
            if ($exit < $boundary) {
                $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'skipped-too-old');
                $this->log_review('Skipped: booking too old', array(
                    'booking_id' => $booking_id,
                    'exit'       => $exit->format('Y-m-d H:i'),
                    'boundary'   => $boundary->format('Y-m-d H:i'),
                ));
                continue;
            }
        }

        $send_time = $exit->modify('+' . $delay_minutes . ' minutes');
        if ($now < $send_time) {
            $this->log_review('Not yet time to send', array(
                'booking_id' => $booking_id,
                'send_at'    => $send_time->format('Y-m-d H:i:s'),
                'now'        => $now->format('Y-m-d H:i:s'),
            ));
            continue;
        }

$contact     = $this->get_booking_contact($booking_id, $meta);
$review_link = $this->get_or_create_review_link($booking_id);

if ($review_link === '') {
    $this->log_review('Skipped: could not build review link', array('booking_id' => $booking_id));
    continue; 
}

// Contact check
if ($contact['email'] === '' && $contact['phone'] === '') {
    $this->log_review('Skipped: no email and no phone', array('booking_id' => $booking_id));
    $this->update_booking_meta($booking_id, 'review_invite_sent_at', 'skipped-no-contact');
    continue;
}

$tokens     = $this->build_tokens($booking_id, $meta, $exit, $review_link);
$email_sent = false;
$sms_sent   = false;

if ((int) $settings['enable_email'] === 1 && $contact['email'] !== '') {
    $subject = $this->replace_tokens($settings['email_subject'], $tokens);
    $body    = $this->replace_tokens($settings['email_body'], $tokens);
    if ($subject !== '' && $body !== '') {
        $email_sent = (bool) wp_mail($contact['email'], $subject, $body);
        $this->log_review($email_sent ? 'Email sent' : 'Email failed', array(
            'booking_id' => $booking_id,
            'to'         => $contact['email'],
        ));
    }
} elseif ((int) $settings['enable_email'] === 1) {
    $this->log_review('Email skipped: no customer email', array('booking_id' => $booking_id));
}

if ((int) $settings['enable_sms'] === 1 && $contact['phone'] !== '') {
    $body = $this->replace_tokens($settings['sms_body'], $tokens);
    if ($body !== '') {
        $sms_sent = (bool) $this->send_twilio_sms($contact['phone'], $body);
        $this->log_review($sms_sent ? 'SMS sent' : 'SMS failed', array(
            'booking_id' => $booking_id,
            'to'         => $contact['phone'],
        ));
    }
} elseif ((int) $settings['enable_sms'] === 1) {
    $this->log_review('SMS skipped: no customer phone', array('booking_id' => $booking_id));
}

// SIRF TAB mark karo jab email ya SMS send hua ho
if ($email_sent || $sms_sent) {
    $this->update_booking_meta($booking_id, 'review_invite_sent_at', $now->format('Y-m-d H:i:s'));
    $this->log_review('Review invite marked as sent', array(
        'booking_id' => $booking_id,
        'email_sent' => $email_sent,
        'sms_sent'   => $sms_sent,
    ));
} else {
    // Fail hua - next cron pe retry karega
    $this->log_review('Review invite NOT marked - will retry next cron', array(
        'booking_id' => $booking_id,
        'email_sent' => $email_sent,
        'sms_sent'   => $sms_sent,
    ));
}
        
    }
}
private function log_review($message, array $context = array())
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC][REVIEW] ' . (string) $message;
    if (!empty($context)) {
        $encoded = wp_json_encode($context);
        if (is_string($encoded) && $encoded !== '') {
            $line .= ' ' . $encoded;
        }
    }
    $line .= PHP_EOL;

    $upload = wp_upload_dir();
    $dir    = isset($upload['basedir']) ? (string) $upload['basedir'] : '';

    if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
        @file_put_contents(
            trailingslashit($dir) . 'cpbs-combined-runtime.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
        return;
    }

    error_log('[cpbs-review] ' . trim($line));
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
      if ($token === '' || strpos($token, '_') !== false || strpos($token, '-') !== false) {
            $token = $this->generate_alphanumeric_token(32);
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

    private function get_sms_settings()
    {
        return CPBSCombinedHelpers::get_sms_settings();
    }

    private function send_twilio_sms($to_phone, $message_body)
    {
        $sms_settings = $this->get_sms_settings();

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
        return CPBSCombinedHelpers::build_site_datetime($normalized_datetime);
    }

    private function site_now()
    {
        return CPBSCombinedHelpers::site_now();
    }

    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
    }

    private function get_meta_prefix()
    {
        return CPBSCombinedHelpers::get_meta_prefix();
    }

    private function is_booking_post($post_id)
    {
        return get_post_type($post_id) === $this->get_booking_post_type();
    }

    private function get_booking_meta($booking_id)
    {
        return CPBSCombinedHelpers::get_booking_meta($booking_id);
    }

    private function get_booking_meta_value($booking_id, $key)
    {
        $meta = $this->get_booking_meta($booking_id);
        return isset($meta[$key]) ? $meta[$key] : '';
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        CPBSCombinedHelpers::update_booking_meta($booking_id, $key, $value);
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

// ============================================================================
// Booking Form Compatibility
// ============================================================================
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

// ============================================================================
// CPBS AJAX Request Guard
// ============================================================================
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

/**
 * Booking Cancellation Feature: Customer account creation, reservations page, cancellation requests.
 */

// ============================================================================
// Booking Cancellation
// ============================================================================
class CPBSCombinedBookingCancellation
{
    const VERSION = '1.0.0';

    private $automation;
    private $customer_account_notice = array('type' => '', 'message' => '');

    public function __construct()
    {
        // Get automation instance for SMS sending and settings access
        // Note: Automation must be instantiated before this class
        add_action('plugins_loaded', array($this, 'init_features'), 20);
    }

    public function init_features()
    {
        // Customer portal shortcode is now registered in CPBSCombinedCustomerPortal class
        // Hook into booking save to create customer account (priority 20, before automation at 30)
        add_action('save_post_' . $this->get_booking_post_type(), array($this, 'maybe_create_customer_account'), 20, 3);
        add_action('added_post_meta', array($this, 'maybe_create_customer_account_from_meta'), 10, 4);
        add_action('updated_post_meta', array($this, 'maybe_create_customer_account_from_meta'), 10, 4);
        add_action('template_redirect', array($this, 'handle_customer_account_forms'), 1);
        // AJAX handler for cancellation requests
        add_action('wp_ajax_cpbs_cancel_booking', array($this, 'ajax_cancel_booking'));
    }

    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
    }

    private function get_meta_prefix()
    {
        return CPBSCombinedHelpers::get_meta_prefix();
    }

    private function is_customer_account_processed($post_id)
    {
        return (int) get_post_meta($post_id, 'linked_wp_user_id', true) > 0;
    }

    public function maybe_create_customer_account($post_id, $post, $update)
    {
        // Only for new bookings
        if ($update) {
            return;
        }

        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        // Verify post type
        if (get_post_type($post_id) !== $this->get_booking_post_type()) {
            return;
        }

        $this->process_customer_account_creation($post_id);
    }

    public function maybe_create_customer_account_from_meta($meta_id, $object_id, $meta_key, $meta_value)
    {
        unset($meta_id, $meta_value);

        $post_id = (int) $object_id;
        if ($post_id <= 0 || get_post_type($post_id) !== $this->get_booking_post_type()) {
            return;
        }

        $prefix = $this->get_meta_prefix();
        $normalized_key = strpos($meta_key, $prefix) === 0 ? substr($meta_key, strlen($prefix)) : $meta_key;
        $watched_keys = array(
            'client_contact_detail_email_address',
            'email_address',
            'email',
            'billing_email',
            'customer_email',
            'contact_email',
        );

        if (!in_array($normalized_key, $watched_keys, true)) {
            return;
        }

        $this->process_customer_account_creation($post_id);
    }

    private function process_customer_account_creation($post_id)
    {
        if ($this->is_customer_account_processed($post_id)) {
            return;
        }

        $contact = $this->get_booking_contact($post_id);
        $customer_email = $contact['email'];

        if (!is_email($customer_email)) {
            return;
        }

        $user_id = null;
        $is_new_user = false;

        // Check if user already exists
        if (email_exists($customer_email)) {
            $user = get_user_by('email', $customer_email);
            if ($user) {
                $user_id = $user->ID;
            }
        } else {
            // Create new user
            $customer_name = $contact['name'];

            $username = sanitize_user($customer_email, true);
            // Ensure unique username
            $base_username = $username;
            $counter = 1;
            while (username_exists($username)) {
                $username = $base_username . $counter;
                $counter++;
            }

            $user_data = array(
                'user_login' => $username,
                'user_email' => $customer_email,
                'user_pass' => wp_generate_password(16),
                'display_name' => !empty($customer_name) ? $customer_name : $customer_email,
                'first_name' => !empty($customer_name) ? $customer_name : '',
            );

            $user_id = wp_insert_user($user_data);

            if (is_wp_error($user_id)) {
                return;
            }

            $is_new_user = true;
        }

        // Store linked user ID in booking meta
        if ($user_id) {
            update_post_meta($post_id, 'linked_wp_user_id', (int) $user_id);
            update_post_meta($post_id, '_linked_wp_user_processed', '1');

            if ($is_new_user) {
                $this->send_new_account_notifications($user_id, $post_id, $customer_email, $contact['name']);
            }
        }
    }

    private function send_new_account_notifications($user_id, $post_id, $email, $name)
    {
        $settings = $this->get_automation_settings();
        $email_sent = false;
        $sms_sent = false;

        // Send welcome email
        if ((int) $settings['enable_email']) {
            $subject = $settings['new_account_email_subject'];
            $body = $settings['new_account_email_body'];

            // Get password reset link
            $reset_link = $this->get_password_reset_link($user_id);

            // Replace placeholders
            $body = str_replace(
                array('{customer_name}', '{password_reset_link}'),
                array($name, $reset_link),
                $body
            );

            $email_sent = (bool) wp_mail($email, $subject, $body);
            $this->log_runtime($email_sent ? 'New account email sent' : 'New account email failed', array(
                'user_id' => $user_id,
                'booking_id' => $post_id,
                'email' => $email,
            ));
        }

        // Send welcome SMS
        if ((int) $settings['enable_sms']) {
            $contact = $this->get_booking_contact($post_id);
            $phone = $contact['phone'];

            if ($phone) {
                $sms_body = $settings['new_account_sms_body'];
                $sms_body = str_replace(
                    array('{customer_name}', '{support_link}'),
                    array($name, home_url()),
                    $sms_body
                );

                $sms_result = $this->send_twilio_sms($phone, $sms_body);
                $sms_sent = !is_wp_error($sms_result);
                $this->log_runtime($sms_sent ? 'New account SMS sent' : 'New account SMS failed', array(
                    'user_id' => $user_id,
                    'booking_id' => $post_id,
                    'phone' => $phone,
                    'error' => is_wp_error($sms_result) ? $sms_result->get_error_message() : '',
                ));
            }
        }

        $this->log_runtime('New user account created and notifications processed', array(
            'user_id' => $user_id,
            'booking_id' => $post_id,
            'email' => $email,
            'email_sent' => $email_sent,
            'sms_sent' => $sms_sent,
        ));
    }

    /**
     * Link existing bookings by email when user views reservation page
     * This is a safety net in case automatic linking failed during booking creation
     */
    private function link_existing_bookings_by_email($user_id, $user_email)
    {
        if (empty($user_email) || empty($user_id)) {
            return;
        }

        global $wpdb;
        
        // Find bookings matching this email that don't have a linked user yet
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = 'linked_wp_user_id'
            WHERE p.post_type = %s
            AND m.post_id IS NULL
            AND p.ID IN (
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE (meta_key = %s OR meta_key = %s)
                AND meta_value = %s
            )
        ", $this->get_booking_post_type(), 'client_contact_detail_email_address', $this->get_meta_prefix() . 'client_contact_detail_email_address', $user_email));

        if (!empty($bookings)) {
            foreach ($bookings as $booking) {
                $post_id = (int) $booking->ID;
                // Only link if not already processed
                if (!$this->is_customer_account_processed($post_id)) {
                    update_post_meta($post_id, 'linked_wp_user_id', (int) $user_id);
                }
            }
        }
    }

    public function render_reservations_shortcode()
    {
        $this->enqueue_customer_portal_assets();
        $notice = $this->customer_account_notice;

        if ($this->is_password_setup_request()) {
            return $this->render_customer_password_setup($notice);
        }

        // Require user to be logged in
        if (!is_user_logged_in()) {
            return $this->render_customer_access_panel($notice);
        }

        $current_user_id = get_current_user_id();
        $current_user    = wp_get_current_user();
        $current_user_email = ($current_user instanceof \WP_User) ? sanitize_email((string) $current_user->user_email) : '';

        // Attempt to link any existing unlinked bookings by email
        if (!empty($current_user_email)) {
            $this->link_existing_bookings_by_email($current_user_id, $current_user_email);
        }

        // Build meta query - look for bookings linked to this user OR matching email
        $meta_query_args = array(
            'relation' => 'OR',
            array(
                'key'     => 'linked_wp_user_id',
                'value'   => (int) $current_user_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ),
        );

        if (!empty($current_user_email)) {
            $meta_query_args[] = array(
                'key'     => $this->get_meta_prefix() . 'client_contact_detail_email_address',
                'value'   => $current_user_email,
                'compare' => '=',
            );

            $meta_query_args[] = array(
                'key'     => 'client_contact_detail_email_address',
                'value'   => $current_user_email,
                'compare' => '=',
            );
        }

        // Query bookings - use separate orderby clause
        $args = array(
            'post_type'      => $this->get_booking_post_type(),
            'posts_per_page' => -1,
            'meta_query'     => $meta_query_args,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        );

        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            ob_start();
            ?>
            <?php echo $this->get_customer_portal_styles(); ?>
            <div class="cpbs-customer-portal">
                <div class="cpbs-portal-header">
                    <div>
                        <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                        <h2><?php echo esc_html__('Your Parking', 'cpbs-combined-extensions'); ?></h2>
                    </div>
                </div>
                <div class="cpbs-empty-state">
                    <h3><?php echo esc_html__('No reservations yet', 'cpbs-combined-extensions'); ?></h3>
                    <p><?php echo esc_html__('Your confirmed and upcoming parking reservations will appear here.', 'cpbs-combined-extensions'); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        ob_start();
        ?>
        <?php echo $this->get_customer_portal_styles(); ?>
        <?php echo $this->get_customer_portal_inline_script(); ?>
        <div class="cpbs-customer-portal">
            <?php if (!empty($notice['message'])) : ?>
                <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
            <?php endif; ?>
            <div class="cpbs-portal-header">
                <div>
                    <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                    <h2><?php echo esc_html__('Your Parking', 'cpbs-combined-extensions'); ?></h2>
                </div>
                <a class="cpbs-portal-link" href="<?php echo esc_url(wp_logout_url($this->get_reservations_page_url())); ?>"><?php echo esc_html__('Sign out', 'cpbs-combined-extensions'); ?></a>
            </div>
                <div class="cpbs-reservation-grid">
                <?php
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $meta = $this->get_booking_meta($post_id);

                    $location_name = !empty($meta['location_name']) ? sanitize_text_field($meta['location_name']) : __('N/A', 'cpbs-combined-extensions');
                    $entry_dt = !empty($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '';
                    $exit_dt = !empty($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '';
                    $status_id = (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0);

                    $entry_formatted = $entry_dt ? date_i18n('M j, Y g:i A', strtotime($entry_dt)) : __('N/A', 'cpbs-combined-extensions');
                    $exit_formatted = $exit_dt ? date_i18n('M j, Y g:i A', strtotime($exit_dt)) : __('N/A', 'cpbs-combined-extensions');
                    $status_badge = $this->get_status_badge($status_id);

                    $can_cancel = $this->can_customer_cancel_booking($post_id, $meta);
                    ?>
                    <article class="cpbs-reservation-card">
                        <div class="cpbs-card-topline">
                            <span class="cpbs-booking-number"><?php echo esc_html(sprintf(__('Reservation #%d', 'cpbs-combined-extensions'), $post_id)); ?></span>
                            <?php echo wp_kses_post($status_badge); ?>
                        </div>
                        <h3><?php echo esc_html($location_name); ?></h3>
                        <div class="cpbs-card-times">
                            <div>
                                <span><?php echo esc_html__('Entry', 'cpbs-combined-extensions'); ?></span>
                                <strong><?php echo esc_html($entry_formatted); ?></strong>
                            </div>
                            <div>
                                <span><?php echo esc_html__('Exit', 'cpbs-combined-extensions'); ?></span>
                                <strong><?php echo esc_html($exit_formatted); ?></strong>
                            </div>
                        </div>
                        <div class="cpbs-card-actions">
                            <?php if ($can_cancel): ?>
                                <button type="button" class="cpbs-button cpbs-button-danger cpbs-cancel-booking-btn" data-booking-id="<?php echo esc_attr($post_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('cpbs_cancel_booking_' . $post_id)); ?>"><?php echo esc_html__('Cancel reservation', 'cpbs-combined-extensions'); ?></button>
                            <?php else: ?>
                                <span class="cpbs-muted-action"><?php echo esc_html__('No action needed', 'cpbs-combined-extensions'); ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
        wp_reset_postdata();

        return ob_get_clean();
    }

    public function handle_customer_account_forms()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cpbs_customer_account_action'])) {
            return $this->customer_account_notice;
        }

        $action = sanitize_key(wp_unslash($_POST['cpbs_customer_account_action']));

        if ($action === 'login') {
            if (!isset($_POST['cpbs_customer_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_customer_login_nonce'])), 'cpbs_customer_login')) {
                return $this->set_customer_account_notice('error', __('Security check failed. Please try again.', 'cpbs-combined-extensions'));
            }

            $email = isset($_POST['cpbs_customer_email']) ? sanitize_email(wp_unslash($_POST['cpbs_customer_email'])) : '';
            $password = isset($_POST['cpbs_customer_password']) ? (string) wp_unslash($_POST['cpbs_customer_password']) : '';
            $user = is_email($email) ? get_user_by('email', $email) : false;

            if (!$user) {
                return $this->set_customer_account_notice('error', __('No account was found for that email address.', 'cpbs-combined-extensions'));
            }

            $signed_in = wp_signon(array(
                'user_login' => $user->user_login,
                'user_password' => $password,
                'remember' => true,
            ), is_ssl());

            if (is_wp_error($signed_in)) {
                return $this->set_customer_account_notice('error', __('The email or password is incorrect.', 'cpbs-combined-extensions'));
            }

            wp_set_current_user($signed_in->ID);
            $this->safe_redirect($this->get_reservations_page_url());
            return $this->set_customer_account_notice('success', __('Signed in.', 'cpbs-combined-extensions'));
        }

        if ($action === 'send_setup_link') {
            if (!isset($_POST['cpbs_customer_link_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_customer_link_nonce'])), 'cpbs_customer_send_setup_link')) {
                return $this->set_customer_account_notice('error', __('Security check failed. Please try again.', 'cpbs-combined-extensions'));
            }

            $email = isset($_POST['cpbs_customer_email']) ? sanitize_email(wp_unslash($_POST['cpbs_customer_email'])) : '';
            if (is_email($email)) {
                $user = get_user_by('email', $email);
                if ($user && $this->customer_has_linked_booking($user->ID)) {
                    $link = $this->get_password_reset_link($user->ID);
                    if ($link !== '') {
                        wp_mail(
                            $email,
                            __('Your SpotAPark secure access link', 'cpbs-combined-extensions'),
                            sprintf(__('Use this secure link to set a new password and access your reservations: %s', 'cpbs-combined-extensions'), $link)
                        );
                    }
                }
            }

            return $this->set_customer_account_notice('success', __('If an account exists for that email, a secure access link has been sent.', 'cpbs-combined-extensions'));
        }

        if ($action === 'set_password') {
            if (!isset($_POST['cpbs_customer_set_password_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_customer_set_password_nonce'])), 'cpbs_customer_set_password')) {
                return $this->set_customer_account_notice('error', __('Security check failed. Please try again.', 'cpbs-combined-extensions'));
            }

            $login = isset($_POST['cpbs_login']) ? sanitize_user(wp_unslash($_POST['cpbs_login'])) : '';
            $key = isset($_POST['cpbs_key']) ? sanitize_text_field(wp_unslash($_POST['cpbs_key'])) : '';
            $password = isset($_POST['cpbs_new_password']) ? (string) wp_unslash($_POST['cpbs_new_password']) : '';
            $confirm = isset($_POST['cpbs_confirm_password']) ? (string) wp_unslash($_POST['cpbs_confirm_password']) : '';

            if (strlen($password) < 8) {
                return $this->set_customer_account_notice('error', __('Please use at least 8 characters for your password.', 'cpbs-combined-extensions'));
            }

            if ($password !== $confirm) {
                return $this->set_customer_account_notice('error', __('Password confirmation does not match.', 'cpbs-combined-extensions'));
            }

            $user = check_password_reset_key($key, $login);
            if (is_wp_error($user)) {
                return $this->set_customer_account_notice('error', __('This secure link is invalid or has expired. Request a new link below.', 'cpbs-combined-extensions'));
            }

            reset_password($user, $password);
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true, is_ssl());

            $this->safe_redirect($this->get_reservations_page_url());
            return $this->set_customer_account_notice('success', __('Password updated.', 'cpbs-combined-extensions'));
        }

        return $this->customer_account_notice;
    }

    private function set_customer_account_notice($type, $message)
    {
        $this->customer_account_notice = array(
            'type' => $type,
            'message' => $message,
        );

        return $this->customer_account_notice;
    }

    private function enqueue_customer_portal_assets()
    {
        $handle = 'cpbs-combined-customer-portal';

        if (!wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                dirname(plugin_dir_url(__FILE__)) . '/cpbs-combined-customer-portal.js',
                array('jquery'),
                self::VERSION,
                true
            );
        }

        wp_enqueue_script($handle);

        wp_localize_script($handle, 'cpbsCustomerPortal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cancelAction' => 'cpbs_cancel_booking',
            'redirectUrl' => $this->get_reservations_page_url(),
            'i18n' => array(
                'confirm' => __('Cancel this reservation?', 'cpbs-combined-extensions'),
                'processing' => __('Cancelling...', 'cpbs-combined-extensions'),
                'success' => __('Reservation cancelled.', 'cpbs-combined-extensions'),
                'genericError' => __('The reservation could not be cancelled.', 'cpbs-combined-extensions'),
            ),
        ));
    }

    private function customer_has_linked_booking($user_id)
    {
        $query = new WP_Query(array(
            'post_type' => $this->get_booking_post_type(),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'linked_wp_user_id',
            'meta_value' => (int) $user_id,
        ));

        $has_booking = $query->have_posts();
        wp_reset_postdata();

        return $has_booking;
    }

    private function is_password_setup_request()
    {
        return isset($_GET['cpbs_account_action']) && sanitize_key(wp_unslash($_GET['cpbs_account_action'])) === 'set_password';
    }

    private function render_customer_password_setup($notice = array())
    {
        $login = isset($_GET['login']) ? sanitize_user(wp_unslash($_GET['login'])) : '';
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        ob_start();
        echo $this->get_customer_portal_styles();
        echo $this->get_customer_portal_inline_script();
        ?>
        <div class="cpbs-customer-portal cpbs-auth-wrap">
            <div class="cpbs-auth-panel">
                <span class="cpbs-portal-kicker"><?php echo esc_html__('Secure Access', 'cpbs-combined-extensions'); ?></span>
                <h2><?php echo esc_html__('Create your password', 'cpbs-combined-extensions'); ?></h2>
                <?php if (!empty($notice['message'])) : ?>
                    <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
                <?php endif; ?>
                <form method="post" class="cpbs-auth-form">
                    <input type="hidden" name="cpbs_customer_account_action" value="set_password" />
                    <input type="hidden" name="cpbs_login" value="<?php echo esc_attr($login); ?>" />
                    <input type="hidden" name="cpbs_key" value="<?php echo esc_attr($key); ?>" />
                    <?php wp_nonce_field('cpbs_customer_set_password', 'cpbs_customer_set_password_nonce'); ?>
                    <label>
                        <span><?php echo esc_html__('New password', 'cpbs-combined-extensions'); ?></span>
                        <input type="password" name="cpbs_new_password" autocomplete="new-password" required minlength="8" />
                    </label>
                    <label>
                        <span><?php echo esc_html__('Confirm password', 'cpbs-combined-extensions'); ?></span>
                        <input type="password" name="cpbs_confirm_password" autocomplete="new-password" required minlength="8" />
                    </label>
                    <button class="cpbs-button" type="submit"><?php echo esc_html__('Continue to reservations', 'cpbs-combined-extensions'); ?></button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_customer_access_panel($notice = array())
    {
        ob_start();
        echo $this->get_customer_portal_styles();
        echo $this->get_customer_portal_inline_script();
        ?>
        <div class="cpbs-customer-portal cpbs-auth-wrap">
            <div class="cpbs-auth-panel">
                <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                <h2><?php echo esc_html__('Access your parking', 'cpbs-combined-extensions'); ?></h2>
                <?php if (!empty($notice['message'])) : ?>
                    <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
                <?php endif; ?>
                <form method="post" class="cpbs-auth-form">
                    <input type="hidden" name="cpbs_customer_account_action" value="login" />
                    <?php wp_nonce_field('cpbs_customer_login', 'cpbs_customer_login_nonce'); ?>
                    <label>
                        <span><?php echo esc_html__('Email address', 'cpbs-combined-extensions'); ?></span>
                        <input type="email" name="cpbs_customer_email" autocomplete="email" required />
                    </label>
                    <label>
                        <span><?php echo esc_html__('Password', 'cpbs-combined-extensions'); ?></span>
                        <input type="password" name="cpbs_customer_password" autocomplete="current-password" required />
                    </label>
                    <button class="cpbs-button" type="submit"><?php echo esc_html__('Sign in', 'cpbs-combined-extensions'); ?></button>
                </form>
                <form method="post" class="cpbs-link-form">
                    <input type="hidden" name="cpbs_customer_account_action" value="send_setup_link" />
                    <?php wp_nonce_field('cpbs_customer_send_setup_link', 'cpbs_customer_link_nonce'); ?>
                    <label>
                        <span><?php echo esc_html__('Need a secure setup link?', 'cpbs-combined-extensions'); ?></span>
                        <input type="email" name="cpbs_customer_email" autocomplete="email" required />
                    </label>
                    <button class="cpbs-text-button" type="submit"><?php echo esc_html__('Email me a secure link', 'cpbs-combined-extensions'); ?></button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function can_customer_cancel_booking($post_id, $meta = array())
    {
        if (empty($meta)) {
            $meta = $this->get_booking_meta($post_id);
        }

        $status_id = (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0);
        // Status must be 1 (Pending) or 2 (Processing)
        if ($status_id !== 1 && $status_id !== 2) {
            $this->log_runtime('Cancellation check failed: Invalid status', array('booking_id' => $post_id, 'status_id' => $status_id));
            return false;
        }

        // Must NOT be confirmed (no tracking link clicked)
        $confirmed_at = get_post_meta($post_id, 'automation_tracking_clicked_at', true);
        if ($confirmed_at) {
            $this->log_runtime('Cancellation check failed: Already confirmed', array('booking_id' => $post_id, 'confirmed_at' => $confirmed_at));
            return false;
        }

        // Must be more than cutoff hours before entry
        $entry_dt = !empty($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '';
        if (!$entry_dt) {
            $this->log_runtime('Cancellation check failed: No entry datetime', array('booking_id' => $post_id));
            return false;
        }

        try {
            $settings = $this->get_automation_settings();
            $cutoff_hours = (int) $settings['cancellation_cutoff_hours'];
            $now = $this->get_site_now();
            $entry = $this->build_site_datetime($entry_dt);

            if (!$entry) {
                $this->log_runtime('Cancellation check failed: Invalid entry datetime', array('booking_id' => $post_id, 'entry_dt' => $entry_dt));
                return false;
            }

            $cutoff_time = $entry->sub(new DateInterval('PT' . $cutoff_hours . 'H'));
            $can_cancel = $now < $cutoff_time;
            
            $this->log_runtime('Cancellation check result', array(
                'booking_id' => $post_id,
                'entry_time' => $entry->format('Y-m-d H:i:s'),
                'current_time' => $now->format('Y-m-d H:i:s'),
                'cutoff_time' => $cutoff_time->format('Y-m-d H:i:s'),
                'cutoff_hours' => $cutoff_hours,
                'can_cancel' => $can_cancel ? 'YES' : 'NO',
            ));
            
            return $can_cancel;
        } catch (Exception $e) {
            $this->log_runtime('Cancellation check exception', array('booking_id' => $post_id, 'error' => $e->getMessage()));
            return false;
        }
    }

    public function ajax_cancel_booking()
    {
        // Security checks
        $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
        if (!$booking_id) {
            wp_send_json_error(array('message' => __('Invalid booking ID.', 'cpbs-combined-extensions')), 400);
        }

        check_ajax_referer('cpbs_cancel_booking_' . $booking_id, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Not authenticated.', 'cpbs-combined-extensions')), 401);
        }

        $current_user_id = get_current_user_id();

        // Verify booking belongs to current user
        $linked_user_id = (int) get_post_meta($booking_id, 'linked_wp_user_id', true);
        if ($linked_user_id !== $current_user_id) {
            wp_send_json_error(array('message' => __('Unauthorized.', 'cpbs-combined-extensions')), 403);
        }

        // Re-validate all cancellation conditions
        $meta = $this->get_booking_meta($booking_id);
        
        $this->log_runtime('Cancellation AJAX request', array('booking_id' => $booking_id, 'meta_keys' => array_keys($meta)));
        
        if (!$this->can_customer_cancel_booking($booking_id, $meta)) {
            $this->log_runtime('Cancellation validation failed', array('booking_id' => $booking_id, 'status_id' => (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0)));
            wp_send_json_error(array(
                'message' => __('This booking cannot be cancelled.', 'cpbs-combined-extensions'),
                'status' => (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0),
            ), 400);
        }

        $this->log_runtime('Cancellation validation passed, proceeding with cancellation', array('booking_id' => $booking_id));

        // Determine refund eligibility
        $settings = $this->get_automation_settings();
        $cutoff_hours = (int) $settings['cancellation_cutoff_hours'];

        $entry_dt = !empty($meta['entry_datetime_2']) ? $meta['entry_datetime_2'] : '';
        $entry = $this->build_site_datetime($entry_dt);
        if (!$entry) {
            wp_send_json_error(array('message' => __('This booking cannot be cancelled because the entry time is invalid.', 'cpbs-combined-extensions')), 422);
        }
        $now = $this->get_site_now();
        $cutoff_time = $entry->sub(new DateInterval('PT' . $cutoff_hours . 'H'));

        $eligible_for_refund = ($now < $cutoff_time);

        // Store cancellation info
        $old_status_id = (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0);
        $this->update_booking_meta($booking_id, 'cancellation_requested_at', $now->format('Y-m-d H:i:s'));
        $this->update_booking_meta($booking_id, 'cancellation_original_status', $old_status_id);

        // Update booking status
        $new_status = 3;
        $this->update_booking_meta($booking_id, 'booking_status_id', $new_status);

        // Free up parking spot for cancelled bookings
        if ($new_status === 3) {
            $this->ensure_status_nonblocking($new_status);
        }

        // Send SMS
        $contact = $this->get_booking_contact($booking_id, $meta);
        $sms_sent = false;
        $exit_dt = !empty($meta['exit_datetime_2']) ? $meta['exit_datetime_2'] : '';
        $exit = $exit_dt !== '' ? $this->build_site_datetime($exit_dt) : null;
        $sms_debug = array();
        
        error_log('=== ABOUT TO SEND CANCELLATION SMS === Booking: ' . $booking_id);
        error_log('Contact Phone: ' . (!empty($contact['phone']) ? substr($contact['phone'], 0, 5) . '****' : 'NONE'));
        error_log('Settings SMS Enabled: ' . (!empty($settings['enable_sms']) ? 'YES' : 'NO'));
        
        $sms_sent = $this->send_cancellation_sms($booking_id, $meta, $entry, $exit, $contact, $settings, $eligible_for_refund, $now, true, $sms_debug);

        // Send customer confirmation email
        $email_sent = false;
        if ((int) $settings['enable_email'] && !empty($contact['email'])) {
            $email_template_key = $eligible_for_refund ? 'cancellation_refund_email_body' : 'cancellation_no_refund_email_body';
            $email_subject = $settings['cancellation_email_subject'];
            $email_body = $settings[$email_template_key];

            $tokens = $this->build_message_tokens($booking_id, $meta, $entry, $exit);
            $tokens['{customer_name}'] = !empty($contact['name']) ? $contact['name'] : __('Customer', 'cpbs-combined-extensions');
            $tokens['{customer_email}'] = $contact['email'];
            $tokens['{refund_eligible}'] = $eligible_for_refund ? 'Yes' : 'No';
            $tokens['{timestamp}'] = $now->format('d-m-Y H:i');
            
            $email_body = $this->replace_tokens($email_body, $tokens);
            $email_subject = $this->replace_tokens($email_subject, $tokens);

            $email_sent = wp_mail($contact['email'], $email_subject, $email_body, array('Content-Type: text/html; charset=UTF-8'));
            $this->log_runtime($email_sent ? 'Cancellation email sent to customer' : 'Cancellation email to customer failed', array(
                'booking_id' => $booking_id,
                'customer_email' => $contact['email'],
            ));
        } else {
            $this->log_runtime('Customer email not sent', array(
                'booking_id' => $booking_id,
                'email_enabled' => (int) $settings['enable_email'],
                'has_email' => !empty($contact['email']),
            ));
        }

        // Send admin notification email
        $admin_email_sent = false;
        if ((int) $settings['enable_email']) {
            $admin_email = get_option('admin_email', '');
            if (!empty($admin_email)) {
                $email_subject = $settings['cancellation_admin_email_subject'];
                $email_body = $settings['cancellation_admin_email_body'];

                $tokens = $this->build_message_tokens($booking_id, $meta, $entry, $exit);
                $tokens['{customer_email}'] = $contact['email'];
                $tokens['{customer_name}'] = !empty($contact['name']) ? $contact['name'] : __('Customer', 'cpbs-combined-extensions');
                $tokens['{refund_eligible}'] = $eligible_for_refund ? 'Yes' : 'No';
                $tokens['{timestamp}'] = $now->format('d-m-Y H:i');

                $email_body = $this->replace_tokens($email_body, $tokens);
                $email_subject = $this->replace_tokens($email_subject, $tokens);

                $admin_email_sent = wp_mail($admin_email, $email_subject, $email_body, array('Content-Type: text/html; charset=UTF-8'));
                $this->log_runtime($admin_email_sent ? 'Cancellation notification sent to admin' : 'Cancellation notification to admin failed', array(
                    'booking_id' => $booking_id,
                    'admin_email' => $admin_email,
                ));
            } else {
                $this->log_runtime('Admin email not configured', array('booking_id' => $booking_id));
            }
        }

        $message = $eligible_for_refund
            ? __('Booking cancelled. You are eligible for a refund.', 'cpbs-combined-extensions')
            : __('Booking cancelled. Refund is not applicable.', 'cpbs-combined-extensions');

        if ($contact['phone'] && !$sms_sent) {
            $message .= ' ' . __('The cancellation SMS could not be sent.', 'cpbs-combined-extensions');
        }

        if (!empty($contact['email']) && !$email_sent) {
            $message .= ' ' . __('The confirmation email could not be sent.', 'cpbs-combined-extensions');
        }

        $this->log_runtime('Cancellation completed', array(
            'booking_id' => $booking_id,
            'sms_sent' => $sms_sent,
            'customer_email_sent' => $email_sent,
            'admin_email_sent' => $admin_email_sent,
        ));

        wp_send_json_success(array(
            'message' => $message,
            'status' => $new_status,
            'refund_eligible' => $eligible_for_refund,
            'sms_sent' => $sms_sent,
            'email_sent' => $email_sent,
            'admin_notified' => $admin_email_sent,
            'sms_debug' => $sms_debug,
        ));
    }

    private function get_automation_settings()
    {
        $option_key = 'cpbs_combined_booking_automation_settings';
        $stored = get_option($option_key, array());
        $stored = is_array($stored) ? $stored : array();

        $defaults = array(
            'enable_email' => 1,
            'enable_sms' => 1,
            'enable_runtime_log' => 1,
            'cancellation_cutoff_hours' => 2,
            'cancellation_refund_sms' => 'SpotAPark: Your reservation #{booking_id} cancellation is confirmed. You are eligible for a refund.',
            'cancellation_no_refund_sms' => 'SpotAPark: Your reservation #{booking_id} has been cancelled. Refund is not applicable.',
            'cancellation_email_subject' => 'Reservation Cancellation Confirmed - #{booking_id}',
            'cancellation_refund_email_body' => 'Hello {customer_name},\n\nYour reservation #{booking_id} has been successfully cancelled.\n\nDetails:\nLocation: {location_name}\nEntry: {booking_start}\nExit: {booking_end}\n\nYou are eligible for a full refund. Please allow 3-5 business days for the refund to appear in your account.\n\nThank you for using SpotAPark!\n\nIf you have any questions, please contact our support team.',
            'cancellation_no_refund_email_body' => 'Hello {customer_name},\n\nYour reservation #{booking_id} has been successfully cancelled.\n\nDetails:\nLocation: {location_name}\nEntry: {booking_start}\nExit: {booking_end}\n\nUnfortunately, no refund is applicable for this cancellation based on our cancellation policy.\n\nThank you for using SpotAPark!\n\nIf you have any questions, please contact our support team.',
            'cancellation_admin_email_subject' => 'Reservation Cancelled by Customer - #{booking_id}',
            'cancellation_admin_email_body' => 'A reservation has been cancelled by the customer.\n\nBooking ID: {booking_id}\nCustomer: {customer_name}\nEmail: {customer_email}\nLocation: {location_name}\nEntry: {booking_start}\nExit: {booking_end}\n\nRefund Eligible: {refund_eligible}\nCancellation Time: {timestamp}',
            'new_account_email_subject' => 'Welcome to SpotAPark - Set Your Password',
            'new_account_email_body' => 'Hello {customer_name},\n\nYour account has been created on SpotAPark.\n\nFIRST TIME LOGIN INSTRUCTIONS:\n1. Click the link below to set your password\n2. Set a secure password\n3. Log in with your email and password\n4. Access your booking dashboard\n\nPassword Setup Link: {password_reset_link}\n\nThank you!',
            'new_account_sms_body' => 'Welcome to SpotAPark! Your account created. Check your email for password setup instructions.',
        );

        return wp_parse_args($stored, $defaults);
    }

    private function get_sms_settings()
    {
        return CPBSCombinedHelpers::get_sms_settings();
    }

    private function get_password_reset_link($user_id)
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return '';
        }

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return '';
        }

        $reset_url = add_query_arg(array(
            'cpbs_account_action' => 'set_password',
            'key' => $key,
            'login' => $user->user_login,
        ), $this->get_reservations_page_url());

        return $reset_url;
    }

    private function get_reservations_page_url()
    {
        $url = '';

        if (is_singular()) {
            $permalink = get_permalink();
            if (is_string($permalink) && $permalink !== '') {
                $url = $permalink;
            }
        }

        if ($url === '') {
            $page = get_page_by_path('reservations');
            if ($page instanceof WP_Post) {
                $permalink = get_permalink($page);
                if (is_string($permalink) && $permalink !== '') {
                    $url = $permalink;
                }
            }
        }

        if ($url === '') {
            $url = home_url('/reservations/');
        }

        return apply_filters('cpbs_combined_customer_reservations_page_url', $url);
    }

    private function safe_redirect($url)
    {
        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }

        echo '<script>window.location.href=' . wp_json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '"></noscript>';
        exit;
    }

    private function get_customer_portal_styles()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }

        $printed = true;

        ob_start();
        ?>
        <style>
            .cpbs-customer-portal{--cpbs-ink:#17201b;--cpbs-muted:#68736d;--cpbs-line:#e2e7e3;--cpbs-soft:#f6f8f6;--cpbs-accent:#1f7a4d;--cpbs-danger:#b42318;font-family:inherit;color:var(--cpbs-ink);max-width:1080px;margin:0 auto;padding:18px 0}
            .cpbs-portal-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:22px;border-bottom:1px solid var(--cpbs-line);padding-bottom:16px}
            .cpbs-portal-kicker{display:block;color:var(--cpbs-accent);font-size:12px;font-weight:700;letter-spacing:0;text-transform:uppercase;margin-bottom:6px}
            .cpbs-portal-header h2,.cpbs-auth-panel h2{font-size:30px;line-height:1.15;margin:0;color:var(--cpbs-ink)}
            .cpbs-portal-link,.cpbs-text-button{appearance:none;background:transparent;border:0;color:var(--cpbs-accent);font-weight:700;text-decoration:none;cursor:pointer;padding:0}
            .cpbs-reservation-grid{display:flex;flex-direction:column;gap:14px}
            .cpbs-reservation-card{border:1px solid var(--cpbs-line);border-radius:10px;background:#fff;padding:18px 20px;box-shadow:0 8px 24px rgba(23,32,27,.05)}
            .cpbs-card-topline{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
            .cpbs-booking-number{font-size:13px;color:var(--cpbs-muted);font-weight:700}
            .cpbs-reservation-card h3{font-size:20px;line-height:1.25;margin:0 0 16px;color:var(--cpbs-ink)}
            .cpbs-card-times{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:18px}
            .cpbs-card-times div{background:var(--cpbs-soft);border-radius:8px;padding:12px}
            .cpbs-card-times span{display:block;color:var(--cpbs-muted);font-size:12px;font-weight:700;margin-bottom:4px}
            .cpbs-card-times strong{display:block;font-size:14px;line-height:1.35;color:var(--cpbs-ink)}
            .cpbs-card-actions{display:flex;align-items:center;justify-content:flex-end;min-height:38px}
            .cpbs-button{appearance:none;border:0;border-radius:8px;background:var(--cpbs-accent);color:#fff;cursor:pointer;font-weight:700;line-height:1;padding:13px 16px;text-decoration:none}
            .cpbs-button-danger{background:var(--cpbs-danger)}
            .cpbs-muted-action{color:var(--cpbs-muted);font-size:13px}
            .cpbs-status-badge{display:inline-flex;align-items:center;border-radius:999px;font-size:12px;font-weight:700;line-height:1;padding:7px 10px;background:#eef7f1;color:#17633d}
            .cpbs-status-badge.status-cancelled,.cpbs-status-badge.status-refund{background:#fff1f0;color:#b42318}
            .cpbs-status-badge.status-completed{background:#edf3ff;color:#2f5597}
            .cpbs-status-badge.status-pending{background:#fff7e6;color:#915c00}
            .cpbs-status-badge.status-unknown{background:#f1f3f2;color:#5f6963}
            .cpbs-empty-state,.cpbs-auth-panel{border:1px solid var(--cpbs-line);border-radius:8px;background:#fff;padding:26px;box-shadow:0 10px 28px rgba(23,32,27,.06)}
            .cpbs-empty-state h3{margin:0 0 8px;font-size:20px}.cpbs-empty-state p{margin:0;color:var(--cpbs-muted)}
            .cpbs-auth-wrap{max-width:520px}.cpbs-auth-panel h2{margin-bottom:18px}
            .cpbs-auth-form,.cpbs-link-form{display:grid;gap:14px}.cpbs-link-form{border-top:1px solid var(--cpbs-line);margin-top:18px;padding-top:18px}
            .cpbs-auth-form label,.cpbs-link-form label{display:grid;gap:7px;color:var(--cpbs-muted);font-size:13px;font-weight:700}
            .cpbs-auth-form input,.cpbs-link-form input{width:100%;border:1px solid var(--cpbs-line);border-radius:8px;background:#fff;color:var(--cpbs-ink);font-size:16px;line-height:1.2;padding:12px 13px;box-sizing:border-box}
            .cpbs-portal-notice{border-radius:8px;margin:0 0 16px;padding:12px 14px;font-weight:700;font-size:14px;background:#eef7f1;color:#17633d}
            .cpbs-portal-notice.error{background:#fff1f0;color:#b42318}
            button.cpbs-text-button {padding: 10px 20px;background-color: #007bff;color: #fff;border: none;border-radius: 4px;cursor: pointer;transition: background-color 0.3s;text-decoration: none;}
            button.cpbs-text-button:hover {background-color: #005a87;color: #fff;}
            @media (max-width:640px){.cpbs-customer-portal{padding:12px 0}.cpbs-portal-header{align-items:flex-start;flex-direction:column}.cpbs-card-times{grid-template-columns:1fr}.cpbs-portal-header h2,.cpbs-auth-panel h2{font-size:26px}.cpbs-reservation-card{padding:16px}}
        </style>
        <?php
        return ob_get_clean();
    }

    private function get_customer_portal_inline_script()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }

        $printed = true;
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cancelAction' => 'cpbs_cancel_booking',
            'redirectUrl' => $this->get_reservations_page_url(),
            'i18n' => array(
                'confirm' => __('Cancel this reservation?', 'cpbs-combined-extensions'),
                'processing' => __('Cancelling...', 'cpbs-combined-extensions'),
                'success' => __('Reservation cancelled.', 'cpbs-combined-extensions'),
                'genericError' => __('The reservation could not be cancelled.', 'cpbs-combined-extensions'),
            ),
        );

        ob_start();
        ?>
        <script>
        (function (window, document) {
            if (window.__cpbsCustomerPortalBound) {
                return;
            }
            window.__cpbsCustomerPortalBound = true;

            var config = <?php echo wp_json_encode($config); ?>;

            function notice(message, isError) {
                var portal = document.querySelector('.cpbs-customer-portal');
                if (!portal) {
                    return;
                }

                var box = portal.querySelector('.cpbs-portal-notice');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'cpbs-portal-notice';
                    portal.insertBefore(box, portal.firstChild);
                }

                box.classList.toggle('error', !!isError);
                box.textContent = message || '';
            }

            function setBusy(button, busy) {
                if (busy) {
                    if (!button.dataset.cpbsOriginalLabel) {
                        button.dataset.cpbsOriginalLabel = button.textContent || '';
                    }
                    button.disabled = true;
                    button.textContent = config.i18n.processing || 'Cancelling...';
                    return;
                }

                button.disabled = false;
                if (button.dataset.cpbsOriginalLabel) {
                    button.textContent = button.dataset.cpbsOriginalLabel;
                }
            }

            document.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('.cpbs-cancel-booking-btn') : null;
                if (!button) {
                    return;
                }

                event.preventDefault();

                var bookingId = Number(button.getAttribute('data-booking-id') || 0);
                var nonce = String(button.getAttribute('data-nonce') || '');

                if (!bookingId || !nonce) {
                    notice(config.i18n.genericError || 'The reservation could not be cancelled.', true);
                    return;
                }

                if (window.confirm(config.i18n.confirm || 'Cancel this reservation?') !== true) {
                    return;
                }

                setBusy(button, true);
                notice('', false);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', config.ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) {
                        return;
                    }

                    var payload = null;
                    try {
                        payload = JSON.parse(xhr.responseText || '{}');
                    } catch (e) {
                        payload = null;
                    }

                    if (xhr.status >= 200 && xhr.status < 300 && payload && payload.success) {
                        notice((payload.data && payload.data.message) ? payload.data.message : (config.i18n.success || 'Reservation cancelled.'), false);
                        window.setTimeout(function () {
                            window.location.href = config.redirectUrl || window.location.href;
                        }, 450);
                        return;
                    }

                    var message = config.i18n.genericError || 'The reservation could not be cancelled.';
                    if (payload && payload.data && payload.data.message) {
                        message = payload.data.message;
                    }
                    notice(message, true);
                    setBusy(button, false);
                };

                xhr.send(
                    'action=' + encodeURIComponent(config.cancelAction || 'cpbs_cancel_booking') +
                    '&booking_id=' + encodeURIComponent(String(bookingId)) +
                    '&nonce=' + encodeURIComponent(nonce)
                );
            });
        })(window, document);
        </script>
        <?php
        return ob_get_clean();
    }

    private function log_runtime($message, array $context = array())
    {
        $settings = $this->get_automation_settings();
        if (!(int) $settings['enable_runtime_log']) {
            return;
        }

        $log_option = 'cpbs_combined_booking_automation_runtime_log';
        $log = get_option($log_option, array());
        if (!is_array($log)) {
            $log = array();
        }

        $entry = array(
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'context' => $context,
        );

        // Keep only last 100 entries
        $log[] = $entry;
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }

        update_option($log_option, $log);
    }

    private function get_site_now()
    {
        try {
            if (function_exists('wp_timezone')) {
                $timezone = wp_timezone();
            } else {
                $tz_string = get_option('timezone_string', 'UTC');
                $timezone = new DateTimeZone($tz_string);
            }
            $now = new DateTimeImmutable('now', $timezone);
            return $now;
        } catch (Exception $e) {
            $this->log_runtime('get_site_now exception', array('error' => $e->getMessage()));
            return new DateTimeImmutable('now');
        }
    }

    private function build_site_datetime($datetime_str)
    {
        if (empty($datetime_str)) {
            return CPBSCombinedHelpers::build_site_datetime($datetime_str);
        }

        try {
            if (function_exists('wp_timezone')) {
                $timezone = wp_timezone();
            } else {
                $tz_string = get_option('timezone_string', 'UTC');
                $timezone = new DateTimeZone($tz_string);
            }
            $dt = new DateTimeImmutable($datetime_str, $timezone);
            return $dt;
        } catch (Exception $e) {
            $this->log_runtime('build_site_datetime exception', array('datetime_str' => $datetime_str, 'error' => $e->getMessage()));
            return null;
        }
    }

    private function replace_tokens($message, $tokens)
    {
        $search = array_keys($tokens);
        $replace = array_values($tokens);
        return str_replace($search, $replace, $message);
    }

    private function send_cancellation_sms($booking_id, $meta, $entry, $exit, $contact, $settings, $eligible_for_refund, \DateTimeImmutable $now, $mark_sent = false, array &$debug = array())
    {
        error_log('=== CANCELLATION SMS START === Booking: ' . $booking_id);
        
        $debug = array(
            'booking_id' => (int) $booking_id,
            'eligible_for_refund' => (bool) $eligible_for_refund,
            'enable_sms' => isset($settings['enable_sms']) ? (int) $settings['enable_sms'] : null,
            'has_phone' => !empty($contact['phone']),
            'phone' => !empty($contact['phone']) ? substr($contact['phone'], 0, 5) . '****' : 'NONE',
            'template_key' => $eligible_for_refund ? 'cancellation_refund_sms' : 'cancellation_no_refund_sms',
            'template_length' => 0,
            'rendered_length' => 0,
            'twilio_account_sid_present' => false,
            'twilio_auth_token_present' => false,
            'twilio_from_number_present' => false,
            'stage' => 'start',
            'reason' => '',
        );

        error_log('Phone present: ' . (!empty($contact['phone']) ? 'YES' : 'NO'));
        
        if (empty($contact['phone'])) {
            error_log('STOP: No phone number');
            $debug['stage'] = 'phone';
            $debug['reason'] = 'missing_phone';
            $this->log_runtime('Cancellation SMS blocked', $debug);
            return false;
        }

        error_log('SMS Enable Setting: ' . (isset($settings['enable_sms']) ? (int) $settings['enable_sms'] : 'NOT SET'));
        
        if (isset($settings['enable_sms']) && (int) $settings['enable_sms'] !== 1) {
            error_log('STOP: SMS disabled in settings');
            $debug['stage'] = 'settings';
            $debug['reason'] = 'sms_disabled';
            $this->log_runtime('Cancellation SMS blocked', $debug);
            return false;
        }

        $sms_template_key = $eligible_for_refund ? 'cancellation_refund_sms' : 'cancellation_no_refund_sms';
        $sms_template = isset($settings[$sms_template_key]) ? (string) $settings[$sms_template_key] : '';
        $debug['template_key'] = $sms_template_key;
        $debug['template_length'] = strlen($sms_template);
        
        error_log('Template Key: ' . $sms_template_key . ', Template Length: ' . strlen($sms_template));
        
        if (trim($sms_template) === '') {
            error_log('STOP: Empty SMS template');
            $debug['stage'] = 'template';
            $debug['reason'] = 'empty_template';
            $this->log_runtime('Cancellation SMS blocked', $debug);
            return false;
        }

        $tokens = $this->build_message_tokens($booking_id, $meta, $entry, $exit);
        $tokens['{customer_name}'] = !empty($contact['name']) ? $contact['name'] : __('Customer', 'cpbs-combined-extensions');
        $tokens['{timestamp}'] = $now->format('d-m-Y H:i');
        $sms_body = $this->replace_tokens($sms_template, $tokens);
        $debug['rendered_length'] = strlen($sms_body);

        error_log('SMS Body rendered, length: ' . strlen($sms_body));
        
        if (trim($sms_body) === '') {
            error_log('STOP: Rendered SMS body is empty');
            $debug['stage'] = 'rendered_body';
            $debug['reason'] = 'empty_rendered_body';
            $this->log_runtime('Cancellation SMS blocked', $debug);
            return false;
        }

        $sms_settings = $this->get_sms_settings();
        $debug['twilio_account_sid_present'] = !empty($sms_settings['twilio_account_sid']);
        $debug['twilio_auth_token_present'] = !empty($sms_settings['twilio_auth_token']);
        $debug['twilio_from_number_present'] = !empty($sms_settings['twilio_from_number']);
        
        error_log('Twilio Settings - SID: ' . ($debug['twilio_account_sid_present'] ? 'YES' : 'NO') . 
                  ', Token: ' . ($debug['twilio_auth_token_present'] ? 'YES' : 'NO') . 
                  ', From: ' . ($debug['twilio_from_number_present'] ? 'YES' : 'NO'));
        
        if (empty($sms_settings['twilio_account_sid']) || empty($sms_settings['twilio_auth_token']) || empty($sms_settings['twilio_from_number'])) {
            error_log('STOP: Missing Twilio settings');
            $debug['stage'] = 'twilio_settings';
            $debug['reason'] = 'missing_twilio_settings';
            $this->log_runtime('Cancellation SMS blocked', $debug);
            return false;
        }

        error_log('Attempting to send SMS to ' . substr($contact['phone'], 0, 5) . '****');
        
        $debug['stage'] = 'twilio_send';
        $sms_result = $this->send_twilio_sms($contact['phone'], $sms_body);
        $sms_sent = !is_wp_error($sms_result);

        error_log('SMS Result: ' . ($sms_sent ? 'SUCCESS' : 'FAILED'));
        if (is_wp_error($sms_result)) {
            error_log('Error: ' . $sms_result->get_error_message());
        }

        if ($mark_sent && $sms_sent) {
            $this->update_booking_meta($booking_id, 'cancellation_sms_sent_at', $now->format('Y-m-d H:i:s'));
            error_log('Marked SMS as sent in booking meta');
        }

        $debug['reason'] = $sms_sent ? 'sent' : 'twilio_failed';
        $debug['twilio_error'] = is_wp_error($sms_result) ? $sms_result->get_error_message() : 'No error';
        $this->log_runtime($sms_sent ? 'Cancellation SMS sent' : 'Cancellation SMS failed', $debug);

        error_log('=== CANCELLATION SMS END === Result: ' . ($sms_sent ? 'SENT' : 'FAILED'));

        return $sms_sent;
    }

    private function send_twilio_sms($phone, $body)
    {
        return CPBSCombinedHelpers::send_twilio_sms($phone, $body);
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

        if (!in_array($status_id, $normalized, true)) {
            $normalized[] = $status_id;
        }

        \CPBSOption::updateOption(
            array('booking_status_nonblocking' => array_values($normalized))
        );
    }

    private function get_booking_meta($post_id)
    {
        return CPBSCombinedHelpers::get_booking_meta($post_id);
    }

    private function update_booking_meta($post_id, $key, $value)
    {
        CPBSCombinedHelpers::update_booking_meta($post_id, $key, $value);
    }

    private function get_booking_contact($post_id, $meta = array())
    {
        if (empty($meta)) {
            $meta = $this->get_booking_meta($post_id);
        }

        $first_name = isset($meta['client_contact_detail_first_name']) ? sanitize_text_field((string) $meta['client_contact_detail_first_name']) : '';
        $last_name = isset($meta['client_contact_detail_last_name']) ? sanitize_text_field((string) $meta['client_contact_detail_last_name']) : '';
        $name = trim($first_name . ' ' . $last_name);

        if ($name === '' && isset($meta['customer_name'])) {
            $name = sanitize_text_field((string) $meta['customer_name']);
        }

        $email_sources = array(
            isset($meta['client_contact_detail_email_address']) ? $meta['client_contact_detail_email_address'] : '',
            isset($meta['email_address']) ? $meta['email_address'] : '',
            isset($meta['email']) ? $meta['email'] : '',
            isset($meta['billing_email']) ? $meta['billing_email'] : '',
            isset($meta['customer_email']) ? $meta['customer_email'] : '',
            isset($meta['contact_email']) ? $meta['contact_email'] : '',
            get_post_meta($post_id, $this->get_meta_prefix() . 'client_contact_detail_email_address', true),
            get_post_meta($post_id, 'customer_email', true),
        );

        $email = '';
        foreach ($email_sources as $candidate) {
            $candidate = sanitize_email((string) $candidate);
            if ($candidate !== '' && is_email($candidate)) {
                $email = $candidate;
                break;
            }
        }

        $phone_sources = array(
            isset($meta['client_contact_detail_phone_number']) ? $meta['client_contact_detail_phone_number'] : '',
            isset($meta['phone_number']) ? $meta['phone_number'] : '',
            isset($meta['phone']) ? $meta['phone'] : '',
            isset($meta['billing_phone']) ? $meta['billing_phone'] : '',
            isset($meta['customer_phone']) ? $meta['customer_phone'] : '',
            isset($meta['contact_phone']) ? $meta['contact_phone'] : '',
            get_post_meta($post_id, $this->get_meta_prefix() . 'client_contact_detail_phone_number', true),
            get_post_meta($post_id, 'customer_phone', true),
        );

        $phone = '';
        foreach ($phone_sources as $candidate) {
            $candidate = sanitize_text_field((string) $candidate);
            if ($candidate !== '') {
                $phone = $candidate;
                break;
            }
        }

        return array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        );
    }

    private function build_message_tokens($post_id, $meta, $entry, $exit)
    {
        $location_name = !empty($meta['location_name']) ? $meta['location_name'] : __('N/A', 'cpbs-combined-extensions');

        $entry_formatted = $entry ? $entry->format('d-m-Y H:i') : '';
        $exit_formatted = $exit ? $exit->format('d-m-Y H:i') : '';

        return array(
            '{booking_id}' => $post_id,
            '{booking_start}' => $entry_formatted,
            '{booking_end}' => $exit_formatted,
            '{location_name}' => $location_name,
        );
    }

    private function get_status_badge($status_id)
    {
        $status_id = (int) $status_id;
        $badges = array(
            1 => '<span class="cpbs-status-badge status-pending">' . esc_html__('Pending', 'cpbs-combined-extensions') . '</span>',
            2 => '<span class="cpbs-status-badge status-processing">' . esc_html__('Accepted(processing)', 'cpbs-combined-extensions') . '</span>',
            3 => '<span class="cpbs-status-badge status-cancelled">' . esc_html__('Cancelled', 'cpbs-combined-extensions') . '</span>',
            4 => '<span class="cpbs-status-badge status-completed">' . esc_html__('Completed', 'cpbs-combined-extensions') . '</span>',
            6 => '<span class="cpbs-status-badge status-refund">' . esc_html__('Refund', 'cpbs-combined-extensions') . '</span>',
        );

        return isset($badges[$status_id]) ? $badges[$status_id] : '<span class="cpbs-status-badge status-unknown">' . esc_html__('Unknown', 'cpbs-combined-extensions') . '</span>';
    }
}

/**
 * Prevents duplicate/fake bookings by checking for existing active bookings
 * with the same email, license plate, and location in overlapping time slots
 * before the booking is created. Also enforces license plate as mandatory.
 */

// ============================================================================
// Service Fee Summary
// ============================================================================
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
            dirname(plugin_dir_url(__FILE__)) . '/cpbs-combined-service-fee-summary.js',
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
