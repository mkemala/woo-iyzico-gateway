# WooCommerce iyzico Ödeme Ağ Geçidi (Custom)

iyzico Checkout Form (hosted, 3D Secure zorunlu) tabanlı, sıfırdan yazılmış bir WooCommerce ödeme eklentisi. Kart verisi hiçbir zaman bu sunucudan geçmez — müşteri iyzico'nun kendi ödeme sayfasına yönlenir.

**[Türkçe](#türkçe)** | **[English](#english)**

---

## Türkçe

### Neden bu plugin?

iyzico'nun resmi WooCommerce eklentisi düşük puanlı ve versiyon uyumu belirsiz. Bu plugin, iyzico'nun resmi PHP SDK'sını (bundled, Composer gerektirmez) kullanarak minimal, okunabilir ve tamamen kontrol edilebilir bir alternatif sunuyor.

### Nasıl çalışır

1. Müşteri checkout'ta "Kredi/Banka Kartı" (iyzico logo band'i ile) seçeneğini seçer, siparişi oluşturur.
2. Plugin, iyzico'ya `CheckoutFormInitialize` isteği atar (`forceThreeDS=1` — sadece 3D Secure).
3. Müşteri iyzico'nun kendi ödeme sayfasına yönlendirilir — kart bilgisi ve 3D Secure doğrulaması tamamen orada gerçekleşir.
4. iyzico, ödeme sonucunu `/api/payment/callback` adresine POST eder (token ile birlikte).
5. Plugin, o token'ı **kendi secret key'iyle iyzico'ya sorup** (server-to-server) gerçek sonucu doğrular, siparişi `processing`/`failed` yapar.

Callback endpoint'i WordPress'in rewrite/permalink sistemine **bağımlı değil** — doğrudan `REQUEST_URI` kontrolüyle `init` seviyesinde yakalanıyor. Bunun sebebi: production'da başka bir plugin'in `query_vars` filtresini ezmesi yüzünden rewrite-rule tabanlı yaklaşım güvenilmez çıktı (detaylar `woo-iyzico-custom.php` içindeki yorumda).

### Özellikler

- Hosted Checkout Form akışı — PCI-DSS yükü minimum
- Ayarlar sayfasında marka renklerine uyarlanabilir checkout görünümü (kart logoları, güven rozetleri)
- Callback URL ve sunucu çıkış IP'si için otomatik tespit + öneri paneli
- Yerleşik **sağlık kontrolü**: callback erişilebilirliği, iyzico bağlantısı, API key durumu — anlık ya da günlük otomatik (WP-Cron), sorun tespit edilirse e-posta uyarısı
- İsteğe bağlı **TCKN alanı**: checkout'a gerçek bir TC Kimlik No alanı eklenebilir (açık/kapalı + zorunlu/isteğe bağlı ayrı ayrı ayarlanabilir), kapalıyken ya da boş bırakıldığında Türkiye'de yaygın kullanılan standart yer tutucu (`11111111111`) otomatik gönderilir
- Tüm kullanıcıya görünen metinler WordPress i18n standardına (`__()`/`_e()`) uygun, `languages/woo-iyzico-custom.pot` şablonu dahil

### Kurulum

1. Bu klasörü zip'leyip **WP Admin > Eklentiler > Yeni Ekle > Eklenti Yükle**'den yükle, ya da `/wp-content/plugins/` altına doğrudan aç.
2. Eklentiyi aktifleştir.
3. `https://siteniz.com/api/payment/callback` adresine tarayıcıdan GET isteği at — sitenin ana sayfasına/sepete yönlenmelisin, gerçek bir 404 GÖRMEMELİSİN.
4. Full-page cache kullanan bir hosting'teysen (SiteGround SG Optimizer vb.), ilk testten sonra cache'i temizle.
5. **WooCommerce > Ayarlar > Ödemeler > "iyzico (Custom / 3D Secure)"** satırına gir.
6. Sandbox modunu AÇIK bırak, test API key/secret'larını gir, kaydet.
7. Ayarlar sayfasındaki "Sunucu Çıkış IP Adresi" alanından **Tespit Et**'e bas, bulunan IP'yi ve Callback URL'i iyzico panelinde **Ayarlar > IP/Back URL Yönetimi**'ne ekleyip onaya gönder.
8. Sandbox test kartlarıyla uçtan uca bir sipariş tamamla.
9. Onay tamamlanmadan LIVE modda gerçek işlem denemeyin.
10. Her şey sorunsuzsa: sandbox modunu kapat, live key/secret'ları gir, küçük tutarlı bir gerçek işlemle tekrar test et.

### Bilinen sınırlamalar

- `Buyer.identityNumber` (TCKN) alanı artık checkout'a isteğe bağlı bir alan olarak eklenebiliyor (Ayarlar > "TCKN Alanını Göster" / "TCKN Girişini Zorunlu Kıl"). Kapalıyken veya boş bırakıldığında, iyzico'nun kabul ettiği ve Türkiye'de yaygın kullanılan standart yer tutucu (`11111111111`) gönderiliyor — Türkiye'deki birçok muhasebe/faturalama yazılımının da kullandığı aynı konvansiyon. Not: bu alanın format kontrolü var (11 hane, 0 ile başlamıyor) ama resmi TCKN checksum algoritması uygulanmıyor.
- Basket items tek satır "sipariş özeti" olarak gönderiliyor, ürün bazlı kırılım yok.
- HMAC signature doğrulaması (`CheckoutForm::getSignature()`) kullanılmıyor — server-to-server retrieve zaten güvenli, ek bir katman istenirse eklenebilir.

### Yol haritası

