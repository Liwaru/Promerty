# Promerty di VPS Rumahweb dengan Docker

Paket ini menyiapkan staging dan serah terima. Build dan smoke test lokal tidak membuka layanan publik atau mengimpor data asli.

## Arsitektur dan versi

| Komponen | Konfigurasi |
|---|---|
| Web | Nginx Stable Alpine unprivileged, port container 8080 dan publish ke `127.0.0.1` pada host |
| Aplikasi | PHP-FPM 8.4 Bookworm, UID/GID 10001, Laravel 12 dari `composer.lock` |
| Build | Composer 2 dengan dependensi produksi saja, Node 22 dengan `npm ci` dan Vite 7.3.8 |
| Worker | Image PHP yang sama, antrean database `default,media`, timeout 120 detik, retry-after 150 detik |
| Scheduler | Source saat ini belum mendaftarkan tugas terjadwal. Tambahkan satu proses `schedule:work` ketika tugas terjadwal tersedia. |
| Database | MariaDB via TCP privat. Compose utama menerima nama host dan CA dari operator. Overlay smoke menyediakan MariaDB 11.4 sintetis tanpa port host. |
| Storage | Dua volume eksternal Compose. Nginx hanya menerima mount public-media read-only. MP4 listing berada pada storage privat PHP. |

