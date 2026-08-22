<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Health check sistemi. Bu konuşmada elle yaptığımız teşhisleri
 * (callback endpoint gerçekten yanıt veriyor mu, iyzico'ya ağ
 * bağlantısı var mı, API key/secret dolu mu) otomatikleştiriyor.
 *
 * İki tetikleyici:
 * 1. Ayarlar sayfasındaki "Şimdi Kontrol Et" butonu (anlık, AJAX).
 * 2. Günlük WP-Cron (wic_daily_health_check) — arka planda sessizce
 *    çalışır, sonucu kaydeder, durum ok<->error arasında DEĞİŞTİĞİNDE
 *    (her gün tekrar değil) admin e-postasına uyarı gönderir.
 */

function wic_run_health_checks() {
    $checks   = array();
    $settings = get_option('woocommerce_iyzico_custom_settings', array());
    $sandbox  = isset($settings['sandbox']) && 'yes' === $settings['sandbox'];

    // 1. Ödeme yöntemi etkin mi?
    $enabled = isset($settings['enabled']) && 'yes' === $settings['enabled'];
    $checks['enabled'] = array(
        'label'   => __('Ödeme yöntemi etkin', 'iyzico-payment-gateway'),
        'status'  => $enabled ? 'ok' : 'warning',
        'message' => $enabled
            ? __('Etkin.', 'iyzico-payment-gateway')
            : __('Etkin değil — müşteriler checkout\'ta bu yöntemi göremez.', 'iyzico-payment-gateway'),
    );

    // 2. API key/secret dolu mu? (aktif mod hangisiyse onu kontrol et)
    $api_key    = $sandbox ? ($settings['test_api_key'] ?? '') : ($settings['live_api_key'] ?? '');
    $secret_key = $sandbox ? ($settings['test_secret_key'] ?? '') : ($settings['live_secret_key'] ?? '');
    $keys_ok    = !empty($api_key) && !empty($secret_key);
    $checks['keys'] = array(
        /* translators: %s: "Sandbox" or "Live" */
        'label'   => sprintf(__('%s API key/secret dolu', 'iyzico-payment-gateway'), $sandbox ? __('Sandbox', 'iyzico-payment-gateway') : __('Live', 'iyzico-payment-gateway')),
        'status'  => $keys_ok ? 'ok' : 'error',
        'message' => $keys_ok
            ? __('Dolu.', 'iyzico-payment-gateway')
            /* translators: %s: "Test" or "Live" */
            : sprintf(__('%s API Key/Secret eksik.', 'iyzico-payment-gateway'), $sandbox ? __('Test', 'iyzico-payment-gateway') : __('Live', 'iyzico-payment-gateway')),
    );

    // 3. Callback endpoint'i gerçekten çalışıyor mu?
    // Token olmadan GET atıldığında handler'ımız her zaman home_url()'e
    // redirect eder (bkz. includes/callback-handler.php) — bu davranış
    // API key ayarlanmış olsa da olmasa da AYNI, yani deterministik bir
    // test noktası.
    $callback_url = trim($settings['callback_url_override'] ?? '');
    if (empty($callback_url)) {
        $callback_url = home_url('/api/payment/callback');
    }

    $response = wp_remote_get($callback_url, array(
        'timeout'     => 8,
        'redirection' => 0,
    ));

    if (is_wp_error($response)) {
        $checks['callback'] = array(
            'label'   => __('Callback endpoint erişilebilir', 'iyzico-payment-gateway'),
            'status'  => 'warning',
            'message' => sprintf(
                /* translators: 1: WP_Error message, 2: callback URL */
                __('Sunucu kendi kendine istek atamadı (bazı hostlarda loopback engeli olur): %1$s. Tarayıcıdan elle kontrol et: %2$s', 'iyzico-payment-gateway'),
                $response->get_error_message(),
                esc_url($callback_url)
            ),
        );
    } else {
        $code        = (int) wp_remote_retrieve_response_code($response);
        $is_redirect = in_array($code, array(301, 302, 303, 307, 308), true);

        $checks['callback'] = $is_redirect
            ? array(
                'label'   => __('Callback endpoint erişilebilir', 'iyzico-payment-gateway'),
                'status'  => 'ok',
                /* translators: %d: HTTP status code */
                'message' => sprintf(__('Doğru şekilde yönlendirme yapıyor (HTTP %d).', 'iyzico-payment-gateway'), $code),
            )
            : array(
                'label'   => __('Callback endpoint erişilebilir', 'iyzico-payment-gateway'),
                'status'  => 'error',
                /* translators: %d: HTTP status code */
                'message' => sprintf(__('Beklenmeyen yanıt: HTTP %d. Başka bir plugin bu path\'i ele geçirmiş olabilir (rewrite/cache/güvenlik eklentisi kontrol et).', 'iyzico-payment-gateway'), $code),
            );
    }

    // 4. iyzico sunucularına ağ bağlantısı var mı? (yetkilendirme
    // gerektirmeyen basit bir erişilebilirlik testi, gerçek işlem değil)
    $base_url = $sandbox ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com';
    $ping     = wp_remote_get($base_url, array('timeout' => 8));

    $checks['connectivity'] = is_wp_error($ping)
        ? array(
            'label'   => __('iyzico sunucularına bağlantı', 'iyzico-payment-gateway'),
            'status'  => 'error',
            /* translators: %s: WP_Error message */
            'message' => sprintf(__('Sunucudan iyzico\'ya ulaşılamıyor: %s. Hosting firewall\'ı dış bağlantıyı engelliyor olabilir.', 'iyzico-payment-gateway'), $ping->get_error_message()),
        )
        : array(
            'label'   => __('iyzico sunucularına bağlantı', 'iyzico-payment-gateway'),
            'status'  => 'ok',
            /* translators: %d: HTTP status code */
            'message' => sprintf(__('Bağlantı kuruldu (HTTP %d).', 'iyzico-payment-gateway'), wp_remote_retrieve_response_code($ping)),
        );

    // 5. WooCommerce aktif ve sürüm uyumlu mu?
    if (!class_exists('WooCommerce')) {
        $checks['woocommerce'] = array(
            'label'   => __('WooCommerce aktif', 'iyzico-payment-gateway'),
            'status'  => 'error',
            'message' => __('WooCommerce aktif değil.', 'iyzico-payment-gateway'),
        );
    } else {
        $tested_up_to  = '11.0';
        $current       = defined('WC_VERSION') ? WC_VERSION : '0';
        // Sadece major.minor karşılaştır (11.0.1 gibi patch sürümleri
        // yanlışlıkla "uyumsuz" görünmesin — WC patch release'leri
        // neredeyse hiçbir zaman ödeme gateway API'sini kırmıyor).
        $current_minor = implode('.', array_slice(explode('.', $current), 0, 2));
        $checks['woocommerce'] = array(
            'label'   => __('WooCommerce sürümü', 'iyzico-payment-gateway'),
            'status'  => version_compare($current_minor, $tested_up_to, '>') ? 'warning' : 'ok',
            /* translators: 1: installed WC version, 2: tested-up-to version */
            'message' => sprintf(__('Kurulu: %1$s (bu plugin %2$s sürümüne kadar test edildi).', 'iyzico-payment-gateway'), $current, $tested_up_to),
        );
    }

    return $checks;
}

