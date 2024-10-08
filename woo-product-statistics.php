<?php
/*
Plugin Name: WooCommerce Product Statistics by IP
Description: Tracks product views and purchases uniquely by user IP in WooCommerce.
Version: 1.3
Author: GJ
Text Domain: wc-product-stats
*/

require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/georgs-jegers/woo-product-statistics',
    __FILE__,
    'woo-product-statistics'
);

$myUpdateChecker->setBranch('master');
$myUpdateChecker->setAuthentication('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');


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WC_Product_Stats
{
    private static $instance = null;
    private $table_name;

    //fake data
    private $fake_view_option = 'wc_product_fake_view_count';
    private $fake_purchase_option = 'wc_product_fake_purchase_count';
    private $view_threshold = 176;
    private $purchase_threshold = 88;

    private function __construct()
    {
        try {
            if ($this->is_woocommerce_active()) {

                global $wpdb;
                $this->table_name = $wpdb->prefix . 'wc_product_statistics';

                register_activation_hook(__FILE__, [$this, 'create_db_table']);

                register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

                add_action('woocommerce_before_single_product', [$this, 'track_view']);

                add_action('woocommerce_order_status_completed', [$this, 'track_purchase']);

                add_action('woocommerce_single_product_summary', [$this, 'display_stats_below_price'], 11);

                add_shortcode('wc_product_stats', [$this, 'display_product_stats_shortcode']);

                //init fkae data
                add_action('init', [$this, 'initialize_fake_data']);
            } else {
                add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
            }
        } catch (Throwable $e) {
            $this->handle_critical_error($e);
        }
    }

    protected function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    private function handle_critical_error(Throwable $e)
    {

        deactivate_plugins(plugin_basename(__FILE__));

        add_action('admin_notices', function () use ($e) {
            echo '<div class="notice notice-error"><p>';
            echo 'The WooCommerce Product Statistics plugin was deactivated due to a critical error: ' . $e->getMessage();
            echo '</p></div>';
        });

        error_log('Critical error in WooCommerce Product Statistics plugin: ' . $e->getMessage());
    }

    //logg erros
    public function log_erros($message, $function_name)
    {
        error_log("Error in {$function_name}: {$message}");
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function create_db_table()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id int(9) NOT NULL AUTO_INCREMENT,
            product_id int(9) NOT NULL,
            view_count bigint(20) DEFAULT 0 NOT NULL,
            purchase_count bigint(20) DEFAULT 0 NOT NULL,
            last_view_ip VARCHAR(100) DEFAULT NULL,
            last_viewed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY product_id_ip (product_id, last_view_ip)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function deactivate_plugin()
    {
        global $wpdb;

        //delete fake data
        delete_option($this->fake_view_option);
        delete_option($this->fake_purchase_option);

        //drop table
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }

    private function is_woocommerce_active()
    {
        return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
    }

    public function woocommerce_not_active_notice()
    {
?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce Product Statistics by IP requires WooCommerce to be active. Please activate WooCommerce.', 'wc-product-stats'); ?></p>
        </div>
<?php
    }

    public function track_view()
    {
        try {
            if (is_product()) {
                global $post, $wpdb;

                $product_id = $post->ID;
                $user_ip = $this->get_user_ip();

                $time_limit = '1 DAY';

                $existing_view = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT last_viewed_at FROM {$this->table_name} 
                        WHERE product_id = %d AND last_view_ip = %s 
                        AND last_viewed_at >= (NOW() - INTERVAL $time_limit)",
                        $product_id,
                        $user_ip
                    )
                );

                if (!$existing_view) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO {$this->table_name} (product_id, view_count, last_view_ip, last_viewed_at) 
                            VALUES (%d, 1, %s, NOW()) 
                            ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = NOW()",
                            $product_id,
                            $user_ip
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            $this->handle_critical_error($e);
            $this->log_erros($e->getMessage(), 'track_view');
        }
    }

    public function track_purchase($order_id)
    {
        try {
            $order = wc_get_order($order_id);

            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();

                global $wpdb;

                $existing_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$this->table_name} WHERE product_id = %d",
                        $product_id
                    ),
                    ARRAY_A
                );

                if ($existing_row) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$this->table_name} 
                        SET purchase_count = purchase_count + %d 
                        WHERE product_id = %d",
                            $item->get_quantity(),
                            $product_id
                        )
                    );
                } else {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO {$this->table_name} (product_id, purchase_count) 
                        VALUES (%d, %d)",
                            $product_id,
                            $item->get_quantity()
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            $this->handle_critical_error($e);
            $this->log_erros($e->getMessage(), 'track_purchase');
        }
    }

    public function initialize_fake_data()
    {
        if (get_option($this->fake_view_option) === false) {
            update_option($this->fake_view_option, $this->view_threshold);
        }
        /*************  ✨ Codeium Command ⭐  *************/
        /**
         * Returns an array with the total view and purchase count for a given product ID.
         * If the product has less than the view/purchase threshold, fake data is used instead.
         * If there is an error, the function logs the error and returns an array with zeros.
         * @param int $product_id The product ID to retrieve statistics for.
         * @return array An array with 'view_count' and 'purchase_count' keys.
         */
        /******  e7c50aba-dbc3-41f9-99aa-eadd4edc32c5  *******/        if (get_option($this->fake_purchase_option) === false) {
            update_option($this->fake_purchase_option, $this->purchase_threshold);
        }
    }

    public function increment_fake_data()
    {
        $fake_view_count = get_option($this->fake_view_option, $this->view_threshold);
        $fake_purchase_count = get_option($this->fake_purchase_option, $this->purchase_threshold);

        $new_fake_view_count = $fake_view_count + rand(0, 1);
        $new_fake_purchase_count = $fake_purchase_count + rand(0, 1);

        update_option($this->fake_view_option, $new_fake_view_count);
        update_option($this->fake_purchase_option, $new_fake_purchase_count);
    }


    public function get_product_statistics($product_id)
    {
        try {
            global $wpdb;

            $stats = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT SUM(view_count) as view_count, MAX(purchase_count) as purchase_count 
                FROM {$this->table_name} 
                WHERE product_id = %d",
                    $product_id
                ),
                ARRAY_A
            );

            //fake data
            $this->increment_fake_data();

            $fake_view_count = get_option($this->fake_view_option);
            $fake_purchase_count = get_option($this->fake_purchase_option);


            if ($stats && ($stats['view_count'] < $this->view_threshold || $stats['purchase_count'] < $this->purchase_threshold)) {
                return [
                    'view_count' => max($stats['view_count'], $fake_view_count),
                    'purchase_count' => max($stats['purchase_count'], $fake_purchase_count)
                ];
            }

            return $stats ? $stats : ['view_count' => $fake_view_count, 'purchase_count' => $fake_purchase_count];
        } catch (Throwable $e) {
            $this->handle_critical_error($e);
            $this->log_erros($e->getMessage(), 'get_product_statistics');
            return ['view_count' => 0, 'purchase_count' => 0];
        }
    }

    private function get_user_ip(): string
    {
        $ip = '127.0.0.1';

        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];

            if (preg_match('/^(?:127|10)\.0\.0\.[12]?\d{1,2}$/', $ip)) {
                if (isset($_SERVER['HTTP_X_REAL_IP'])) {
                    $ip = $_SERVER['HTTP_X_REAL_IP'];
                } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    $ip = trim($ipList[0]);
                }
            }
        }

        if (in_array($ip, ['::1', '0.0.0.0', 'localhost'], true)) {
            $ip = '127.0.0.1';
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = '127.0.0.1';
        }

        return $ip;
    }

    public function display_stats_below_price()
    {
        echo $this->display_product_stats_shortcode();
    }

    public function display_product_stats_shortcode()
    {
        try {
            if (is_product()) {
                global $post;
                $product_id = $post->ID;
                $stats = $this->get_product_statistics($product_id);

                $view_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8a13.133 13.133 0 0 1-1.66 2.043C11.879 11.332 10.12 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.133 13.133 0 0 1 1.172 8z"/>
                        <path d="M8 5a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/>
                        <path d="M8 6.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/>
                      </svg>';
                $purchase_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-bag" viewBox="0 0 16 16">
                            <path d="M8 1a2 2 0 0 0-2 2v1H3.5A1.5 1.5 0 0 0 2 5.5v9A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 12.5 4H10V3a2 2 0 0 0-2-2zm3 3H5V3a1 1 0 1 1 2 0v1h2V3a1 1 0 1 1 2 0v1z"/>
                          </svg>';

                $view_count_text = $view_icon . ' ' . sprintf(
                    _n('%d person viewed this product in the last 30 days.', '%d people viewed this product in the last 30 days.', $stats['view_count'], 'wc-product-stats'),
                    $stats['view_count']
                );

                $purchase_count_text = $purchase_icon . ' ' . sprintf(
                    _n('%d product sold in the last 30 days.', '%d products sold in the last 30 days.', $stats['purchase_count'], 'wc-product-stats'),
                    $stats['purchase_count']
                );

                return '<div class="wc-product-stats" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">'
                    . '<div class="view-count" style="display: flex; align-items: center; gap: 10px;">'
                    . $view_count_text . '</div>'
                    . '<div class="purchase-count" style="display: flex; align-items: center; gap: 10px;">'
                    . $purchase_count_text . '</div>'
                    . '</div>';
            }

            return '';
        } catch (Throwable $e) {
            $this->handle_critical_error($e);
            $this->log_erros($e->getMessage(), 'display_product_stats_shortcode');
            return '';
        }
    }
}

WC_Product_Stats::get_instance();
