<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer Portal - Handles user reservation page, login, password reset, and booking cancellation.
 * This class uses CPBSCombinedHelpers for shared utility methods.
 */
final class CPBSCombinedCustomerPortal
{
    const SHORTCODE = 'cpbs_customer_reservations';
    const NONCE_LOGIN = 'cpbs_customer_login';
    const NONCE_SETUP_LINK = 'cpbs_customer_send_setup_link';
    const NONCE_PASSWORD = 'cpbs_customer_password';
    const NONCE_CANCEL = 'cpbs_customer_cancel';
    const NONCE_CANCEL_ACTION = 'cpbs_customer_cancel_action';
    private static $instance = null;

    private $customer_account_notice = array('type' => '', 'message' => '');

    /**
     * Constructor - Register shortcode and initialization hooks.
     */
    public function __construct()
    {
         self::$instance = $this;
        // Register shortcode to display customer reservations page
        add_shortcode(self::SHORTCODE, array($this, 'render_reservations_shortcode'));
        
        // Handle customer account requests (login, password setup, cancellation)
        add_action('init', array($this, 'process_customer_account_requests'), 20);
    }

    /**
     * Get booking post type with context support.
     */
    private function get_booking_post_type()
    {
        return CPBSCombinedHelpers::get_booking_post_type();
    }

    /**
     * Get booking metadata.
     */
    private function get_booking_meta($booking_id)
    {
        return CPBSCombinedHelpers::get_booking_meta($booking_id);
    }

    /**
     * Update booking metadata.
     */
    private function update_booking_meta($booking_id, $key, $value)
    {
        return CPBSCombinedHelpers::update_booking_meta($booking_id, $key, $value);
    }

    /**
     * Get booking metadata value by key.
     */
    private function get_booking_meta_value($booking_id, $key)
    {
        return CPBSCombinedHelpers::get_booking_meta_value($booking_id, $key);
    }

    /**
     * Check if post is a booking post type.
     */
    private function is_booking_post($post_id)
    {
        return CPBSCombinedHelpers::is_booking_post($post_id);
    }

    /**
     * Render customer reservations shortcode.
     * Displays login panel or customer's reservations list.
     */
    public function render_reservations_shortcode()
    {
        $this->enqueue_customer_portal_assets();
        $notice = $this->customer_account_notice;
        
        // Check for password setup request (from email link)
        if ($this->is_password_setup_request()) {
            return $this->render_customer_password_setup($notice);
        }

        // Require user to be logged in
        if (!is_user_logged_in()) {
            return $this->render_customer_access_panel($notice);
        }

        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        $current_user_email = ($current_user instanceof \WP_User) ? sanitize_email((string) $current_user->user_email) : '';

        // Link any existing unlinked bookings by email
        if (!empty($current_user_email)) {
            $this->link_existing_bookings_by_email($current_user_id, $current_user_email);
        }

        // Build meta query for current user's bookings
        $meta_query_args = array(
            'relation' => 'OR',
            array(
                'key' => CPBSCombinedHelpers::get_meta_prefix() . 'linked_wp_user_id',
                'value' => (int) $current_user_id,
                'compare' => '=',
                'type' => 'NUMERIC',
            ),
        );

        if (!empty($current_user_email)) {
            $meta_query_args[] = array(
                'key' => CPBSCombinedHelpers::get_meta_prefix() . 'client_contact_detail_email_address',
                'value' => $current_user_email,
                'compare' => '=',
            );
        }

        $args = array(
            'post_type' => $this->get_booking_post_type(),
            'numberposts' => -1,
            'meta_query' => $meta_query_args,
        );

        $bookings = get_posts($args);
        wp_reset_postdata();

        // Render reservations list
        return $this->render_reservations_list($bookings, $current_user);
    }

