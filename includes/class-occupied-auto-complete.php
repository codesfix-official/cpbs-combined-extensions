<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatically marks occupied bookings as Completed once end time passes.
 *
 * This runs as a separate feature on the existing automation cron hook and
 * intentionally does not affect the existing no-show -> Failed flow.
 */
final class CPBSCombinedOccupiedAutoComplete
{
    const CRON_HOOK = 'cpbs_combined_booking_automation_cron';

    public function __construct()
    {
        if (!$this->is_feature_enabled('occupied_auto_complete', true)) {
            return;
        }

        // Run after main automation has updated occupancy/no-show states.
        add_action(self::CRON_HOOK, array($this, 'process'), 20);
    }

    public function process()
    {
        $booking_ids = $this->get_candidate_bookings();
        if (empty($booking_ids)) {
            return;
        }

        $now = CPBSCombinedHelpers::site_now();

        foreach ($booking_ids as $booking_id) {
            $booking_id = (int) $booking_id;
            if ($booking_id <= 0) {
                continue;
            }

            $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
            if (!$this->is_eligible_for_auto_complete($meta)) {
                continue;
            }

            $exit_raw = isset($meta['exit_datetime_2']) ? (string) $meta['exit_datetime_2'] : '';
            $exit = CPBSCombinedHelpers::build_site_datetime($exit_raw);
            if (!($exit instanceof \DateTimeImmutable)) {
                continue;
            }

            if ($now < $exit) {
                continue;
            }

            $this->complete_booking($booking_id);
        }
    }

    private function get_candidate_bookings()
    {
        $prefix = CPBSCombinedHelpers::get_meta_prefix();

        return get_posts(
            array(
                'post_type' => CPBSCombinedHelpers::get_booking_post_type(),
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'suppress_filters' => true,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => $prefix . 'automation_status',
                        'value' => 'occupied',
                        'compare' => '=',
                    ),
                    array(
                        'key' => $prefix . 'booking_status_id',
                        'value' => array(1, 2, 5),
                        'compare' => 'IN',
                        'type' => 'NUMERIC',
                    ),
                ),
            )
        );
    }

    private function is_eligible_for_auto_complete(array $meta)
    {
        $automation_status = isset($meta['automation_status']) ? (string) $meta['automation_status'] : '';
        if ($automation_status !== 'occupied') {
            return false;
        }

        $is_noshow = isset($meta['automation_noshow']) && (string) $meta['automation_noshow'] === '1';
        if ($is_noshow) {
            return false;
        }

        $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;

        return in_array($status_id, array(1, 2, 5), true);
    }

    private function complete_booking($booking_id)
    {
        if (!class_exists('CPBSBooking')) {
            return;
        }

        $booking_model = new \CPBSBooking();
        if (!method_exists($booking_model, 'getBooking')) {
            return;
        }

        $booking_old = $booking_model->getBooking($booking_id);
        if (!is_array($booking_old)) {
            return;
        }

        $booking_old_meta = isset($booking_old['meta']) && is_array($booking_old['meta']) ? $booking_old['meta'] : array();
        $completed_status_id = (int) apply_filters('cpbs_combined_end_booking_completed_status_id', 4, $booking_id, $booking_old);
        if ($completed_status_id <= 0) {
            return;
        }

        $sync_mode = class_exists('CPBSOption') ? (int) \CPBSOption::getOption('booking_status_synchronization') : 1;
        $has_linked_order = !empty($booking_old_meta['woocommerce_booking_id']);
        $current_status_id = isset($booking_old_meta['booking_status_id']) ? (int) $booking_old_meta['booking_status_id'] : 0;

        // Keep behavior aligned with existing end-booking logic for sync mode 2.
        if ($sync_mode === 2 && $has_linked_order) {
            return;
        }

        if ($current_status_id === $completed_status_id) {
            return;
        }

        CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', $completed_status_id);
        $this->ensure_status_nonblocking($completed_status_id);
        $this->sync_booking_status($booking_id);

        clean_post_cache($booking_id);
        $booking_new = $booking_model->getBooking($booking_id);

        if (method_exists($booking_model, 'sendEmailBookingChangeStatus') && is_array($booking_new)) {
            try {
                $booking_model->sendEmailBookingChangeStatus($booking_old, $booking_new);
            } catch (\Throwable $exception) {
                do_action('cpbs_combined_end_booking_email_error', $booking_id, $exception);
            }
        }

        do_action('cpbs_combined_booking_auto_completed', $booking_id, $booking_old, $booking_new);
    }

    private function ensure_status_nonblocking($status_id)
    {
        $status_id = (int) $status_id;
        if ($status_id <= 0 || !class_exists('CPBSOption')) {
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
            array(
                'booking_status_nonblocking' => array_values(array_unique($normalized)),
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

    private function is_feature_enabled($feature_key, $default)
    {
        return CPBSCombinedHelpers::is_feature_enabled($feature_key, $default);
    }
}