PHP menyediakan PDO MySQL, GD dengan JPEG/PNG/WebP, OPcache, PCNTL, dan modul Laravel yang dibuktikan dengan `composer check-platform-reqs` pada build. Optimasi video existing memakai kode PHP. Paket ini tidak memerlukan FFmpeg. Tag base image mengikuti seri yang dipilih. Rekam digest base image dan image rilis yang disetujui untuk reproduksi serta rollback. [PHP](https://hub.docker.com/_/php) · [Node](https://hub.docker.com/_/node) · [Composer](https://hub.docker.com/_/composer) · [NGINX Unprivileged](https://hub.docker.com/r/nginx/nginx-unprivileged) · [MariaDB](https://hub.docker.com/_/mariadb)

## Build dan jalankan staging

Gunakan Docker Engine Linux dan plugin Compose di VPS. Salin contoh Compose ke lokasi konfigurasi di luar checkout. Nilainya adalah metadata rilis, nama domain, alamat proxy yang tepercaya, path runtime, CA, port loopback dan nama kedua volume. Simpan konfigurasi privat di luar checkout, misalnya `/etc/promerty/staging-runtime.env`. Batasi direktori host ke operator berwenang. File runtime harus bisa dibaca UID 10001 di dalam container, misalnya mode 0440 dengan grup host yang sesuai dengan GID container. Mount runtime, CA, dan data berada di luar image.

1. Isi [contoh runtime](../../deploy/docker/runtime.env.example) secara privat. Buat APP_KEY acak 32 byte untuk staging sintetis dan pertahankan key-nya antar-rilis lingkungan tersebut. Untuk pemindahan data asli, inventaris kolom/ciphertext yang bergantung pada APP_KEY sebelum memutuskan pemakaian key lama atau rotasi terkontrol. Isi host MariaDB sesuai SAN sertifikat, schema staging, akun runtime non-root, dan password dari penyimpanan privat. Jangan set `DB_URL` atau `DB_SOCKET`. Siapkan tabel database bagi session, cache/cache locks, queue dan rate-limiter. Akun migrasi terpisah hanya dipakai selama migrasi yang disetujui.
2. Siapkan reverse proxy HTTPS di host yang meneruskan ke `http://127.0.0.1:8080`. Redirect HTTP ke HTTPS pada edge, pertahankan `Host` yang disetujui, dan **timpa** `X-Forwarded-For` dengan IP klien terverifikasi. Isi `PROMERTY_TRUSTED_PROXY` dengan IP/CIDR sesempit mungkin dari peer edge yang terlihat Nginx di container. Uji dua IP asli dan spoofed header. Port 8080 tetap loopback dan hanya untuk edge serta operator lokal. Akses TLS browser berhenti di edge, dan Nginx meneruskan status HTTPS ke Laravel sebagai parameter FastCGI. Jangan atur wildcard trust proxy.
3. Siapkan dua volume staging baru secara eksplisit. Isi `PROMERTY_STORAGE_VOLUME` dan `PROMERTY_PUBLIC_VOLUME` sesuai nama yang dibuat. Volume lama harus sudah memberi UID/GID 10001 akses yang diperlukan ke storage. Volume media publik diisi hanya dengan gambar yang memang boleh diakses publik. Untuk referensi media legacy dengan path lain, audit dan sesuaikan allowlist sebelum trafik diaktifkan.

```sh
docker volume create promerty-staging-storage
docker volume create promerty-staging-public
docker compose --env-file /etc/promerty/staging-compose.env -p promerty-staging config --quiet
docker compose --env-file /etc/promerty/staging-compose.env -p promerty-staging build --pull app web
# Jalankan setelah target DB staging dan volume siap
docker compose --env-file /etc/promerty/staging-compose.env -p promerty-staging up -d --no-build --wait
docker compose --env-file /etc/promerty/staging-compose.env -p promerty-staging exec web nginx -t
```

Untuk MariaDB di host VPS, gunakan alamat/DNS privat yang dapat dirutekan dari container PHP dan sesuai SAN. Firewall host menerima TCP hanya dari subnet/identitas yang ditinjau. Untuk MariaDB di container, hubungkan layanan database ke jaringan runtime privat dengan alias sesuai SAN dan volume database terpisah. Gunakan akun runtime least privilege dan pisahkan akun migrasi/provisioning. Jangan tambahkan `ports` database. Uji koneksi PDO aktual, cipher, CA salah, hostname salah, dan penolakan plaintext sebelum membuka trafik. Untuk TLS yang memakai CA privat, isi file CA pada path host `PROMERTY_DB_CA_FILE` dan pertahankan verifikasi identitas. 

Mailer `array` aman untuk pengujian tanpa pengiriman. Untuk produksi, isi SMTP relay yang telah disetujui menggunakan alamat privat yang benar-benar dapat dijangkau dari container. Loopback di container merujuk ke container itu sendiri. Uji pengiriman dan pemulihan sebelum rollout OTP. Gunakan stdout/stderr dengan rotasi Compose dan akses operator terbatas. Access log Nginx berisi metode, status, ukuran, dan waktu. Perlakukan error log sebagai sensitif karena upstream dapat menambahkan URL pada kegagalan. Hindari mencetak konfigurasi runtime, token dan kredensial saat diagnosis.

## Smoke test lokal dengan MariaDB sintetis

Gunakan nama proyek unik, port loopback kosong, dua volume kosong baru, dan file konfigurasi privat yang berbeda dari staging. Sertakan kedua Compose file dengan `-f compose.yaml -f compose.smoke.yaml`. Overlay menerima hanya `qa-db`, schema `promerty_docker_smoke`, akun `promerty_smoke`, URL HTTP loopback, dan mailer `array`. Simpan APP_KEY dan password acak lokal dalam file runtime/secrets sintetis privat. Isi `PROMERTY_SMOKE_DB_PASSWORD_FILE` dan `PROMERTY_SMOKE_ROOT_PASSWORD_FILE` dengan path masing-masing. Set `PROMERTY_DB_CA_FILE` ke file biasa sintetis yang tersedia untuk mount pengujian. Smoke HTTP lokal tidak membuktikan TLS MariaDB produksi.

Mulai hanya `qa-db` dan tunggu health check. Verifikasi schema sintetis kosong sebelum memuat baseline dari checkout. Laravel menamai schema dump sesuai nama koneksi. Repo memiliki `database/schema/mysql-schema.sql`, sehingga profil `mariadb` tidak memuatnya otomatis. Baseline tersebut hanya untuk schema baru yang disetujui dan tidak masuk image. Sesudah baseline, tinjau dan jalankan migration target dengan akun migrasi. Untuk hasil restore asli, pakai riwayat `migrations` yang sudah diimpor dan tinjau hanya migration yang tertunda.

Setelah semua layanan hidup, periksa `/up`, halaman beranda, `/search`, dan CSS/JS pada `public/build/manifest.json`. Pastikan `/.env` serta akses langsung ke storage privat menghasilkan 404, sementara gambar publik dari volume public-media tersedia. Verifikasi video sintetis aktif lewat URL Laravel `/api/listing-media/…`, respons `206` dengan `Content-Range`, serta penolakan bagi media draft. Uji proses worker lewat satu job sintetis dan cek job selesai tanpa `failed_jobs`. Ulangi setelah restart container dan pastikan volume lama tetap terpasang.

## Media dan batas request

Paket menetapkan batas 128 MiB per request pada edge, Nginx container dan PHP POST. Batas multipart per file PHP 100 MiB, dengan batas foto aplikasi 20 MiB. Untuk batch foto dengan total besar gunakan beberapa request. Target video **750.000.000 byte** memakai jalur resumable dengan chunk **4 MiB**. Aktifkan `AGENT_RESUMABLE_VIDEO` hanya setelah uji ukuran, resume, konkurensi, kuota disk, dan timeout staging. Reverse proxy depan harus mengizinkan body dan waktu yang cukup untuk chunk/upload yang dipilih. PHP-FPM memiliki empat child; ukur RAM dan ruang temporary sebelum menaikkan konkurensi.

Salin file yang dipublikasikan dari Laravel melalui endpoint yang sudah memeriksa visibilitas. Nginx meneruskan request Range ke PHP untuk media privat. Staging chunk dan media final harus berada pada filesystem volume privat yang sama agar hard link bekerja. Pantau ruang bebas, sisa upload sementara, antrean, dan backup media. Volume eksternal bertahan antar-rilis. Hindari `down -v`, `volume prune`, serta penghapusan volume rilis.

## Data asli, backup, dan rollback

Gunakan [runbook pemindahan database dan media](docker-data-transfer.md) setelah gate backup dan restore lulus. Pisahkan image, data, dan rahasia. Promosi ke produksi menunggu konfirmasi topologi MariaDB, identitas TLS dan reverse proxy, domain, OS/arsitektur VPS, kapasitas disk/RAM, relay SMTP, dan lokasi backup terenkripsi. 

Untuk update, catat tag dan digest rilis aktif. Siapkan source baru, ubah hanya `PROMERTY_RELEASE` di salinan konfigurasi Compose rilis, lalu jalankan `build app web` dan `up -d --no-build --wait app web worker` setelah backup serta tinjauan migrasi. Pertahankan nama proyek, APP_KEY, koneksi database, dan nama volume. Jalankan pemeriksaan setelah recreate sebelum membuka trafik. Jika rilis gagal dengan schema yang kompatibel, kembalikan file Compose env ke tag image lama dan jalankan kembali perintah `up`. Gunakan prosedur restore terisolasi pada runbook untuk perubahan schema yang tidak kompatibel.
