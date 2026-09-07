<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Facility Vendor Frontend Portal
 *
 * Vendor:
 * - Can only view bookings
 * - Can only see bookings belonging to assigned facility
 * - Cannot edit/change booking status
 * - Uses existing customer login form
 * - Gets redirected to /facility-dashboard/
 */
final class CPBSCombinedFacilityVendorPortal
{
    const ROLE = 'cpbs_facility_vendor';

    const CAPABILITY = 'cpbs_view_facility_dashboard';

    const LOCATION_VENDOR_META = 'cpbs_vendor_user_id';

    const NONCE_LOCATION = 'cpbs_facility_vendor_location';

    const NONCE_DASHBOARD = 'cpbs_facility_vendor_dashboard';

    const AJAX_ACTION = 'cpbs_facility_vendor_bookings';

    const BOOKINGS_PER_PAGE = 10;


    /**
     * Constructor
     */
    public function __construct()
    {
        /*
         * Do this directly instead of registering another
         * plugins_loaded callback.
         */
        $this->ensure_vendor_role();

        add_action(
            'add_meta_boxes',
            array($this, 'register_location_meta_box')
        );

        add_action(
            'save_post',
            array($this, 'save_location_vendor')
        );

        add_shortcode(
            'cpbs_facility_dashboard',
            array($this, 'render_dashboard_shortcode')
        );

        add_action(
            'wp_ajax_' . self::AJAX_ACTION,
            array($this, 'ajax_get_bookings')
        );

        add_action(
            'admin_init',
            array($this, 'restrict_vendor_admin')
        );

        add_filter(
            'show_admin_bar',
            array($this, 'hide_vendor_admin_bar')
        );

        add_action(
            'wp_enqueue_scripts',
            array($this, 'enqueue_assets')
        );
    }


    /**
     * Create/update vendor role
     */
    public function ensure_vendor_role()
    {
        $role = get_role(self::ROLE);

        if (!$role) {

            add_role(
                self::ROLE,
                __('Facility Vendor', 'cpbs-combined-extensions'),
                array(
                    'read' => true,
                    self::CAPABILITY => true,
                )
            );

            return;
        }

        $role->add_cap('read');
        $role->add_cap(self::CAPABILITY);
    }


    /**
     * Location CPT
     */
    private function get_location_post_type()
    {
        if (
            class_exists('CPBSLocation') &&
            method_exists('CPBSLocation', 'getCPTName')
        ) {
            return CPBSLocation::getCPTName();
        }

        return 'cpbs_location';
    }


    /**
     * Booking CPT
     */
    private function get_booking_post_type()
    {
        if (
            class_exists('CPBSCombinedHelpers') &&
            method_exists(
                'CPBSCombinedHelpers',
                'get_booking_post_type'
            )
        ) {
            return CPBSCombinedHelpers::get_booking_post_type();
        }

        if (
            class_exists('CPBSBooking') &&
            method_exists('CPBSBooking', 'getCPTName')
        ) {
            return CPBSBooking::getCPTName();
        }

        return 'cpbs_booking';
    }


    /**
     * Meta prefix
     */
    private function get_meta_prefix()
    {
        if (
            class_exists('CPBSCombinedHelpers') &&
            method_exists(
                'CPBSCombinedHelpers',
                'get_meta_prefix'
            )
        ) {
            return CPBSCombinedHelpers::get_meta_prefix();
        }

        return 'cpbs_';
    }


    /*
    |--------------------------------------------------------------------------
    | Location Vendor Assignment
    |--------------------------------------------------------------------------
    */

    public function register_location_meta_box()
    {
        add_meta_box(
            'cpbs-facility-vendor-assignment',
            __('Facility Vendor', 'cpbs-combined-extensions'),
            array($this, 'render_location_meta_box'),
            $this->get_location_post_type(),
            'side',
            'default'
        );
    }


