<?php
/*
Plugin Name: Cart Catch for WooCommerce
Plugin URI: https://www.cartcatch.com
Description: Cart Catch helps recover lost sales by emailing customers. Requires WooCommerce.
WC requires at least: 2.6
WC tested up to: 3.5
Requires at least: 4.8
Tested up to: 5.0.0
Version: 0.0.2
Author: Rhys W
Author URI: https://www.codeworkshop.com.au
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

//cart catch requires woocommerce.
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

// if an item has been added to cart, keep track of it with a session identifier.

    add_action("plugins_loaded", "cartcatch_init", 0);
    register_activation_hook(__FILE__, 'cartcatch_create_plugin_database_table');


    global $cartcatch_db_version;
    $cartcatch_db_version = '0.26';

    if (get_option('cartcatch_db_version') !== $cartcatch_db_version) {
        cartcatch_create_plugin_database_table();
    }


    function cartcatch_create_plugin_database_table()
    {
        global $table_prefix, $wpdb, $cartcatch_db_version;

        $tblname = 'cartcatch_carts';
        $wp_track_table = $table_prefix . "$tblname";

        $sql = "CREATE TABLE `" . $wp_track_table . "` ( ";
        $sql .= "  `cart_identifier`  varchar(255)  NOT NULL,
					`state` VARCHAR(255) NOT NULL, 
					`cart_contents` text,
					`user_info` text,
					order_number varchar(20) not null default '',
					created_at datetime default null, 
					synced_at datetime default null,
	`sync_attempted_at` DATETIME  null DEFAULT NULL,
					 modified_at datetime default null, 
					finalised_at datetime default null, sync_key varchar(255) default null, email varchar(255) null,";
        $sql .= "	PRIMARY KEY (`cart_identifier`, `state`, `order_number`) ";
        $sql .= ") ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ; ";
        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

        dbDelta($sql);
        if ($wpdb->last_error) {
            echo $wpdb->last_error;
            die();
        } else {
            update_option("cartcatch_db_version", $cartcatch_db_version);
        }
    }


    function cartcatch_init()
    {
        class WC_CartCatch
        {
            private static $_instance = null;
            protected $table = "";
            protected $extendedLogging = false;

            public static $send_orders_older_than_minutes = 0;

            public static $settings = [
                'store_id' => false,
                'secret_key' => false
            ];


            public static function instance()
            {
                if (is_null(self::$_instance)) {
                    self::$_instance = new self();
                }

                return self::$_instance;
            }

            public function load_settings()
            {
                self::$settings['store_id'] = get_option('WC_settings_cartcatch_site_id');
                self::$settings['secret_key'] = get_option('WC_settings_cartcatch_secret_key');
                self::$settings['log'] = get_option('WC_settings_cartcatch_logging_enabled');
                self::$settings['live'] = get_option('WC_settings_cartcatch_livemode_enabled');
                self::$settings['endpoint'] = self::$settings['live'] === 'yes' ? 'https://app.cartcatch.com' : 'http://app.cartcatch.local';
                // error_log("live mode is " . self::$settings['live']);
            }

            public function __construct()
            {

                add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
                add_action('woocommerce_settings_tabs_settings_cartcatch', array($this, 'settings_tab'));
                add_action('woocommerce_update_options_settings_cartcatch', array($this, 'update_settings'));


                //do_action( 'woocommerce_add_to_cart', $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data );
                add_action("woocommerce_add_to_cart", array($this, 'added_to_cart'), 10, 0);
                add_action("woocommerce_checkout_update_order_review", array($this, 'adding_order_details'), 10, 0);

                add_action('wp_ajax_capture_cartcatch_email', array($this, 'capture_cartcatch_email_callback'));
                add_action('wp_ajax_nopriv_capture_cartcatch_email',
                    array($this, 'capture_cartcatch_email_callback'));

                add_action('woocommerce_thankyou', array($this, 'user_placed_order'), 10, 1);
                add_action("woocommerce_order_status_processing", array($this, 'user_placed_order_by_email'), 10, 1);
                add_action('wp_login', array($this, 'user_signed_in'));


                add_filter('query_vars', array($this, 'cart_catch_query_vars_filter'));

                add_action('pre_get_posts', array($this, 'cart_catch_url_handler'));


                add_filter('woocommerce_checkout_fields', array($this, 'prioritise_email_field'));


                global $wpdb;
                $this->table = "{$wpdb->prefix}cartcatch_carts";

                $this->load_settings();

                // $this->queue_assets();

                add_action ('wp_enqueue_scripts', array($this, 'queue_assets'));

            }

            public function user_signed_in()
            {

                $email = wp_get_current_user()->user_email;;
                $this->_set_email_local($this->_get_identifier(), $email);
            }

            public function prioritise_email_field($fields)
            {
                $fields['billing']['billing_email']['class'] = array_filter($fields['billing']['billing_email']['class'], function ($el) {
                    return $el != "form-row-last";
                });
                $fields['billing']['billing_email']['class'][] = 'form-row-wide';
                $fields['billing']['billing_email']['clear'] = true;
                $fields['billing']['billing_email']['priority'] = 1;
                $fields['billing']['billing_email']['autofocus'] = true;

                $fields['billing']['billing_first_name']['autofocus'] = false;

                $fields['billing']['billing_phone']['class'] = array_filter($fields['billing']['billing_phone']['class'],
                    function ($el) {
                        return $el != "form-row-first";
                    });
                $fields['billing']['billing_phone']['class'][] = 'form-row-wide';
                $fields['billing']['billing_phone']['clear'] = true;

                return $fields;
            }

            public function cart_catch_query_vars_filter($vars)
            {
                $vars[] = "resume_cart_with_cookie";

                return $vars;
            }

            public function add_settings_tab($settings_tabs)
            {
                $settings_tabs['settings_cartcatch'] = __('Cart Catch', 'woocommerce-settings-tab-cartcatch');

                return $settings_tabs;
            }

            public function settings_tab()
            {
                woocommerce_admin_fields(self::get_settings());
            }

            public static function update_settings()
            {
                woocommerce_update_options(self::get_settings());
            }

            public static function get_settings()
            {

                $settings = array(
                    'wc_cart_catch_section_title' => array(
                        'name' => __('Settings', 'woocommerce-settings-tab-cartcatch'),
                        'type' => 'title',
                        'desc' => '',
                        'id' => 'WC_settings_cartcatch_section_title'
                    ),
                    'wc_cart_catch_site_id' => array(
                        'name' => __('Enter your Site ID', 'woocommerce-settings-tab-cartcatch'),
                        'type' => 'text',
                        'desc' => __('This will be on your intro email.',
                            'woocommerce-settings-tab-cartcatch'),
                        'desc_tip' => true,
                        'id' => 'WC_settings_cartcatch_site_id'
                    ),
                    'wc_cart_catch_secret_key' => array(
                        'name' => __('Enter your Secret Key', 'woocommerce-settings-tab-cartcatch'),
                        'type' => 'text',
                        'css' => 'min-width:350px;',
                        'desc' => __('This will be on your intro email.',
                            'woocommerce-settings-tab-cartcatch'),
                        'desc_tip' => true,
                        'id' => 'WC_settings_cartcatch_secret_key'
                    ),
                    'wc_cart_catch_logging_enabled' => array(
                        'name' => __('Enable Logging?', 'woocommerce-settings-tab-cartcatch'),
                        'type' => 'checkbox',
                        'id' => 'WC_settings_cartcatch_logging_enabled'
                    ),
                    'wc_cart_catch_livemode_enabled' => array(
                        'name' => __('Enable Live Mode? (YES if unsure)', 'woocommerce-settings-tab-cartcatch'),
                        'type' => 'checkbox',
                        'id' => 'WC_settings_cartcatch_livemode_enabled'
                    ),
                    'wc_cart_catch_section_end' => array(
                        'type' => 'sectionend',
                        'id' => 'WC_settings_cartcatch_section_end'
                    )
                );

                return apply_filters('WC_settings_cartcatch_settings', $settings);
            }

            public static function log($message)
            {
                if (empty(self::$log)) {
                    self::$log = new WC_Logger();
                }

                if (get_option("WC_settings_cartcatch_livemode_enabled") === "yes") {
                    self::$log->add('Cartcatch', $message);
                }
                //
            }


            function cart_catch_url_handler($query)
            {
                if ($query->is_main_query()) {
                    $cookie = get_query_var('resume_cart_with_cookie');
                    if ($cookie) {

                        global $wpdb;

                        $table = $this->table;


                        $results = $wpdb->get_results($wpdb->prepare("select * from $table where cart_identifier = %s",
                            $cookie));

                        if (count($results) > 0) {


                            global $woocommerce;

                            $woocommerce->cart->empty_cart();


                            $result = $results[0];

                            $contents = json_decode($result->cart_contents);

                            foreach ($contents->items as $item) {
                                $woocommerce->cart->add_to_cart($item->product_id, $item->quantity, $item->variation_id);
                            }

                            $user = json_decode($result->user_info);


                            // add a translation entry for  this.


                            add_filter('woocommerce_checkout_get_value', function ($input, $key) use ($user) {
                                if (isset($user) && property_exists($user, $key)) {
                                    return $user->$key;
                                }
                            }, 10, 2);

                        }

                    }
                }
            }

            public function capture_cartcatch_email_callback()
            {

                $email = $_POST['email'];
                $time = $_POST['time'];
                $transient_key = "cartcatch_email_received_" . $this->_get_identifier();

                if ($last_received_time = get_transient($transient_key)) {

                    if ($time > $last_received_time) {
                        // more recent keystroke.
                        set_transient($transient_key, $time);
                        $this->_set_email_local($this->_get_identifier(), $email);
                    }
                } else {
                    set_transient($transient_key, $time);
                    $this->_set_email_local($this->_get_identifier(), $email);
                }


                echo "ok";
                die();


            }

            public function get_authorization_header()
            {

                $token_id = self::$settings['store_id'];
                $secret_key = self::$settings['secret_key'];

                return 'Basic ' . base64_encode($token_id . ':' . $secret_key);
            }


            public function user_placed_order_by_email($order_id)
            {
                $order = new WC_Order($order_id);

                if (version_compare(WC()->version, 3.0, ">=")) {
                    $email = $order->get_billing_email();

                } else {
                    $email = $order->billing_email;
                }

                $identifier = $this->_get_last_identifier_for_email($email);


                if ($identifier) {
                    $contents = $this->contentsFromOrder($order);

                    $this->_sync_local($identifier, $contents, $user_info = null, $order_id);

                    $this->_sync_remote_if_should();
                }


            }

            public function user_placed_order($order_id)
            {

                $order = new WC_Order($order_id);

                $identifier = $this->_get_identifier();

                $contents = $this->contentsFromOrder($order);
                $user_info = $this->getUserInfo();

                $this->_sync_local($identifier, $contents, $user_info, $order_id);

                $this->_sync_remote_if_should();
            }

            public function _encode_customer($postData)
            {
                parse_str($postData, $params);
                $whitelist = [
                    'billing_address_1',
                    'billing_address_2',
                    'billing_first_name',
                    'billing_last_name',
                    'billing_company',
                    'billing_email',
                    'billing_phone',
                    'billing_country',
                    'billing_company',
                    'billing_city',
                    'billing_state',
                    'billing_postcode'
                ];

                $whitelisted = [];
                foreach ($whitelist as $key) {
                    if (isset($params[$key])) {
                        $whitelisted[$key] = $params[$key];
                    }
                }

                return json_encode($whitelisted);
            }

            public function _get_last_identifier_for_email($email)
            {
                global $wpdb;

                $table = $this->table;
                $results = $wpdb->get_results($wpdb->prepare("select cart_identifier from $table where email = %s order by modified_at desc", $email));

                if (count($results) > 0) {
                    $identifier = $results[0]->cart_identifier;
                    return $identifier;
                }
                return null;
            }


            public function _get_identifier()
            {
                $session_cookie = WC()->session->get_session_cookie();

                $identifier = $session_cookie[3];

                $old_identifier = isset($_GET['resume_cart_with_cookie']) ? $_GET['resume_cart_with_cookie'] : null;
                if ($old_identifier && !get_transient("original_identifier_for" . $identifier)) {
                    // user is resuming this session.
                    set_transient("original_identifier_for" . $identifier, $old_identifier);
                }

                if ($translated_identifier = get_transient("original_identifier_for" . $identifier)) {
                    $identifier = $translated_identifier;
                }
                return $identifier;
            }

            public function _get_cart_contents()
            {
                $contents = array();
                $contents['items'] = array();
                $contents['meta'] = array();
            }

            //do_action( 'woocommerce_add_to_cart', $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data );

            public function getUserInfo()
            {
                if (isset($_POST['post_data'])) {
                    $user_info = $this->_encode_customer($_POST['post_data']);
                    return $user_info;
                }
                return null;
            }

            public function adding_order_details()
            {

                $identifier = $this->_get_identifier();

                $contents = $this->contentsFromCart(WC()->cart->cart_contents);
                $user_info = $this->getUserInfo();

                $this->_sync_local($identifier, $contents, $user_info);

                $this->_sync_remote_if_should();
            }

            public function added_to_cart()
            {

                $identifier = $this->_get_identifier();

                $contents = $this->contentsFromCart(WC()->cart->cart_contents);

                if ($identifier) {
                    $this->_sync_local($identifier, $contents, null);

                    $this->_sync_remote_if_should();
                }

            }

            public function _sync_remote_if_should()
            {
                if ($this->should_sync()) {
                    $this->sync_to_server();
                }
            }

            public function _set_email_local($identifier, $email)
            {
                global $wpdb;
                $table = $this->table;
                $date = date("Y-m-d H:i:s");
                $wpdb->update($table,
                    array(
                        'email' => $email,
                        'modified_at' => $date,
                        'synced_at' => null
                    ),
                    array('cart_identifier' => $identifier, 'state' => 'add_to_cart'),
                    array(
                        '%s',
                        '%s',
                        '%s'
                    ));

                $this->_sync_remote_if_should();
            }

            public function _sync_local($identifier, $contents, $user_info = null, $order_number = null)
            {
                global $wpdb;

                $date = date("Y-m-d H:i:s");
                $table = $this->table;

                $existing_row = $wpdb->get_row($wpdb->prepare("select * from $table where cart_identifier = %s order by order_number asc",
                    $identifier));

                $state = $order_number ? "completed" : 'add_to_cart';

                if (!$order_number) {
                    $order_number = "";
                }
                if (($existing_row && !$existing_row->order_number) || $existing_row->order_number === $order_number) {
                    $conditions = array('cart_identifier' => $identifier, 'state' => 'add_to_cart');

                    $wpdb->update($table,
                        array(
                            'state' => $state,
                            'cart_contents' => json_encode($contents),
                            'user_info' => $user_info,
                            'order_number' => $order_number,
                            'modified_at' => $date,
                            'synced_at' => null
                        ),
                        $conditions,
                        array(
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                        ));
                } else {
                    $wpdb->insert($table,
                        array(
                            'cart_identifier' => $identifier,
                            'state' => $state,
                            'cart_contents' => json_encode($contents),
                            'user_info' => $user_info,
                            'order_number' => $order_number,
                            'modified_at' => $date,
                            'email' => $contents['email'] ? $contents['email'] : null,
                            'synced_at' => null,
                            'finalised_at' => null
                        ),
                        array(
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s',
                            '%s'
                        ));
                }


            }

            public function should_sync()
            {
                global $wpdb;

                $table = $this->table;
                //$sql = "insert into {$wpdb->prefix}cartcatch_carts (cookie, cart_contents, created_at, email, synced_at) VALUES (%s,%s, %s, %s, null) ON DUPLICATE KEY UPDATE cart_contents = %s, modified_at = %s";
                $sql = "select count(*) as count from $table where synced_at = null;";

                $row = $wpdb->get_row($sql);

                if ((int)$row->count > 10) {
                    return true;
                }
                $this->extendedLogging && error_log("there's less than 10 rows.");

                $sql = "select modified_at as first_modified_not_synced from $table where synced_at is null order by modified_at asc;";

                $row = $wpdb->get_row($sql);
                $minute = 60;

                if (strtotime($row->first_modified_not_synced) < time() - (self::$send_orders_older_than_minutes * $minute)) {
                    return true;
                }

                // if ANY orders have been finalised and not synced, sync.
                $sql = "select count(*) as count from $table where synced_at = null and finalised_at is not null;";

                $row = $wpdb->get_row($sql);

                if ((int)$row->count > 0) {
                    return true;
                }

                return true;

                return false;
            }

            public function sync_to_server()
            {
                global $wpdb;
                $table = $this->table;
                $sync_key = wp_generate_password(24);
                $date = date("Y-m-d H:i:s");
                $wpdb->update("{$wpdb->prefix}cartcatch_carts",
                    ['sync_key' => $sync_key, 'sync_attempted_at' => $date], ['synced_at' => null]);

                $records = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE sync_key = %s",
                    $sync_key));

                foreach ($records as $record) {
                    // sanitize the user data -- we don't want it at cartcatch.
                    if ($record->user_info) {
                        $user_info = json_decode($record->user_info);
                        unset($user_info->billing_address_1);
                        unset($user_info->billing_address_2);
                        unset($user_info->billing_state);
                        unset($user_info->billing_postcode);
                        // unset( $user_info->billing_email ); // we need the email, derp
                        unset($user_info->billing_country);
                        unset($user_info->billing_city);
                        unset($user_info->billing_company);

                        $record->user_info = json_encode($user_info);
                    }
                }

                if (count($records) > 0) {

                    $body = compact('records');

                    $args = array(
                        'headers' => array(
                            'Authorization' => $this->get_authorization_header(),
                            'Content-Type' => 'application/json'
                        ),
                        'timeout' => 45,
                        'body' => json_encode($body)
                    );


                    $url = self::$settings['endpoint'] . "/api/store_sync?XDEBUG_SESSION_START=1";

                    $this->extendedLogging && error_log("calling" . $url);

                    $response = wp_remote_post($url, $args);
                    $this->extendedLogging && error_log("response is");
                    $this->extendedLogging && error_log(json_encode($response));


                    if ($response && !is_wp_error($response)) {
                        $response_object = json_decode($response['body']);

                        if ($response_object->result === "success") {
                            $wpdb->update($table, ['synced_at' => date("Y-m-d H:i:s")],
                                ['sync_key' => $sync_key]);
                        } else {

                            $wpdb->update($table, ['sync_key' => null],
                                ['sync_key' => $sync_key]);

                            $this->extendedLogging && error_log("Remote server said");
                            $this->extendedLogging && error_log($response_object);
                        }
                    }
                }


            }

            public function queue_assets()
            {


                wp_register_script("wc-cart-catch", plugins_url('js/wc-cart-catch.js', __FILE__),
                    array('jquery'));
                wp_enqueue_script("wc-cart-catch");

            }


            private function contentsLineItem(
                $product_id,
                $product_title,
                $quantity,
                $variation_id,
                $variation,
                $product_image,
                $product_url,
                $product_price,
                $line_total
            )
            {
                return array(
                    'product_id' => $product_id,
                    'product_title' => $product_title,
                    'quantity' => $quantity,
                    'variation_id' => $variation_id,
                    'variation' => $variation,
                    'product_image' => $product_image,
                    'product_url' => $product_url,
                    'product_price' => $product_price,
                    'line_total' => $line_total
                );
            }

            /**
             * @param WC_Order $order
             *
             * @return array
             */
            public function contentsFromOrder($order)
            {
                $contents = array();
                $items = $order->get_items();

                foreach ($items as $item) {
                    if (version_compare(WC()->version, 3.0, ">=")) {
                        $product = $item->get_product();

                    } else {
                        $product = new WC_Product($item['product_id']);

                    }


                    $image_url = $this->getImageUrl($cc);

                    $product_id = false;
                    if (version_compare(WC()->version, 3.0, ">=")) {
                        $product_id = $product->get_id();

                    } else {
                        $product_id = $product->id;
                    }

                    if (version_compare(WC()->version, 3.0, ">=")) {
                        $name = $product->get_name();
                    } else {
                        $name = $product->get_title();
                    }


                    $quantity = version_compare(WC()->version, 3.0, ">=") ? $item->get_quantity() : $item['qty'];
                    $variation_id = version_compare(WC()->version, 3.0,
                        ">=") ? $item->get_variation_id() : $item['variation_id'];
                    $price = version_compare(WC()->version, 3.0, ">=") ? $product->get_price() : $product->price;
                    $total = version_compare(WC()->version, 3.0,
                        ">=") ? $item->get_total() + $item->get_total_tax() : $item['line_total'] + $item['line_tax'];


                    $line = $this->contentsLineItem(
                        $product_id,
                        $name,
                        $quantity,
                        $variation_id,
                        null,
                        $image_url,
                        get_permalink($product_id),
                        $price,
                        $total

                    );
                    $contents['items'][] = $line;
                }

                $contents['meta']['total'] = $order->get_total();

                $contents['meta']['current_status'] = $order->get_status();
                $this->_add_generic_contents($contents);

                return $contents;
            }

            public function _add_generic_contents(&$contents)
            {

                $contents['meta']['currency'] = get_woocommerce_currency();
                $contents['meta']['currency_symbol'] = get_woocommerce_currency_symbol();

            }

            /**
             * @param $cart
             *
             * @return array;
             */
            public function contentsFromCart($cart)
            {

                $contents = array();
                foreach ($cart as $ck => $cc) {
                    $image_url = $this->getImageUrl($cc);

                    $name = false;
                    if (version_compare(WC()->version, 3.0, ">=")) {
                        $name = $cc['data']->get_name();
                    } else {
                        $name = $cc['data']->get_title();
                    }


                    $contents['items'][] = $this->contentsLineItem(
                        $cc['product_id'],
                        $name,
                        $cc['quantity'],
                        $cc['variation_id'],
                        $cc['variation'],
                        $image_url,
                        get_permalink($cc['product_id']),
                        $cc['data']->get_price(),
                        $cc['data']->get_price() * $cc['quantity']
                    );


                }
                WC()->cart->calculate_totals();
                $contents['meta']['checkout_url'] = wc_get_checkout_url();
                $contents['meta']['total'] = WC()->cart->subtotal;

                $this->_add_generic_contents($contents);

                return $contents;
            }

            /**
             * @param $lineItem
             * @return bool|false|string
             */
            public function getImageUrl($lineItem)
            {
                if (version_compare(WC()->version, 3.0, "<")) {
                    $image_ids = $lineItem['data']->get_gallery_attachment_ids();

                    if (count($image_ids) === 0) {
                        $image_ids = [$lineItem['data']->get_image_id()];
                    }
                } else {
                    $image_ids = [$lineItem['data']->get_image_id()];
                }


                $image_url = false;


                $this->extendedLogging && error_log("IMAGE_IDS");
                $this->extendedLogging && error_log(json_encode($image_ids));

                if ($image_ids && count($image_ids) > 0) {
                    $image_url = wp_get_attachment_url($image_ids[0]);
                }
                return $image_url;
            }

        }

        $cartcatch = new WC_CartCatch();
    }
}