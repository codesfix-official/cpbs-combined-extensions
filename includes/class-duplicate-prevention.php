<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Duplicate Booking Prevention
// ============================================================================
final class CPBSCombinedDuplicateBookingPrevention
{
    const CONTEXT = 'cpbs';

    public function __construct()
    {
        // Hook before the main plugin's handler (priority 1 vs default 10).
        add_action('wp_ajax_cpbs_go_to_step',        array($this, 'intercept'), 1);
        add_action('wp_ajax_nopriv_cpbs_go_to_step', array($this, 'intercept'), 1);

        // A payment callback arriving late must not reactivate a booking that
        // this feature has already replaced and marked as Failed.
        add_action('added_post_meta',   array($this, 'protect_replaced_booking_status'), 99, 4);
        add_action('updated_post_meta', array($this, 'protect_replaced_booking_status'), 99, 4);
    }

    /**
     * Intercepts the go_to_step AJAX action.
     * Enforces license plate as mandatory and prevents duplicate bookings.
     */
    public function intercept()
    {
        $prefix        = self::CONTEXT . '_';
        $step_request  = isset($_POST[$prefix . 'step_request']) ? (int) $_POST[$prefix . 'step_request'] : 0;

        // ── Duplicate booking check ─────────────────────────────────────────
        // Only run on step 4→5 transition (just before the booking is created).
        if ($step_request > 4) {
            $raw_location  = isset($_POST[$prefix . 'location_id']) ? wp_unslash($_POST[$prefix . 'location_id']) : 0;
            $location_id   = is_array($raw_location) ? (int) reset($raw_location) : absint($raw_location);

            $email         = isset($_POST[$prefix . 'client_contact_detail_email_address'])
                ? sanitize_email(wp_unslash($_POST[$prefix . 'client_contact_detail_email_address']))
                : '';
            $license_plate = isset($_POST[$prefix . 'client_contact_detail_license_plate'])
                ? sanitize_text_field(wp_unslash($_POST[$prefix . 'client_contact_detail_license_plate']))
                : '';
            $entry_date    = isset($_POST[$prefix . 'entry_date'])
                ? sanitize_text_field(wp_unslash($_POST[$prefix . 'entry_date']))
                : '';
            $entry_time    = isset($_POST[$prefix . 'entry_time'])
                ? sanitize_text_field(wp_unslash($_POST[$prefix . 'entry_time']))
                : '';
            $exit_date     = isset($_POST[$prefix . 'exit_date'])
                ? sanitize_text_field(wp_unslash($_POST[$prefix . 'exit_date']))
                : '';
            $exit_time     = isset($_POST[$prefix . 'exit_time'])
                ? sanitize_text_field(wp_unslash($_POST[$prefix . 'exit_time']))
                : '';

            $this->debug_log( 'intercept fired', array(
                'step_request'  => $step_request,
                'email'         => $email,
                'license_plate' => $license_plate,
                'location_id'   => $location_id,
                'entry_date'    => $entry_date,
                'entry_time'    => $entry_time,
                'exit_date'     => $exit_date,
                'exit_time'     => $exit_time,
            ) );

            if (!empty($email) && !empty($license_plate) && $location_id > 0
                && !empty($entry_date) && !empty($exit_date)
            ) {
                // Convert d-m-Y dates to Y-m-d H:i (the format stored as entry/exit_datetime_2).
                $new_entry_dt2 = $this->to_sortable_datetime($entry_date, $entry_time);
                $new_exit_dt2  = $this->to_sortable_datetime($exit_date, $exit_time);

                $this->debug_log( 'datetime converted', array(
                    'new_entry_dt2' => $new_entry_dt2,
                    'new_exit_dt2'  => $new_exit_dt2,
                ) );

                if ($new_entry_dt2 && $new_exit_dt2) {
                    $duplicate_ids = $this->find_duplicates($email, $license_plate, $location_id, $new_entry_dt2, $new_exit_dt2);

                    $this->debug_log( 'find_duplicates result', array(
                        'duplicate_ids' => $duplicate_ids,
                    ) );

                    if (!empty($duplicate_ids) && $this->has_blocking_duplicate($duplicate_ids)) {
                        $this->send_global_error(
                            3,
                            esc_html__('A reservation with the same details already exists for this location and time period. Please contact us if you need assistance.', 'cpbs-combined-extensions')
                        );
                    }

                    if (!empty($duplicate_ids)) {
                        foreach ($duplicate_ids as $booking_id) {
                            $this->mark_unpaid_duplicate_failed($booking_id);
                        }
                    }
                }
            } else {
                $this->debug_log( 'check skipped — missing required fields', array(
                    'email_empty'         => empty($email),
                    'plate_empty'         => empty($license_plate),
                    'location_id_zero'    => ($location_id <= 0),
                    'entry_date_empty'    => empty($entry_date),
                    'exit_date_empty'     => empty($exit_date),
                ) );
            }
        }
    }

