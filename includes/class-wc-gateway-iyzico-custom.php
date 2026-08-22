<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_Iyzico_Custom
 *
 * iyzico "Checkout Form" (hosted payment page) entegrasyonu.
 * Kart bilgisi hiçbir zaman bu sitenin sunucusundan geçmiyor —
 * müşteri iyzico'nun kendi ödeme sayfasına yönlendiriliyor, orada
 * 3D Secure dahil tüm akışı tamamlıyor, sonra callback URL'imize
 * dönüyor. PCI-DSS yükü bu sayede minimum seviyede kalıyor.
 *
 * ÖNEMLİ (INCI Analyzer entegrasyonundan gelen ders):
 * Sipariş eşleştirmesi iyzico'nun bize verdiği "token" üzerinden
 * yapılır — conversationId üzerinden DEĞİL. conversationId sadece
 * bizim tarafımızdan üretilen bir referans, güvenilir eşleştirme
 * anahtarı değil.
 *
 * i18n NOTU: Tüm kullanıcıya görünen metinler __()/_e() ile sarılı,
 * text domain 'woo-iyzico-custom'. Kaynak dil Türkçe (iyzico zaten
 * sadece Türkiye'de kullanılan bir sağlayıcı, gerçek kullanıcı kitlesi
 * %100 Türkçe) — ama bu sarım sayesinde ileride biri gerçekten başka
 * bir dile çevirmek isterse kod değişikliği gerekmez, sadece .po/.mo
 * dosyası eklemesi yeterli olur.
 */
class WC_Gateway_Iyzico_Custom extends WC_Payment_Gateway {

    /** @var bool */
    private $sandbox;

    /** @var string */
    private $api_key;

    /** @var string */
    private $secret_key;

    /** @var bool */
    private $debug;

    /** @var WC_Logger|null */
    private $logger;

