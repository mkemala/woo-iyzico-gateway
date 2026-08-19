<?php
/**
 * Plugin Name: WooCommerce iyzico Ödeme Ağ Geçidi (Custom)
 * Description: iyzico Checkout Form (hosted, 3D Secure) ile WooCommerce entegrasyonu. Kart verisi sitede tutulmaz.
 * Version: 1.3.2
 * Author: Gazi / Pibakom Group
 * Text Domain: woo-iyzico-custom
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.0
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WIC_PLUGIN_FILE', __FILE__);
define('WIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WIC_VERSION', '1.3.2');

/**
 * Çeviri dosyalarını yükler (varsa). Kaynak dil Türkçe olduğu için
 * çoğu kurulumda hiçbir .mo dosyasına ihtiyaç yok — ama biri gerçekten
 * başka bir dile çevirmek isterse (ör. languages/woo-iyzico-custom-en_US.mo)
 * standart WordPress i18n akışı burada devreye girer.
 */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('woo-iyzico-custom', false, dirname(plugin_basename(WIC_PLUGIN_FILE)) . '/languages');
});

/**
 * Bail early with an admin notice if WooCommerce isn't active,
 * instead of fataling the whole site.
 */
function wic_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('WooCommerce iyzico Ödeme Ağ Geçidi çalışmak için WooCommerce eklentisinin aktif olmasını gerektiriyor.', 'woo-iyzico-custom'); ?></p>
    </div>
    <?php
}

/**
 * Callback isteğini WordPress'in rewrite_rule + query_vars mekanizması
 * ÜZERİNDEN DEĞİL, doğrudan REQUEST_URI kontrolüyle 'init' seviyesinde
 * yakalıyoruz. NEDEN: rewrite_rule + query_vars yaklaşımı denendi ve
 * production'da başka bir plugin'in 'query_vars' filtresini ezmesi
 * yüzünden güvenilmez çıktı (?iyzico_callback=1 çıplak query string
 * ile bile test edildi, aynı sonuç — yani sorun rewrite katmanında
 * değil, query_vars filtresinde). Doğrudan REQUEST_URI kontrolü hem bu
 * çakışmadan bağımsız hem de kalıcı bağlantı flush'ına ihtiyaç duymuyor
 * (Plain permalink modunda bile çalışır).
 */
add_action('init', 'wic_maybe_handle_callback');

function wic_maybe_handle_callback() {
    $path = isset($_SERVER['REQUEST_URI']) ? trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') : '';

    if ('api/payment/callback' !== $path) {
        return;
    }

    require_once WIC_PLUGIN_DIR . 'includes/callback-handler.php';
    wic_process_iyzico_callback();
    exit;
}

/**
 * Ayarlar sayfasındaki "Tespit Et" butonunun AJAX handler'ı.
 * BİLİNÇLİ OLARAK burada, plugins_loaded seviyesinde koşulsuz kayıt
 * ediliyor — gateway class'ının constructor'ına KOYMUYORUZ, çünkü
 * admin-ajax.php istekleri WC_Payment_Gateways'in gateway'leri
 * instantiate etmesini garanti etmiyor (sadece checkout/ayarlar
 * render'ında oluşuyor). Bu yüzden hook orada hiç kayıt olmuyordu ve
 * "Tespit edilemedi" hatası buradan geliyordu.
 */
add_action('wp_ajax_wic_detect_ip', 'wic_ajax_detect_ip');

function wic_ajax_detect_ip() {
    check_ajax_referer('wic_detect_ip', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Yetkisiz.', 'woo-iyzico-custom')), 403);
    }

    $response = wp_remote_get('https://api.ipify.org?format=json', array('timeout' => 8));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['ip'])) {
        wp_send_json_error(array('message' => __('IP bulunamadı.', 'woo-iyzico-custom')));
    }

    wp_send_json_success(array('ip' => sanitize_text_field($body['ip'])));
}

/**
 * Health check: AJAX handler + admin notice + günlük cron hook'u.
 * Aynı sebeple (gateway class instantiation'a bağımlı olmamak için)
 * hepsi burada, koşulsuz kayıt ediliyor. Asıl mantık includes/health-check.php'de.
 */
require_once WIC_PLUGIN_DIR . 'includes/health-check.php';

add_action('wp_ajax_wic_run_health_check', 'wic_ajax_run_health_check');
add_action('admin_notices', 'wic_health_admin_notice');
add_action('wic_daily_health_check', 'wic_run_scheduled_health_check');

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('wic_daily_health_check')) {
        // İlk kontrolü hemen değil, 5 dakika sonra çalıştır — böylece
        // henüz API key girilmemişken "hata var" e-postası atılmaz.
        wp_schedule_event(time() + 300, 'daily', 'wic_daily_health_check');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wic_daily_health_check');
});

add_action('plugins_loaded', 'wic_init_plugin', 11);

function wic_init_plugin() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wic_woocommerce_missing_notice');
        return;
    }

    wic_maybe_upgrade();

    // Bundled iyzico SDK — no Composer needed on the server.
    require_once WIC_PLUGIN_DIR . 'vendor/iyzico/iyzipay-php/autoload.php';

    require_once WIC_PLUGIN_DIR . 'includes/class-wc-gateway-iyzico-custom.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Iyzico_Custom';
        return $gateways;
    });
}

/**
 * Basit versiyon bazlı upgrade rutini. Şu an tek işi: eski varsayılan
 * başlıkta ("Kredi/Banka Kartı (iyzico)") kalan kurulumları, artık
 * checkout'ta resmi logo band görüneceği için tekrarı önlemek adına
 * yeni varsayılana ("Kredi/Banka Kartı") geçiriyor — kullanıcının elle
 * ayarlara girip düzeltmesine gerek kalmasın diye. Sadece kullanıcının
 * hiç dokunmadığı, tam olarak eski varsayılana eşit değerleri değiştirir;
 * özel bir başlık yazmışsa dokunmaz.
 */
function wic_maybe_upgrade() {
    $installed_version = get_option('wic_version');

    if ($installed_version === WIC_VERSION) {
        return;
    }

    $settings = get_option('woocommerce_iyzico_custom_settings');

    if (is_array($settings) && isset($settings['title']) && 'Kredi/Banka Kartı (iyzico)' === $settings['title']) {
        $settings['title'] = 'Kredi/Banka Kartı';
        update_option('woocommerce_iyzico_custom_settings', $settings);
    }

    update_option('wic_version', WIC_VERSION);
}

/**
 * HPOS (High-Performance Order Storage) compatibility declaration.
 * Site may or may not have HPOS enabled yet, but declaring
 * compatibility avoids the WooCommerce admin warning either way.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WIC_PLUGIN_FILE,
            true
        );
    }
});
