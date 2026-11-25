# Profil staging Promerty di Rumahweb

23 November 2026 WIB. **Rancangan offline. Production NO-GO.** Domain, produk, lokasi server, dan OS masih keputusan owner. VPS Linux berbasis KVM dapat dievaluasi jika mendukung konfigurasi web, worker, cron, penyimpanan, dan pemulihan yang diperlukan. [VPS Rumahweb](https://www.rumahweb.com/vps-murah/)

- Verifikasi PHP 8.4 yang mendapat patch, Laravel 12 sesuai lockfile, ctype, cURL, DOM, fileinfo, filter, hash, mbstring, OpenSSL, PCRE, PDO dengan mysqlnd, session, tokenizer, XML, dan GD untuk upload. Direktori layanan web menunjuk ke `public/`. [Laravel deployment](https://laravel.com/framework/docs/12.x/deployment)
- Jalankan worker dan scheduler dengan identitas minimum, cron sesuai kebutuhan scheduler, supervisi job dan restart worker per rilis. Pilih cache/queue bersama berdasarkan jumlah instans. Jika memilih Redis, batasi jaringan dan identitasnya.
- Gunakan storage privat. Jalur multipart lama tetap maksimal 100 MiB per video. Jalur resumable memiliki plafon **750.000.000 byte** dan tetap nonaktif sampai staging disetujui. Uji chunk multipart 4 MiB beserta overhead, jalur foto hingga 20 MiB, ruang sementara, hard link antara staging dan media pada volume yang sama, serta HTTP Range. Optimasi lossless memakai antrean terpisah dan memerlukan worker yang tervalidasi. [Bukti kapasitas media](../qa/promerty-media-capacity-test-results.md)
- Gunakan [template Nginx Direct HTTPS](nginx.conf.template) bila topology benar-benar Nginx menuju PHP-FPM langsung. Jika memakai CDN atau proxy, tentukan daftar trust proxy aktual. Uji `nginx -t` pada hasil render, TLS, host/SAN/renewal, cookies, CSP, CSRF, CORS, rate limiting, serta OAuth `no-referrer`. Health `/up` membuktikan boot aplikasi. Periksa database, storage, dan worker melalui monitor privat.
- Log access hanya metode, status, ukuran, dan waktu. Log error memerlukan redaksi, ACL, rotasi, dan retensi. Uji respons kegagalan dengan debug OFF. Semua aplikasi lama dan konfigurasi yang berjalan berada di luar rancangan ini.

## Profil konfigurasi untuk dirender

Nilai berikut semuanya placeholder. Secret diinjeksi melalui penyimpanan yang disetujui pada host. Jangan menambahkan credential staging/produksi pada repository.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain-yang-disetujui>
APP_KEY=<secret-store>
DB_CONNECTION=mariadb
DB_HOST=<identitas-database-sesuai-SAN>
DB_PORT=<port-terisolasi>
DB_DATABASE=<schema-yang-disetujui>
DB_USERNAME=<akun-runtime-hak-minimum>
DB_PASSWORD=<secret-store>
MYSQL_ATTR_SSL_CA=<path-CA-yang-diverifikasi>
SESSION_COOKIE=<nama-khusus-origin>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
SANCTUM_STATEFUL_DOMAINS=<domain-yang-disetujui>
CACHE_STORE=database
PASSWORD_LOGIN_CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
LOG_CHANNEL=daily
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=<port-SMTP-loopback-yang-diverifikasi>
MAIL_FROM_ADDRESS=<pengirim-domain-yang-diverifikasi>
GOOGLE_REDIRECT_URI=https://<domain-yang-disetujui>/auth/google/callback
GOOGLE_CLIENT_ID=<provider-config>
GOOGLE_CLIENT_SECRET=<secret-store>
PROMERTY_SECURITY_QA_ROLLOUT=false
```

Verifikasi hak runtime queue/cache/session sesuai backend yang dipilih. Jalankan negative test CA salah, host salah, kedaluwarsa, dan plaintext dengan klien/PDO yang dipakai aplikasi. Buktikan cipher/protokol serta batas akun dengan koneksi aktual. Tentukan rollout session revocation dan audit setelah uji staging. MFA tetap OFF. Client Google produksi memerlukan domain HTTPS final, konfigurasi terpisah dari localhost, dan pengujian state, callback, login ulang, serta logout.

Limiter password memerlukan penyimpanan database dengan tabel `cache` dan `cache_locks`, atau Redis. Semua worker harus memakai store, namespace, dan APP_KEY yang sama serta jam yang sinkron. Untuk checker statis, masukkan driver efektif `cache_driver`, `rate_limit_cache_driver` dan `password_login_cache_driver`. Yang kedua mengikuti override `cache.limiter` bila disetel. Yang ketiga mengikuti `PASSWORD_LOGIN_CACHE_STORE` bila disetel. Jika tidak ada override, isikan driver efektif dari cache default. Gunakan nilai driver yang sudah diresolusikan dari nama store, tanpa memberikan konfigurasi rahasia. Uji lima kegagalan lintas worker, HTTP 429, kedaluwarsa, dan gangguan cache pada staging. Pengujian lokal belum mencakup topologi Rumahweb. [Laravel cache locks](https://laravel.com/docs/12.x/cache#atomic-locks)

Profil tanpa biaya layanan tambahan memakai `media_driver=local`, daftar `log_drivers` yang sudah diresolusikan (misalnya `["daily"]`), `mail_transport=smtp`, `smtp_host=127.0.0.1` atau `::1`, dan `smtp_url_configured=false`. Hapus `MAIL_URL` dari profil rilis agar tidak menimpa host. Postfix pada VPS merupakan kandidat relay lokal yang belum diinstal. Verifikasi egress TCP 25, PTR, SPF, DKIM, DMARC, pencegahan open relay, reputasi IP, penerimaan reset dan redaksi log sebelum menyatakan pengiriman tersedia. Jika pengiriman langsung tidak layak, gunakan review terpisah sebelum menambahkan relay eksternal. [Lisensi Postfix](https://www.postfix.org/announcements/postfix-3.3.0.html)

## Pemilihan paket dan kapasitas

| Pilihan | Pemeriksaan sebelum memilih |
|---|---|
| Shared hosting | Minta bukti PHP 8.4 dan ekstensi, document root ke `public/`, proses worker yang dapat dipertahankan, cron, akses ke log dan kemampuan memasang konfigurasi HTTPS serta TLS database. |
| VPS KVM | Pilih OS yang masih didukung dan konfirmasi kuota CPU/RAM/disk, kapasitas I/O, bandwidth, lokasi, alamat publik, layanan backup pelanggan dan tanggung jawab patching. Staging bisa dimulai dengan perkiraan 2 vCPU, 4 GiB RAM dan 40 GiB SSD untuk Laravel, satu worker dan database uji. Angka ini asumsi awal tanpa transcoding. Ukur penggunaan sebelum memilih kapasitas produksi. |

Ukur disk untuk OS, rilis aktif dan rollback, database, aset dan sumber media, upload sementara, log, serta ruang restore. Hitung ruang upload sementara dari batas per request, konkurensi yang diizinkan dan penggunaan PHP/FPM. Uji antrean, waktu respons dan penggunaan disk di bawah beban staging. Tetapkan kapasitas dan biaya setelah data beban tersedia.

Inventaris paket rilis harus mengecualikan fixture, backup, `.env` lokal, credential QA dan artefak bukti. Aplikasi memakai `/storage/` untuk aset dari disk public yang dipublikasikan secara sengaja. Periksa bahwa symlink tujuan hanya mengarah ke disk public, bahwa allowlist file publik cocok, dan storage privat serta media yang belum diterbitkan tetap memerlukan otorisasi.

Rumahweb mendeskripsikan backup internal VPS untuk pemulihan gangguan hardware. Verifikasi opsi backup yang dapat dipulihkan oleh pelanggan secara terpisah. Untuk profil Rp0 tambahan, prioritaskan media penyimpanan milik Owner yang sudah tersedia di lokasi terpisah. Aksesnya harus privat, ciphertext diperiksa setelah salin, dan jadwal retensi, kustodian serta uji restore harus dibuktikan. Cloud berbayar tetap di luar profil. [Rumahweb VPS KVM](https://www.rumahweb.com/vps-murah/)

## Rollback

Simpan manifest rilis, rilis sebelumnya, migrasi yang kompatibel mundur, dan smoke test health/session/queue/media. Jika smoke gagal, hentikan rollout, pulihkan rilis sehat yang kompatibel dan restart worker. Rollback aplikasi tidak menjalankan downgrade data. Verifikasi backup terenkripsi, hash readback offsite, custody passphrase terpisah, dan latihan restore terisolasi sebelum DNS/cutover.
