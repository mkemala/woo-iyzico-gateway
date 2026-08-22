<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /api/payment/callback isteğini işler. Bilinçli olarak gateway class'ından
 * bağımsız, standalone bir fonksiyon — WC_Gateway_Iyzico_Custom sadece
 * checkout/ayarlar render'ında instantiate edildiği için, o class'ın
 * içine konursa bu kod hiç çalışmayabilirdi (tam olarak yaşadığımız bug).
 * Ayarları $this->get_option() yerine doğrudan get_option() ile okuyoruz.
 *
 * Güvenlik modeli: POST body'deki token'a körü körüne güvenmiyoruz —
 * token'ı kendi secret key'imizle iyzico'ya sorup (server-to-server)
 * gerçek ödeme durumunu iyzico'dan doğruluyoruz.
 *
 * NOT (INCI Analyzer entegrasyonundan gelen ders): sipariş eşleştirmesi
 * iyzico'nun bize verdiği "token" üzerinden yapılır — conversationId
 * üzerinden DEĞİL.
 */
function wic_process_iyzico_callback() {
    $settings = get_option('woocommerce_iyzico_custom_settings', array());

    $sandbox    = isset($settings['sandbox']) && 'yes' === $settings['sandbox'];
    $api_key    = $sandbox ? ($settings['test_api_key'] ?? '') : ($settings['live_api_key'] ?? '');
    $secret_key = $sandbox ? ($settings['test_secret_key'] ?? '') : ($settings['live_secret_key'] ?? '');
    $debug      = isset($settings['debug']) && 'yes' === $settings['debug'];

    $log = function ($message, $level = 'info') use ($debug) {
        if (!$debug || !function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->log($level, $message, array('source' => 'iyzico-custom'));
    };

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

    if (empty($token)) {
        $log('Callback: token eksik.', 'error');
        wp_safe_redirect(home_url('/'));
        exit;
    }

    if (empty($api_key) || empty($secret_key)) {
        $log('Callback: API key/secret ayarlanmamış.', 'error');
        wp_safe_redirect(home_url('/'));
        exit;
    }

    if (!function_exists('wc_get_orders')) {
        // WooCommerce henüz tam yüklenmemiş olabilir (çok erken bir 'init'
        // aşaması) — normalde olmaması gerekir ama güvenlik için kontrol.
        $log('Callback: wc_get_orders mevcut değil, WooCommerce yüklenmemiş olabilir.', 'error');
        wp_safe_redirect(home_url('/'));
        exit;
    }

    $orders = wc_get_orders(array(
        'meta_key'   => '_wic_iyzico_token',
        'meta_value' => $token,
        'limit'      => 1,
    ));

    if (empty($orders)) {
        $log('Callback: token ile eşleşen sipariş bulunamadı: ' . $token, 'error');
        wp_safe_redirect(wc_get_page_permalink('cart'));
        exit;
    }

    $order = $orders[0];

    // İdempotency: aynı callback birden fazla tetiklenirse tekrar işlemeyelim.
    if ($order->has_status(array('processing', 'completed'))) {
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    require_once WIC_PLUGIN_DIR . 'vendor/iyzico/iyzipay-php/autoload.php';

    $options = new \Iyzipay\Options();
    $options->setApiKey($api_key);
    $options->setSecretKey($secret_key);
    $options->setBaseUrl($sandbox ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com');

    $request = new \Iyzipay\Request\RetrieveCheckoutFormRequest();
    $request->setToken($token);

    try {
        $checkoutForm = \Iyzipay\Model\CheckoutForm::retrieve($request, $options);
    } catch (\Exception $e) {
        $log('CheckoutForm retrieve exception (order #' . $order->get_id() . '): ' . $e->getMessage(), 'error');
        /* translators: %s: exception message from the iyzico SDK */
        $order->update_status('failed', sprintf(__('iyzico doğrulama hatası: %s', 'woo-iyzico-custom'), $e->getMessage()));
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    if ('success' === $checkoutForm->getStatus() && 'SUCCESS' === $checkoutForm->getPaymentStatus()) {
        $order->payment_complete($checkoutForm->getPaymentId());

        // İade API'si (CreateRefundRequest) üstteki paymentId'yi DEĞİL,
        // paymentItems[] içindeki paymentTransactionId'yi istiyor — iyzico'da
        // bir "payment" birden fazla "payment item" (sepet kalemi) içerebilir,
        // her kalemin kendi transaction ID'si var. Biz tek satırlık sepet
        // gönderdiğimiz için (bkz. process_payment()) burada tam olarak 1
        // eleman bekleniyor. Bunu ayrıca saklamazsak process_refund() hiç
        // çalışamaz — WooCommerce'in kendi _transaction_id'si (payment_complete
        // ile set edilen) bu iş için yanlış ID.
        $payment_items = $checkoutForm->getPaymentItems();
        if (!empty($payment_items) && isset($payment_items[0])) {
            $order->update_meta_data('_wic_payment_transaction_id', $payment_items[0]->getPaymentTransactionId());
            $order->save();
        } else {
            $log('Uyarı: paymentItems boş geldi (order #' . $order->get_id() . '), ileride iade yapılamayabilir.', 'warning');
        }

        $order->add_order_note(sprintf(
            /* translators: 1: iyzico payment ID, 2: iyzico auth code */
            __('iyzico ödemesi başarılı. Payment ID: %1$s, Auth Code: %2$s', 'woo-iyzico-custom'),
            $checkoutForm->getPaymentId(),
            $checkoutForm->getAuthCode()
        ));
        $log('Payment success for order #' . $order->get_id());
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    $errorMessage = $checkoutForm->getErrorMessage() ?: __('Ödeme tamamlanamadı.', 'woo-iyzico-custom');
    /* translators: %s: iyzico error message */
    $order->update_status('failed', sprintf(__('iyzico ödemesi başarısız: %s', 'woo-iyzico-custom'), $errorMessage));
    $log('Payment failed for order #' . $order->get_id() . ': ' . $errorMessage, 'error');

    if (function_exists('wc_add_notice')) {
        /* translators: %s: iyzico error message */
        wc_add_notice(sprintf(__('Ödemeniz tamamlanamadı: %s', 'woo-iyzico-custom'), $errorMessage), 'error');
    }
    wp_safe_redirect(wc_get_checkout_url());
    exit;
}