    /**
     * Writes a debug line to wp-content/uploads/cpbs-dup-debug.log.
     * Remove this method (and all $this->debug_log() calls) once the issue is resolved.
     */
    private function debug_log( $message, array $context = array() )
    {
        if (!CPBSCombinedHelpers::is_runtime_logging_enabled()) {
            return;
        }

        $upload = wp_upload_dir();
        $dir    = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
        if ( $dir === '' || ! is_writable( $dir ) ) {
            return;
        }

        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC][DUP] ' . $message;
        if ( ! empty( $context ) ) {
            $line .= ' ' . wp_json_encode( $context );
        }

        file_put_contents( $dir . '/cpbs-dup-debug.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX );
    }

    /**
     * Converts a date + time (in the site's configured display format) to the
     * Y-m-d H:i format the main plugin stores in entry/exit_datetime_2 meta.
     *
     * The main plugin uses CPBSOption::getOption('date_format') /
     * 'time_format' for all form parsing, so we mirror that here instead of
     * hardcoding 'd-m-Y' / 'H:i' which may not match the site's settings.
     *
     * @param string $date  Date in the site's configured display format (e.g. m-d-Y)
     * @param string $time  Time in the site's configured display format (e.g. h:i A)
     * @return string|false  Y-m-d H:i, or false on parse failure
     */
    private function to_sortable_datetime($date, $time)
    {
        // Determine the formats the booking form actually uses.
        $date_fmt = 'd-m-Y'; // fallback
        $time_fmt = 'H:i';   // fallback

        if (class_exists('CPBSOption')) {
            $opt_date = CPBSOption::getOption('date_format');
            $opt_time = CPBSOption::getOption('time_format');
            if (!empty($opt_date)) {
                $date_fmt = $opt_date;
            }
            if (!empty($opt_time)) {
                $time_fmt = $opt_time;
            }
        }

        $time = (empty($time) ? '00:00' : $time);
        $dt   = \DateTime::createFromFormat($date_fmt . ' ' . $time_fmt, $date . ' ' . $time);

        $this->debug_log('to_sortable_datetime', array(
            'date'     => $date,
            'time'     => $time,
            'date_fmt' => $date_fmt,
            'time_fmt' => $time_fmt,
            'result'   => $dt ? $dt->format('Y-m-d H:i') : false,
        ));

        return ($dt ? $dt->format('Y-m-d H:i') : false);
    }