function wic_health_overall_status($checks) {
    $has_error   = false;
    $has_warning = false;

    foreach ($checks as $check) {
        if ('error' === $check['status']) {
            $has_error = true;
        } elseif ('warning' === $check['status']) {
            $has_warning = true;
        }
    }

    if ($has_error) {
        return 'error';
    }
    return $has_warning ? 'warning' : 'ok';
}

function wic_store_health_result($checks, $status) {
    update_option('wic_last_health_check', array(
        'time'   => current_time('mysql'),
        'checks' => $checks,
        'status' => $status,
    ));
}

/**
 * Günlük WP-Cron tetikleyicisi. Durum ok<->error arasında GEÇTİĞİNDE
 * (aynı sorun her gün tekrar tekrar değil) admin e-postasına uyarı yollar.
 */
function wic_run_scheduled_health_check() {
    $checks = wic_run_health_checks();
    $status = wic_health_overall_status($checks);

    wic_store_health_result($checks, $status);

    $previous_status = get_option('wic_health_status', 'ok');

    if ($status !== $previous_status && in_array($status, array('ok', 'error'), true)) {
        wic_send_health_alert_email($checks, $status);
    }

    update_option('wic_health_status', $status);
}

function wic_send_health_alert_email($checks, $status) {
    $to   = get_option('admin_email');
    $site = get_bloginfo('name');

    if ('error' === $status) {
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] iyzico ödeme sistemi sorunlu görünüyor', 'iyzico-payment-gateway'), $site);
        $body    = __('iyzico ödeme entegrasyonunda bir sorun tespit edildi:', 'iyzico-payment-gateway') . "\n\n";
    } else {
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] iyzico ödeme sistemi normale döndü', 'iyzico-payment-gateway'), $site);
        $body    = __('Önceki sorun(lar) düzelmiş görünüyor:', 'iyzico-payment-gateway') . "\n\n";
    }

    foreach ($checks as $check) {
        $body .= '- ' . $check['label'] . ': ' . strtoupper($check['status']) . ' — ' . $check['message'] . "\n";
    }

    $body .= "\n" . __('Detaylar:', 'iyzico-payment-gateway') . ' ' . admin_url('admin.php?page=wc-settings&tab=checkout&section=iyzico_custom');

    wp_mail($to, $subject, $body);
}

