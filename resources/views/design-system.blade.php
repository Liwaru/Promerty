<x-layouts.app title="Design System - Promerty">
    <div class="p-shell">
        <header class="p-navbar">
            <a href="{{ url('/') }}" class="p-brand"><x-brand-logo /> Promerty<span class="p-badge">Design system / 01</span></a>
            <x-theme-switch />
        </header>
        <main id="main">
            <section class="p-hero">
                <div class="p-stack" style="gap:var(--space-6)">
                    <span class="p-eyebrow p-muted">Satu identitas. Di setiap ruang.</span>
                    <h1>Dirancang untuk<br>rasa percaya.</h1>
                    <p class="p-muted">Putih yang lapang. Gelap yang tenang. Sentuhan emas yang menyatukan pengalaman Promerty.</p>
                    <div class="p-row"><a class="p-button p-button--primary" href="#components">Jelajahi komponen <x-icon name="arrow" /></a><a class="p-button p-button--ghost" href="#palette">Lihat palette</a></div>
                    <span class="p-eyebrow p-muted">White & gold · Black & gold</span>
                </div>
                <div class="p-hero-panel p-stack">
                    <div class="p-section-title" style="margin-bottom:0"><span class="p-eyebrow">Identitas visual</span><span class="p-muted text-sm">PROMERTY</span></div>
                    <h2>Detail kecil.<br>Kesatuan yang terasa.</h2>
                    <hr class="p-divider">
                    <div class="p-grid" style="grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div class="p-swatch" style="background:var(--color-primary);color:var(--color-on-primary)"><span>Signature gold</span><strong>#D4AF37</strong></div>
                        <div class="p-swatch" style="background:var(--color-bg)"><span>Ruang & kontras</span><strong>Light / Dark</strong></div>
                    </div>
                    <p class="p-muted text-sm">Warna terkunci. Komponen adaptif. Satu fondasi untuk web dan mobile.</p>
                </div>
            </section>
            <nav class="p-row p-section" aria-label="Bagian design system" style="padding-block:var(--space-4)">
                <a class="p-button p-button--ghost" href="#palette">01 Palette</a><a class="p-button p-button--ghost" href="#components">02 Komponen</a><a class="p-button p-button--ghost" href="#navigation">03 Navigasi</a><a class="p-button p-button--ghost" href="#feedback">04 Feedback</a>
            </nav>
            <section id="palette" class="p-section">
                <div class="p-section-title"><h2>Warna yang menyatukan.</h2><span class="p-eyebrow p-muted">01 / Brand foundation</span></div>
                <div class="p-grid" style="grid-template-columns:repeat(auto-fit,minmax(145px,1fr))">
                    @foreach (['gold-primary'=>'Signature gold','gold-soft'=>'Soft gold','light-background'=>'White','light-surface'=>'Warm white','dark-background'=>'Black','dark-surface'=>'Charcoal','text-dark'=>'Dark text'] as $key=>$label)
                        <div class="p-stack">
                            <div class="p-swatch" style="background:var(--brand-{{ $key }});min-height:110px" aria-label="Warna {{ $label }}"></div>
                            <div><strong class="text-sm">{{ $label }}</strong><p class="p-muted text-xs">{{ json_decode(file_get_contents(base_path('design-tokens/promerty.json')), true)['brand'][$key] }}</p></div>
                        </div>
                    @endforeach
                </div>
                <div class="p-grid" style="margin-top:var(--space-12)">
                    <div class="p-stack"><span class="p-eyebrow p-muted">Heading / Georgia</span><h2>Ruang untuk cerita baru.</h2><p class="p-muted">Serif yang hangat untuk judul, dengan ruang napas yang cukup.</p></div>
                    <div class="p-stack"><span class="p-eyebrow p-muted">Body / System sans</span><p>Informasi properti harus mudah dibaca, mudah dipahami, dan nyaman di setiap perangkat.</p><div class="p-row"><span class="p-badge p-badge--premium">Premium</span><span class="p-badge">Pilihan Promerty</span><span class="p-badge p-badge--success">Terverifikasi</span></div></div>
                </div>
            </section>
            <section id="components" class="p-section">
                <div class="p-section-title"><h2>Interaksi yang terasa familiar.</h2><span class="p-eyebrow p-muted">02 / Components</span></div>
                <div class="p-row" style="margin-bottom:var(--space-8)">
                    <button class="p-button p-button--primary" data-dialog-open="example-modal">Primary <x-icon name="arrow" /></button>
                    <button class="p-button p-button--secondary" data-dialog-open="example-sheet">Secondary</button>
                    <button class="p-button p-button--ghost" data-toast-show="example-toast">Ghost</button>
                    <button class="p-button p-button--danger" data-dialog-open="example-danger">Danger</button>
                    <button class="p-button" disabled>Disabled</button>
                    <span class="p-tooltip-wrap"><button class="p-button p-button--ghost" aria-describedby="save-tooltip" data-toast-show="example-toast"><x-icon name="heart" /><span>Simpan</span></button><span class="p-tooltip" id="save-tooltip" role="tooltip">Simpan ke koleksi favorit</span></span>
                </div>
                <div class="p-grid">
                    <article class="p-property-card">
                        <div class="p-property-card__media" style="display:grid;place-items:center;position:relative" aria-label="Placeholder foto properti">
                            <x-icon style="width:100px;height:100px;color:var(--color-primary);stroke-width:.6" />
                            <span class="p-badge" style="position:absolute;top:1rem;left:1rem">Contoh property card</span>
                        </div>
                        <div class="p-property-card__body"><span class="p-eyebrow p-muted">Rumah · Contoh konten</span><h3>Hunian Taman Selatan</h3><span class="p-price">Rp 2,4 miliar</span><p class="p-muted text-sm">Jakarta Selatan · 3 kamar · 180 m²</p><hr class="p-divider" style="margin:0"><div class="p-row"><span class="p-avatar" aria-label="Avatar Nadia">NA</span><div><strong class="text-sm">Nadia A.</strong><p class="p-muted text-xs">Agent terverifikasi</p></div><button class="p-button p-button--ghost" data-dialog-open="example-modal" aria-label="Lihat contoh detail"><x-icon name="arrow" /></button></div></div>
                    </article>
                    <div class="p-card p-stack">
                        <h3>Form & pencarian</h3>
                        <label class="p-search"><x-icon name="search" /><input aria-label="Cari lokasi contoh" placeholder="Cari lokasi atau properti"></label>
                        <label class="p-field">Nama lengkap<input class="p-input" placeholder="Nama Anda" autocomplete="name"></label>
                        <label class="p-field">Jenis properti<select class="p-select"><option>Rumah</option><option>Apartemen</option><option>Tanah</option></select></label>
                        <label class="p-field">Catatan<textarea class="p-textarea" placeholder="Ceritakan kebutuhan Anda"></textarea></label>
                        <label class="p-field">Email (contoh error)<input class="p-input" value="nama@" aria-invalid="true" aria-describedby="email-error"><span class="p-field-error" id="email-error">Masukkan alamat email yang lengkap.</span></label>
                    </div>
                </div>
                <div class="p-row" data-chip-group aria-label="Contoh filter properti" style="margin-top:var(--space-6)">
                    @foreach (['Semua','Rumah','Apartemen','Tanah'] as $filter)
                        <button class="p-chip" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $filter }}</button>
                    @endforeach
                </div>
            </section>
            <section id="navigation" class="p-section">
                <div class="p-section-title"><h2>Jelas di setiap langkah.</h2><span class="p-eyebrow p-muted">03 / Navigation</span></div>
                <div class="p-grid">
                    <nav class="p-sidebar" aria-label="Contoh sidebar"><span class="p-eyebrow p-muted" style="padding:1rem">Workspace</span><a href="#navigation" aria-current="page">Ringkasan</a><a href="#components">Listing properti</a><a href="#feedback">Pesan & aktivitas</a><a href="#palette">Preferensi tampilan</a></nav>
                    <div class="p-stack">
                        <div class="p-tabs" role="tablist" aria-label="Contoh tabs"><button class="p-tab" role="tab" id="tab-listing" aria-controls="panel-listing" aria-selected="true">Listing</button><button class="p-tab" role="tab" id="tab-saved" aria-controls="panel-saved" aria-selected="false" tabindex="-1">Tersimpan</button></div>
                        <div role="tabpanel" id="panel-listing" aria-labelledby="tab-listing" tabindex="0" class="p-card"><h3>Semua dalam satu tempat.</h3><p class="p-muted">Contoh panel untuk daftar properti Anda.</p></div>
                        <div role="tabpanel" id="panel-saved" aria-labelledby="tab-saved" tabindex="0" class="p-empty" hidden><x-icon name="heart" /><p>Belum ada properti tersimpan.</p></div>
                        <nav class="p-bottom-nav" aria-label="Contoh bottom navigation">
                            @foreach (['home'=>'Beranda','search'=>'Pencarian','heart'=>'Tersimpan','chat'=>'Pesan','user'=>'Profil'] as $icon=>$label)
                                <a href="#{{ $icon === 'chat' ? 'feedback' : 'components' }}" @if($loop->first) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
                            @endforeach
                        </nav>
                        <div class="p-row" style="justify-content:space-between"><nav class="p-pagination" aria-label="Contoh pagination"><a href="#navigation" aria-current="page" aria-label="Halaman 1">1</a><a href="#components" aria-label="Halaman 2">2</a><a href="#feedback" aria-label="Halaman 3">3</a></nav><details class="p-dropdown"><summary class="p-button p-button--secondary">Menu pilihan ↓</summary><nav class="p-dropdown-menu" aria-label="Menu contoh"><a href="#palette">Palette</a><a href="#components">Komponen</a><a href="#feedback">Feedback</a></nav></details></div>
                    </div>
                </div>
            </section>
            <section id="feedback" class="p-section">
                <div class="p-section-title"><h2>Selalu memberi kejelasan.</h2><span class="p-eyebrow p-muted">04 / Feedback & content</span></div>
                <div class="p-grid">
                    <div class="p-stack"><div class="p-stat"><span class="p-muted text-sm">Contoh dashboard · Listing aktif</span><strong>24</strong><span class="p-badge p-badge--success">↑ 4 listing bulan ini</span></div><div class="p-card p-stack"><h3>Percakapan</h3><p class="p-chat text-sm">Apakah rumah ini bisa disurvei akhir pekan?</p><p class="p-chat p-chat--outgoing text-sm">Tentu. Saya bantu siapkan jadwal yang sesuai.</p><span class="p-muted text-xs" style="text-align:right">Contoh chat · 10.24</span></div></div>
                    <div class="p-stack"><div class="p-card"><h3 style="margin-bottom:1rem">Notifikasi</h3><div class="p-notification p-notification--unread"><span class="p-avatar"><x-icon name="check" /></span><div><strong class="text-sm">Jadwal survei dikonfirmasi</strong><p class="p-muted text-sm">Contoh notifikasi belum dibaca.</p></div></div><div class="p-notification"><span class="p-avatar">PR</span><div><strong class="text-sm">Selamat datang di Promerty</strong><p class="p-muted text-sm">Temukan ruang yang tepat untuk Anda.</p></div></div></div><div class="p-toast" role="status" id="example-toast"><span data-toast-message>Contoh toast: perubahan tersimpan.</span><button class="p-button p-button--ghost" data-toast-close aria-label="Tutup notifikasi">×</button></div></div>
                    <div class="p-stack"><div class="p-empty"><x-icon name="search" /><h3>Belum ada hasil.</h3><p class="p-muted text-sm">Coba sesuaikan lokasi atau filter pencarian.</p><a href="#components" class="p-button p-button--secondary">Sesuaikan filter</a></div><div class="p-card p-stack" aria-busy="true" aria-label="Contoh loading properti"><div class="p-skeleton p-skeleton--image" aria-hidden="true"></div><div class="p-skeleton" aria-hidden="true"></div><div class="p-skeleton" style="width:60%" aria-hidden="true"></div><span class="p-muted text-xs">Contoh loading / skeleton</span></div></div>
                </div>
            </section>
        </main>
        <footer class="p-navbar"><span class="p-brand">Promerty</span><p class="p-muted text-sm">Fondasi visual untuk perjalanan properti yang lebih baik.</p><a class="p-button p-button--ghost" href="#main">Kembali ke atas ↑</a></footer>
    </div>
    <dialog id="example-modal" class="p-modal" aria-labelledby="modal-title"><div class="p-stack"><span class="p-eyebrow p-muted">Contoh modal</span><h2 id="modal-title">Ruang untuk keputusan.</h2><p class="p-muted">Dialog mempertahankan fokus keyboard dan dapat ditutup dengan Escape. Ini hanya preview komponen.</p><button class="p-button p-button--primary" data-dialog-close autofocus>Selesai</button></div></dialog>
    <dialog id="example-sheet" class="p-sheet" aria-labelledby="sheet-title"><div class="p-stack"><h2 id="sheet-title">Filter properti</h2><p class="p-muted">Contoh bottom sheet yang mengikuti tema aktif.</p><label class="p-field">Rentang harga<select class="p-select"><option>Semua harga</option><option>Di bawah Rp 1 miliar</option></select></label><button class="p-button p-button--primary" data-dialog-close autofocus>Tutup preview</button></div></dialog>
    <dialog id="example-danger" class="p-modal" aria-labelledby="danger-title"><div class="p-stack"><h2 id="danger-title">Contoh tindakan penting</h2><p class="p-muted">Warna merah hanya untuk tindakan berisiko. Preview ini tidak menghapus data apa pun.</p><button class="p-button p-button--secondary" data-dialog-close autofocus>Kembali</button></div></dialog>
</x-layouts.app>
