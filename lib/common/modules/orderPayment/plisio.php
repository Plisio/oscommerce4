<?php
/**
 * This file is part of osCommerce ecommerce platform.
 * osCommerce the ecommerce
 *
 * @link https://www.oscommerce.com
 * @copyright Copyright (c) 2000-2022 osCommerce LTD
 *
 * Released under the GNU General Public License
 * For the full copyright and license information, please view the LICENSE.TXT file that was distributed with this source code.
 */

namespace common\modules\orderPayment;

use common\classes\order_total;
use common\classes\modules\ModuleStatus;
use common\classes\modules\ModulePayment;
use common\classes\extended\OrderAbstract;
use common\classes\modules\ModuleSortOrder;

define('PLISIO_OSCOMMERCE_EXTENSION_VERSION', '2.0.0');
if (!class_exists('plisio')) {
    class plisio extends ModulePayment {
        var $code;
        var $title;
        var $description;
        var $enabled;
        var $sort_order;
        var $plugin_name;
        var $icon = "plisio.png";
        var $order_id;
        var $public_title;
        var $status;

        protected $defaultTranslationArray = [
            'MODULE_PAYMENT_PLISIO_TEXT_TITLE' => 'Plisio Payment Gateway',
            'MODULE_PAYMENT_PLISIO_TEXT_DESCRIPTION' => 'Plisio is a payment gateway for Bitcoin, Litecoin, Ethereum and 30 other cryptocurrencies. With our API, any website can accept crypto payments.',
            'MODULE_PAYMENT_PLISIO_TEXT_EMAIL_FOOTER' => 'You have attempted to make an order using Plisio!',
            'MODULE_PAYMENT_PLISIO_CREATE_INVOICE_FAILED' => 'Unable to process payment using Plisio.',
            'MODULE_PAYMENT_PLISIO_ORDER_ERROR_TITLE' => 'There has been an error processing your order',
        ];

        /*
         * Constructor
         */
        function __construct($order_id = -1) {
            parent::__construct();

            $this->code             = 'plisio';
            $this->title            = MODULE_PAYMENT_PLISIO_TEXT_TITLE;
            $this->description      = MODULE_PAYMENT_PLISIO_TEXT_DESCRIPTION;
            if (!defined('MODULE_PAYMENT_PLISIO_STATUS')) {
                $this->enabled = false;
                return false;
            }
            $this->api_key          = MODULE_PAYMENT_PLISIO_API_KEY;
            $this->sort_order       = MODULE_PAYMENT_PLISIO_SORT_ORDER;
            $this->enabled          = MODULE_PAYMENT_PLISIO_STATUS == 'True';
            $this->plugin_name = 'Plugin 2.0.0 (' . PROJECT_VERSION . ')';

            $this->update_status();

            $this->order_id = $order_id;
            $this->public_title = MODULE_PAYMENT_PLISIO_TEXT_TITLE;
            $this->status = null;
        }

        /*
         * Check whether this payment module is available
         */
        function update_status() {

            if (($this->enabled == true)) {
                $check_flag = false;
                $check_query = tep_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where zone_country_id = '" . $this->billing['country']['id'] . "' order by zone_id");
                while ($check = tep_db_fetch_array($check_query)) {
                    if ($check['zone_id'] < 1) {
                        $check_flag = true;
                        break;
                    } elseif ($check['zone_id'] == $this->delivery['zone_id']) {
                        $check_flag = true;
                        break;
                    }
                }

                if ($check_flag == false) {
                    $this->enabled = false;
                }
            }
        }

        // ---- select payment module ----
        /*
         * Client side javascript that will verify any input fields you use in the
         * payment method selection page
         */
        function javascript_validation() {
            return false;
        }

        /*
         * Outputs the payment method title/text and if required, the input fields
         */
        function selection() {
            $selection = array('id' => $this->code,
                'module' => $this->public_title,
                'fields' => array());
            return $selection;
        }

        /*
         * Any checks of any conditions after payment method has been selected
         */
        function pre_confirmation_check() {
            if (defined('MODULE_PAYMENT_PLISIO_GATEWAY_SELECTION') && MODULE_PAYMENT_PLISIO_GATEWAY_SELECTION == 'True') {
                $gatewaytest = $_POST['plisio_gateway_selection'];
                if (!$gatewaytest) {
                }
                $this->gateway_selection = $_POST['plisio_gateway_selection'];
            } else {
                return false;
            }
        }

        // ---- confirm order ----
        /*
         * Any checks or processing on the order information before proceeding to
         * payment confirmation
         */
        function confirmation() {
            return false;
        }

        /*
         * Outputs the html form hidden elements sent as POST data to the payment
         * gateway
         */
        function process_button() {
            if (defined('MODULE_PAYMENT_PLISIO_GATEWAY_SELECTION') && MODULE_PAYMENT_PLISIO_GATEWAY_SELECTION == 'True') {
                $fields = tep_draw_hidden_field('plisio_gateway_selection', $_POST['plisio_gateway_selection']);
                return $fields;
            } else {
                return false;
            }
        }

        // ---- process payment ----
        /*
         * Payment verification
         */
        function before_process() {
            return false;
        }

        /*
         * Post-processing of the payment/order after the order has been finalised
         */
        function after_process() {
            require_once(dirname(__FILE__) . "/lib/PlisioClient.php");
            global $insert_id;

            $currencies = \Yii::$container->get('currencies');
            $order = $this->manager->getOrderInstance();
            $amount = round($currencies->format_clear($order->info['total_inc_tax'], true, $order->info['currency']), 2);

            $callback = $this->getWebHookUrl();

            $client = new \PlisioClient(MODULE_PAYMENT_PLISIO_API_KEY);
            $params = array(
                'order_name' => 'Order #' . $order->order_id,
                'order_number' => $order->order_id,
                'source_amount' => number_format($amount, 2, '.', ''),
                'source_currency' => $order->info['currency'],
                'callback_url' => $callback,
                'cancel_url' => tep_href_link(FILENAME_CHECKOUT_PAYMENT),
                'success_url' => tep_href_link(FILENAME_CHECKOUT_SUCCESS),
                'email' => $order->customer['email_address'],
                'plugin' => 'OSCommerce',
                'version' => PLISIO_OSCOMMERCE_EXTENSION_VERSION
            );

            $response = $client->createTransaction($params);
            if ($response && $response['status'] !== 'error' && !empty($response['data'])) {
                $_SESSION['cart']->reset(true);
                tep_redirect($response['data']['invoice_url']);
            } else {
                tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code . '&error=' . implode(',', json_decode($response['data']['message'], true)), 'SSL'));
            }
        }

        // ---- error handling ----
        /*
         * Advanced error handling
         */
        function output_error() {
            return false;
        }

        function get_error() {
            $error = array(
                'title' => MODULE_PAYMENT_PLISIO_ORDER_ERROR_TITLE,
                'error' => $_GET['error']
            );

            return $error;
        }

        /*
         * Checks current order status and updates the database
         */
        private function updateQty(){
            //rewrite to new warehouse way
            $order_query = tep_db_query("select products_id, products_quantity from " . TABLE_ORDERS_PRODUCTS . " where orders_id = '" . $this->order_id . "'");

            while ($order = tep_db_fetch_array($order_query)) {
                tep_db_query("update " . TABLE_PRODUCTS . " set products_quantity = products_quantity + " . $order['products_quantity'] . ", products_ordered = products_ordered - " . $order['products_quantity'] . " where products_id = '" . (int) $order['products_id'] . "'");
            }
        }

        // ---- Ripped from checkout_process.php ----
        /*
         * Store the order in the database, and set $this->order_id
         */
        function _save_order() {
            global $languages_id;

            if (!empty($this->order_id) && $this->order_id > 0) {
                return;
            }

            $order = $this->manager->getOrderInstance();

            $order->save_order();

            $order->save_details();

            $order->save_products(false);

            $stock_updated = false;

            $this->order_id = $order->order_id;
        }

        function _output_string($string, $translate = false, $protected = false) {
            if ($protected == true) {
                return htmlspecialchars($string);
            } else {
                if ($translate == false) {
                    return $this->_parse_input_field_data($string, array('"' => '&quot;'));
                } else {
                    return $this->_parse_input_field_data($string, $translate);
                }
            }
        }

        function _output_string_protected($string) {
            return $this->_output_string($string, false, true);
        }

        function _parse_input_field_data($data, $parse) {
            return strtr(trim($data), $parse);
        }

        // ---- installation & configuration ----
        public function configure_keys()
        {
            $status_id_pending = defined('MODULE_PAYMENT_PLISIO_PENDING_STATUS_ID') ? MODULE_PAYMENT_PLISIO_PENDING_STATUS_ID : $this->getDefaultOrderStatusId();
            $status_id_paid = defined('MODULE_PAYMENT_PLISIO_PAID_STATUS_ID') ? MODULE_PAYMENT_PLISIO_PAID_STATUS_ID : $this->paidOrderStatus();
            $status_id_cancelled = defined('MODULE_PAYMENT_PLISIO_CANCELLED_STATUS_ID') ? MODULE_PAYMENT_PLISIO_CANCELLED_STATUS_ID : $this->refundOrderStatus();
            $status_id_expired = defined('MODULE_PAYMENT_PLISIO_EXPIRED_STATUS_ID') ? MODULE_PAYMENT_PLISIO_EXPIRED_STATUS_ID : $this->refundOrderStatus();

            return array(
                'MODULE_PAYMENT_PLISIO_STATUS' => array(
                    'title' => 'Plisio payment method enabled',
                    'value' => 'True',
                    'description' => 'Enable Plisio payments for this website',
                    'sort_order' => '20',
                    'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
                ),
                'MODULE_PAYMENT_PLISIO_API_KEY' => array(
                    'title' => 'Plisio API KEY',
                    'value' => '',
                    'description' => '<a href="https://plisio.net" target="_blank" style="text-decoration:underline;font-weight:bold;color:#696916;">Need to register account in plisio.net</a>',
                    'sort_order' => '21',
                ),
                'MODULE_PAYMENT_PLISIO_SORT_ORDER' => array(
                    'title' => 'Sort order of display.',
                    'value' => '0',
                    'description' => 'Sort order of display. Lowest is displayed first.',
                    'sort_order' => '0',
                ),
                'MODULE_PAYMENT_PLISIO_PENDING_STATUS_ID' => array(
                    'title' => 'Set Pending Order Status',
                    'value' => $status_id_pending,
                    'description' => 'Pending Status',
                    'sort_order' => '0',
                    'set_function' => 'tep_cfg_pull_down_order_statuses(',
                    'use_function' => '\\common\\helpers\\Order::get_order_status_name',
                ),
                'MODULE_PAYMENT_PLISIO_PAID_STATUS_ID' => array(
                    'title' => 'Set Paid Order Status',
                    'value' => $status_id_paid,
                    'description' => 'Paid Status',
                    'sort_order' => '0',
                    'set_function' => 'tep_cfg_pull_down_order_statuses(',
                    'use_function' => '\\common\\helpers\\Order::get_order_status_name',
                ),
                'MODULE_PAYMENT_PLISIO_CANCELLED_STATUS_ID' => array(
                    'title' => 'Set Cancelled Order Status',
                    'value' => $status_id_cancelled,
                    'description' => 'Cancelled Status',
                    'sort_order' => '0',
                    'set_function' => 'tep_cfg_pull_down_order_statuses(',
                    'use_function' => '\\common\\helpers\\Order::get_order_status_name',
                ),
                'MODULE_PAYMENT_PLISIO_EXPIRED_STATUS_ID' => array(
                    'title' => 'Set Expired Order Status',
                    'value' => $status_id_expired,
                    'description' => 'Expired Status',
                    'sort_order' => '0',
                    'set_function' => 'tep_cfg_pull_down_order_statuses(',
                    'use_function' => '\\common\\helpers\\Order::get_order_status_name',
                ),
            );
        }

        public function describe_status_key()
        {
            return new ModuleStatus('MODULE_PAYMENT_PLISIO_STATUS', 'True', 'False');
        }

        public function describe_sort_key()
        {
            return new ModuleSortOrder('MODULE_PAYMENT_PLISIO_SORT_ORDER');
        }

        function getScriptName() {

            global $PHP_SELF;

            if (class_exists('\Yii') && is_object(\Yii::$app)) {
                return \Yii::$app->controller->id;
            } else {
                return basename($PHP_SELF);
            }
        }

        function getLangStr($str) {
            switch ($str) {
                case "Plisio":
                    return MODULE_PAYMENT_PLISIO_TEXT_TITLE;
                default:
                    return MODULE_PAYMENT_PLISIO_TEXT_TITLE;
                    break;
            }
        }

        function checkView() {
            $view = "admin";

            if (tep_session_name() != 'tlAdminID') {
                if ($this->getScriptName() == 'checkout' /* FILENAME_CHECKOUT_PAYMENT */) {
                    $view = "checkout";
                } else {
                    $view = "frontend";
                }
            }
            return $view;
        }

        function generateIcon($icon) {
            return tep_image($icon);
        }

        function getIcon() {
            $icon = DIR_WS_IMAGES . "plisio/en/" . $this->icon;

            if (file_exists(DIR_WS_IMAGES . "plisio/" . strtolower($this->getUserLanguage("DETECT")) . "/" . $this->icon)) {
                $icon = DIR_WS_IMAGES . "plisio/" . strtolower($this->getUserLanguage("DETECT")) . "/" . $this->icon;
            }
            return $icon;
        }

        function getUserLanguage($savedSetting) {
            if ($savedSetting != "DETECT") {
                return $savedSetting;
            }

            global $languages_id;

            $query = tep_db_query("select languages_id, name, code, image from " . TABLE_LANGUAGES . " where languages_id = " . (int) $languages_id . " limit 1");
            if ($languages = tep_db_fetch_array($query)) {
                return strtoupper($languages['code']);
            }

            return "EN";
        }

        function getlocale($lang) {
            switch ($lang) {
                case "dutch":
                    $lang = 'nl_NL';
                    break;
                case "spanish":
                    $lang = 'es_ES';
                    break;
                case "french":
                    $lang = 'fr_FR';
                    break;
                case "german":
                    $lang = 'de_DE';
                    break;
                case "english":
                    $lang = 'en_EN';
                    break;
                default:
                    $lang = 'en_EN';
                    break;
            }
            return $lang;
        }

        function getcountry($country) {
            if (empty($country)) {
                $langcode = explode(";", $_SERVER['HTTP_ACCEPT_LANGUAGE']);
                $langcode = explode(",", $langcode['0']);
                return strtoupper($langcode['1']);
            } else {
                return strtoupper($country);
            }
        }

        public function isOnline() {
            return true;
        }

        function tep_get_languages() {
            $languages_query = tep_db_query("select languages_id, name, code, image, directory from " . TABLE_LANGUAGES . " order by sort_order");
            while ($languages = tep_db_fetch_array($languages_query)) {
                $languages_array[] = array('id' => $languages['languages_id'],
                    'name' => $languages['name'],
                    'code' => $languages['code'],
                    'image' => $languages['image'],
                    'directory' => $languages['directory']);
            }

            return $languages_array;
        }

        function install( $platform_id ) {
            $config = \common\models\Configuration::find()->where(['configuration_key' => 'MODULE_PAYMENT_PLISIO_STATUS'])
                ->select('configuration_id')
                ->scalar();

            if (!$config) {
                $status_query = tep_db_query("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
                $status = tep_db_fetch_array($status_query);

                $status_id = $status['status_id'] + 1;
                $status_id_paid = $status_id;
                $status_id_pending = $status_id + 1;
                $status_id_expired = $status_id + 2;
                $status_id_cancelled = $status_id + 3;

                $languages = $this->tep_get_languages();

                foreach ($languages as $lang) {
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_groups_id, orders_status_id, language_id, orders_status_name) values (4, '" . $status_id_paid . "', '" . $lang['id'] . "', 'Plisio [Paid]')");
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_groups_id, orders_status_id, language_id, orders_status_name) values (1, '" . $status_id_pending . "', '" . $lang['id'] . "', 'Plisio [Pending]')");
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_groups_id, orders_status_id, language_id, orders_status_name) values (7, '" . $status_id_expired . "', '" . $lang['id'] . "', 'Plisio [Expired]')");
                    tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_groups_id, orders_status_id, language_id, orders_status_name) values (7, '" . $status_id_cancelled . "', '" . $lang['id'] . "', 'Plisio [Cancelled]')");
                }

                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Plisio Module', 'MODULE_PAYMENT_PLISIO_STATUS', 'False', 'Enable Plisio Payment Gateway plugin?', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Plisio API Key', 'MODULE_PAYMENT_PLISIO_API_KEY', '0', 'Your Plisio API Key', '6', '0', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PLISIO_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '8', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Plisio Order Status when order is pending', 'MODULE_PAYMENT_PLISIO_PENDING_STATUS_ID', '" . $status_id_pending . "', 'Status in your store when order is pending.<br />(\'Plisio [Pending]\' recommended)', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Plisio Order Status when order is cancelled', 'MODULE_PAYMENT_PLISIO_CANCELLED_STATUS_ID', '" . $status_id_cancelled . "', 'Status in your store when order is cancelled.<br />(\'Plisio [Cancelled]\' recommended)', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Plisio Order Status when order is expired', 'MODULE_PAYMENT_PLISIO_EXPIRED_STATUS_ID', '" . $status_id_expired . "', 'Status in your store when order is expired.<br />(\'Plisio [Expired]\' recommended)', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
                tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Plisio Order Status when order is paid', 'MODULE_PAYMENT_PLISIO_PAID_STATUS_ID', '" . $status_id_paid . "', 'Status in your store when order is paid.<br />(\'Plisio [Paid]\' recommended)', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
            }

            parent::install($platform_id);
        }

        function remove ( $platform_id ) {
            $platformsConfig = \common\models\PlatformsConfiguration::find()->where(['configuration_key' => 'MODULE_PAYMENT_PLISIO_STATUS'])
                ->select('configuration_id')
                ->scalar();

            if (!$platformsConfig) {
                tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key LIKE 'MODULE\_PAYMENT\_PLISIO\_%'");
                tep_db_query("delete from " . TABLE_ORDERS_STATUS . " where LOWER(orders_status_name) LIKE '%plisio%'");
            }

            parent::remove($platform_id);
        }

        private function verifyCallbackData($post, $apiKey) {
            if (!isset($post['verify_hash'])) {
                return false;
            }

            $verifyHash = $post['verify_hash'];
            unset($post['verify_hash']);
            ksort($post);
            if (isset($post['expire_utc'])){
                $post['expire_utc'] = (string)$post['expire_utc'];
            }
            if (isset($post['tx_urls'])){
                $post['tx_urls'] = html_entity_decode($post['tx_urls']);
            }
            $postString = serialize($post);
            $checkKey = hash_hmac('sha1', $postString, $apiKey);
            if ($checkKey != $verifyHash) {
                return false;
            }

            return true;
        }

        function call_webhooks() {
            $get = \Yii::$app->request->get();
            switch ($get['action']) {
                case 'processWebhook':
                    if ($this->verifyCallbackData($_POST, MODULE_PAYMENT_PLISIO_API_KEY)) {
                        $order_id = intval($_REQUEST['order_number']);
                        $order = $this->manager->getOrderInstanceWithId('\common\classes\Order', $order_id);

                        switch ($_REQUEST['status']) {
                            case 'completed':
                            case 'mismatch':
                                $order_status = MODULE_PAYMENT_PLISIO_PAID_STATUS_ID;
                                $status_comment = 'Payment complete';
                                break;
                            case 'cancelled':
                                $order_status = MODULE_PAYMENT_PLISIO_CANCELLED_STATUS_ID;
                                $status_comment = 'Payment cancelled';
                                break;
                            case 'expired':
                                $order_status = MODULE_PAYMENT_PLISIO_EXPIRED_STATUS_ID;
                                $status_comment = 'Payment expired';
                                break;
                            case 'new':
                            case 'pending':
                                $order_status = MODULE_PAYMENT_PLISIO_PENDING_STATUS_ID;
                                $status_comment = 'Payment pending';
                                break;
                            default:
                                $order_status = NULL;
                        }

                        $order->info['order_status'] = $order_status;
                        \common\helpers\Order::setStatus($order_id, (int)$order_status, [
                            'comments' => $status_comment,
                            'customer_notified' => 0,
                        ]);
                        $order->update_piad_information(true);
                        $order->save_details();
                        $order->notify_customer($order->getProductsHtmlForEmail(), []);

                        $tManager = $this->manager->getTransactionManager($this);
                        $ret = $tManager->updatePaymentTransaction($_REQUEST['order_number'], [
                            'fulljson' => json_encode($_REQUEST),
                            'status_code' => $order_status,
                            'status' => $_REQUEST['status'],
                            'amount' => (float) $_REQUEST['amount'],
                            'comments' => $_REQUEST['comment'],
                            'date' => date('Y-m-d H:i:s'),
                            'orders_id' => $order_id
                        ]);

//                        if ($pl_order_status) {
//                            tep_db_query("update " . TABLE_ORDERS . " set orders_status = " . $pl_order_status . " where orders_id = " . intval($order_id));
//                        }
                        echo 'OK';
                    } else {
                        echo 'Verify callback data failed';
                    }
                    break;
            }
            http_response_code(200);
        }

        public function getWebHookUrl() {
            if (function_exists('tep_catalog_href_link')) {
                $url = tep_catalog_href_link('callback/webhooks.payment.' . $this->code, http_build_query(['action' => 'processWebhook']));
            } else {
                $url = \Yii::$app->urlManager->createAbsoluteUrl(['callback/webhooks.payment.' . $this->code, 'action' => 'processWebhook']);
            }
            //$url = 'https://domain/callback/webhooks.payment.plisio?action=processWebhook&v=1';
            return $url;
        }
    }
}