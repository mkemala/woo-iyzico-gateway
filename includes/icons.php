<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ödeme kutusundaki güven rozetleri için basit inline SVG ikonlar
 * (kilit, kalkan, kart-yok). Bunlar jenerik, marka-bağımsız ikonlar —
 * hiçbir kart/marka logosu değiller. Checkout'taki ödeme ikonu (varsa)
 * ayrıca, mağaza sahibinin ayarlar sayfasından kendi yüklediği görsel
 * ile gösteriliyor (bkz. WC_Gateway_Iyzico_Custom::get_icon()), bu
 * dosyayla ilgisi yok.
 */

function wic_svg_lock() {
    return '<svg class="wic-trust-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <rect x="4" y="9" width="12" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <circle cx="10" cy="13.2" r="1.2" fill="currentColor"/>
    </svg>';
}

function wic_svg_shield() {
    return '<svg class="wic-trust-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M10 2.5l6 2.2v4.4c0 4-2.6 7.1-6 8.4-3.4-1.3-6-4.4-6-8.4V4.7l6-2.2z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
        <path d="M7 10l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>';
}

function wic_svg_no_card() {
    return '<svg class="wic-trust-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <rect x="2.5" y="5" width="15" height="10.5" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <line x1="2.5" y1="8.5" x2="17.5" y2="8.5" stroke="currentColor" stroke-width="1.6"/>
        <line x1="4" y1="17" x2="16" y2="3" stroke="currentColor" stroke-width="1.4"/>
    </svg>';
}

/**
 * Checkout'ta ödeme yöntemi başlığının yanında gösterilebilecek, hazır,
 * marka-bağımsız ikonlar (bkz. WC_Gateway_Iyzico_Custom::get_icon() —
 * mağaza sahibi kendi görselini yüklemediyse bu üçten birini seçebilir).
 * Trust-badge ikonlarıyla aynı çizgi-sanatı dilinde: currentColor, 20x20
 * viewBox, 1.6 stroke-width — hiçbiri gerçek bir kart/marka logosu değil.
 */
function wic_svg_icon_card() {
    return '<svg class="wic-payment-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <rect x="1.5" y="4.5" width="17" height="11" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <line x1="1.5" y1="7.8" x2="18.5" y2="7.8" stroke="currentColor" stroke-width="1.6"/>
        <line x1="4" y1="12.3" x2="8.5" y2="12.3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
    </svg>';
}

function wic_svg_icon_secure_card() {
    return '<svg class="wic-payment-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <rect x="1.5" y="3.5" width="13.5" height="9.5" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.5"/>
        <line x1="1.5" y1="6.3" x2="15" y2="6.3" stroke="currentColor" stroke-width="1.5"/>
        <g transform="translate(11.5,9.5)">
            <rect x="0" y="2.4" width="7" height="5.4" rx="1" fill="none" stroke="currentColor" stroke-width="1.4"/>
            <path d="M1.3 2.4V1.3a2.2 2.2 0 0 1 4.4 0v1.1" fill="none" stroke="currentColor" stroke-width="1.4"/>
        </g>
    </svg>';
}

function wic_svg_icon_checkmark() {
    return '<svg class="wic-payment-icon" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <path d="M6.3 10.2l2.3 2.3 5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>';
}

/**
 * builtin_icon ayarındaki key => render fonksiyonu eşlemesi. get_icon()
 * ve ayarlar sayfasındaki seçim listesi bu tek listeden besleniyor —
 * yeni bir ikon eklemek istersen sadece burayı güncellemen yeterli.
 */
function wic_builtin_icon_choices() {
    return array(
        ''              => __('Yok (sadece başlık metni)', 'woo-iyzico-custom'),
        'card'          => __('Kart (basit)', 'woo-iyzico-custom'),
        'secure_card'   => __('Kart + Kilit', 'woo-iyzico-custom'),
        'checkmark'     => __('Onay İşareti', 'woo-iyzico-custom'),
    );
}

function wic_render_builtin_icon($key) {
    switch ($key) {
        case 'card':
            return wic_svg_icon_card();
        case 'secure_card':
            return wic_svg_icon_secure_card();
        case 'checkmark':
            return wic_svg_icon_checkmark();
        default:
            return '';
    }
}