    public function render_location_meta_box($post)
    {
        wp_nonce_field(
            self::NONCE_LOCATION,
            'cpbs_facility_vendor_location_nonce'
        );

        $assigned_vendor_id = absint(
            get_post_meta(
                $post->ID,
                self::LOCATION_VENDOR_META,
                true
            )
        );

        $vendors = get_users(
            array(
                'role' => self::ROLE,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => array(
                    'ID',
                    'display_name',
                    'user_email',
                ),
            )
        );
        ?>

        <p>
            <label
                for="cpbs_facility_vendor_user_id"
                style="font-weight:600;"
            >
                <?php
                echo esc_html__(
                    'Assigned Vendor',
                    'cpbs-combined-extensions'
                );
                ?>
            </label>
        </p>

        <select
            name="cpbs_facility_vendor_user_id"
            id="cpbs_facility_vendor_user_id"
            style="width:100%;"
        >

            <option value="0">
                <?php
                echo esc_html__(
                    '— No Vendor Assigned —',
                    'cpbs-combined-extensions'
                );
                ?>
            </option>

            <?php foreach ($vendors as $vendor) : ?>

                <option
                    value="<?php echo esc_attr($vendor->ID); ?>"
                    <?php selected(
                        $assigned_vendor_id,
                        $vendor->ID
                    ); ?>
                >

                    <?php
                    echo esc_html(
                        $vendor->display_name .
                        ' (' .
                        $vendor->user_email .
                        ')'
                    );
                    ?>

                </option>

            <?php endforeach; ?>

        </select>

        <p
            style="
                margin-top:10px;
                color:#646970;
                font-size:12px;
                line-height:1.5;
            "
        >
            <?php
            echo esc_html__(
                'This vendor will only be able to view reservations for this facility.',
                'cpbs-combined-extensions'
            );
            ?>
        </p>

        <?php
    }


