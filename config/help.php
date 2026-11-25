<?php

return [
    'customer'=>['label'=>'Mencari properti','entries'=>[
        'Bagaimana mencari properti?' => 'Buka Jelajahi dan masukkan kebutuhan seperti sewa apartemen atau nama proyek. Kartu hasil membuka viewer properti.',
        'Bagaimana mempersempit hasil dengan filter?' => 'Buka Filter properti, pilih transaksi, kategori atau lokasi, lalu gunakan filter lanjutan untuk harga dan spesifikasi. Pilih Terapkan Filter.',
        'Bagaimana melihat detail listing?' => 'Buka kartu dari Jelajahi atau feed, kemudian pilih Detail properti untuk membaca harga, spesifikasi dan lokasi publik.',
        'Bagaimana menyimpan listing dan mengikuti Agent?' => 'Masuk sebagai Customer. Gunakan ikon Simpan pada listing dan tombol Ikuti pada kartu atau profil Agent.',
        'Bagaimana menghubungi dan mengirim pesan ke Agent?' => 'Masuk sebagai Customer dengan nomor HP terverifikasi. Pilih Hubungi Agent dari properti, atau buka Pesan untuk melanjutkan percakapan.',
        'Bagaimana mengajukan atau membatalkan survei?' => 'Customer dengan HP terverifikasi dapat memilih Ajukan survei pada detail. Permintaan menunggu konfirmasi Agent. Pembatalan mandiri belum tersedia. Hubungi Agent melalui Pesan untuk meminta perubahan atau pembatalan.',
    ]],
    'account'=>['label'=>'Akun dan tampilan','entries'=>[
        'Bagaimana cara masuk dan keluar?' => 'Gunakan tombol Masuk pada beranda untuk login dengan email dan password. Untuk mengakhiri sesi, pilih Keluar dari beranda atau halaman Umum.',
        'Bagaimana cara login dengan Google?' => 'Pilih Lanjutkan dengan Google bila tombol tersedia di modal Masuk. Gunakan akun Google yang berhak mengakses konfigurasi layanan tersebut. Jika akun Promerty sudah ada, gunakan alur pengaitan dari Pengaitan Google saat sudah masuk.',
        'Bagaimana reset password?' => 'Pilih Lupa password pada modal Masuk. Ikuti instruksi dari tautan pemulihan jika email tersebut dapat menerima pengiriman. Jangan bagikan tautan pemulihan.',
        'Bagaimana mengubah profil dan tema?' => 'Customer yang sudah masuk dapat menyunting profil dari menu Profil. Untuk tema, buka Lainnya → Umum dan pilih Gelap, Terang, atau Sistem.',
    ]],
    'agent'=>['label'=>'Workspace Agent','role'=>'agent','permission'=>'manage_own_listings','entries'=>[
        'Bagaimana membuat atau menyunting listing?' => 'Buka Workspace Agent, pilih Tambah listing dan simpan draft sebelum menambahkan media. Gunakan Edit pada draft atau listing yang sudah diarsipkan.',
        'Apa bedanya Primary dan Secondary?' => 'Primary memakai inventori proyek/developer. Pilih proyek aktif yang memang ditugaskan ke Agent atau cabangnya. Secondary memakai inventori resale yang dikelola Agent.',
        'Bagaimana menambahkan foto atau video?' => 'Dalam editor draft, pilih Upload file untuk foto atau video MP4. Untuk TikTok atau Instagram Reels, pilih sumber sesuai provider dan masukkan tautan publik yang mengizinkan sematan. Periksa hasil di Pratinjau.',
        'Bagaimana publikasi dan arsip?' => 'Pilih Publikasikan setelah draft dan media lengkap. Gunakan Arsipkan dari workspace untuk menarik listing dari hasil publik.',
    ]],
    'branch'=>['label'=>'Workspace Branch Manager','role'=>'branch_manager','permission'=>'view_branch_dashboard','entries'=>[
        'Bagaimana melihat operasi cabang?' => 'Gunakan dashboard cabang untuk melihat Agent dan listing dalam cabang yang ditugaskan. Tindakan penugasan memerlukan permission yang ditetapkan.',
    ]],
    'admin'=>['label'=>'Workspace Admin','role'=>'admin','permission'=>'view_operational_analytics','entries'=>[
        'Bagaimana meninjau operasi?' => 'Gunakan tautan yang tersedia di workspace administrasi untuk meninjau akun, Agent, katalog, atau moderasi sesuai izin. Perubahan pada akun istimewa mengikuti otorisasi terpisah.',
    ]],
    'owner'=>['label'=>'Workspace Owner','role'=>'owner','permission'=>'view_global_analytics','entries'=>[
        'Bagaimana melihat laporan?' => 'Buka bagian Laporan di Workspace Owner. Pilih cabang dan rentang tanggal jika diperlukan untuk membatasi cakupan data.',
    ]],
];