    /**
     * Returns IDs of active bookings with the same email or license plate,
     * location, and an overlapping period.
     *
     * Candidate statuses include Pending (1), Processing/Accepted (2), and
     * On hold (5). Pending/On hold candidates are later replaced only when
     * their payment is not confirmed.
     *
     * Overlap condition:
     *   existing.entry_datetime_2 < new_exit   AND
     *   existing.exit_datetime_2  > new_entry
     *
     * Different locations are intentionally NOT checked (allowed per spec).
     *
     * @param string $email
     * @param string $license_plate
     * @param int    $location_id
     * @param string $new_entry_dt2  Y-m-d H:i
     * @param string $new_exit_dt2   Y-m-d H:i
     * @return int[]
     */
    private function find_duplicates($email, $license_plate, $location_id, $new_entry_dt2, $new_exit_dt2)
    {
        $p = self::CONTEXT . '_';

        $args = array(
            'post_type'      => self::CONTEXT . '_booking',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                // Block if EITHER the same email OR the same license plate
                // is involved — catches cases where the user changes one but
                // not the other to bypass the duplicate check.
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => $p . 'client_contact_detail_email_address',
                        'value'   => $email,
                        'compare' => '=',
                    ),
                    array(
                        'key'     => $p . 'client_contact_detail_license_plate',
                        'value'   => $license_plate,
                        'compare' => '=',
                    ),
                ),
                array(
                    'key'     => $p . 'location_id',
                    'value'   => $location_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
                array(
                    // Exclude terminal statuses that should not participate:
                    // 3 = Cancelled, 4 = Completed, 6 = Refunded, 7 = Failed.
                    // Pending (1), Processing/Accepted (2), and On hold (5)
                    // must be evaluated below because Pending/On hold unpaid
                    // bookings can be replaced by the new booking.
                    'key'     => $p . 'booking_status_id',
                    'value'   => array(3, 4, 6, 7),
                    'compare' => 'NOT IN',
                    'type'    => 'NUMERIC',
                ),
                // Existing booking starts before the new booking ends.
                // Use CHAR type: Y-m-d H:i strings sort chronologically,
                // avoiding CAST(DATETIME) issues with missing seconds.
                array(
                    'key'     => $p . 'entry_datetime_2',
                    'value'   => $new_exit_dt2,
                    'compare' => '<',
                    'type'    => 'CHAR',
                ),
                // Existing booking ends after the new booking starts.
                array(
                    'key'     => $p . 'exit_datetime_2',
                    'value'   => $new_entry_dt2,
                    'compare' => '>',
                    'type'    => 'CHAR',
                ),
            ),
        );

        $query = new WP_Query($args);
        wp_reset_postdata();

        $duplicate_ids = array_map('absint', (array) $query->posts);

        $this->debug_log( 'WP_Query result', array(
            'post_count'  => $query->post_count,
            'found_posts' => $query->found_posts,
            'duplicate_ids' => $duplicate_ids,
            'query_args'  => $args,
        ) );

        return $duplicate_ids;
    }

    /**
     * Keep paid, unknown, and otherwise ambiguous duplicates blocking.
     * Pending/On hold bookings can be replaced when unpaid. A Processing /
     * Accepted booking can only be replaced when the payment layer explicitly
     * says it is not paid; a missing payment value remains ambiguous.
     *
     * @param int[] $booking_ids
     * @return bool
     */
    private function has_blocking_duplicate($booking_ids)
    {
        foreach ((array) $booking_ids as $booking_id) {
            $meta = CPBSCombinedHelpers::get_booking_meta((int) $booking_id);
            $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
            $payment_status = isset($meta['payment_status']) ? strtolower((string) $meta['payment_status']) : '';

            if (!$this->is_replaceable_unpaid_booking($status_id, $payment_status)) {
                $this->debug_log('duplicate remains blocking', array(
                    'booking_id' => (int) $booking_id,
                    'booking_status_id' => $status_id,
                    'payment_status' => $payment_status,
                ));
                return true;
            }
        }

        return false;
    }

    /**
     * A replaceable booking must be in a known unpaid working state. A missing
     * or paid payment status is not enough to auto-fail an ambiguous booking.
     *
     * @param int    $status_id
     * @param string $payment_status
     * @return bool
     */
    private function is_replaceable_unpaid_booking($status_id, $payment_status)
    {
        $status_id = (int) $status_id;
        $payment_status = strtolower((string) $payment_status);

        if (!in_array($status_id, array(1, 2, 5), true) || $payment_status === 'paid') {
            return false;
        }

        // Status 2 can be a valid accepted booking for non-Stripe/payment
        // methods. Do not auto-fail it unless payment is explicitly unpaid.
        if ($status_id === 2 && $payment_status === '') {
            return false;
        }

        return true;
    }

    /**
     * Marks a clearly unpaid duplicate as Failed so the new booking can use
     * the same location and period. The booking is retained for audit/history.
     *
     * @param int $booking_id
     * @return bool
     */
    private function mark_unpaid_duplicate_failed($booking_id)
    {
        $booking_id = absint($booking_id);
        if ($booking_id <= 0 || !CPBSCombinedHelpers::is_booking_post($booking_id)) {
            return false;
        }

        $meta = CPBSCombinedHelpers::get_booking_meta($booking_id);
        $status_id = isset($meta['booking_status_id']) ? (int) $meta['booking_status_id'] : 0;
        $payment_status = isset($meta['payment_status']) ? strtolower((string) $meta['payment_status']) : '';

        if (!$this->is_replaceable_unpaid_booking($status_id, $payment_status)) {
            return false;
        }

        CPBSCombinedHelpers::update_booking_meta($booking_id, 'duplicate_replaced_at', current_time('mysql'));
        CPBSCombinedHelpers::update_booking_meta($booking_id, 'duplicate_replaced_reason', 'replaced_by_new_unpaid_booking');
        CPBSCombinedHelpers::update_booking_meta($booking_id, 'booking_status_id', 7);

        $this->debug_log('unpaid duplicate marked failed', array(
            'booking_id' => $booking_id,
            'previous_status_id' => $status_id,
            'payment_status' => $payment_status,
        ));

        return true;
    }

    /**
     * Prevents the parent payment callback from changing a replaced booking
     * back to Processing after a delayed payment succeeds.
     *
     * @param int    $meta_id
     * @param int    $object_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return void
     */
    public function protect_replaced_booking_status($meta_id, $object_id, $meta_key, $meta_value)
    {
        unset($meta_id, $meta_value);

        $object_id = absint($object_id);
        $normalized_key = CPBSCombinedHelpers::normalize_booking_meta_key($meta_key);

        if ($object_id <= 0 || $normalized_key !== 'booking_status_id'
            || !CPBSCombinedHelpers::is_booking_post($object_id)
            || get_post_meta($object_id, CPBSCombinedHelpers::get_meta_prefix() . 'duplicate_replaced_at', true) === ''
            || (int) get_post_meta($object_id, CPBSCombinedHelpers::get_meta_prefix() . 'booking_status_id', true) === 7
        ) {
            return;
        }

        static $guard = array();
        if (isset($guard[$object_id])) {
            return;
        }

        $guard[$object_id] = true;
        CPBSCombinedHelpers::update_booking_meta($object_id, 'booking_status_id', 7);
        unset($guard[$object_id]);

        $this->debug_log('replaced booking status protected from reactivation', array(
            'booking_id' => $object_id,
        ));
    }

    /**
     * Outputs a global error JSON response and exits.
     * This short-circuits the main plugin's AJAX handler.
     *
     * @param int    $step    Step to return the user to.
     * @param string $message Human-readable error message.
     */
    private function send_global_error($step, $message)
    {
        echo wp_json_encode(array(
            'step'  => (int) $step,
            'error' => array(
                'local'  => array(),
                'global' => array(array('message' => $message)),
            ),
        ));
        exit;
    }
}