/**
 * Her admin sayfasında (YENİDEN kontrol ÇALIŞTIRMADAN, sadece son
 * kaydedilmiş sonucu okuyarak) kalıcı bir uyarı gösterir. Her sayfa
 * yüklemesinde network isteği atmak performans açısından anlamsız
 * olurdu — o yüzden sadece cron/manuel kontrolün son sonucunu okuyor.
 */
function wic_health_admin_notice() {
    $last = get_option('wic_last_health_check');

    if (empty($last) || 'error' !== $last['status'] || !current_user_can('manage_woocommerce')) {
        return;
    }

    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=iyzico_custom');
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e('iyzico ödeme entegrasyonunda bir sorun tespit edildi', 'iyzico-payment-gateway'); ?></strong>
            (<?php esc_html_e('son kontrol:', 'iyzico-payment-gateway'); ?> <?php echo esc_html($last['time']); ?>).
            <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Ayarlar sayfasından detayları gör', 'iyzico-payment-gateway'); ?></a>.
        </p>
    </div>
    <?php
}

/**
 * Ayarlar sayfasındaki "Şimdi Kontrol Et" butonunun AJAX handler'ı.
 * BİLİNÇLİ OLARAK plugins_loaded seviyesinde koşulsuz kayıt ediliyor —
 * daha önce IP tespit butonuyla yaşadığımız "gateway class instantiate
 * edilmeden hook kayıt olmuyor" hatasını burada tekrarlamıyoruz.
 */
function wic_ajax_run_health_check() {
    check_ajax_referer('wic_health_check', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Yetkisiz.', 'iyzico-payment-gateway')), 403);
    }

    $checks = wic_run_health_checks();
    $status = wic_health_overall_status($checks);

    wic_store_health_result($checks, $status);
    update_option('wic_health_status', $status);

    wp_send_json_success(array(
        'status' => $status,
        'checks' => $checks,
        'time'   => current_time('mysql'),
    ));
}

function wic_render_health_results_html($last) {
    if (empty($last) || empty($last['checks'])) {
        return '<p style="color:#666;margin:0;">' . esc_html__('Henüz kontrol çalıştırılmadı. "Şimdi Kontrol Et" butonuna bas ya da günlük otomatik kontrolü bekle.', 'iyzico-payment-gateway') . '</p>';
    }

    $colors = array('ok' => '#2e7d32', 'warning' => '#b8860b', 'error' => '#c62828');
    $labels = array(
        'ok'      => __('OK', 'iyzico-payment-gateway'),
        'warning' => __('UYARI', 'iyzico-payment-gateway'),
        'error'   => __('HATA', 'iyzico-payment-gateway'),
    );

    $html = '<p style="color:#666;margin:0 0 6px;">' . esc_html__('Son kontrol:', 'iyzico-payment-gateway') . ' ' . esc_html($last['time']) . '</p>';
    $html .= '<ul style="margin:0;padding-left:18px;">';

    foreach ($last['checks'] as $check) {
        $color = isset($colors[$check['status']]) ? $colors[$check['status']] : '#666';
        $label = isset($labels[$check['status']]) ? $labels[$check['status']] : strtoupper($check['status']);
        $html .= '<li style="margin-bottom:4px;"><strong style="color:' . esc_attr($color) . '">[' . esc_html($label) . ']</strong> '
            . esc_html($check['label']) . ' — ' . esc_html($check['message']) . '</li>';
    }

    $html .= '</ul>';
    return $html;
}
