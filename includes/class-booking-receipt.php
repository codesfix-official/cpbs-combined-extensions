<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Booking Receipt Override
// ============================================================================
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
        return CPBSCombinedHelpers::is_feature_enabled($feature_key, $default);
    }
}


// ============================================================================
// Parking QR Code
// ============================================================================
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



