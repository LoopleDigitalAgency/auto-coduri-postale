<?php
/*
Plugin Name: Auto Coduri Postale
Plugin URI: https://github.com/LoopleDigitalAgency/auto-coduri-postale
Description: Completează automat codul poștal pe pagina de checkout WooCommerce folosind API-ul Nominatim.
Version: 1.2
Author: Loople Romania
Author URI: https://www.loople.ro
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}

class WC_Auto_Postal_Code {
    public function __construct() {
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_auto_postal_code', array($this, 'settings_tab')); 
        add_action('woocommerce_update_options_auto_postal_code', array($this, 'update_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_postal_code', array($this, 'get_postal_code'));
        add_action('wp_ajax_nopriv_get_postal_code', array($this, 'get_postal_code'));
        add_action('wp_footer', array($this, 'inline_script'));
    }

    public function add_settings_tab($tabs) {
        $tabs['auto_postal_code'] = __('Auto Coduri Poștale', 'auto-coduri-postale');
        return $tabs;
    }

    public function settings_tab() {
        woocommerce_admin_fields($this->get_settings());
    }

    public function update_settings() {
        woocommerce_update_options($this->get_settings());
    }

    public function get_settings() {
        return array(
            'section_title' => array(
                'name' => __('Setări Auto Coduri Poștale', 'auto-coduri-postale'),
                'type' => 'title',
                'desc' => '',
                'id'   => 'wc_auto_postal_code_section_title'
            ),
            'enabled' => array(
                'name' => __('Activează completarea automată', 'auto-coduri-postale'),
                'type' => 'checkbox',
                'desc' => __('Completează automat codul poștal la checkout', 'auto-coduri-postale'),
                'id'   => 'wc_auto_postal_enabled',
                'default' => 'no'
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id'   => 'wc_auto_postal_code_section_end'
            )
        );
    }

    public function enqueue_scripts() {
        if (!is_checkout() || get_option('wc_auto_postal_enabled', 'no') !== 'yes') return;
        wp_localize_script('jquery', 'wcPostalCode', array('ajax_url' => admin_url('admin-ajax.php')));
    }

    public function get_postal_code() {
        if (!isset($_POST['address'], $_POST['city'], $_POST['_wpnonce']) || 
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'get_postal_code_nonce')) { 
            wp_send_json_error(['message' => 'Eroare de securitate: nonce invalid sau lipsă.']);
        }
            
        $address = sanitize_text_field(wp_unslash($_POST['address']));
        $city = sanitize_text_field(wp_unslash($_POST['city']));

        if (empty($address) || empty($city)) {
            wp_send_json_error(['message' => 'Adresă sau oraș lipsă.']);
        }

        $address = urlencode("{$address}, {$city}");
        $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&addressdetails=1";
        
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Eroare la conectarea cu API-ul.']);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body) && isset($body[0]['address']['postcode'])) {
            wp_send_json_success(['postal_code' => $body[0]['address']['postcode']]);
        } else {
            wp_send_json_error(['message' => 'Cod poștal negăsit.']);
        }
    }

    public function inline_script() {
        if (!is_checkout() || get_option('wc_auto_postal_enabled', 'no') !== 'yes') return;
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#billing_address_1, #billing_city').on('blur', function() {
                let address = $('#billing_address_1').val();
                let city = $('#billing_city').val();
                if (address && city) {
                    $.post(wcPostalCode.ajax_url, {
                        action: 'get_postal_code',
                        address: address,
                        city: city,
                        _wpnonce: '<?php echo esc_js(wp_create_nonce("get_postal_code_nonce")); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#billing_postcode').val(response.data.postal_code);
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
}

new WC_Auto_Postal_Code();

class Auto_Update_Notice {
    private $update_url = 'https://api.github.com/repos/LoopleDigitalAgency/auto-coduri-postale/releases/latest';

    public function __construct() {
        add_action('admin_notices', [$this, 'check_for_update_notice']);
    }

    public function check_for_update_notice() {
        $response = wp_remote_get($this->update_url);
        if (is_wp_error($response)) return;

        $update_info = json_decode(wp_remote_retrieve_body($response));
        $current_version = '1.0';

        if (version_compare($current_version, $update_info->version, '<')) {
            echo '<div class="notice notice-warning is-dismissible">
                <p><strong>Auto Cod Postal</strong> - O nouă versiune este disponibilă: <strong>' . esc_html($update_info->version) . '</strong>! 
                <a href="' . esc_url($update_info->download_url) . '">Descarcă actualizarea</a>.</p>
            </div>';
        }
    }
}

new Auto_Update_Notice();

class Auto_Update_Plugin {
    private $plugin_slug = 'auto-cod-postal';
    private $github_url = 'https://api.github.com/repos/LoopleDigitalAgency/auto-coduri-postale/releases/latest';

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $response = wp_remote_get($this->github_url);
        if (is_wp_error($response)) return $transient;

        $release = json_decode(wp_remote_retrieve_body($response));
        if (version_compare($transient->checked[$this->plugin_slug . '/' . $this->plugin_slug . '.php'], $release->tag_name, '<')) {
            $transient->response[$this->plugin_slug . '/' . $this->plugin_slug . '.php'] = (object) [
                'new_version' => $release->tag_name,
                'package' => $release->zipball_url,
                'slug' => $this->plugin_slug
            ];
        }

        return $transient;
    }
}

new Auto_Update_Plugin();
