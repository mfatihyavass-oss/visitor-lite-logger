# Visitor Lite Logger

**Geliştirici:** Maya Hukuk

## Açıklama
Visitor Lite Logger, WordPress siteleri için hafif ve asenkron çalışan bir ziyaretçi kayıt eklentisidir. Amaç, harici servis kullanmadan temel ziyaret bilgisini veritabanına güvenli ve performans dostu şekilde kaydetmektir.

## Özellikler
- Tek dosyalı eklenti yapısı (`visitor-lite-logger.php`)
- Harici JS/CSS dosyası olmadan inline JavaScript ile asenkron log gönderimi
- `sendBeacon` önceliği, destek yoksa `fetch + keepalive` fallback
- Sadece giriş yapmamış ziyaretçiler için çalışma
- Bot/crawler filtreleme
- Nonce doğrulamalı REST endpoint
- Transient tabanlı tekrar kayıt engelleme (IP + URL, 10 dk)
- Yönetim panelinde son 100 kayıt görüntüleme
- Günlük cron ile 30 günden eski kayıtları temizleme
- Opsiyonel IP anonimleştirme desteği (filtre ile)

## Kurulum
1. `visitor-lite-logger.php` dosyasını `wp-content/plugins/visitor-lite-logger/` klasörüne koyun.
2. WordPress yönetim panelinden eklentiyi etkinleştirin.
3. Etkinleştirme sırasında özel tablo oluşturulur ve günlük temizlik görevi planlanır.

## Dosya yapısı
```text
visitor-lite-logger/
├─ visitor-lite-logger.php
└─ README.md
```

## Veritabanı tablosu
Eklenti aktivasyonunda `dbDelta` ile şu tablo oluşturulur:

- Tablo adı: `$wpdb->prefix . 'visitor_logs'`
- Alanlar:
  - `id` (BIGINT UNSIGNED, AUTO_INCREMENT, PK)
  - `visitor_ip` (VARCHAR(45), NOT NULL)
  - `visited_url` (TEXT, NOT NULL)
  - `user_agent` (TEXT, NULL)
  - `referrer` (TEXT, NULL)
  - `page_title` (TEXT, NULL)
  - `visit_time` (DATETIME, NOT NULL)
- İndeksler:
  - `KEY visit_time (visit_time)`
  - `KEY visitor_ip (visitor_ip)`

## Asenkron çalışma mantığı
- Sayfa render aşamasında PHP tarafında veritabanına insert yapılmaz.
- Uygun public frontend sayfalarında küçük bir inline script yüklenir.
- Script, sayfa yüklendikten sonra `requestIdleCallback` (varsa) veya kısa `setTimeout` ile çalışır.
- İstek gönderim sırası:
  1. `navigator.sendBeacon`
  2. `fetch` (`keepalive: true`)
- Veri gönderimi:
  - `visited_url`
  - `page_title`
  - `referrer`
  - `nonce`

## Bot filtreleme
Boş user-agent veya bilinen bot imzalarında kayıt tutulmaz. Filtreleme case-insensitive yapılır.

Örnek bot imzaları:
- `googlebot`, `bingbot`, `yandex`, `baiduspider`, `duckduckbot`
- `slurp`, `facebookexternalhit`, `twitterbot`, `linkedinbot`
- `ahrefsbot`, `semrushbot`, `mj12bot`, `dotbot`, `petalbot`
- `applebot`, `bytespider`, `crawler`, `spider`, `bot`

## Tekrar kayıt engelleme
Transient tabanlı throttle uygulanır:

- Anahtar: `vll_seen_` + `md5($ip . '|' . $url)`
- Varsayılan süre: 600 saniye (10 dakika)
- Süre filtre ile değiştirilebilir:
  - `vll_throttle_seconds`

## Admin paneli
Yönetim ekranı **Araçlar > Ziyaretçi Kayıtları** altında bulunur (`manage_options` gerekir).

Sayfada:
- Üstte özet kutusu:
  - Toplam kayıt sayısı
  - Son 24 saatteki kayıt sayısı
  - En eski kayıt tarihi
- Altta son 100 kayıt tablosu:
  - Tarih
  - IP
  - Ziyaret Edilen URL
  - Sayfa Başlığı
  - Referrer
  - User-Agent

Uzun alanlar kısaltılarak gösterilir. URL/referrer alanları güvenli şekilde tıklanabilir bağlantı olarak gösterilebilir.

## Otomatik temizlik
- Aktivasyonda günlük cron planlanır.
- Her gün 30 günden eski kayıtlar silinir.
- Deaktivasyonda cron event temizlenir.
- Temizlik sorgusu güvenli SQL (`prepare`) ile çalışır.
- Gün sayısı filtre ile değiştirilebilir:
  - `vll_retention_days`

## Güvenlik
- REST endpoint: `/wp-json/visitor-lite-logger/v1/log`
- Nonce doğrulaması zorunludur.
- Geçersiz/eksik nonce durumunda kayıt yapılmaz.
- Sunucu tarafında IP ve user-agent yalnızca `$_SERVER` üzerinden alınır.
- URL verisi `esc_url_raw`, metin verileri `sanitize_text_field` ile temizlenir.
- Uzun alanlar makul limitlerde kısaltılır.
- Veritabanına kayıt için `$wpdb->insert` kullanılır.
- Admin çıktıları `esc_html`, `esc_url`, `esc_attr` ile escape edilir.

## Gizlilik / KVKK notu
Eklenti IP adresi kaydı tutar. Kod içinde şu not bulunmaktadır:

> “IP adresi KVKK/GDPR kapsamında kişisel veri sayılabilir. Kullanım öncesinde gizlilik politikası ve aydınlatma metni buna göre değerlendirilmelidir.”

Opsiyonel IP anonimleştirme filtre ile açılabilir:

- `vll_anonymize_ip` (varsayılan: kapalı / `false`)
- IPv4: son oktet `0` yapılır
- IPv6: son segmentler sıfırlanır

## Performans notları
- Harici servis, tracking pixel, dış API yoktur.
- Frontend’de ek CSS dosyası yoktur.
- Frontend’de harici JS dosyası yoktur.
- Sayfa render anında veritabanı insert yapılmaz.
- Endpoint içinde sadece gerekli doğrulama/filtre/throttle/insert adımları vardır.
- Admin listesi son 100 kayıtla sınırlıdır.
- Temizlik cron ile arka planda yapılır.

## Kaldırma
- Uninstall hook vardır.
- Varsayılan davranış: eklenti kaldırıldığında tablo otomatik silinmez.
- Bu sayede yanlışlıkla devre dışı bırakma/silme senaryolarında log kaybı riski azaltılır.
- Kod içinde not:
  - “Tam kaldırma istenirse tablo drop işlemi burada aktif edilebilir.”

## Geliştirme önerileri
- Ayarlar ekranı eklenerek throttle süresi ve kayıt saklama süresi panelden yönetilebilir.
- CSV dışa aktarma özelliği eklenebilir.
- IP anonimleştirme için yönetim paneli toggle’ı eklenebilir.
- Gelişmiş bot imza listesi filtrelenebilir hale getirilebilir.
- Çok yüksek trafikli siteler için periyodik toplu yazma (batch) yaklaşımı değerlendirilebilir.
