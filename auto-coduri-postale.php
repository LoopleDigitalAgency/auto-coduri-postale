<?php
/*
Plugin Name: Auto Coduri Postale
Plugin URI: https://github.com/LoopleDigitalAgency/auto-coduri-postale
Description: Completează automat codul poștal pe pagina de checkout WooCommerce folosind API-ul Nominatim.
Version: 1.1
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
        $tabs['auto_postal_code'] = __('Woo Coduri Poștale', 'auto-coduri-postale');
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
                'name' => __('Setări Woo Coduri Poștale', 'auto-coduri-postale'),
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
		
    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
    $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';

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
        if ($('#billing_postcode').length) {
            $('#billing_postcode').after('<p id="postal_code_error" style="color:red;display:none;"></p>');
        }
        
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
                        $('#postal_code_error').hide();
                    } else {
                        $('#postal_code_error').text(response.data.message).show();
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
class Auto_Update_Plugin {
    private $plugin_slug = 'auto-coduri-postale/auto-coduri-postale.php';
    private $update_url = 'https://loople.ro/module/auto-coduri-postale/update.json';

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $response = wp_remote_get($this->update_url);
        if (is_wp_error($response)) {
            return $transient;
        }

        $update_info = json_decode(wp_remote_retrieve_body($response));
        $plugin_data = get_plugin_data(__FILE__);
        $current_version = $plugin_data['Version'];

        if (version_compare($current_version, $update_info->version, '<')) {
            $transient->response[$this->plugin_slug] = (object) [
                'slug'        => $this->plugin_slug,
                'new_version' => $update_info->version,
                'package'     => $update_info->download_url,
                'url'         => 'https://loople.ro'
            ];
        }

        return $transient;
    }

    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return $res;
        }

        $response = wp_remote_get($this->update_url);
        if (is_wp_error($response)) {
            return $res;
        }

        $update_info = json_decode(wp_remote_retrieve_body($response));

        $res = (object) [
            'name'          => 'Auto Coduri Postale',
            'slug'          => $this->plugin_slug,
            'version'       => $update_info->version,
            'author'        => 'Loople Romania',
            'download_link' => $update_info->download_url,
            'sections'      => [
                'description' => 'Completează automat codul poștal pe pagina de checkout WooCommerce folosind API-ul Nominatim.',
            ]
        ];

        return $res;
    }

    public function clear_update_cache($upgrader_object, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_site_transient('update_plugins');
        }
    }
}

new Auto_Update_Plugin();
