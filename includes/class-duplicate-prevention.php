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
                    $found = $this->has_duplicate($email, $license_plate, $location_id, $new_entry_dt2, $new_exit_dt2);

                    $this->debug_log( 'has_duplicate result', array( 'found' => $found ) );

                    if ($found) {
                        $this->send_global_error(
                            3,
                            esc_html__('A reservation with the same details already exists for this location and time period. Please contact us if you need assistance.', 'cpbs-combined-extensions')
                        );
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
     * Returns true if an active booking exists with the same email + license
     * plate + location whose period overlaps the requested period.
     *
     * "Active" means booking_status_id 1 (Pending) or 2 (Processing/Accepted).
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
     * @return bool
     */
    private function has_duplicate($email, $license_plate, $location_id, $new_entry_dt2, $new_exit_dt2)
    {
        $p = self::CONTEXT . '_';

        $args = array(
            'post_type'      => self::CONTEXT . '_booking',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
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
                    // Exclude statuses that should not block a new booking:
                    // 1 = Pending, 3 = Cancelled, 4 (Completed) 6 = Refunded, 7 = Failed.
                    // Keep 2 (Processing/Accepted), 5 (On hold)
                    // as blocking states.
                    'key'     => $p . 'booking_status_id',
                    'value'   => array(1, 3, 4, 6, 7),
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

        $this->debug_log( 'WP_Query result', array(
            'post_count'  => $query->post_count,
            'found_posts' => $query->found_posts,
            'query_args'  => $args,
        ) );

        return $query->post_count > 0;
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