**Ücretsiz (bu repo, WordPress.org):** Sadece iyzico, mevcut özellik seti — hosted Checkout Form, sağlık kontrolü, domain/IP paneli. Bu her zaman ücretsiz ve açık kaynak kalacak.

**Planlanan Pro sürüm (ayrı, ücretli):**
- Çoklu provider desteği (PayTR, Craftgate, Param vb.) — `WC_Gateway_Hosted_Checkout_Base` soyutlaması üzerinden
- Ürün bazlı satır kırılımlı basket (tek satır özet yerine)
- Öncelikli destek
- Fiyatlandırma fikri: $9.99/yıl ya da $29.90 lifetime (kesinleşmedi)

Bilinçli olarak erken genelleştirmedik (erken soyutlama genelde yanlış soyutlamaya çıkar) — ücretsiz sürümde gerçek kullanıcı geri bildirimi toplandıktan sonra Pro'nun kapsamı netleşecek.

### Lisans

MIT — bkz. [LICENSE](LICENSE). Bundled iyzico SDK ayrıca kendi MIT lisansıyla geliyor (`vendor/iyzico/iyzipay-php/LICENSE`).

---

## English

### Why this plugin?

iyzico's official WooCommerce plugin has low ratings and uncertain version compatibility. This plugin uses iyzico's official PHP SDK (bundled, no Composer required on the server) to offer a minimal, readable, fully auditable alternative.

### How it works

1. The customer selects the "Kredi/Banka Kartı" payment method (shown with iyzico's official logo band) at checkout and places the order.
2. The plugin sends a `CheckoutFormInitialize` request to iyzico (`forceThreeDS=1` — 3D Secure only, non-3DS is disabled).
3. The customer is redirected to iyzico's own hosted payment page — card data and 3D Secure verification happen entirely there. No card data ever touches this server.
4. iyzico POSTs the payment result to `/api/payment/callback` (with a token).
5. The plugin looks up that token **against iyzico itself, server-to-server, using its own secret key** — it never trusts the POST body blindly — then marks the order `processing`/`failed` accordingly.

The callback endpoint intentionally does **not** rely on WordPress's rewrite/permalink system. It's caught via a direct `REQUEST_URI` check at the `init` hook instead. Reason: in production, another active plugin was silently stripping our custom `query_vars` entry, which made the rewrite-rule based approach unreliable (see the comment in `woo-iyzico-custom.php` for the full story).

### Features

- Hosted Checkout Form flow — minimal PCI-DSS scope
- Brand-matchable checkout UI (card logos, trust badges) configurable via CSS
- Auto-detection panel for the callback URL and the server's outbound IP, ready to paste into iyzico's dashboard
- Built-in **health check system**: verifies callback reachability, connectivity to iyzico, and API key presence — on demand or daily via WP-Cron, with email alerts on status change
- Optional **TCKN (Turkish national ID) field**: adds a real ID field to checkout, independently toggleable as shown/hidden and required/optional; when off or left blank, sends the standard Turkish placeholder (`11111111111`) automatically
- All user-facing strings follow WordPress i18n conventions (`__()`/`_e()`), including a `languages/woo-iyzico-custom.pot` template. Source strings are Turkish (iyzico is a Turkey-only payment provider, so that's the realistic user base) — but the plugin is translation-ready for anyone who wants to contribute an English `.po`/`.mo` file.

### Installation

1. Zip this folder and upload it via **WP Admin > Plugins > Add New > Upload Plugin**, or extract it directly into `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit `https://yoursite.com/api/payment/callback` in a browser — it should redirect to your homepage/cart, NOT show a real 404.
4. If you're on a host with full-page caching (e.g. SiteGround SG Optimizer), purge the cache after the first test.
5. Go to **WooCommerce > Settings > Payments > "iyzico (Custom / 3D Secure)"**.
6. Keep sandbox mode ON, enter your test API key/secret, save.
7. Use the "Detect" button next to "Server Outbound IP Address" in settings, then add both the detected IP and the callback URL to iyzico's dashboard under **Settings > IP/Back URL Management**, and submit for approval.
8. Complete a full test order using iyzico's sandbox test cards.
9. Do not attempt real transactions in LIVE mode before that approval completes.
10. Once everything works: turn sandbox mode off, enter live key/secret, and do one small real-money test transaction before announcing.

### Known limitations

- `Buyer.identityNumber` (Turkish national ID) can now be collected via an optional checkout field (Settings > "Show TCKN Field" / "Require TCKN"). When off, or left blank, the plugin sends the standard placeholder (`11111111111`) accepted by iyzico — the same convention widely used in Turkish accounting/invoicing software. Note: basic format validation only (11 digits, no leading zero) — the official TCKN checksum algorithm isn't implemented.
- Basket items are sent as a single "order summary" line rather than broken out per product.
- HMAC signature verification (`CheckoutForm::getSignature()`) isn't used — the server-to-server retrieve is already secure on its own, but this could be added as an extra layer.

### Roadmap

**Free (this repo, WordPress.org):** iyzico only, current feature set — hosted Checkout Form, health checks, domain/IP panel. This will always stay free and open source.

**Planned Pro version (separate, paid):**
- Multi-provider support (PayTR, Craftgate, Param, etc.) via a `WC_Gateway_Hosted_Checkout_Base` abstraction
- Per-product basket line items (instead of a single order-summary line)
- Priority support
- Rough pricing idea: $9.99/year or $29.90 lifetime (not finalized)

We intentionally didn't generalize early (premature abstraction usually produces the wrong abstraction) — the Pro scope will firm up once the free version has real user feedback behind it.

### License

MIT — see [LICENSE](LICENSE). The bundled iyzico SDK ships under its own MIT license (`vendor/iyzico/iyzipay-php/LICENSE`).
