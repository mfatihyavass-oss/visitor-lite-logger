# Visitor Lite Logger

**Geliştirici:** Maya Hukuk  
**Sürüm:** 3.1.0

## Açıklama
Visitor Lite Logger, WordPress siteleri için hafif ve asenkron çalışan bir ziyaretçi kayıt eklentisidir. Amaç, harici servis kullanmadan temel ziyaret bilgisini veritabanına güvenli ve performans dostu şekilde kaydetmektir.

## Özellikler
- Tek dosyalı eklenti yapısı (`visitor-lite-logger.php`)
- CSP uyumlu frontend akışı: JS kodu REST endpoint üzerinden (`/wp-json/visitor-lite-logger/v1/script.js`) harici script olarak sunulur
- `sendBeacon` önceliği, destek yoksa `fetch + keepalive` fallback
- Sayfada kalma süresi ölçümü (milisaniye)
- Sadece giriş yapmamış ziyaretçiler için çalışma
- Bot/crawler filtreleme (case-insensitive)
- Nonce doğrulamalı REST endpoint
- Transient tabanlı tekrar kayıt engelleme (IP + URL, varsayılan 10 dk)
- Ayarlar paneli:
  - Saklama süresi (gün)
  - Throttle süresi (saniye)
  - IP anonimleştirme (varsayılan açık)
  - Kaldırmada veriyi sil (varsayılan kapalı)
- `WP_List_Table` tabanlı gelişmiş yönetim listesi:
  - Sayfalama (varsayılan 50 kayıt/sayfa)
  - Sıralama (Tarih, IP, URL)
  - Arama (IP, URL, User-Agent)
  - Tarih aralığı filtreleme (Başlangıç/Bitiş)
  - Sayfada kalma süresi kolonu
- CSV dışa aktarma (büyük veri için chunking ile bellek dostu)
- Günlük cron ile eski kayıt temizliği

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
- Frontend’de script, REST üzerinden dinamik JS olarak yüklenir (CSP uyumuna yardımcı olur).
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
- Süre ayarlar panelinden değiştirilebilir

## Admin paneli
Yönetim ekranı **Araçlar > Ziyaretçi Kayıtları** altında bulunur (`manage_options` gerekir).

Sayfada:
- Modern özet kartları:
  - Toplam kayıt sayısı
  - Son 24 saatteki kayıt sayısı
  - En eski kayıt tarihi
- `WP_List_Table` tabanlı kayıt tablosu:
  - Tarih, IP, URL alanlarında sıralama
  - IP/URL/User-Agent arama kutusu
  - Başlangıç/Bitiş tarih filtreleme
  - Sayfalama (varsayılan 50 kayıt/sayfa)
- `CSV Olarak İndir` butonu:
  - Aktif arama + tarih filtresiyle uyumlu export
  - Büyük veri setlerinde parçalı (chunked) yazma ile bellek dostu çıktı

Uzun alanlar kısaltılarak gösterilir. URL/referrer alanları güvenli şekilde tıklanabilir bağlantı olarak gösterilebilir.

## Otomatik temizlik
- Aktivasyonda günlük cron planlanır.
- Her gün saklama süresini aşan kayıtlar silinir (varsayılan 30 gün).
- Deaktivasyonda cron event temizlenir.
- Temizlik sorgusu güvenli SQL (`prepare`) ile çalışır.

## Güvenlik
- REST endpoint: `/wp-json/visitor-lite-logger/v1/log`
- Nonce doğrulaması zorunludur.
- Geçersiz/eksik nonce durumunda kayıt yapılmaz.
- Sunucu tarafında IP ve user-agent yalnızca `$_SERVER` üzerinden alınır.
- URL verisi `esc_url_raw`, metin verileri `sanitize_text_field` ile temizlenir.
- Uzun alanlar makul limitlerde kısaltılır.
- Veritabanına kayıt için `$wpdb->insert` kullanılır.
- Admin çıktıları `esc_html`, `esc_url`, `esc_attr` ile escape edilir.
- Admin liste sorguları ve export sorguları `prepare` ile güvenli hazırlanır.

## Gizlilik / KVKK notu
Eklenti IP adresi kaydı tutar. Kod içinde şu not bulunmaktadır:

> “IP adresi KVKK/GDPR kapsamında kişisel veri sayılabilir. Kullanım öncesinde gizlilik politikası ve aydınlatma metni buna göre değerlendirilmelidir.”

IP anonimleştirme panelden yönetilir (varsayılan açık):
- IPv4: son oktet `0` yapılır
- IPv6: son segmentler sıfırlanır

## Performans notları
- Harici servis, tracking pixel, dış API yoktur.
- Frontend’de ek CSS dosyası yoktur.
- Ziyaret log insert işlemi render sırasında yapılmaz.
- Endpoint içinde sadece gerekli doğrulama/filtre/throttle/insert adımları vardır.
- Yönetim listesi sayfalamalıdır; tüm kayıtlar tek seferde yüklenmez.
- CSV export, büyük veri için chunking (`LIMIT/OFFSET`) ile çalışır.
- Temizlik cron ile arka planda yapılır.

## Kaldırma
- Uninstall hook vardır.
- Varsayılan davranış: eklenti kaldırıldığında tablo otomatik silinmez.
- Ayarlarda **Kaldırmada Veriyi Sil** açılırsa uninstall sırasında tablo + eklenti ayarları kaldırılır.

## Geliştirme önerileri
- Yönetim paneline günlük/haftalık trend grafikleri eklenebilir.
- CSV dışında JSON/TSV export seçenekleri eklenebilir.
- Gelişmiş bot imza listesi filtrelenebilir hale getirilebilir.
- Çok yüksek trafikli siteler için periyodik toplu yazma (batch) yaklaşımı değerlendirilebilir.