    public function __construct() {
        // icons.php burada, en başta yükleniyor — init_form_fields() (hemen
        // aşağıda) artık wic_builtin_icon_choices() çağırıyor, get_icon() de
        // wic_render_builtin_icon() çağırıyor, ikisi de checkout sayfası her
        // yüklendiğinde (payment_fields() hiç çalışmadan) tetiklenebiliyor.
        // Önceden bu require sadece payment_fields() içinde vardı — kutu hiç
        // açılmadan get_icon() çağrılırsa (ki checkout listesi oluşturulurken
        // hep öyle olur) fatal error'a yol açardı.
        require_once WIC_PLUGIN_DIR . 'includes/icons.php';

        $this->id                 = 'iyzico_custom';
        $this->icon               = '';
        $this->has_fields         = false; // hosted form - kendi kart formumuz yok
        $this->method_title       = __('iyzico (Custom / 3D Secure)', 'woo-iyzico-custom');
        $this->method_description = __('iyzico Checkout Form ile 3D Secure zorunlu ödeme. Kart verisi sitede tutulmaz.', 'woo-iyzico-custom');
        $this->supports            = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->sandbox      = 'yes' === $this->get_option('sandbox');
        $this->debug        = 'yes' === $this->get_option('debug');

        $this->api_key    = $this->sandbox ? $this->get_option('test_api_key') : $this->get_option('live_api_key');
        $this->secret_key = $this->sandbox ? $this->get_option('test_secret_key') : $this->get_option('live_secret_key');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Ödeme ikonu (logo) yükleme alanı için medya kütüphanesi
        // seçiciyi SADECE bu gateway'in kendi ayarlar sayfasında yüklüyoruz.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_media_picker'));

        // Checkout sayfasında kart logoları + güven rozetleri için CSS.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));

        // TCKN alanı sadece ayarlarda açıksa checkout'a ekleniyor — kapalı
        // sitelerde eski (alansız) davranış birebir korunuyor.
        if ('yes' === $this->get_option('identity_field_enabled')) {
            add_filter('woocommerce_billing_fields', array($this, 'add_identity_billing_field'), 20);
            add_action('woocommerce_checkout_process', array($this, 'validate_identity_field'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_identity_field'));
        }

        // NOT: /api/payment/callback isteği artık burada DEĞİL,
        // woo-iyzico-custom.php içinde wic_maybe_handle_callback() ile,
        // doğrudan REQUEST_URI kontrolüyle 'init' seviyesinde yakalanıyor.
        // Bkz. oradaki yorum — bu class sadece checkout/ayarlar render'ında
        // instantiate edildiği için buraya konan hook'lar admin-ajax.php
        // ve hatta bazı doğrudan istek senaryolarında hiç tetiklenmeyebiliyor.
    }

    /**
     * Checkout'ta ödeme yöntemi başlığının yanına gösterilecek ikon.
     *
     * iyzico'nun kendi resmi görsellerini (logo band, "iyzico ile öde"
     * rozeti vb.) plugin paketinin İÇİNE GÖMMÜYORUZ — iyzico ile yapılan
     * yazışma sonucunda, geniş kitlelere açık dağıtımda (WP.org gibi)
     * marka şeridinin gömülü kullanılmaması istendi. Onun yerine mağaza
     * sahibi, iyzico'nun resmi indirme sayfasından kendi indirdiği görseli
     * buradan (Ayarlar > Ödeme İkonu) yükleyebiliyor. Hiçbir şey
     * yüklenmediyse ikon boş kalır — WooCommerce'te birçok gateway zaten
     * ikonsuz, sadece başlık metniyle çalışır, bu normal bir durumdur.
     */
    public function get_icon() {
        $url = $this->get_option('custom_icon_url');

        if (!empty($url)) {
            $icon = '<img src="' . esc_url($url) . '" alt="' . esc_attr($this->title) . '" class="wic-custom-icon" />';
            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        $builtin = wic_render_builtin_icon($this->get_option('builtin_icon'));
        return apply_filters('woocommerce_gateway_icon', $builtin, $this->id);
    }

    /**
     * Ödeme yöntemi seçildiğinde açılan kutunun içeriği — açıklama metni
     * + güven rozetleri (SSL, 3D Secure, kart verisi saklanmaz).
     * has_fields=false olduğu için burada kart formu YOK, sadece bilgi.
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
        ?>
        <div class="wic-trust-badges">
            <span class="wic-trust-badge"><?php echo wic_svg_lock(); ?> <?php esc_html_e('256-bit SSL ile şifrelenir', 'woo-iyzico-custom'); ?></span>
            <span class="wic-trust-badge"><?php echo wic_svg_shield(); ?> <?php esc_html_e('3D Secure zorunlu doğrulama', 'woo-iyzico-custom'); ?></span>
            <span class="wic-trust-badge"><?php echo wic_svg_no_card(); ?> <?php esc_html_e('Kart bilgisi sitede tutulmaz', 'woo-iyzico-custom'); ?></span>
        </div>
        <?php
    }

    public function enqueue_settings_media_picker($hook) {
        static $already_enqueued = false;

        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which settings tab/section is open, not processing form input.
        if (!isset($_GET['tab'], $_GET['section']) || 'checkout' !== $_GET['tab'] || $this->id !== $_GET['section']) {
            return;
        }

        // WooCommerce'in ayarlar sayfası bazı kurulumlarda admin_enqueue_scripts'i
        // birden fazla tetikleyebiliyor (ör. bir başka eklenti/tema kendi
        // admin_enqueue_scripts çağrısını farklı bir hook önceliğinde tekrar
        // yapıyorsa). Bu olursa, aşağıdaki inline script iki kez enjekte
        // olur ve "Görsel Seç" butonu + önizleme ikiye katlanır. Bu statik
        // flag, aynı sayfa yüklemesinde ikinci kez çalışmayı engelliyor.
        if ($already_enqueued) {
            return;
        }
        $already_enqueued = true;

        wp_enqueue_media();

        $field_id = $this->get_field_key('custom_icon_url');
        wp_add_inline_script('media-editor', $this->get_media_picker_js($field_id));
    }

    private function get_media_picker_js($field_id) {
        $field_id = esc_js($field_id);
        $select_label = esc_js(__('Ödeme İkonu Seç', 'woo-iyzico-custom'));
        $button_label = esc_js(__('Görsel Seç', 'woo-iyzico-custom'));

        return "
        jQuery(function ($) {
            var field = $('#{$field_id}');
            if (!field.length) { return; }

            // Ekstra güvenlik: bu alan için buton zaten eklenmişse tekrar ekleme
            // (server-side static flag'in yakalayamadığı bir durum olursa diye).
            if (field.data('wicMediaPickerReady')) { return; }
            field.data('wicMediaPickerReady', true);

            var button = $('<button type=\"button\" class=\"button\" style=\"margin-left:8px;\">{$button_label}</button>');
            field.after(button);

            var preview = $('<div style=\"margin-top:8px;\"><img id=\"{$field_id}_preview\" src=\"' + field.val() + '\" style=\"max-height:40px;' + (field.val() ? '' : 'display:none;') + '\" /></div>');
            button.after(preview);

            button.on('click', function (e) {
                e.preventDefault();
                var frame = wp.media({
                    title: '{$select_label}',
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    field.val(attachment.url);
                    $('#{$field_id}_preview').attr('src', attachment.url).show();
                });
                frame.open();
            });
        });
        ";
    }


    public function enqueue_checkout_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        wp_enqueue_style(
            'wic-checkout',
            WIC_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            WIC_VERSION
        );

        // Kullanıcının ayarlar sayfasında seçtiği renkleri CSS custom
        // property olarak enjekte ediyoruz. Hiçbir şey değiştirmediyse
        // checkout.css'teki fallback değerler (mevcut yeşil palet) geçerli
        // kalır — bu yüzden var(--wic-primary, #2F453A) şeklinde yazıldı.
        $custom_css = sprintf(
            ':root{--wic-primary:%s;--wic-bg:%s;--wic-border:%s;}',
            sanitize_hex_color($this->get_option('color_primary', '#2F453A')) ?: '#2F453A',
            sanitize_hex_color($this->get_option('color_bg', '#F5FAF6')) ?: '#F5FAF6',
            sanitize_hex_color($this->get_option('color_border', '#D7E7DC')) ?: '#D7E7DC'
        );
        wp_add_inline_style('wic-checkout', $custom_css);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'getting_started' => array(
                'type' => 'wic_getting_started',
            ),
            'enabled' => array(
                'title'   => __('Etkinleştir', 'woo-iyzico-custom'),
                'type'    => 'checkbox',
                'label'   => __('iyzico ile ödemeyi etkinleştir', 'woo-iyzico-custom'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Başlık', 'woo-iyzico-custom'),
                'type'        => 'text',
                'description' => __('Checkout sayfasında müşteriye görünen başlık.', 'woo-iyzico-custom'),
                'default'     => __('Kredi/Banka Kartı', 'woo-iyzico-custom'),
                'desc_tip'    => false,
            ),
            'description' => array(
                'title'       => __('Açıklama', 'woo-iyzico-custom'),
                'type'        => 'textarea',
                'description' => __('Ödeme yöntemi seçildiğinde müşteriye görünen açıklama.', 'woo-iyzico-custom'),
                'default'     => __('Kartınızla güvenli ödeme yapın. 3D Secure doğrulama sonrası siparişiniz onaylanır.', 'woo-iyzico-custom'),
            ),
            'builtin_icon' => array(
                'title'       => __('Hazır İkon', 'woo-iyzico-custom'),
                'type'        => 'select',
                'options'     => wic_builtin_icon_choices(),
                'default'     => '',
                'description' => __('Marka-bağımsız, plugin\'e gömülü basit bir ikon (SVG). Aşağıda bir görsel yüklersen bu ayar yok sayılır, yüklenen görsel öncelikli olur.', 'woo-iyzico-custom'),
                'desc_tip'    => false,
            ),
            'custom_icon_url' => array(
                'title'       => __('Özel Görsel Yükle (isteğe bağlı)', 'woo-iyzico-custom'),
                'type'        => 'text',
                /* translators: %s: link to iyzico's official logo download page */
                'description' => sprintf(
                    __('Yukarıdaki hazır ikon yerine kendi görselini kullanmak istersen buraya yükle — bu alan doluysa hazır ikon ayarı yok sayılır. Bu eklenti hiçbir logoyu paket içine gömmez; istersen iyzico\'nun resmi görsellerini %s adresinden indirip Medya Kütüphanesi\'ne yükleyip burada seçebilirsin. Not: "logo band" (kart şeridi) ya da "iyzico ile öde" rozetlerinden birini seçmen önerilir — download paketindeki diğer varyantlar checkout ikonu için tasarlanmamıştır.', 'woo-iyzico-custom'),
                    '<a href="https://docs.iyzico.com/ek-bilgiler/iyzico-logo-paketi" target="_blank" rel="noopener noreferrer">docs.iyzico.com</a>'
                ),
                'default'     => '',
                'desc_tip'    => false,
            ),
            'sandbox' => array(
                'title'       => __('Sandbox modu', 'woo-iyzico-custom'),
                'type'        => 'checkbox',
                'label'       => __('Test (sandbox) modunu etkinleştir', 'woo-iyzico-custom'),
                'default'     => 'yes',
                'description' => __('Canlıya almadan önce mutlaka sandbox key\'leriyle uçtan uca test et.', 'woo-iyzico-custom'),
            ),
            'test_api_key' => array(
                'title' => __('Test API Key', 'woo-iyzico-custom'),
                'type'  => 'text',
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'woo-iyzico-custom'),
                'type'  => 'password',
            ),
            'live_api_key' => array(
                'title' => __('Live API Key', 'woo-iyzico-custom'),
                'type'  => 'text',
            ),
            'live_secret_key' => array(
                'title' => __('Live Secret Key', 'woo-iyzico-custom'),
                'type'  => 'password',
            ),
            'debug' => array(
                'title'       => __('Debug log', 'woo-iyzico-custom'),
                'type'        => 'checkbox',
                'label'       => __('İşlem loglarını WooCommerce > Status > Logs altına yaz', 'woo-iyzico-custom'),
                'default'     => 'yes',
            ),
            'identity_title' => array(
                'title'       => __('TCKN (Kimlik No) Toplama', 'woo-iyzico-custom'),
                'type'        => 'title',
                'description' => __('iyzico API\'si her ödemede bir TCKN alanı istiyor; WooCommerce checkout\'u bunu varsayılan olarak toplamaz. Bu alanı açarsan checkout\'a bir "TC Kimlik No" alanı eklenir — zorunlu tutmazsan müşteri boş bırakabilir. Alan kapalıyken, ya da açık olup boş bırakıldığında, Türkiye\'de yaygın kullanılan standart doldurma değeri (11111111111) otomatik gönderilir. Not: bu alan, checkout\'ta hangi ödeme yönteminin seçileceğinden bağımsız olarak eklenir (WooCommerce ödeme yöntemi seçilmeden önce checkout alanlarını oluşturur), yani açarsan tüm siparişlerde görünür.', 'woo-iyzico-custom'),
            ),
            'identity_field_enabled' => array(
                'title'       => __('TCKN Alanını Göster', 'woo-iyzico-custom'),
                'type'        => 'checkbox',
                'label'       => __('Checkout\'a TC Kimlik No alanı ekle', 'woo-iyzico-custom'),
                'default'     => 'no',
                'description' => __('Kapalıyken checkout\'ta hiçbir şey değişmez, iyzico\'ya otomatik olarak 11111111111 gönderilir.', 'woo-iyzico-custom'),
            ),
            'identity_field_required' => array(
                'title'       => __('TCKN Girişini Zorunlu Kıl', 'woo-iyzico-custom'),
                'type'        => 'checkbox',
                'label'       => __('Müşteri TCKN girmeden siparişi tamamlayamasın', 'woo-iyzico-custom'),
                'default'     => 'no',
                'description' => __('Yalnızca yukarıdaki alan açıkken etkilidir. Kapalı bırakılırsa alan görünür ama boş geçilebilir; boş geçilirse yine 11111111111 gönderilir.', 'woo-iyzico-custom'),
            ),
            'iyzico_panel_info' => array(
                /* translators: section title shown above the callback URL / IP fields */
                'title'       => __('iyzico Paneline Eklenecek Bilgiler', 'woo-iyzico-custom'),
                'type'        => 'title',
                'description' => __('Bu değerleri iyzico panelinde Ayarlar > IP/Back URL Yönetimi sayfasına ekleyip onaya göndermen gerekiyor. Aşağıdaki alanlar düzenlenebilir — otomatik tespit edilen değer yanlışsa (ör. yönlendirme yapan bir domain kullanıyorsan) elle düzeltebilirsin, plugin gerçekten burada yazan değeri kullanır.', 'woo-iyzico-custom'),
            ),
            'callback_url_override' => array(
                'title'       => __('Callback URL', 'woo-iyzico-custom'),
                'type'        => 'text',
                /* translators: %s: computed default callback URL */
                'description' => sprintf(__('iyzico panelinde Back URL olarak bunu kaydet. Boş bırakılırsa siteni home_url() adresinden otomatik hesaplanır (%s).', 'woo-iyzico-custom'), home_url('/api/payment/callback')),
                'placeholder' => home_url('/api/payment/callback'),
                'desc_tip'    => false,
            ),
            'server_ip' => array(
                'title'       => __('Sunucu Çıkış IP Adresi', 'woo-iyzico-custom'),
                'type'        => 'wic_ip_detect',
                'description' => __('iyzico panelinde IP Adresleri listesine bunu ekle. "Tespit Et" butonu sunucunun iyzico\'ya giden isteklerde kullandığı gerçek çıkış IP\'sini bulur (ziyaretçi IP\'si değil).', 'woo-iyzico-custom'),
            ),
            'reference_links' => array(
                'title' => __('Faydalı Linkler', 'woo-iyzico-custom'),
                'type'  => 'wic_reference_links',
            ),
            'branding_title' => array(
                'title'       => __('Görünüm', 'woo-iyzico-custom'),
                'type'        => 'title',
                'description' => __('Checkout\'ta ödeme kutusunun rengini kendi marka renklerine uyarlayabilirsin. Hiçbir şey değiştirmezsen mevcut yeşil palet kalır.', 'woo-iyzico-custom'),
            ),
            'color_primary' => array(
                'title'       => __('Ana Renk', 'woo-iyzico-custom'),
                'type'        => 'color',
                'default'     => '#2F453A',
                'description' => __('Güven rozeti ikonları ve metinleri bu rengi kullanır.', 'woo-iyzico-custom'),
                'desc_tip'    => true,
            ),
            'color_bg' => array(
                'title'       => __('Kutu Arka Plan Rengi', 'woo-iyzico-custom'),
                'type'        => 'color',
                'default'     => '#F5FAF6',
                'description' => __('Ödeme yöntemi seçildiğinde açılan kutunun arka planı.', 'woo-iyzico-custom'),
                'desc_tip'    => true,
            ),
            'color_border' => array(
                'title'       => __('Kenarlık Rengi', 'woo-iyzico-custom'),
                'type'        => 'color',
                'default'     => '#D7E7DC',
                'description' => __('Kutunun ince kenarlığı.', 'woo-iyzico-custom'),
                'desc_tip'    => true,
            ),
            'health_check_title' => array(
                'title'       => __('Sağlık Kontrolü', 'woo-iyzico-custom'),
                'type'        => 'title',
                /* translators: %s: site admin email address */
                'description' => sprintf(__('Callback endpoint erişilebilir mi, iyzico\'ya ağ bağlantısı var mı, API key/secret dolu mu — bugüne kadar elle yaptığımız kontrolleri otomatikleştiriyor. Günde bir kez arka planda da çalışır; bir sorun tespit edilir (veya düzelir) ise %s adresine otomatik e-posta gider.', 'woo-iyzico-custom'), esc_html(get_option('admin_email'))),
            ),
            'health_check' => array(
                'title' => __('Durum', 'woo-iyzico-custom'),
                'type'  => 'wic_health_check',
            ),
        );
    }

    /**
     * "Sağlık Kontrolü" alanı — son kaydedilmiş sonucu sayfa yüklenirken
     * gösterir (network isteği atmadan), buton tıklanınca AJAX ile
     * anlık yeniden çalıştırır. Asıl mantık includes/health-check.php'de.
     */
    public function generate_wic_health_check_html($key, $data) {
        require_once WIC_PLUGIN_DIR . 'includes/health-check.php';
        $last = get_option('wic_last_health_check');
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo esc_html($data['title']); ?></th>
            <td class="forminp">
                <button type="button" class="button" id="wic-health-check-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('wic_health_check')); ?>"><?php esc_html_e('Şimdi Kontrol Et', 'woo-iyzico-custom'); ?></button>
                <span id="wic-health-check-status" style="margin-left:8px;"></span>
                <div id="wic-health-check-results" style="margin-top:10px;">
                    <?php echo wic_render_health_results_html($last); // phpcs:ignore -- kendi escape ettiğimiz HTML ?>
                </div>
            </td>
        </tr>
        <script>
        (function() {
            var btn = document.getElementById('wic-health-check-btn');
            if (!btn || btn.dataset.wicBound) { return; }
            btn.dataset.wicBound = '1';

            var i18n = <?php echo wp_json_encode(array(
                'checking'    => __('Kontrol ediliyor (birkaç saniye sürebilir)...', 'woo-iyzico-custom'),
                'done'        => __('Tamamlandı.', 'woo-iyzico-custom'),
                'failed'      => __('Kontrol başarısız oldu.', 'woo-iyzico-custom'),
                'lastCheck'   => __('Son kontrol:', 'woo-iyzico-custom'),
                'statusOk'    => __('OK', 'woo-iyzico-custom'),
                'statusWarn'  => __('UYARI', 'woo-iyzico-custom'),
                'statusError' => __('HATA', 'woo-iyzico-custom'),
            )); ?>;

            var statusColors = {ok: '#2e7d32', warning: '#b8860b', error: '#c62828'};
            var statusLabels = {ok: i18n.statusOk, warning: i18n.statusWarn, error: i18n.statusError};

            function renderChecks(checks, time) {
                var html = '<p style="color:#666;margin:0 0 6px;">' + i18n.lastCheck + ' ' + time + '</p><ul style="margin:0;padding-left:18px;">';
                Object.keys(checks).forEach(function(key) {
                    var c = checks[key];
                    var color = statusColors[c.status] || '#666';
                    var label = statusLabels[c.status] || c.status.toUpperCase();
                    html += '<li style="margin-bottom:4px;"><strong style="color:' + color + '">[' + label + ']</strong> ' + c.label + ' — ' + c.message + '</li>';
                });
                html += '</ul>';
                return html;
            }

            btn.addEventListener('click', function() {
                var status = document.getElementById('wic-health-check-status');
                var results = document.getElementById('wic-health-check-results');
                status.textContent = i18n.checking;
                var body = new URLSearchParams();
                body.append('action', 'wic_run_health_check');
                body.append('nonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(json) {
                        if (json.success) {
                            status.textContent = i18n.done;
                            results.innerHTML = renderChecks(json.data.checks, json.data.time);
                        } else {
                            status.textContent = i18n.failed;
                        }
                    })
                    .catch(function() {
                        status.textContent = i18n.failed;
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Ayarlar sayfasının en üstünde tam genişlikte bir "Başlarken" özeti
     * — README'yi hiç görmemiş biri bile ne yapması gerektiğini burada
     * bulsun diye. Diğer alan tiplerinden farklı olarak label/input
     * ikili sütun düzenini değil, tam genişlik (colspan) kullanıyor.
     */
    public function generate_wic_getting_started_html($key, $data) {
        ob_start();
        ?>
        <tr valign="top">
            <td colspan="2" style="padding:0 0 20px;">
                <div style="background:#F5FAF6;border:1px solid #D7E7DC;border-radius:10px;padding:16px 20px;">
                    <p style="margin-top:0;"><strong><?php esc_html_e('Başlarken', 'woo-iyzico-custom'); ?></strong></p>
                    <ol style="margin:0 0 8px;padding-left:20px;">
                        <li><?php esc_html_e('Sandbox modunda başla — Test API Key/Secret\'ı iyzico\'nun sandbox panelinden (sandbox-merchant.iyzipay.com) al, aşağıdaki ilgili alanlara gir.', 'woo-iyzico-custom'); ?></li>
                        <li><?php esc_html_e('Aşağıdaki "Callback URL" ve "Sunucu Çıkış IP Adresi" değerlerini iyzico panelinde Ayarlar > IP/Back URL Yönetimi\'ne ekleyip onaya gönder.', 'woo-iyzico-custom'); ?></li>
                        <li><?php esc_html_e('iyzico\'nun sandbox test kartlarından biriyle bir sipariş tamamla.', 'woo-iyzico-custom'); ?></li>
                        <li><?php esc_html_e('En alttaki Sağlık Kontrolü bölümünden "Şimdi Kontrol Et"e bas — hepsi yeşil olmalı.', 'woo-iyzico-custom'); ?></li>
                        <li><?php esc_html_e('Onay tamamlanınca: Sandbox modunu kapat, Live key\'leri gir, küçük tutarlı bir gerçek işlemle son kez test et.', 'woo-iyzico-custom'); ?></li>
                    </ol>
                    <p style="margin:0;font-size:12.5px;color:#5A7666;">
                        <?php
                        printf(
                            /* translators: %s: link to the plugin's GitHub README */
                            esc_html__('Daha fazla ayrıntı için: %s', 'woo-iyzico-custom'),
                            '<a href="https://github.com/mkemala/woo-iyzico-gateway" target="_blank" rel="noopener">README</a>'
                        );
                        ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * WooCommerce Settings API'nin standart alan tiplerinde "IP tespit et"
     * butonu yok, o yüzden custom bir field type render ediyoruz.
     * generate_{type}_html isimlendirmesi WC_Settings_API'nin kendi
     * konvansiyonu — bu isimle üzerine yazınca otomatik çağrılıyor.
     */
    public function generate_wic_ip_detect_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $value     = $this->get_option($key);
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <input type="text" class="regular-text" id="<?php echo esc_attr($field_key); ?>" name="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($value); ?>" />
                <button type="button" class="button" id="wic-detect-ip-btn" data-target="<?php echo esc_attr($field_key); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wic_detect_ip')); ?>"><?php esc_html_e('Tespit Et', 'woo-iyzico-custom'); ?></button>
                <span id="wic-detect-ip-status" style="margin-left:8px;"></span>
                <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
            </td>
        </tr>
        <script>
        (function() {
            var btn = document.getElementById('wic-detect-ip-btn');
            if (!btn || btn.dataset.wicBound) { return; }
            btn.dataset.wicBound = '1';

            var i18n = <?php echo wp_json_encode(array(
                'detecting'  => __('Tespit ediliyor...', 'woo-iyzico-custom'),
                'found'      => __('Bulundu — kaydetmeyi unutma.', 'woo-iyzico-custom'),
                'notFound'   => __('Tespit edilemedi, elle gir.', 'woo-iyzico-custom'),
            )); ?>;

            btn.addEventListener('click', function() {
                var status = document.getElementById('wic-detect-ip-status');
                var input = document.getElementById(btn.dataset.target);
                status.textContent = i18n.detecting;
                var body = new URLSearchParams();
                body.append('action', 'wic_detect_ip');
                body.append('nonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(json) {
                        if (json.success && json.data && json.data.ip) {
                            input.value = json.data.ip;
                            status.textContent = i18n.found;
                        } else {
                            status.textContent = i18n.notFound;
                        }
                    })
                    .catch(function() {
                        status.textContent = i18n.notFound;
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * NOT: Bu metod artık kullanılmıyor, admin-ajax.php üzerinden
     * gelen istekler bu gateway class'ının instantiate edilmesini garanti
     * etmiyor (sadece checkout/ayarlar render'ında oluşturuluyor). Gerçek
     * handler woo-iyzico-custom.php içinde wic_ajax_detect_ip() olarak,
     * plugins_loaded seviyesinde koşulsuz kayıt ediliyor. Bkz. oradaki yorum.
     */

    private function log($message, $level = 'info') {
        if (!$this->debug) {
            return;
        }
        if (null === $this->logger) {
            $this->logger = wc_get_logger();
        }
        $this->logger->log($level, $message, array('source' => 'iyzico-custom'));
    }

    private function get_iyzico_options() {
        $options = new \Iyzipay\Options();
        $options->setApiKey($this->api_key);
        $options->setSecretKey($this->secret_key);
        $options->setBaseUrl($this->sandbox ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com');
        return $options;
    }

    /**
     * WooCommerce'in "Refund" arayüzü (sipariş ekranındaki "Refund" butonu)
     * bu metodu çağırıyor. Kısmi tutar desteklenmiyor — iyzico'nun
     * CreateRefundRequest'i teknik olarak kısmi tutarı kabul ediyor ama
     * biz şimdilik sadece TAM iadeyi destekliyoruz (Pro'da kısmi iade +
     * iade geçmişi paneli planlanıyor, bkz. README Yol Haritası).
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('wic_refund_no_order', __('Sipariş bulunamadı.', 'woo-iyzico-custom'));
        }

        $payment_transaction_id = $order->get_meta('_wic_payment_transaction_id');
        if (empty($payment_transaction_id)) {
            $this->log('Refund: _wic_payment_transaction_id eksik (order #' . $order_id . ').', 'error');
            return new \WP_Error(
                'wic_refund_missing_transaction_id',
                __('Bu sipariş için iyzico işlem numarası bulunamadı. Sipariş bu plugin\'in eski bir sürümüyle mi tamamlanmıştı? İadeyi iyzico Merchant Panel üzerinden manuel yapman gerekebilir.', 'woo-iyzico-custom')
            );
        }

        if (null === $amount) {
            $amount = $order->get_total();
        }

        if ((float) $amount !== (float) $order->get_total()) {
            return new \WP_Error(
                'wic_refund_partial_not_supported',
                __('Bu sürüm kısmi iadeyi desteklemiyor — sadece siparişin tam tutarını iade edebilirsin.', 'woo-iyzico-custom')
            );
        }

        require_once WIC_PLUGIN_DIR . 'vendor/iyzico/iyzipay-php/autoload.php';

        $request = new \Iyzipay\Request\CreateRefundRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId((string) $order_id . '-refund-' . uniqid());
        $request->setPaymentTransactionId($payment_transaction_id);
        $request->setPrice(number_format((float) $amount, 2, '.', ''));
        $request->setCurrency(\Iyzipay\Model\Currency::TL);
        $request->setIp($order->get_customer_ip_address() ?: '127.0.0.1');
        if (!empty($reason)) {
            $request->setDescription(sanitize_text_field($reason));
        }

        try {
            $refund = \Iyzipay\Model\Refund::create($request, $this->get_iyzico_options());
        } catch (\Exception $e) {
            $this->log('Refund exception (order #' . $order_id . '): ' . $e->getMessage(), 'error');
            return new \WP_Error('wic_refund_exception', $e->getMessage());
        }

        if ('success' !== $refund->getStatus()) {
            $error = $refund->getErrorMessage() ?: __('İade işlemi iyzico tarafından reddedildi.', 'woo-iyzico-custom');
            $this->log('Refund failed (order #' . $order_id . '): ' . $error, 'error');
            return new \WP_Error('wic_refund_failed', $error);
        }

        $order->add_order_note(sprintf(
            /* translators: 1: refunded amount, 2: reason given for the refund, if any */
            __('iyzico üzerinden %1$s iade edildi. %2$s', 'woo-iyzico-custom'),
            wc_price($amount),
            $reason ? '(' . $reason . ')' : ''
        ));
        $this->log('Refund success for order #' . $order_id . ', amount: ' . $amount);

        return true;
    }

    /**
     * Ayarlar sayfasındaki "Callback URL" alanı doldurulmuşsa onu kullan
     * (ör. home_url() gerçek domain'i yansıtmıyorsa manuel düzeltme imkanı),
     * doldurulmamışsa home_url() üzerinden otomatik hesapla.
     */
    private function get_callback_url() {
        $override = trim($this->get_option('callback_url_override'));
        return $override ? $override : home_url('/api/payment/callback');
    }

    /**
     * Sandbox/live moduna göre değişen kısa link listesi — bir önceki
     * oturumda "sandbox key'lerini nereden bulacağım" diye epey vakit
     * harcamıştık, o keşif sürecini burada bir kerelik yapıp linkleri
     * ayarlar sayfasına gömdük.
     *
     * BİLİNÇLİ OLARAK render zamanında (generate_{type}_html) çağrılan
     * bir custom field type — init_form_fields() içinde STATİK olarak
     * çağrılsaydı $this->sandbox henüz set edilmemiş olurdu (constructor
     * sıralaması: form_fields önce, sandbox/settings sonra atanıyor).
     * Aynı deseni generate_wic_ip_detect_html / generate_wic_health_check_html
     * için de kullanıyoruz.
     */
    public function generate_wic_reference_links_html($key, $data) {
        $sandbox   = 'yes' === $this->get_option('sandbox');
        $panel_url = $sandbox
            ? 'https://sandbox-merchant.iyzipay.com'
            : 'https://merchant.iyzipay.com';
        $panel_label = $sandbox
            ? __('Sandbox Panel', 'woo-iyzico-custom')
            : __('Live Panel', 'woo-iyzico-custom');

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo esc_html($data['title']); ?></th>
            <td class="forminp">
                <a href="<?php echo esc_url($panel_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($panel_label); ?></a>
                &middot;
                <a href="https://docs.iyzico.com" target="_blank" rel="noopener"><?php esc_html_e('Dokümantasyon', 'woo-iyzico-custom'); ?></a>
                <p class="description">
                    <?php esc_html_e('Test kartı numaraları dokümantasyon sitesindeki "Test Kartları" bölümünde — güncel liste zaman zaman değişebildiği için buraya sabit yazmadık.', 'woo-iyzico-custom'); ?>
                </p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Adds the "TC Kimlik No" field to the billing fieldset. Not tied to a
     * specific payment method — WooCommerce builds checkout fields before
     * any gateway is selected — so once enabled it appears regardless of
     * which payment method the customer ends up choosing.
     */
    public function add_identity_billing_field($fields) {
        $fields['billing_tckn'] = array(
            'label'             => __('TC Kimlik No', 'woo-iyzico-custom'),
            'type'              => 'text',
            'required'          => 'yes' === $this->get_option('identity_field_required'),
            'class'             => array('form-row-wide'),
            'priority'          => 105,
            'maxlength'         => 11,
            'custom_attributes' => array('inputmode' => 'numeric', 'pattern' => '[0-9]{11}'),
        );
        return $fields;
    }

    public function validate_identity_field() {
        if ('yes' !== $this->get_option('identity_field_required')) {
            return;
        }

        $value = isset($_POST['billing_tckn']) ? sanitize_text_field(wp_unslash($_POST['billing_tckn'])) : '';

        if ('' === $value) {
            wc_add_notice(__('Lütfen TC Kimlik No alanını doldurun.', 'woo-iyzico-custom'), 'error');
            return;
        }

        if (!self::is_valid_identity_format($value)) {
            wc_add_notice(__('TC Kimlik No 11 haneli, 0 ile başlamayan bir sayı olmalı.', 'woo-iyzico-custom'), 'error');
        }
    }

    public function save_identity_field($order_id) {
        if (!isset($_POST['billing_tckn'])) {
            return;
        }

        $value = sanitize_text_field(wp_unslash($_POST['billing_tckn']));

        if ('' === $value || !self::is_valid_identity_format($value)) {
            // Boş ya da hatalı formatlı değeri sessizce yok sayıyoruz;
            // get_identity_number() zaten standart yer tutucuya (11111111111)
            // düşecek, checkout'u bu yüzden bloklamıyoruz (validate_identity_field
            // zorunlu olduğunda zaten ayrı bir hata gösteriyor).
            return;
        }

        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_billing_tckn', $value);
            $order->save();
        }
    }

    /**
     * Sadece format kontrolü: tam 11 hane, 0 ile başlamıyor. BİLİNÇLİ
     * OLARAK resmi TCKN checksum algoritmasını (son iki hanenin ilk 9
     * haneden hesaplanan doğrulama basamakları olduğu formül) uygulamıyoruz
     * — bu, ezbere yazılırsa yanlış çıkma riski taşıyan bir matematiksel
     * kural ve yanlış uygulanırsa GERÇEK geçerli TCKN'leri bile reddedip
     * müşteriyi checkout'ta tıkatabilir (hiç doğrulama yapmamaktan daha
     * kötü bir sonuç). Tam checksum doğrulaması eklemek istersen, formülü
     * birkaç bilinen geçerli TCKN ile test ederek doğrulamamız gerekir.
     */
    private static function is_valid_identity_format($value) {
        return (bool) preg_match('/^[1-9][0-9]{10}$/', $value);
    }

    /**
     * TCKN alanı açık ve müşteri gerçek bir değer girdiyse onu kullanır.
     * Aksi halde (alan kapalı, ya da açık-zorunlu-değil-ve-boş-bırakılmış)
     * Türkiye'de yaygın kullanılan standart yer tutucuyu (11111111111)
     * döner — iyzico'nun format validasyonunu geçer, muhasebe
     * yazılımlarında TCKN bilinmediğinde kullanılan aynı konvansiyon.
     */
    private function get_identity_number($order) {
        if ('yes' === $this->get_option('identity_field_enabled')) {
            $tckn = $order->get_meta('_billing_tckn');
            if ($tckn && self::is_valid_identity_format($tckn)) {
                return $tckn;
            }
        }

        return '11111111111';
    }

    private function get_country_name($code) {
        if (function_exists('WC') && WC()->countries) {
            $countries = WC()->countries->get_countries();
            if (isset($countries[$code])) {
                return $countries[$code];
            }
        }
        return $code ? $code : 'Turkey';
    }

    private function build_address($order, $type = 'billing') {
        $address = new \Iyzipay\Model\Address();

        if ('shipping' === $type && $order->has_shipping_address()) {
            $address->setContactName(trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()));
            $address->setCity($order->get_shipping_city() ?: $order->get_billing_city());
            $address->setCountry($this->get_country_name($order->get_shipping_country() ?: $order->get_billing_country()));
            $address->setAddress(trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()) ?: trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()));
            $address->setZipCode($order->get_shipping_postcode() ?: $order->get_billing_postcode());
            return $address;
        }

        $address->setContactName(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
        $address->setCity($order->get_billing_city());
        $address->setCountry($this->get_country_name($order->get_billing_country()));
        $address->setAddress(trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()));
        $address->setZipCode($order->get_billing_postcode());
        return $address;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Sipariş bulunamadı.', 'woo-iyzico-custom'), 'error');
            return array('result' => 'failure');
        }

        if (empty($this->api_key) || empty($this->secret_key)) {
            $this->log('API key/secret ayarlanmamış, ödeme başlatılamadı.', 'error');
            wc_add_notice(__('Ödeme sistemi şu anda yapılandırılamamış durumda, lütfen bizimle iletişime geçin.', 'woo-iyzico-custom'), 'error');
            return array('result' => 'failure');
        }

        $request = new \Iyzipay\Request\CreateCheckoutFormInitializeRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId((string) $order->get_id() . '-' . uniqid());
        $request->setPrice(number_format((float) $order->get_total(), 2, '.', ''));
        $request->setPaidPrice(number_format((float) $order->get_total(), 2, '.', ''));
        $request->setCurrency(\Iyzipay\Model\Currency::TL);
        $request->setBasketId((string) $order->get_id());
        $request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
        $request->setForceThreeDS(1); // sadece 3D Secure
        $request->setCallbackUrl($this->get_callback_url());

        $buyer = new \Iyzipay\Model\Buyer();
        $buyer->setId('BY' . $order->get_id());
        $buyer->setName($order->get_billing_first_name() ?: __('Musteri', 'woo-iyzico-custom'));
        $buyer->setSurname($order->get_billing_last_name() ?: '-');
        $buyer->setIdentityNumber($this->get_identity_number($order));
        $buyer->setEmail($order->get_billing_email());
        $buyer->setGsmNumber($order->get_billing_phone());
        $buyer->setRegistrationAddress($order->get_billing_address_1() ?: __('Adres belirtilmedi', 'woo-iyzico-custom'));
        $buyer->setCity($order->get_billing_city() ?: 'Istanbul');
        $buyer->setCountry($this->get_country_name($order->get_billing_country()));
        $buyer->setZipCode($order->get_billing_postcode() ?: '00000');
        $buyer->setIp($order->get_customer_ip_address() ?: '127.0.0.1');
        $request->setBuyer($buyer);

        $request->setBillingAddress($this->build_address($order, 'billing'));
        $request->setShippingAddress($this->build_address($order, $order->has_shipping_address() ? 'shipping' : 'billing'));

        // Basit ve sağlam yaklaşım: tek satırlık "sipariş özeti" basket item.
        // iyzico, basketItems fiyat toplamının price alanına eşit olmasını
        // istiyor; ürün bazlı satır kırılımı istenirse (vergi/kargo dahil
        // toplamla tam eşleşecek şekilde) ileride genişletilebilir.
        $basketItem = new \Iyzipay\Model\BasketItem();
        $basketItem->setId('order-' . $order->get_id());
        /* translators: %s: order number */
        $basketItem->setName(sprintf(__('Siparis #%s', 'woo-iyzico-custom'), $order->get_order_number()));
        $basketItem->setCategory1(__('Genel', 'woo-iyzico-custom'));
        $basketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
        $basketItem->setPrice(number_format((float) $order->get_total(), 2, '.', ''));
        $request->setBasketItems(array($basketItem));

        try {
            $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, $this->get_iyzico_options());
        } catch (\Exception $e) {
            $this->log('CheckoutFormInitialize exception: ' . $e->getMessage(), 'error');
            wc_add_notice(__('Ödeme başlatılırken bir hata oluştu, lütfen tekrar deneyin.', 'woo-iyzico-custom'), 'error');
            return array('result' => 'failure');
        }

        if ('success' !== $checkoutFormInitialize->getStatus()) {
            $this->log('CheckoutFormInitialize failed: ' . $checkoutFormInitialize->getErrorMessage() . ' (order #' . $order->get_id() . ')', 'error');
            /* translators: %s: iyzico error message */
            wc_add_notice(sprintf(__('Ödeme başlatılamadı: %s', 'woo-iyzico-custom'), $checkoutFormInitialize->getErrorMessage()), 'error');
            return array('result' => 'failure');
        }

        // Token'ı sipariş meta'sına yaz — callback'te eşleştirme bununla yapılacak.
        $order->update_meta_data('_wic_iyzico_token', $checkoutFormInitialize->getToken());
        /* translators: %s: iyzico checkout form token */
        $order->add_order_note(sprintf(__('iyzico ödeme sayfasına yönlendirildi. Token: %s', 'woo-iyzico-custom'), $checkoutFormInitialize->getToken()));
        $order->update_status('pending', __('iyzico ödemesi bekleniyor', 'woo-iyzico-custom'));
        $order->save();

        $this->log('CheckoutForm initialized for order #' . $order->get_id() . ', token: ' . $checkoutFormInitialize->getToken());

        return array(
            'result'   => 'success',
            'redirect' => $checkoutFormInitialize->getPaymentPageUrl(),
        );
    }

    // NOT: Callback işleme mantığı artık burada değil — bkz.
    // includes/callback-handler.php > wic_process_iyzico_callback().
    // Bu class instantiation'a bağımlı olduğu için taşındı (detaylı
    // açıklama woo-iyzico-custom.php içindeki wic_maybe_handle_callback()
    // yorumunda).
}
