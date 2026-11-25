<x-layouts.app :title="request()->routeIs('post.public') ? 'Detail Properti - Promerty' : (request()->routeIs('agent.public') ? 'Profil Agen - Promerty' : 'Promerty - Find Your Property')">
    <div id="promerty-feed" class="f-app" data-post="{{ request()->route('post') }}" data-agent="{{ request()->route('agent') }}" data-google-message="{{ session('google_oauth_message') }}">
        <header class="f-header">
            <a class="p-brand" href="/" aria-label="Promerty beranda"><x-brand-logo />Promerty</a>
            <div class="f-feed-tabs" role="group" aria-label="Pilihan feed"><button class="f-feed-tab" data-feed-action="for-you" aria-pressed="true">Untuk Anda</button><button class="f-feed-tab" data-feed-action="following" aria-pressed="false">Mengikuti</button></div>
            <span class="p-eyebrow p-muted f-header-note">Properti. Koneksi. Kemungkinan.</span>
            <div class="p-row"><button class="p-button p-button--ghost" data-feed-action="search" aria-label="Cari dan filter properti"><x-icon name="search" /><span class="f-desktop-label">Cari properti</span><span id="feed-filter-count" class="c-filter-count" hidden></span></button><button class="p-button p-button--secondary" id="feed-account" data-feed-action="account">Masuk</button></div>
        </header>
        <div class="f-layout">
            <x-customer-navigation :active="request('panel', request('view', 'for-you'))" />
            <main id="main" class="f-main">
                <h1 class="sr-only">Temukan properti dan ruang untuk cerita Anda</h1>
                <p class="sr-only" id="feed-context" role="status" aria-live="polite"></p>
                <div id="feed-scroll" class="f-scroll" tabindex="0" aria-label="Feed properti, gunakan panah atas dan bawah untuk berpindah">
                    <div id="feed-state" class="f-state" role="status"><div class="p-skeleton p-skeleton--image"></div><p>Menyiapkan properti untuk Anda…</p></div>
                    <div id="feed-sentinel" class="f-sentinel"><button class="p-button p-button--secondary" id="feed-more" hidden>Muat berikutnya</button></div>
                </div>
            </main>
        <dialog id="feed-comments" class="f-comments" aria-labelledby="comments-title"><div data-comment-resize class="f-comment-resize" role="separator" aria-label="Lebar panel komentar, gunakan panah kiri atau kanan" aria-orientation="vertical" aria-controls="feed-comments" tabindex="0"></div><div class="f-dialog-head"><h2 id="comments-title">Komentar <span id="comments-count" class="p-muted">0</span></h2><button class="p-button p-button--ghost" data-close aria-label="Tutup komentar">×</button></div><div class="f-comments-scroll" tabindex="0" aria-label="Daftar komentar"><div id="comments-list" class="p-stack"></div><button id="comments-more" class="p-button p-button--ghost" hidden>Komentar berikutnya</button></div><button type="button" class="p-button p-button--secondary" data-comment-login hidden>Masuk untuk berkomentar</button><form id="comment-form" class="p-stack"><span class="p-muted text-sm" id="reply-label"></span><button type="button" id="cancel-reply" class="p-button p-button--ghost" hidden>Batal membalas</button><label class="p-field">Komentar<textarea class="p-textarea" name="content" maxlength="2000" required placeholder="Tulis komentar dengan sopan…"></textarea></label><p data-error class="p-field-error" role="alert"></p><button class="p-button p-button--primary">Kirim komentar</button></form></dialog>
        </div>
        <x-customer-navigation mobile :active="request('panel', request('view', 'for-you'))" />
        <dialog id="customer-workspace" class="c-workspace" aria-labelledby="customer-title"><header class="c-workspace-head"><span class="p-eyebrow p-muted">Promerty · Ruang Customer</span><button class="p-button p-button--ghost" data-close aria-label="Tutup ruang Customer">&times;</button><h2 id="customer-title">Ruang pribadi Anda</h2></header><div data-customer-body class="c-workspace-body p-stack"></div></dialog>
        <div class="f-announcement p-toast" id="feed-notice" role="status" hidden><span></span><button class="p-button p-button--ghost" aria-label="Tutup pemberitahuan">×</button></div>

        <dialog id="feed-auth" class="p-modal c-auth-dialog" aria-labelledby="auth-title">
            <div class="c-auth-content">
                <header class="c-auth-header">
                    <a class="p-brand" href="/" aria-label="Promerty beranda"><x-brand-logo />Promerty</a>
                    <button class="c-auth-close" type="button" data-close aria-label="Tutup dialog masuk">×</button>
                </header>
                <h2 id="auth-title">Masuk Ke Promerty</h2>
                <section data-auth-view="credentials">
                    <form id="feed-auth-form" class="c-auth-form" data-password-min="{{ \App\Support\NewPassword::MIN_LENGTH }}" data-password-max-bytes="{{ \App\Support\NewPassword::MAX_BYTES }}">
                        <label class="p-field c-auth-register-field" data-register hidden>Nama lengkap<input class="p-input" name="name" autocomplete="name" maxlength="150"></label>
                        <label class="p-field">Email<input class="p-input" id="auth-email" name="email" type="email" autocomplete="email" maxlength="255" placeholder="nama@email.com" required autofocus></label>
                        <div class="c-auth-password-field">
                            <div class="c-auth-label-row"><label for="auth-password">Password</label><button class="c-auth-link" type="button" data-forgot-password data-auth-login-only>Lupa password?</button></div>
                            <div class="c-auth-password-input"><input class="p-input" id="auth-password" name="password" type="password" autocomplete="current-password" minlength="1" placeholder="••••••••" required><button class="c-auth-password-toggle" type="button" data-password-toggle aria-label="Tampilkan password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.2 12s3.4-6.2 9.8-6.2 9.8 6.2 9.8 6.2-3.4 6.2-9.8 6.2-9.8-6.2-9.8-6.2Z"/><circle cx="12" cy="12" r="3.1"/></svg></button></div>
                        </div>
                        <label class="p-field c-auth-register-field" data-register hidden>Ulangi password<input class="p-input" name="password_confirmation" type="password" autocomplete="new-password" minlength="{{ \App\Support\NewPassword::MIN_LENGTH }}"><small>Minimal {{ \App\Support\NewPassword::MIN_LENGTH }} karakter, maksimal {{ \App\Support\NewPassword::MAX_BYTES }} byte, dan mengandung huruf.</small></label>
                        <p class="p-field-error c-auth-error" role="alert" data-error></p>
                        <button class="p-button p-button--primary c-auth-submit" type="submit">Masuk</button>
                    </form>
                    <div class="c-auth-login-options" data-auth-login-only>
                        @php($googleConfigured = \App\Support\GoogleOAuth::configured())
                        <div class="c-auth-divider"><span>atau lanjutkan dengan</span></div>
                        <button class="c-google-button" type="button" data-google-login data-google-url="{{ route('google.redirect', absolute: false) }}" @unless($googleConfigured) disabled aria-describedby="auth-google-note" @endunless>
                            <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285F4" d="M43.6 24.5c0-1.4-.1-2.8-.4-4.1H24v7.8h11c-.5 2.5-1.9 4.7-4.1 6.2v5.1h6.7c3.9-3.6 6-8.8 6-15Z"/><path fill="#34A853" d="M24 44c5.5 0 10.1-1.8 13.5-4.9l-6.7-5.1c-1.8 1.2-4 2-6.8 2-5.3 0-9.8-3.6-11.4-8.4H5.7v5.3C9.1 39.5 16 44 24 44Z"/><path fill="#FBBC05" d="M12.6 27.6a12 12 0 0 1 0-7.2v-5.3H5.7a20 20 0 0 0 0 17.8l6.9-5.3Z"/><path fill="#EA4335" d="M24 12.1c3 0 5.7 1 7.8 3.1l5.8-5.8C34.1 6 29.5 4 24 4 16 4 9.1 8.5 5.7 15.1l6.9 5.3c1.6-4.8 6.1-8.3 11.4-8.3Z"/></svg>
                            <span>Lanjutkan dengan Google</span>
                        </button>
                        @unless($googleConfigured)
                            <p class="c-auth-google-note" id="auth-google-note">Login Google belum dikonfigurasi. Gunakan email dan password.</p>
                        @endunless
                    </div>
                    <p class="c-auth-footer" data-auth-prompt="login">Belum punya akun? <button class="c-auth-link" type="button" data-auth-mode="register">Daftar sekarang</button></p>
                    <p class="c-auth-footer" data-auth-prompt="register" hidden>Sudah punya akun? <button class="c-auth-link" type="button" data-auth-mode="login">Masuk</button></p>
                </section>
                <section data-auth-view="forgot" hidden>
                    <form id="feed-forgot-form" class="c-auth-form">
                        <label class="p-field">Email<input class="p-input" id="auth-forgot-email" name="email" type="email" autocomplete="email" maxlength="255" placeholder="nama@email.com" required></label>
                        <p class="p-field-error c-auth-error" role="alert" data-error></p>
                        <p class="c-auth-reset-status" role="status" aria-live="polite" data-status></p>
                        <button class="p-button p-button--primary c-auth-submit" type="submit">Kirim tautan reset</button>
                    </form>
                    <button class="c-auth-link c-auth-back" type="button" data-auth-back>← Kembali ke masuk</button>
                </section>
            </div>
        </dialog>

        <dialog id="feed-search" class="p-modal" aria-labelledby="search-title"><span class="p-eyebrow c-dialog-eyebrow">Pencarian properti</span><div class="f-dialog-head"><h2 id="search-title">Temukan ruang Anda</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup pencarian">×</button></div><form id="feed-search-form" class="p-stack">
            <label class="p-field">Lokasi atau judul properti<input class="p-input" name="q" maxlength="120" placeholder="Kota, kawasan, atau nama properti"></label>
            <div class="p-grid"><label class="p-field">Jenis listing<select class="p-select" name="type"><option value="">Semua</option><option value="primary">Primary</option><option value="secondary">Secondary</option></select></label><label class="p-field">Transaksi<select class="p-select" name="transaction"><option value="">Semua</option><option value="sale">Dijual</option><option value="rent">Disewakan</option></select></label></div>
            <label class="p-field">Kategori<select class="p-select" name="category" id="feed-category"><option value="">Semua kategori</option></select></label>
            <div class="p-grid"><label class="p-field">Harga minimum (Rp)<input class="p-input" name="min_price" type="number" min="0" placeholder="Tanpa batas"></label><label class="p-field">Harga maksimum (Rp)<input class="p-input" name="max_price" type="number" min="0" placeholder="Tanpa batas"></label></div>
            <div class="p-row"><button class="p-button p-button--primary">Tampilkan properti</button><button type="reset" class="p-button p-button--ghost">Reset filter</button></div>
        </form></dialog>

        <dialog id="feed-detail" class="p-modal f-wide-dialog" aria-labelledby="detail-title"><div class="f-dialog-head"><h2 id="detail-title">Detail properti</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup detail">×</button></div><div id="detail-body" class="p-stack"></div></dialog>
        <dialog id="feed-agent" class="p-modal" aria-labelledby="agent-title"><div class="f-dialog-head"><h2 id="agent-title">Profil Agent</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup profil">×</button></div><div id="agent-body" class="p-stack"></div></dialog>



        <dialog id="feed-contact" class="p-sheet" aria-labelledby="contact-title"><div class="f-dialog-head"><h2 id="contact-title">Hubungi Agent</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup">×</button></div><div id="contact-history" class="p-stack"></div><button type="button" id="contact-more" class="p-button p-button--ghost" hidden>Pesan sebelumnya</button><form id="contact-form" class="p-stack"><label class="p-field" id="survey-date-label" hidden>Tanggal dan waktu survei<input class="p-input" type="datetime-local" name="requested_at"></label><label class="p-field">Pesan / catatan<textarea class="p-textarea" name="body" maxlength="2000" required></textarea></label><p data-error class="p-field-error" role="alert"></p><button class="p-button p-button--primary">Kirim</button></form></dialog>

        <dialog id="feed-phone" class="p-sheet" aria-labelledby="phone-title"><div class="f-dialog-head"><h2 id="phone-title">Verifikasi nomor HP</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup verifikasi">×</button></div><p class="p-muted">Verifikasi nomor Anda sebelum menghubungi Agent atau menjadwalkan survei.</p><form id="phone-form" class="p-stack"><label class="p-field">Nomor HP<input class="p-input" name="phone" autocomplete="tel" type="tel" placeholder="08…" required></label><button class="p-button p-button--secondary" type="button" id="phone-send">Kirim OTP</button><label class="p-field">Kode OTP<input class="p-input" name="otp" inputmode="numeric" autocomplete="one-time-code" required></label><p data-error class="p-field-error" role="status"></p><button class="p-button p-button--primary">Verifikasi</button></form></dialog>
        <dialog id="feed-download" class="p-sheet" aria-labelledby="download-title"><div class="f-dialog-head"><h2 id="download-title">Download media asli</h2><button class="p-button p-button--ghost" data-close aria-label="Tutup download">×</button></div><form id="download-form" class="p-stack"><div id="download-items" class="p-stack"></div><p data-error class="p-field-error" role="alert"></p><button class="p-button p-button--primary">Siapkan download</button></form><div id="download-links" class="p-stack"></div></dialog>
        <dialog id="feed-link" class="p-modal" aria-labelledby="link-title"><div class="p-stack"><h2 id="link-title">Bagikan properti</h2><label class="p-field">Salin link ini<input id="share-link" class="p-input" readonly></label><button class="p-button p-button--primary" data-close>Selesai</button></div></dialog>
    </div>
</x-layouts.app>
