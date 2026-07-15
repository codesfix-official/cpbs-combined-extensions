<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Booking Extension
// ============================================================================
class CPBSCombinedBookingExtension
{
    const SHORTCODE = 'cpbs_booking_extend';
    const AJAX_ACTION_CREATE_CHECKOUT = 'cpbs_combined_booking_extension_checkout';
    const NONCE_ACTION = 'cpbs_combined_booking_extension_checkout_nonce';
    const WEBHOOK_ROUTE_NAMESPACE = 'cpbs-combined/v1';
    const WEBHOOK_ROUTE = '/booking-extension-webhook';
    const WEBHOOK_SETTINGS_OPTION_KEY = 'cpbs_combined_booking_automation_settings';
    const WEBHOOK_SETTINGS_SECRET_KEY = 'booking_extension_webhook_secret';
    const META_PENDING = 'extension_pending';
    const META_HISTORY = 'extension_history';
    const META_TOTAL_HOURS = 'extension_total_hours';
    const META_TOTAL_AMOUNT = 'extension_total_amount';
    const META_TOTAL_COUNT = 'extension_total_count';
    const COLUMN_HOURS = 'extension_hours';
    const COLUMN_AMOUNT = 'extension_amount';
    const LOG_FILE_NAME = 'cpbs-combined-runtime.log';
    const VERSION = '1.0.0';

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('wp_ajax_' . self::AJAX_ACTION_CREATE_CHECKOUT, array($this, 'ajax_create_checkout'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_CREATE_CHECKOUT, array($this, 'ajax_create_checkout'));
        add_action('rest_api_init', array($this, 'register_webhook_route'));
        add_action('init', array($this, 'maybe_finalize_checkout_payment'), 1);

        add_filter('manage_edit-' . $this->get_booking_post_type() . '_columns', array($this, 'register_admin_columns'), 30);
        add_action('manage_' . $this->get_booking_post_type() . '_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
    }

    public function register_webhook_route()
    {
        register_rest_route(
            self::WEBHOOK_ROUTE_NAMESPACE,
            self::WEBHOOK_ROUTE,
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_webhook_request'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_webhook_request(\WP_REST_Request $request)
    {
        $secret = $this->get_webhook_signing_secret();
        if ($secret === '') {
            return new \WP_REST_Response(
                array('message' => esc_html__('Stripe webhook signing secret is not configured.', 'cpbs-combined-extensions')),
                400
            );
        }

        $payload = $request->get_body();
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_STRIPE_SIGNATURE'])) : '';

        if ($payload === '' || $signature === '') {
            $this->log_extension_debug('Stripe webhook rejected: missing payload or signature', array(
                'has_payload' => $payload !== '',
                'has_signature' => $signature !== '',
            ));

            return new \WP_REST_Response(
                array('message' => esc_html__('Missing Stripe webhook payload or signature.', 'cpbs-combined-extensions')),
                400
            );
        }

        if (!$this->load_stripe_library()) {
            return new \WP_REST_Response(
                array('message' => esc_html__('Stripe library is not available.', 'cpbs-combined-extensions')),
                500
            );
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Throwable $exception) {
            $this->log_extension_debug('Stripe webhook verification failed', array(
                'error' => $exception->getMessage(),
            ));

            return new \WP_REST_Response(
                array('message' => esc_html__('Stripe webhook signature could not be verified.', 'cpbs-combined-extensions')),
                400
            );
        }

        $event_type = isset($event->type) ? (string) $event->type : '';
        $session_data = $this->normalize_session_payload(isset($event->data->object) ? $event->data->object : null);

        if ($event_type === 'checkout.session.completed') {
            $result = $this->finalize_extension_from_session($session_data, 0, true, 'webhook');
            if (!empty($result['success'])) {
                return new \WP_REST_Response(
                    array('message' => esc_html__('Booking extension payment verified and booking end time updated.', 'cpbs-combined-extensions')),
                    200
                );
            }

            return new \WP_REST_Response(
                array('message' => isset($result['message']) ? $result['message'] : esc_html__('Booking extension payment could not be verified.', 'cpbs-combined-extensions')),
                isset($result['code']) ? (int) $result['code'] : 400
            );
        }

        if ($event_type === 'checkout.session.async_payment_succeeded') {
            $result = $this->finalize_extension_from_session($session_data, 0, false, 'webhook');
            if (!empty($result['success'])) {
                return new \WP_REST_Response(
                    array('message' => esc_html__('Booking extension payment verified and booking end time updated.', 'cpbs-combined-extensions')),
                    200
                );
            }

            return new \WP_REST_Response(
                array('message' => isset($result['message']) ? $result['message'] : esc_html__('Booking extension payment could not be verified.', 'cpbs-combined-extensions')),
                isset($result['code']) ? (int) $result['code'] : 400
            );
        }

        if ($event_type === 'checkout.session.async_payment_failed') {
            $this->log_extension_debug('Stripe webhook received async payment failure for booking extension', array(
                'session_id' => isset($session_data['id']) ? (string) $session_data['id'] : '',
            ));
        }

        return new \WP_REST_Response(
            array('message' => esc_html__('Stripe webhook event ignored.', 'cpbs-combined-extensions')),
            200
        );
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
        //$booking_title = isset($booking['post']->post_title) ? (string) $booking['post']->post_title : '#' . $booking_id;
		$booking_title = '#' . $booking_id;

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
                .cpbs-combined-extension-pill{display:inline-block;background:#eef6ff;color:#2F5277;border:1px solid #a8c4e0;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
                .cpbs-combined-extension-notice{border-radius:10px;padding:12px 14px;margin:0 0 16px;font-size:14px}
                .cpbs-combined-extension-notice.success{background:#eef6ff;border-left:4px solid #2F5277;color:#1e3a56}
                .cpbs-combined-extension-notice.error{background:#fff8e1;border-left:4px solid #e6a800;color:#7a5000}
                .cpbs-combined-extension-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:18px}
                .cpbs-combined-extension-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
                .cpbs-combined-extension-item strong{display:block;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
                .cpbs-combined-extension-item span{font-size:15px;color:#0f172a;word-break:break-word}
                .cpbs-combined-extension-form{margin-top:6px;padding-top:16px;border-top:1px solid #e2e8f0}
                .cpbs-combined-extension-form label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px}
                .cpbs-combined-extension-form input[name="cpbs_extension_hours"]{display:block;width:100%;max-width:180px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:16px}
                .cpbs-combined-extension-estimate{margin:10px 0 14px;font-size:14px;color:#2F5277;font-weight:600}
                .cpbs-combined-extension-feedback{margin:10px 0 0;font-size:13px;color:#b91c1c}
                .cpbs-combined-extension-feedback.cpbs-state-error{font-weight:600}
                .cpbs-combined-extension-wrap .button.button-primary{background:#2F5277;color:#FFCC00;border-color:#2F5277;padding:9px 16px;border-radius:8px;font-weight:700}
                .cpbs-combined-extension-wrap .button.button-primary:hover{background:#1e3a56;border-color:#1e3a56;color:#FFCC00}
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
                'booking_id' => $booking_id,
                'access_token' => $access_token,
            ),
            $base_url
        );
        $success_url .= (strpos($success_url, '?') !== false ? '&' : '?') . 'cpbs_extend_session_id={CHECKOUT_SESSION_ID}';

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

        $pending_keys_before = array_keys($pending);
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

        $this->log_extension_debug('Stripe extension pending checkout session prepared', array(
            'booking_id' => $booking_id,
            'session_id' => (string) $session->id,
            'pending_keys_before' => $pending_keys_before,
            'pending_keys_after' => array_keys($pending),
            'hours' => $hours,
            'amount_net' => (float) $amount_net,
            'amount_gross' => (float) $amount_gross,
            'target_exit_datetime_2' => $new_exit->format('Y-m-d H:i'),
        ));

        $this->update_booking_meta($booking_id, self::META_PENDING, $pending);

        $pending_after_write = $this->get_booking_meta_value($booking_id, self::META_PENDING, array());
        $persisted_pending = is_array($pending_after_write) && isset($pending_after_write[$session->id]) && is_array($pending_after_write[$session->id]) ? $pending_after_write[$session->id] : null;
        $this->log_extension_debug('Stripe extension pending checkout session written', array(
            'booking_id' => $booking_id,
            'session_id' => (string) $session->id,
            'persisted' => $persisted_pending !== null,
            'persisted_keys' => is_array($pending_after_write) ? array_keys($pending_after_write) : array(),
            'persisted_status' => is_array($persisted_pending) && isset($persisted_pending['status']) ? (string) $persisted_pending['status'] : '',
            'persisted_target_exit_datetime_2' => is_array($persisted_pending) && isset($persisted_pending['target_exit_datetime_2']) ? (string) $persisted_pending['target_exit_datetime_2'] : '',
        ));

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
            $this->log_extension_debug('Stripe extension checkout canceled by customer', array(
                'booking_id' => isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0,
            ));
            $this->redirect_with_notice('cancel');
        }

        if ($result !== 'success') {
            return;
        }

        $booking_id = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
        $access_token = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : '';
        $session_id = isset($_GET['cpbs_extend_session_id']) ? sanitize_text_field(wp_unslash($_GET['cpbs_extend_session_id'])) : '';

        if ($booking_id <= 0 || $access_token === '' || $session_id === '') {
            $this->log_extension_debug('Stripe extension finalize failed: missing return parameters', array(
                'booking_id' => $booking_id,
                'has_access_token' => $access_token !== '',
                'has_session_id' => $session_id !== '',
                'query_keys' => array_keys($_GET),
            ));
            $this->redirect_with_notice('failed');
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking) || !$this->is_access_token_valid($booking_id, $access_token)) {
            $this->log_extension_debug('Stripe extension finalize failed: booking not found or access token invalid', array(
                'booking_id' => $booking_id,
                'booking_found' => is_array($booking),
            ));
            $this->redirect_with_notice('failed');
        }

        $pending = $this->get_booking_meta_value($booking_id, self::META_PENDING, array());
        if (is_array($pending) && isset($pending[$session_id]) && is_array($pending[$session_id]) && ($pending[$session_id]['status'] ?? '') === 'completed') {
            $this->log_extension_debug('Stripe extension finalize shortcut: session already completed', array(
                'booking_id' => $booking_id,
                'session_id' => $session_id,
            ));
            $this->redirect_with_notice('success');
        }

        $stripe_config = $this->get_stripe_config_for_booking($booking);
        if (!$stripe_config['is_valid'] || !$this->load_stripe_library()) {
            $this->log_extension_debug('Stripe extension finalize failed: Stripe config or library unavailable', array(
                'booking_id' => $booking_id,
                'stripe_valid' => !empty($stripe_config['is_valid']),
                'stripe_library_loaded' => class_exists('Stripe\\Stripe'),
            ));
            $this->redirect_with_notice('failed');
        }

        try {
            \Stripe\Stripe::setApiKey($stripe_config['secret_key']);
            $session = \Stripe\Checkout\Session::retrieve($session_id);
        } catch (\Throwable $exception) {
            $this->log_extension_debug('Stripe extension finalize failed: Stripe session retrieval threw exception', array(
                'booking_id' => $booking_id,
                'session_id' => $session_id,
                'error' => $exception->getMessage(),
            ));
            $this->redirect_with_notice('failed');
        }

        $session_data = $this->normalize_session_payload($session);
        $finalize_result = $this->finalize_extension_from_session($session_data, $booking_id, true, 'return');
        if (!empty($finalize_result['success'])) {
            $this->redirect_with_notice('success');
        }

        $this->redirect_with_notice('failed');
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

    private function get_webhook_signing_secret()
    {
        $settings = get_option(self::WEBHOOK_SETTINGS_OPTION_KEY, array());
        if (!is_array($settings)) {
            return '';
        }

        $secret = isset($settings[self::WEBHOOK_SETTINGS_SECRET_KEY]) ? (string) $settings[self::WEBHOOK_SETTINGS_SECRET_KEY] : '';
        return trim($secret);
    }

    private function normalize_session_payload($session)
    {
        if (is_array($session)) {
            return $session;
        }

        if (!is_object($session)) {
            return array();
        }

        $encoded = wp_json_encode($session);
        if (!is_string($encoded) || $encoded === '') {
            return array();
        }

        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function finalize_extension_from_session(array $session_data, $expected_booking_id = 0, $require_paid = true, $source = 'return')
    {
        $session_id = isset($session_data['id']) ? (string) $session_data['id'] : '';
        if ($session_id === '') {
            $this->log_extension_debug('Stripe extension finalize failed: session id missing', array(
                'source' => $source,
            ));

            return array(
                'success' => false,
                'code' => 400,
                'message' => esc_html__('Stripe checkout session is missing.', 'cpbs-combined-extensions'),
            );
        }

        $metadata = isset($session_data['metadata']) && is_array($session_data['metadata']) ? $session_data['metadata'] : array();
        $booking_id = isset($metadata['booking_id']) ? (int) $metadata['booking_id'] : 0;
        if ($expected_booking_id > 0 && $booking_id > 0 && $booking_id !== (int) $expected_booking_id) {
            $this->log_extension_debug('Stripe extension finalize failed: booking id mismatch', array(
                'source' => $source,
                'expected_booking_id' => (int) $expected_booking_id,
                'metadata_booking_id' => $booking_id,
                'session_id' => $session_id,
            ));

            return array(
                'success' => false,
                'code' => 400,
                'message' => esc_html__('Booking reference does not match the checkout session.', 'cpbs-combined-extensions'),
            );
        }

        if ($booking_id <= 0) {
            $booking_id = (int) $expected_booking_id;
        }

        if ($booking_id <= 0) {
            $this->log_extension_debug('Stripe extension finalize failed: booking id missing', array(
                'source' => $source,
                'session_id' => $session_id,
            ));

            return array(
                'success' => false,
                'code' => 400,
                'message' => esc_html__('Booking reference is missing from the checkout session.', 'cpbs-combined-extensions'),
            );
        }

        $booking = $this->get_booking($booking_id);
        if (!is_array($booking)) {
            $this->log_extension_debug('Stripe extension finalize failed: booking not found', array(
                'source' => $source,
                'booking_id' => $booking_id,
                'session_id' => $session_id,
            ));

            return array(
                'success' => false,
                'code' => 404,
                'message' => esc_html__('Booking not found.', 'cpbs-combined-extensions'),
            );
        }

        $pending = $this->get_booking_meta_value($booking_id, self::META_PENDING, array());
        if (!is_array($pending) || !isset($pending[$session_id]) || !is_array($pending[$session_id])) {
            $this->log_extension_debug('Stripe extension finalize failed: pending session missing', array(
                'source' => $source,
                'booking_id' => $booking_id,
                'session_id' => $session_id,
                'pending_keys' => is_array($pending) ? array_keys($pending) : array(),
            ));

            return array(
                'success' => false,
                'code' => 404,
                'message' => esc_html__('Pending extension session was not found.', 'cpbs-combined-extensions'),
            );
        }

        $pending_item = $pending[$session_id];
        if (($pending_item['status'] ?? '') === 'completed') {
            return array(
                'success' => true,
                'code' => 200,
                'message' => esc_html__('Booking extension has already been processed.', 'cpbs-combined-extensions'),
            );
        }

        $payment_status = isset($session_data['payment_status']) ? (string) $session_data['payment_status'] : '';
        if ($require_paid && $payment_status !== 'paid') {
            $this->log_extension_debug('Stripe extension finalize failed: payment status not paid', array(
                'source' => $source,
                'booking_id' => $booking_id,
                'session_id' => $session_id,
                'payment_status' => $payment_status,
            ));

            return array(
                'success' => false,
                'code' => 402,
                'message' => esc_html__('Stripe payment has not been verified yet.', 'cpbs-combined-extensions'),
            );
        }

        $metadata_target = isset($metadata['target_exit_datetime_2']) ? (string) $metadata['target_exit_datetime_2'] : '';
        $target_exit = isset($pending_item['target_exit_datetime_2']) ? (string) $pending_item['target_exit_datetime_2'] : '';
        if ($metadata_target !== '' && $metadata_target !== $target_exit) {
            $this->log_extension_debug('Stripe extension finalize failed: target exit mismatch', array(
                'source' => $source,
                'booking_id' => $booking_id,
                'session_id' => $session_id,
                'metadata_target_exit_datetime_2' => $metadata_target,
                'pending_target_exit_datetime_2' => $target_exit,
            ));

            return array(
                'success' => false,
                'code' => 409,
                'message' => esc_html__('Checkout session does not match the pending extension request.', 'cpbs-combined-extensions'),
            );
        }

        $target_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $target_exit, wp_timezone());
        if (!($target_dt instanceof \DateTimeImmutable)) {
            $this->log_extension_debug('Stripe extension finalize failed: target exit datetime invalid', array(
                'source' => $source,
                'booking_id' => $booking_id,
                'session_id' => $session_id,
                'target_exit_datetime_2' => $target_exit,
            ));

            return array(
                'success' => false,
                'code' => 422,
                'message' => esc_html__('Extended end time is invalid.', 'cpbs-combined-extensions'),
            );
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
            'source' => (string) $source,
        );

        $this->update_booking_meta($booking_id, self::META_HISTORY, $history);

        $pending[$session_id]['status'] = 'completed';
        $pending[$session_id]['completed_at'] = gmdate('Y-m-d H:i:s');
        $this->update_booking_meta($booking_id, self::META_PENDING, $pending);

        $this->log_extension_debug('Stripe extension finalize succeeded', array(
            'source' => $source,
            'booking_id' => $booking_id,
            'session_id' => $session_id,
            'hours' => isset($pending_item['hours']) ? (int) $pending_item['hours'] : 0,
            'amount_gross' => isset($pending_item['amount_gross']) ? (float) $pending_item['amount_gross'] : 0,
            'new_exit_datetime_2' => $target_dt->format('Y-m-d H:i'),
        ));

        do_action('cpbs_combined_booking_extended', $booking_id, $pending[$session_id], $history);

        return array(
            'success' => true,
            'code' => 200,
            'message' => esc_html__('Booking extension payment verified and booking end time updated.', 'cpbs-combined-extensions'),
            'booking_id' => $booking_id,
            'session_id' => $session_id,
        );
    }

    private function enqueue_assets()
    {
        wp_enqueue_script(
            'cpbs-combined-booking-extension',
            dirname(plugin_dir_url(__FILE__)) . '/cpbs-combined-booking-extension.js',
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

    private function log_extension_debug($message, array $context = array())
    {
        $upload = wp_upload_dir();
        $dir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC][EXT] ' . (string) $message;
        if (!empty($context)) {
            $encoded = wp_json_encode($context);
            if (is_string($encoded) && $encoded !== '') {
                $line .= ' ' . $encoded;
            }
        }

        @file_put_contents(trailingslashit($dir) . self::LOG_FILE_NAME, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
       

    private function get_booking_meta_value($booking_id, $key, $default = null)
    {
        // Define our custom extension keys
        $custom_keys = array(
            self::META_PENDING,
            self::META_HISTORY,
            self::META_TOTAL_HOURS,
            self::META_TOTAL_AMOUNT,
            self::META_TOTAL_COUNT
        );

        if (in_array($key, $custom_keys, true)) {
            // Bypass CPBS framework/cache and get directly from WP metadata
            $prefixed_key = '_' . $this->get_meta_prefix() . $key;
            $value = get_post_meta($booking_id, $prefixed_key, true);
            return ($value !== '') ? $value : $default;
        }

        // Fall back to standard helper for native CPBS properties
        $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
        if (is_array($meta) && array_key_exists($key, $meta)) {
            return $meta[$key];
        }

        return $default;
    }

    private function update_booking_meta($booking_id, $key, $value)
    {
        // Define our custom extension keys
        $custom_keys = array(
            self::META_PENDING,
            self::META_HISTORY,
            self::META_TOTAL_HOURS,
            self::META_TOTAL_AMOUNT,
            self::META_TOTAL_COUNT
        );

        if (in_array($key, $custom_keys, true)) {
            // Bypass CPBS helper and write directly to WordPress postmeta
            $prefixed_key = '_' . $this->get_meta_prefix() . $key;
            update_post_meta($booking_id, $prefixed_key, $value);
            
            // Clean WP cache for this post so immediate read-after-write returns fresh data
            clean_post_cache($booking_id);
        } else {
            // Keep routing native CPBS fields through the helper so core logic behaves correctly
            CPBSCombinedHelpers::update_booking_meta($booking_id, $key, $value);
        }
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
        return CPBSCombinedHelpers::get_meta_prefix();
    }

    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
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