    /**
     * Render the reservations list HTML.
     */
    private function render_reservations_list($bookings, $current_user)
    {
        ob_start();
        echo $this->get_customer_portal_styles();
        echo $this->get_customer_portal_inline_script();
        ?>
        <div class="cpbs-customer-portal">
            <div class="cpbs-portal-header">
                <span class="cpbs-portal-kicker"><?php echo esc_html__('My Reservations', 'cpbs-combined-extensions'); ?></span>
                <h2><?php echo esc_html(sprintf(__('Welcome, %s', 'cpbs-combined-extensions'), esc_html($current_user->display_name))); ?></h2>
                <a href="<?php echo esc_url(wp_logout_url($this->get_reservations_page_url())); ?>" class="cpbs-text-button"><?php echo esc_html__('Sign out', 'cpbs-combined-extensions'); ?></a>
            </div>

            <?php if (empty($bookings)) : ?>
                <p class="cpbs-no-results"><?php echo esc_html__('You have no reservations yet.', 'cpbs-combined-extensions'); ?></p>
            <?php else : ?>
                <div class="cpbs-reservations-list">
                    <?php foreach ($bookings as $post) :
                        $post_id = (int) $post->ID;
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
                            <?php if ($can_cancel) : ?>
                                <button
                                    type="button"
                                    class="cpbs-button cpbs-button-danger cpbs-cancel-booking-btn"
                                    data-booking-id="<?php echo esc_attr($post_id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('cpbs_cancel_booking_' . $post_id)); ?>"
                                >
                                    <?php echo esc_html__('Cancel Reservation', 'cpbs-combined-extensions'); ?>
                                </button>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Check if this is a password setup request.
     */
    private function is_password_setup_request()
    {
        return isset($_GET['cpbs_account_action']) 
            && $_GET['cpbs_account_action'] === 'set_password' 
            && isset($_GET['key']) 
            && isset($_GET['login']);
    }

    /**
     * Render password setup form.
     */
    private function render_customer_password_setup($notice = array())
    {
        // Extract and sanitize URL parameters
        $login = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        // Get user by login
        $user = get_user_by('login', $login);
        if (!$user) {
            ob_start();
            echo $this->get_customer_portal_styles();
            ?>
            <div class="cpbs-customer-portal cpbs-auth-wrap">
                <div class="cpbs-auth-panel">
                    <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                    <?php if (!empty($notice['message'])) : ?>
                        <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
                    <?php endif; ?>
                    <p class="cpbs-error-message"><?php echo esc_html__('Invalid password reset link. Please try again or contact support.', 'cpbs-combined-extensions'); ?></p>
                    <a href="<?php echo esc_url($this->get_reservations_page_url()); ?>" class="cpbs-button"><?php echo esc_html__('Back to Login', 'cpbs-combined-extensions'); ?></a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        // Validate the key
        $valid_key = check_password_reset_key($key, $user->user_login);
        if (is_wp_error($valid_key)) {
            ob_start();
            echo $this->get_customer_portal_styles();
            ?>
            <div class="cpbs-customer-portal cpbs-auth-wrap">
                <div class="cpbs-auth-panel">
                    <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                    <?php if (!empty($notice['message'])) : ?>
                        <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
                    <?php endif; ?>
                    <p class="cpbs-error-message"><?php echo esc_html__('This password reset link has expired. Please request a new one.', 'cpbs-combined-extensions'); ?></p>
                    <a href="<?php echo esc_url($this->get_reservations_page_url()); ?>" class="cpbs-button"><?php echo esc_html__('Back to Login', 'cpbs-combined-extensions'); ?></a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        ob_start();
        echo $this->get_customer_portal_styles();
        echo $this->get_customer_portal_inline_script();
        ?>
        <div class="cpbs-customer-portal cpbs-auth-wrap">
            <div class="cpbs-auth-panel">
                <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                <h2><?php echo esc_html__('Set your password', 'cpbs-combined-extensions'); ?></h2>
                <form method="post" class="cpbs-auth-form">
                    <input type="hidden" name="cpbs_customer_account_action" value="setup_password" />
                    <input type="hidden" name="cpbs_reset_key" value="<?php echo esc_attr($key); ?>" />
                    <input type="hidden" name="cpbs_reset_login" value="<?php echo esc_attr($login); ?>" />
                    <?php wp_nonce_field(self::NONCE_PASSWORD, 'cpbs_customer_password_nonce'); ?>
                    <label>
                        <span><?php echo esc_html__('New Password', 'cpbs-combined-extensions'); ?></span>
                        <input type="password" name="cpbs_customer_password" required />
                    </label>
                    <label>
                        <span><?php echo esc_html__('Confirm Password', 'cpbs-combined-extensions'); ?></span>
                        <input type="password" name="cpbs_customer_password_confirm" required />
                    </label>
                    <button class="cpbs-button" type="submit"><?php echo esc_html__('Set Password', 'cpbs-combined-extensions'); ?></button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render customer access panel (login / forgot password).
     */
    public function render_customer_access_panel($notice = array())
    {
        ob_start();
        echo $this->get_customer_portal_styles();
        echo $this->get_customer_portal_inline_script();
        
        // Check for password reset success
        $show_success = isset($_GET['cpbs_password_reset']) && $_GET['cpbs_password_reset'] === 'success';
        ?>
        <div class="cpbs-customer-portal cpbs-auth-wrap">
            <div class="cpbs-auth-panel">
                <span class="cpbs-portal-kicker"><?php echo esc_html__('Reservations', 'cpbs-combined-extensions'); ?></span>
                <h2><?php echo esc_html__('Access your parking', 'cpbs-combined-extensions'); ?></h2>
                <?php if (!empty($notice['message'])) : ?>
                    <div class="cpbs-portal-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['message']); ?></div>
                <?php endif; ?>
                
                <?php if ($show_success) : ?>
                    <p class="cpbs-success-message"><?php echo esc_html__('Password set successfully! You can now log in.', 'cpbs-combined-extensions'); ?></p>
                <?php endif; ?>
                
                <form method="post" class="cpbs-auth-form">
                    <input type="hidden" name="cpbs_customer_account_action" value="login" />
                    <?php wp_nonce_field(self::NONCE_LOGIN, 'cpbs_customer_login_nonce'); ?>
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
                    <?php wp_nonce_field(self::NONCE_SETUP_LINK, 'cpbs_customer_link_nonce'); ?>
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

    /**
     * Process customer account requests (login, password setup, booking cancellation).
     * This is called on the 'init' hook to handle form submissions.
     */
    public function process_customer_account_requests()
    {
        if (empty($_POST['cpbs_customer_account_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['cpbs_customer_account_action']));

        if ($action === 'login') {
            if (empty($_POST['cpbs_customer_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_customer_login_nonce'])), self::NONCE_LOGIN)) {
                $this->set_customer_account_notice('error', __('Security check failed. Please try again.', 'cpbs-combined-extensions'));
                return;
            }

            $email = isset($_POST['cpbs_customer_email']) ? sanitize_email(wp_unslash($_POST['cpbs_customer_email'])) : '';
            $password = isset($_POST['cpbs_customer_password']) ? (string) wp_unslash($_POST['cpbs_customer_password']) : '';
            $user = is_email($email) ? get_user_by('email', $email) : false;

            if (!$user) {
                $this->set_customer_account_notice('error', __('No account was found for that email address.', 'cpbs-combined-extensions'));
                return;
            }

            $signed_in = wp_signon(array(
                'user_login' => $user->user_login,
                'user_password' => $password,
                'remember' => true,
            ), is_ssl());

            if (is_wp_error($signed_in)) {
                $this->set_customer_account_notice('error', __('The email or password is incorrect.', 'cpbs-combined-extensions'));
                return;
            }

           wp_set_current_user($signed_in->ID);

            /**
             * Facility Vendors should go directly
             * to the Facility Dashboard.
             */
            if (
                in_array(
                    'cpbs_facility_vendor',
                    (array) $signed_in->roles,
                    true
                )
            ) {
                $facility_dashboard_page = get_page_by_path(
                    'facility-dashboard'
                );
            
                if ($facility_dashboard_page instanceof WP_Post) {
                    wp_safe_redirect(
                        get_permalink($facility_dashboard_page->ID)
                    );
                    exit;
                }
            
                wp_safe_redirect(
                    home_url('/facility-dashboard/')
                );
                exit;
            }
            
            /**
             * Normal customers continue to
             * Customer Reservations.
             */
            wp_safe_redirect(
                $this->get_reservations_page_url()
            );
            
            exit;
        }

        // Handle password setup form submission
        if ($action === 'setup_password') {
            $this->handle_password_setup_submission();
        }
    }

    private function set_customer_account_notice($type, $message)
    {
        $this->customer_account_notice = array(
            'type' => (string) $type,
            'message' => (string) $message,
        );
    }

    /**
     * Handle password setup form submission.
     */
    private function handle_password_setup_submission()
    {
        // Verify nonce
        if (empty($_POST['cpbs_customer_password_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpbs_customer_password_nonce'])), self::NONCE_PASSWORD)) {
            wp_die(esc_html__('Security check failed.', 'cpbs-combined-extensions'));
        }

        // Get and sanitize inputs
        $reset_key = isset($_POST['cpbs_reset_key']) ? sanitize_text_field(wp_unslash($_POST['cpbs_reset_key'])) : '';
        $reset_login = isset($_POST['cpbs_reset_login']) ? sanitize_text_field(wp_unslash($_POST['cpbs_reset_login'])) : '';
        $password = isset($_POST['cpbs_customer_password']) ? wp_unslash($_POST['cpbs_customer_password']) : '';
        $password_confirm = isset($_POST['cpbs_customer_password_confirm']) ? wp_unslash($_POST['cpbs_customer_password_confirm']) : '';

        // Validate passwords match
        if ($password !== $password_confirm) {
            wp_die(esc_html__('Passwords do not match.', 'cpbs-combined-extensions'));
        }

        // Validate password strength (minimum 6 characters)
        if (strlen($password) < 6) {
            wp_die(esc_html__('Password must be at least 6 characters long.', 'cpbs-combined-extensions'));
        }

        // Get user by login
        $user = get_user_by('login', $reset_login);
        if (!$user) {
            wp_die(esc_html__('Invalid user.', 'cpbs-combined-extensions'));
        }

        // Validate reset key
        $valid_key = check_password_reset_key($reset_key, $user->user_login);
        if (is_wp_error($valid_key)) {
            wp_die(esc_html__('Password reset link expired or invalid.', 'cpbs-combined-extensions'));
        }

        // Update password
        wp_set_password($password, $user->ID);

        // Clear reset key to prevent reuse
        wp_password_change_notification($user);

        // Redirect to login page with success message
        $login_url = $this->get_reservations_page_url();
        wp_safe_remote_post($login_url, array(
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ));

        // Display success message and login form
        wp_redirect(add_query_arg('cpbs_password_reset', 'success', $this->get_reservations_page_url()));
        exit;
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

    /**
     * Check if customer can cancel a booking.
     */
    private function can_customer_cancel_booking($post_id, $meta = array())
    {
        if (!is_array($meta) || empty($meta)) {
            $meta = $this->get_booking_meta($post_id);
        }

        $booking_status_id = (int) (!empty($meta['booking_status_id']) ? $meta['booking_status_id'] : 0);

        // Can only cancel if status is Pending (1) or Processing (2)
        if (!in_array($booking_status_id, array(1, 2), true)) {
            return false;
        }

        $confirmed_at = get_post_meta($post_id, 'automation_tracking_clicked_at', true);
        if ($confirmed_at) {
            return false;
        }

        $entry_dt = !empty($meta['entry_datetime_2']) ? (string) $meta['entry_datetime_2'] : '';
        $entry = CPBSCombinedHelpers::build_site_datetime($entry_dt);
        if (!($entry instanceof \DateTimeImmutable)) {
            return false;
        }

        $settings = get_option(CPBSCombinedBookingAutomation::OPTION_KEY, array());
        $settings = is_array($settings) ? $settings : array();
        $cutoff_hours = isset($settings['cancellation_cutoff_hours']) ? (int) $settings['cancellation_cutoff_hours'] : 2;
        if ($cutoff_hours < 1) {
            $cutoff_hours = 2;
        }

        $cutoff_time = $entry->sub(new DateInterval('PT' . $cutoff_hours . 'H'));
        $now = CPBSCombinedHelpers::site_now();

        return $now < $cutoff_time;
    }

    /**
     * Link existing bookings by email to current user.
     */
    private function link_existing_bookings_by_email($user_id, $user_email)
    {
        if (empty($user_email) || empty($user_id)) {
            return;
        }

        global $wpdb;
        $meta_prefix = CPBSCombinedHelpers::get_meta_prefix();
        
        // Find bookings matching this email that don't have a linked user yet
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND (m.post_id IS NULL OR m.meta_value = '0' OR m.meta_value = '')
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm
                WHERE pm.post_id = p.ID
                AND pm.meta_key = %s
                AND pm.meta_value = %s
            )
        ", $meta_prefix . 'linked_wp_user_id', $this->get_booking_post_type(), $meta_prefix . 'client_contact_detail_email_address', $user_email));

        if (!empty($bookings)) {
            foreach ($bookings as $booking) {
                $this->update_booking_meta((int) $booking->ID, 'linked_wp_user_id', $user_id);
            }
        }
    }

    /**
     * Get password reset link for user.
     */
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
            'action' => 'rp',
            'key'    => $key,
            'login'  => rawurlencode($user->user_login),
        ), network_site_url('wp-login.php', 'login'));

        return $reset_url;
    }

    /**
     * Enqueue customer portal JavaScript and CSS.
     */
    private function enqueue_customer_portal_assets()
    {
        $handle = 'cpbs-combined-customer-portal';

        if (!wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                dirname(plugin_dir_url(__FILE__)) . '/cpbs-combined-customer-portal.js',
                array('jquery'),
                CPBS_COMBINED_VERSION,
                true
            );
        }

        wp_enqueue_script($handle);

        wp_localize_script($handle, 'cpbsCustomerPortal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cancelAction' => 'cpbs_cancel_booking',
            'cancelNonce' => wp_create_nonce('cpbs_customer_cancel'),
            'i18n' => array(
                'confirm' => esc_html__('Cancel this reservation?', 'cpbs-combined-extensions'),
                'processing' => esc_html__('Cancelling...', 'cpbs-combined-extensions'),
                'success' => esc_html__('Reservation cancelled.', 'cpbs-combined-extensions'),
                'genericError' => esc_html__('The reservation could not be cancelled.', 'cpbs-combined-extensions'),
            ),
        ));
    }

    /**
     * Get status badge HTML.
     */
    private function get_status_badge($status_id)
    {
        $statuses = array(
            1 => array('label' => 'Pending', 'class' => 'pending'),
            2 => array('label' => 'Processing', 'class' => 'processing'),
            3 => array('label' => 'Cancelled', 'class' => 'cancelled'),
            4 => array('label' => 'Completed', 'class' => 'completed'),
            5 => array('label' => 'On Hold', 'class' => 'hold'),
            6 => array('label' => 'Refunded', 'class' => 'refunded'),
            7 => array('label' => 'Failed', 'class' => 'failed'),
        );

        if (!isset($statuses[$status_id])) {
            return '';
        }

        $status = $statuses[$status_id];
        $label = __($status['label'], 'cpbs-combined-extensions');

        return sprintf(
            '<span class="cpbs-status-badge cpbs-status-%s">%s</span>',
            esc_attr($status['class']),
            esc_html($label)
        );
    }

    /**
     * Get customer portal inline styles.
     */
    private function get_customer_portal_styles()
    {
        return '<style>
            .cpbs-customer-portal { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; }
            .cpbs-portal-kicker { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
            .cpbs-portal-header { text-align: center; margin-bottom: 40px; }
            .cpbs-auth-wrap { display: flex; align-items: center; justify-content: center; min-height: 400px; }
            .cpbs-auth-panel { width: 100%; max-width: 500px; padding: 40px; border: 1px solid #ddd; border-radius: 4px; }
            .cpbs-auth-form, .cpbs-link-form { margin-bottom: 30px; }
            .cpbs-auth-form label, .cpbs-link-form label { display: block; margin-bottom: 20px; }
            .cpbs-auth-form label span, .cpbs-link-form label span { display: block; font-weight: bold; margin-bottom: 5px; }
            .cpbs-auth-form input, .cpbs-link-form input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
            .cpbs-button { width: 100%; padding: 12px; background: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; }
            .cpbs-button:hover { background: #005a87; }
            .cpbs-button-danger { width: 100%; padding: 10px; background: #dc3545; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
            .cpbs-button-danger:hover { background: #c82333; }
            .cpbs-text-button { background: none; border: none; color: #0073aa; cursor: pointer; text-decoration: underline; padding: 0; font-size: 14px; width: 100%; text-align: center; }
            .cpbs-text-button:hover {background-color: #005a87;color !important: #fff !important;}
            .cpbs-success-message { padding: 15px; margin-bottom: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; text-align: center; }
            .cpbs-error-message { padding: 15px; margin-bottom: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; text-align: center; }
            .cpbs-portal-notice { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
            .cpbs-portal-notice.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .cpbs-portal-notice.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .cpbs-reservations-list { margin-top: 30px; }
            .cpbs-reservation-card { padding: 20px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px; }
            .cpbs-card-topline { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
            .cpbs-booking-number { font-weight: bold; font-size: 14px; }
            .cpbs-status-badge { padding: 5px 10px; border-radius: 3px; font-size: 12px; font-weight: bold; }
            .cpbs-status-pending { background: #fff3cd; color: #856404; }
            .cpbs-status-processing { background: #d1ecf1; color: #0c5460; }
            .cpbs-status-completed { background: #d4edda; color: #155724; }
            .cpbs-status-cancelled { background: #f8d7da; color: #721c24; }
            .cpbs-status-failed { background: #f8d7da; color: #721c24; }
            .cpbs-card-times { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .cpbs-card-times > div { font-size: 14px; }
            .cpbs-card-times span { color: #666; font-size: 12px; }
            .cpbs-card-times strong { display: block; margin-top: 5px; }
            .cpbs-no-results { text-align: center; color: #666; padding: 40px 0; }
        </style>';
    }

    /**
     * Get customer portal inline script.
     */
    private function get_customer_portal_inline_script()
    {
        return '<script>
            // Customer portal JavaScript initialization
            // Enqueued via wp_enqueue_script in cpbs-combined-customer-portal.js
        </script>';
    }
}
