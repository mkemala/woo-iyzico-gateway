<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ödeme kutusundaki güven rozetleri için basit inline SVG ikonlar
 * (kilit, kalkan, kart-yok). Kart marka logoları artık iyzico'nun
 * kendi resmi "logo band" görseli (assets/images/iyzico-logo-band.svg)
 * ile gösteriliyor, burada elle çizilmiş marka ikonu YOK — resmi
 * görsel her zaman daha güvenilir/güncel.
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