    /**
     * Save assigned vendor
     */
    public function save_location_vendor($post_id)
    {
        if (
            !isset(
                $_POST['cpbs_facility_vendor_location_nonce']
            )
        ) {
            return;
        }

        if (
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash(
                        $_POST[
                            'cpbs_facility_vendor_location_nonce'
                        ]
                    )
                ),
                self::NONCE_LOCATION
            )
        ) {
            return;
        }

        if (
            defined('DOING_AUTOSAVE') &&
            DOING_AUTOSAVE
        ) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if (wp_is_post_autosave($post_id)) {
            return;
        }

        if (
            get_post_type($post_id) !==
            $this->get_location_post_type()
        ) {
            return;
        }

        if (
            !current_user_can(
                'edit_post',
                $post_id
            )
        ) {
            return;
        }

        $vendor_id = isset(
            $_POST['cpbs_facility_vendor_user_id']
        )
            ? absint(
                wp_unslash(
                    $_POST[
                        'cpbs_facility_vendor_user_id'
                    ]
                )
            )
            : 0;


        /*
         * Remove assignment
         */
        if ($vendor_id === 0) {

            delete_post_meta(
                $post_id,
                self::LOCATION_VENDOR_META
            );

            return;
        }


        $vendor = get_user_by(
            'id',
            $vendor_id
        );

        if (!$vendor) {
            return;
        }


        if (
            !in_array(
                self::ROLE,
                (array) $vendor->roles,
                true
            )
        ) {
            return;
        }


        update_post_meta(
            $post_id,
            self::LOCATION_VENDOR_META,
            $vendor_id
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Vendor Helpers
    |--------------------------------------------------------------------------
    */

    private function is_facility_vendor($user_id = 0)
    {
        $user_id = absint($user_id);

        if (!$user_id) {
            return false;
        }

        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        return in_array(
            self::ROLE,
            (array) $user->roles,
            true
        );
    }


    /**
     * Find facility assigned to vendor
     */
    private function get_vendor_location_id($user_id)
    {
        $locations = get_posts(
            array(
                'post_type' => $this->get_location_post_type(),

                'post_status' => array(
                    'publish',
                    'private',
                    'draft',
                ),

                'posts_per_page' => 1,

                'fields' => 'ids',

                'meta_query' => array(
                    array(
                        'key' => self::LOCATION_VENDOR_META,
                        'value' => absint($user_id),
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
            )
        );

        if (empty($locations)) {
            return 0;
        }

        return absint($locations[0]);
    }


    private function get_location_name($location_id)
    {
        $title = get_the_title(
            absint($location_id)
        );

        return $title ? $title : '';
    }


    /*
    |--------------------------------------------------------------------------
    | Vendor Admin Restriction
    |--------------------------------------------------------------------------
    */

    public function restrict_vendor_admin()
    {
        if (wp_doing_ajax()) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        if (
            !$this->is_facility_vendor(
                get_current_user_id()
            )
        ) {
            return;
        }

        wp_safe_redirect(
            home_url('/facility-dashboard/')
        );

        exit;
    }


    public function hide_vendor_admin_bar($show)
    {
        if (
            is_user_logged_in() &&
            $this->is_facility_vendor(
                get_current_user_id()
            )
        ) {
            return false;
        }

        return $show;
    }


    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */

    public function enqueue_assets()
    {
        if (!is_page('facility-dashboard')) {
            return;
        }


        $css_path =
            CPBS_COMBINED_PLUGIN_DIR .
            'assets/css/facility-vendor.css';

        $js_path =
            CPBS_COMBINED_PLUGIN_DIR .
            'assets/js/facility-vendor.js';


        $css_version = file_exists($css_path)
            ? filemtime($css_path)
            : CPBS_COMBINED_VERSION;

        $js_version = file_exists($js_path)
            ? filemtime($js_path)
            : CPBS_COMBINED_VERSION;


        wp_enqueue_style(
            'cpbs-facility-vendor',
            CPBS_COMBINED_PLUGIN_URL .
            'assets/css/facility-vendor.css',
            array(),
            $css_version
        );


        wp_enqueue_script(
            'cpbs-facility-vendor',
            CPBS_COMBINED_PLUGIN_URL .
            'assets/js/facility-vendor.js',
            array(),
            $js_version,
            true
        );


        wp_localize_script(
            'cpbs-facility-vendor',
            'cpbsFacilityVendor',
            array(
                'ajaxUrl' => admin_url(
                    'admin-ajax.php'
                ),

                'nonce' => wp_create_nonce(
                    self::NONCE_DASHBOARD
                ),

                'action' => self::AJAX_ACTION,

                'refreshInterval' => 10000,

                'perPage' => self::BOOKINGS_PER_PAGE,

                'strings' => array(
                    'loading' => __(
                        'Loading reservations...',
                        'cpbs-combined-extensions'
                    ),

                    'empty' => __(
                        'No reservations found.',
                        'cpbs-combined-extensions'
                    ),

                    'error' => __(
                        'Unable to refresh reservations.',
                        'cpbs-combined-extensions'
                    ),

                    'previous' => __(
                        'Previous',
                        'cpbs-combined-extensions'
                    ),

                    'next' => __(
                        'Next',
                        'cpbs-combined-extensions'
                    ),

                    'page' => __(
                        'Page',
                        'cpbs-combined-extensions'
                    ),
                ),
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Dashboard Shortcode
    |--------------------------------------------------------------------------
    */

    public function render_dashboard_shortcode()
    {
        /*
         * Guest:
         * Reuse existing customer login form.
         */
        if (!is_user_logged_in()) {

            $customer_portal =
                class_exists(
                    'CPBSCombinedCustomerPortal'
                )
                ? CPBSCombinedCustomerPortal::get_instance()
                : null;


            if (
                $customer_portal instanceof
                CPBSCombinedCustomerPortal
            ) {

                return
                    '<div class="cpbs-facility-dashboard cpbs-facility-auth">' .
                    $customer_portal->render_customer_access_panel() .
                    '</div>';
            }


            return $this->render_message(
                __(
                    'Please log in to access the facility dashboard.',
                    'cpbs-combined-extensions'
                ),
                'warning'
            );
        }


        $user_id = get_current_user_id();


        /*
         * Only vendor can access this dashboard.
         */
        if (
            !$this->is_facility_vendor($user_id)
        ) {

            return $this->render_message(
                __(
                    'You do not have permission to access this dashboard.',
                    'cpbs-combined-extensions'
                ),
                'error'
            );
        }


        $location_id =
            $this->get_vendor_location_id(
                $user_id
            );


        if (!$location_id) {

            return $this->render_message(
                __(
                    'No facility has been assigned to your account yet. Please contact the administrator.',
                    'cpbs-combined-extensions'
                ),
                'warning'
            );
        }


        return $this->render_vendor_dashboard(
            $location_id,
            $user_id
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Dashboard HTML
    |--------------------------------------------------------------------------
    */

    private function render_vendor_dashboard(
        $location_id,
        $user_id
    ) {

        $user = get_userdata($user_id);

        $location_name =
            $this->get_location_name(
                $location_id
            );


        /*
         * IMPORTANT:
         * Logout goes to HOME.
         */
        $logout_url =
            wp_logout_url(
                home_url('/')
            );

        ob_start();
        ?>

        <div
            class="cpbs-facility-dashboard"
            data-location-id="<?php echo esc_attr($location_id); ?>"
        >

            <div class="cpbs-facility-dashboard-header">

                <div class="cpbs-facility-header-content">

                    <span class="cpbs-facility-kicker">
                        <?php
                        echo esc_html__(
                            'Facility Dashboard',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </span>

                    <h2 class="cpbs-facility-title">
                        <?php
                        echo esc_html($location_name);
                        ?>
                    </h2>

                    <p class="cpbs-facility-welcome">
                        <?php
                        printf(
                            esc_html__(
                                'Welcome, %s',
                                'cpbs-combined-extensions'
                            ),
                            esc_html(
                                $user
                                    ? $user->display_name
                                    : ''
                            )
                        );
                        ?>
                    </p>

                </div>


                <div class="cpbs-facility-header-actions">

                    <span
                        class="cpbs-facility-live-indicator"
                    >
                        <span></span>
                        <?php
                        echo esc_html__(
                            'Live',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </span>

                    <a
                        href="<?php echo esc_url($logout_url); ?>"
                        class="cpbs-facility-logout"
                    >
                        <?php
                        echo esc_html__(
                            'Sign Out',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </a>

                </div>

            </div>


            <!-- Summary -->
            <div class="cpbs-facility-summary">

                <div class="cpbs-facility-summary-card">
                    <span class="cpbs-summary-label">
                        <?php
                        echo esc_html__(
                            'Upcoming',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </span>

                    <strong
                        data-count="upcoming"
                    >
                        0
                    </strong>
                </div>


                <div class="cpbs-facility-summary-card">
                    <span class="cpbs-summary-label">
                        <?php
                        echo esc_html__(
                            'Completed',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </span>

                    <strong
                        data-count="completed"
                    >
                        0
                    </strong>
                </div>


                <div class="cpbs-facility-summary-card">
                    <span class="cpbs-summary-label">
                        <?php
                        echo esc_html__(
                            'Failed',
                            'cpbs-combined-extensions'
                        );
                        ?>
                    </span>

                    <strong
                        data-count="failed"
                    >
                        0
                    </strong>
                </div>

            </div>


            <!-- Filters -->
            <div class="cpbs-facility-filters">

                <button
                    type="button"
                    class="cpbs-facility-filter is-active"
                    data-filter="all"
                >
                    <?php
                    echo esc_html__(
                        'All',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>


                <button
                    type="button"
                    class="cpbs-facility-filter"
                    data-filter="upcoming"
                >
                    <?php
                    echo esc_html__(
                        'Upcoming',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>


                <button
                    type="button"
                    class="cpbs-facility-filter"
                    data-filter="completed"
                >
                    <?php
                    echo esc_html__(
                        'Completed',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>


                <button
                    type="button"
                    class="cpbs-facility-filter"
                    data-filter="failed"
                >
                    <?php
                    echo esc_html__(
                        'Failed',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>

            </div>


            <!-- Table -->
            <div class="cpbs-facility-table-wrap">

                <table
                    class="cpbs-facility-bookings-table"
                >

                    <thead>

                        <tr>

                            <th>
                                <?php
                                echo esc_html__(
                                    'Customer',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </th>

                            <th>
                                <?php
                                echo esc_html__(
                                    'Vehicle',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </th>

                            <th>
                                <?php
                                echo esc_html__(
                                    'Entry',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </th>

                            <th>
                                <?php
                                echo esc_html__(
                                    'Exit',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </th>

                            <th>
                                <?php
                                echo esc_html__(
                                    'Status',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </th>

                        </tr>

                    </thead>


                    <tbody data-bookings-body>

                        <tr class="cpbs-facility-loading-row">

                            <td
                                colspan="5"
                            >
                                <?php
                                echo esc_html__(
                                    'Loading reservations...',
                                    'cpbs-combined-extensions'
                                );
                                ?>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>


            <!-- Pagination -->
                <div
                    class="cpbs-facility-pagination is-hidden"
                    data-pagination
                >

                <button
                    type="button"
                    class="cpbs-facility-page-button"
                    data-page-action="previous"
                >
                    <?php
                    echo esc_html__(
                        'Previous',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>


                <div
                    class="cpbs-facility-page-numbers"
                    data-page-numbers
                ></div>


                <button
                    type="button"
                    class="cpbs-facility-page-button"
                    data-page-action="next"
                >
                    <?php
                    echo esc_html__(
                        'Next',
                        'cpbs-combined-extensions'
                    );
                    ?>
                </button>

            </div>


            <div
                class="cpbs-facility-page-info"
                data-page-info
            ></div>


            <div
                class="cpbs-facility-last-updated"
                data-last-updated
            ></div>

        </div>

        <?php

        return ob_get_clean();
    }


    /*
    |--------------------------------------------------------------------------
    | AJAX
    |--------------------------------------------------------------------------
    */

    public function ajax_get_bookings()
    {
        if (!is_user_logged_in()) {

            wp_send_json_error(
                array(
                    'message' => __(
                        'You must be logged in.',
                        'cpbs-combined-extensions'
                    ),
                ),
                403
            );
        }


        if (
            !$this->is_facility_vendor(
                get_current_user_id()
            )
        ) {

            wp_send_json_error(
                array(
                    'message' => __(
                        'You do not have permission to access this dashboard.',
                        'cpbs-combined-extensions'
                    ),
                ),
                403
            );
        }


        /*
         * Do not let check_ajax_referer() terminate the request with "-1".
         * A plain "-1" response causes the browser JSON parser to fail.
         */
        if (
            !check_ajax_referer(
                self::NONCE_DASHBOARD,
                'nonce',
                false
            )
        ) {
            wp_send_json_error(
                array(
                    'message' => __(
                        'Security check failed. Please refresh the page and try again.',
                        'cpbs-combined-extensions'
                    ),
                ),
                403
            );
        }


        $user_id =
            get_current_user_id();


        /*
         * IMPORTANT:
         * Never accept location_id from frontend.
         */
        $location_id =
            $this->get_vendor_location_id(
                $user_id
            );


        if (!$location_id) {

            wp_send_json_error(
                array(
                    'message' => __(
                        'No facility has been assigned to your account.',
                        'cpbs-combined-extensions'
                    ),
                ),
                403
            );
        }


        $category = isset($_POST['category'])
            ? sanitize_key(
                wp_unslash(
                    $_POST['category']
                )
            )
            : 'all';


        $allowed_categories = array(
            'all',
            'upcoming',
            'previous',
            'completed',
            'failed',
        );


        if (
            !in_array(
                $category,
                $allowed_categories,
                true
            )
        ) {
            $category = 'all';
        }


        $requested_page = isset($_POST['page'])
            ? absint(
                wp_unslash(
                    $_POST['page']
                )
            )
            : 1;


        if ($requested_page < 1) {
            $requested_page = 1;
        }


        $all_bookings =
            $this->get_location_bookings(
                $location_id
            );


        /*
         * Classify every booking first.
         */
        $classified_bookings = array();


        foreach (
            $all_bookings as $booking_id
        ) {

            $booking =
                $this->get_booking_data(
                    $booking_id,
                    $location_id
                );


            if (!$booking) {
                continue;
            }


            $classified_bookings[] = $booking;
        }


        /*
         * Sort EVERYTHING newest -> oldest.
         *
         * This is especially important for "All".
         */
        usort(
            $classified_bookings,
            function ($a, $b) {

                $a_time = isset(
                    $a['_sort_timestamp']
                )
                    ? (int) $a['_sort_timestamp']
                    : 0;

                $b_time = isset(
                    $b['_sort_timestamp']
                )
                    ? (int) $b['_sort_timestamp']
                    : 0;


                if ($a_time === $b_time) {

                    return
                        (int) $b['id'] -
                        (int) $a['id'];
                }


                return
                    $b_time -
                    $a_time;
            }
        );


        /*
         * Summary counts.
         */
        $counts = array(
            'upcoming'  => 0,
            'previous'  => 0,
            'completed' => 0,
            'failed'    => 0,
        );


        foreach (
            $classified_bookings as $booking
        ) {

            $booking_category =
                $booking['category'];


            if (
                isset(
                    $counts[$booking_category]
                )
            ) {
                $counts[$booking_category]++;
            }
        }


        /*
         * Apply selected filter.
         */
        if ($category === 'all') {

            $filtered_bookings =
                $classified_bookings;

        } else {

            $filtered_bookings = array();

            foreach (
                $classified_bookings as $booking
            ) {

                if (
                    $booking['category'] ===
                    $category
                ) {
                    $filtered_bookings[] =
                        $booking;
                }
            }
        }


        $total_items =
            count($filtered_bookings);


        $per_page =
            self::BOOKINGS_PER_PAGE;


        $total_pages =
            $total_items > 0
                ? (int) ceil(
                    $total_items / $per_page
                )
                : 1;


        /*
         * If records disappear during refresh,
         * keep requested page valid.
         */
        if (
            $requested_page >
            $total_pages
        ) {

            $requested_page =
                $total_pages;
        }


        $offset =
            (
                $requested_page - 1
            ) * $per_page;


        $paged_bookings =
            array_slice(
                $filtered_bookings,
                $offset,
                $per_page
            );


        /*
         * Remove internal sorting value.
         */
        foreach (
            $paged_bookings as &$booking
        ) {

            unset(
                $booking['_sort_timestamp']
            );
        }

        unset($booking);


        wp_send_json_success(
            array(
                'bookings' => array_values(
                    $paged_bookings
                ),

                'counts' => $counts,

                'pagination' => array(
                    'currentPage' =>
                        $requested_page,

                    'perPage' =>
                        $per_page,

                    'totalItems' =>
                        $total_items,

                    'totalPages' =>
                        $total_pages,
                ),

                'category' =>
                    $category,

                'lastUpdated' =>
                    current_time('mysql'),
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Booking Retrieval
    |--------------------------------------------------------------------------
    */

    private function get_location_bookings(
        $location_id
    ) {

        $booking_ids =
            get_posts(
                array(
                    'post_type' =>
                        $this->get_booking_post_type(),

                    'post_status' => 'any',

                    'posts_per_page' => -1,

                    'fields' => 'ids',

                    'orderby' => 'date',

                    'order' => 'DESC',

                    'meta_query' => array(
                        array(
                            'key' => 'cpbs_location_id',
                            'value' =>
                                absint(
                                    $location_id
                                ),
                            'compare' => '=',
                            'type' => 'NUMERIC',
                        ),
                    ),
                )
            );


        return array_map(
            'absint',
            $booking_ids
        );
    }


    /**
     * Get individual booking data.
     */
    private function get_booking_data(
        $booking_id,
        $location_id
    ) {

        $booking_id = absint($booking_id);
        $location_id = absint($location_id);

        if (!$booking_id || !$location_id) {
            return false;
        }

        /*
         * Security check:
         * A vendor can only receive bookings from the facility assigned
         * to the currently logged-in vendor.
         *
         * CPBS stores booking location as cpbs_location_id.
         */
        $booking_location_id = absint(
            get_post_meta(
                $booking_id,
                'cpbs_location_id',
                true
            )
        );

        if ($booking_location_id !== $location_id) {
            return false;
        }

        /*
         * CPBSBooking::getBooking() is intentionally NOT used here.
         *
         * In the core CPBS plugin it is a non-static method, so calling:
         *
         *     CPBSBooking::getBooking($booking_id)
         *
         * causes a PHP fatal error.
         *
         * Reading the actual CPBS-prefixed post meta directly is safer
         * for this read-only vendor portal and avoids depending on the
         * internal Booking object implementation.
         */

        $get_meta = function ($key, $default = '') use ($booking_id) {

            $value = get_post_meta(
                $booking_id,
                'cpbs_' . ltrim($key, '_'),
                true
            );

            if (
                $value === '' ||
                $value === null ||
                $value === false
            ) {
                return $default;
            }

            return $value;
        };

        /*
         * Customer information.
         */
        $first_name = $get_meta(
            'client_contact_detail_first_name'
        );

        $last_name = $get_meta(
            'client_contact_detail_last_name'
        );

        $email = $get_meta(
            'client_contact_detail_email_address'
        );

        $phone = $get_meta(
            'client_contact_detail_phone_number'
        );

        $license_plate = $get_meta(
            'client_contact_detail_license_plate'
        );

        /*
         * Reservation entry.
         *
         * CPBS stores these using the cpbs_ prefix.
         */
        $entry_date = $get_meta('entry_date');
        $entry_time = $get_meta('entry_time');

        $entry_datetime = $get_meta('entry_datetime');

        if (empty($entry_datetime)) {
            $entry_datetime = $get_meta('entry_datetime_2');
        }

        /*
         * Reservation exit.
         */
        $exit_date = $get_meta('exit_date');
        $exit_time = $get_meta('exit_time');

        $exit_datetime = $get_meta('exit_datetime');

        if (empty($exit_datetime)) {
            $exit_datetime = $get_meta('exit_datetime_2');
        }

        /*
         * Some CPBS installations may not have the combined datetime
         * meta populated, so construct it from date + time.
         */
        if (
            empty($entry_datetime) &&
            (
                !empty($entry_date) ||
                !empty($entry_time)
            )
        ) {
            $entry_datetime = trim(
                $entry_date . ' ' . $entry_time
            );
        }

        if (
            empty($exit_datetime) &&
            (
                !empty($exit_date) ||
                !empty($exit_time)
            )
        ) {
            $exit_datetime = trim(
                $exit_date . ' ' . $exit_time
            );
        }

        /*
         * Booking status.
         *
         * CPBS uses:
         * 1 Pending
         * 2 Processing
         * 3 Cancelled
         * 4 Completed
         * 5 On hold
         * 6 Refunded
         * 7 Failed
         */
        $status_id = absint(
            $get_meta('booking_status_id')
        );

        /*
         * Place / space type.
         *
         * Keep this flexible because different CPBS configurations may
         * expose either place_type_name or space_type_name.
         */
        $space_type = '';

        $space_type_candidates = array(
            'space_type_name',
            'space_type',
            'place_type_name',
            'place_type',
        );

        foreach ($space_type_candidates as $space_key) {

            $candidate = $get_meta($space_key);

            if (
                $candidate !== '' &&
                $candidate !== null
            ) {
                $space_type = $candidate;
                break;
            }
        }

        /*
         * Status and dashboard category.
         */
        $status_name =
            $this->get_booking_status_name(
                $status_id
            );

        $category =
            $this->get_booking_category(
                $status_id,
                $entry_datetime
            );

        /*
         * Dashboard sorting:
         * newest reservation entry first.
         *
         * If the entry datetime cannot be parsed, use the booking post
         * creation date as a fallback.
         */
        $sort_datetime = !empty($entry_datetime)
            ? $entry_datetime
            : get_post_field(
                'post_date',
                $booking_id
            );

        $sort_timestamp = strtotime(
            $sort_datetime
        );

        if (!$sort_timestamp) {

            $sort_timestamp = (int) get_post_time(
                'U',
                true,
                $booking_id
            );
        }

        $customer_name = trim(
            $first_name . ' ' . $last_name
        );

        if (!$customer_name) {

            $customer_name = __(
                'Guest',
                'cpbs-combined-extensions'
            );
        }

        return array(
            'id' => $booking_id,

            'customer' => array(
                'name' => sanitize_text_field(
                    $customer_name
                ),

                'email' => sanitize_email(
                    $email
                ),

                'phone' => sanitize_text_field(
                    $phone
                ),
            ),

            'vehicle' => array(
                'licensePlate' =>
                    sanitize_text_field(
                        $license_plate
                    ),

                'spaceType' =>
                    sanitize_text_field(
                        $space_type
                    ),
            ),

            'entry' =>
                $this->format_datetime(
                    $entry_datetime
                ),

            'exit' =>
                $this->format_datetime(
                    $exit_datetime
                ),

            'status' => array(
                'id' => $status_id,

                'name' => $status_name,
            ),

            'category' => $category,

            /*
             * Internal value used only for server-side sorting.
             * ajax_get_bookings() removes it before JSON output.
             */
            '_sort_timestamp' => $sort_timestamp,
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Date / Status Helpers
    |--------------------------------------------------------------------------
    */

    private function get_meta_datetime(
        $booking_id,
        $datetime_key,
        $date_key,
        $time_key
    ) {

        $datetime =
            get_post_meta(
                $booking_id,
                strpos($datetime_key, 'cpbs_') === 0
                    ? $datetime_key
                    : 'cpbs_' . ltrim($datetime_key, '_'),
                true
            );


        if (!empty($datetime)) {
            return $datetime;
        }


        $date =
            get_post_meta(
                $booking_id,
                strpos($date_key, 'cpbs_') === 0
                    ? $date_key
                    : 'cpbs_' . ltrim($date_key, '_'),
                true
            );


        $time =
            get_post_meta(
                $booking_id,
                strpos($time_key, 'cpbs_') === 0
                    ? $time_key
                    : 'cpbs_' . ltrim($time_key, '_'),
                true
            );


        return trim(
            $date . ' ' . $time
        );
    }


    private function format_datetime(
        $datetime
    ) {

        if (empty($datetime)) {
            return '—';
        }


        $timestamp =
            strtotime($datetime);


        if (!$timestamp) {
            return sanitize_text_field(
                $datetime
            );
        }


        return wp_date(
            get_option(
                'date_format'
            ) . ' ' .
            get_option(
                'time_format'
            ),
            $timestamp
        );
    }


    private function get_booking_status_name(
        $status_id
    ) {

        $status_id = absint($status_id);

        /*
         * Fallback labels. These also keep the dashboard working if the
         * core status class is unavailable.
         */
        $fallback = array(
            1 => __('Pending', 'cpbs-combined-extensions'),
            2 => __('Processing', 'cpbs-combined-extensions'),
            3 => __('Cancelled', 'cpbs-combined-extensions'),
            4 => __('Completed', 'cpbs-combined-extensions'),
            5 => __('On hold', 'cpbs-combined-extensions'),
            6 => __('Refunded', 'cpbs-combined-extensions'),
            7 => __('Failed', 'cpbs-combined-extensions'),
        );

        /*
         * CPBSBookingStatus::getBookingStatus() is also a NON-STATIC
         * method in the core CPBS plugin.
         *
         * Therefore instantiate it before calling the method.
         */
        if (
            class_exists('CPBSBookingStatus')
        ) {

            $booking_status =
                new CPBSBookingStatus();

            if (
                method_exists(
                    $booking_status,
                    'getBookingStatus'
                )
            ) {

                $status =
                    $booking_status->getBookingStatus(
                        $status_id
                    );

                if (is_array($status)) {

                    if (
                        isset($status['name'])
                    ) {
                        return sanitize_text_field(
                            $status['name']
                        );
                    }

                    if (
                        isset($status[0])
                    ) {
                        return sanitize_text_field(
                            $status[0]
                        );
                    }
                }

                if (
                    is_string($status) &&
                    $status !== ''
                ) {
                    return sanitize_text_field(
                        $status
                    );
                }
            }
        }

        return isset($fallback[$status_id])
            ? $fallback[$status_id]
            : __('Unknown', 'cpbs-combined-extensions');
    }


    /**
     * Category rules:
     *
     * 4 = Completed
     * 7 = Failed
     *
     * Otherwise:
     * future entry = Upcoming
     * past entry   = Previous
     */
    private function get_booking_category(
        $status_id,
        $entry_datetime
    ) {

        $status_id =
            absint($status_id);


         if ($status_id === 4) {
            return 'completed';
        }
        
        if ($status_id === 7) {
            return 'failed';
        }
        
        $comparison_datetime = !empty($exit_datetime)
            ? $exit_datetime
            : $entry_datetime;
        
        $comparison_timestamp = strtotime($comparison_datetime);
        $now_timestamp = current_time('timestamp');
        
        if (
            $comparison_timestamp &&
            $comparison_timestamp < $now_timestamp
        ) {
            return 'previous';
        }
        
        return 'upcoming';
        }

    /*
    |--------------------------------------------------------------------------
    | Generic Message
    |--------------------------------------------------------------------------
    */

    private function render_message(
        $message,
        $type = 'warning'
    ) {

        ob_start();
        ?>

        <div
            class="
                cpbs-facility-dashboard
                cpbs-facility-message
                cpbs-facility-message-<?php echo esc_attr($type); ?>
            "
        >

            <div class="cpbs-facility-message-box">

                <?php
                echo esc_html($message);
                ?>

            </div>

        </div>

        <?php

        return ob_get_clean();
    }
}