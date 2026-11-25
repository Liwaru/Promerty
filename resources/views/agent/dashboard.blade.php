<x-layouts.app :title="$tools[$section][0].' | Promerty'">
    <div id="promerty-agent" class="a-workspace" data-section="{{ $section }}">
        <x-workspace-sidebar label="Ruang Agent" :active="$section" :agent="true" :groups="['Workspace Agent'=>$tools]" />
        <main id="main" class="a-main">
            @if(app()->environment('testing'))<p class="a-qa-note">Lingkungan pengujian · data sintetis terisolasi</p>@endif
            <header class="a-header">
                <div>
                    <span class="p-eyebrow">RUANG AGENT</span>
                    <h1>{{ $tools[$section][0] }}</h1>
                    @if($section === 'overview')<p class="p-muted">{{ $agent->name }} @if($agent->branch) · {{ $agent->branch->name }} @endif</p>@endif
                </div>
                @if(isset($tools['create']) && $section !== 'create')
                    <a class="p-button p-button--primary" href="{{ route('agent.create') }}">Tambah listing <span aria-hidden="true">+</span></a>
                @endif
            </header>
            <div id="agent-notice" role="status" aria-live="polite" tabindex="-1"></div>

            @if($section === 'overview')
                <section aria-label="Statistik performa">
                    <div class="a-summary">
                        @foreach($metrics as $label => $value)
                            <div><span class="p-muted">{{ $label }}</span><strong>{{ $value === null ? '—' : number_format($value, 0, ',', '.') }}</strong></div>
                        @endforeach
                    </div>
                    @if($metrics['Total Tayangan'] !== null)<p class="p-muted a-metric-note">Total tayangan dihitung dari catatan penayangan, termasuk penayangan berulang.</p>@endif
                </section>
                <section class="a-recent p-stack" aria-labelledby="agent-recent-title">
                    <div class="a-list-heading"><h2 id="agent-recent-title">Aktivitas terbaru</h2><a class="p-button p-button--secondary" href="{{ route('agent.listings') }}">Buka Listing Saya</a></div>
                    @forelse($recent as $listing)
                        <article>
                            <a href="{{ route('agent.listings', ['q' => $listing->title]) }}">{{ $listing->title }}</a>
                            <span class="a-badge">{{ ['active'=>'Terpublikasi','draft'=>'Draft','archived'=>'Diarsipkan'][$listing->status] ?? $listing->status }}</span>
                            <time class="p-muted" datetime="{{ $listing->updated_at?->toIso8601String() }}">{{ $listing->updated_at?->translatedFormat('j M Y') }}</time>
                        </article>
                    @empty
                        <p class="p-muted">Belum ada aktivitas listing.</p>
                    @endforelse
                </section>
            @elseif($section === 'projects')
                <div class="a-project-grid">
                    @forelse($projects as $project)
                        <article class="p-account-panel p-stack">
                            <span class="a-badge">Aktif</span>
                            <h2>{{ $project->name }}</h2>
                            @if($project->developer)<p>{{ $project->developer->name }}</p>@endif
                            @if($project->location)<p class="p-muted">{{ $project->location->label }}</p>@endif
                            @if($project->description)<p class="a-project-description">{{ $project->description }}</p>@endif
                            <a class="p-button p-button--secondary" href="{{ route('agent.listings', ['project_id' => $project->id]) }}">Lihat listing terkait ({{ $project->listings_count }})</a>
                            <a class="p-button p-button--ghost" href="{{ route('agent.create', ['project_id' => $project->id]) }}">Tambah listing Primary</a>
                        </article>
                    @empty
                        <p class="p-muted">Belum ada penugasan proyek aktif.</p>
                    @endforelse
                </div>
                {{ $projects->links('agent.pagination') }}
            @elseif($section === 'listings' || $section === 'media')
                @php($videos = $section === 'listings' && request()->query('view') === 'video')
                @if($section === 'listings')
                    <nav class="a-tab-links" aria-label="Tampilan listing">
                        <a href="{{ route('agent.listings', request()->only(['q','status','sort','project_id'])) }}" @unless($videos) aria-current="page" @endunless>Semua Listing</a>
                        <a href="{{ route('agent.listings', [...request()->only(['q','status','sort','project_id']), 'view'=>'video']) }}" @if($videos) aria-current="page" @endif>Video Properti Saya</a>
                    </nav>
                @endif
                <form id="agent-filter" class="p-row a-list-filters" role="search" aria-label="Cari listing saya">
                    @if($videos)<input type="hidden" name="view" value="video">@endif
                    @if(is_string(request()->query('project_id')) && ctype_digit(request()->query('project_id')))
                        <input type="hidden" name="project_id" value="{{ request()->query('project_id') }}">
                    @endif
                    <label class="p-field">Cari listing<input name="q" class="p-input" maxlength="150" placeholder="Judul properti"></label>
                    <label class="p-field">Status<select name="status" class="p-select"><option value="">Semua status</option><option value="draft">Draft</option><option value="active">Terpublikasi</option><option value="archived">Diarsipkan</option></select></label>
                    <label class="p-field">Urutan<select name="sort" class="p-select"><option value="newest">Terbaru</option><option value="oldest">Terlama</option><option value="title">Judul A–Z</option></select></label>
                    <button class="p-button p-button--secondary" type="submit">Terapkan</button>
                    <a class="p-button p-button--ghost" href="{{ $videos ? route('agent.listings', ['view'=>'video']) : $tools[$section][1] }}">Hapus filter</a>
                </form>
                <p id="agent-total" class="p-muted" role="status"></p>
                @if($videos)
                    <section class="a-portfolio" aria-label="Video Properti Saya">
                        <p id="agent-video-status" class="p-muted" role="status">Memuat video…</p>
                        <div id="agent-video-grid" class="a-video-grid"></div>
                        <div class="p-row a-pagination">
                            <button type="button" class="p-button p-button--secondary" data-video-prev hidden>Video sebelumnya</button>
                            <button type="button" class="p-button p-button--secondary" data-video-next hidden>Video berikutnya</button>
                        </div>
                    </section>
                @else
                    <div id="agent-listings" class="a-video-grid" aria-live="polite"><p>Memuat listing…</p></div>
                    <div class="p-row a-pagination">
                        <button type="button" class="p-button p-button--secondary" data-agent-prev hidden>Sebelumnya</button>
                        <button type="button" class="p-button p-button--secondary" data-agent-next hidden>Berikutnya</button>
                    </div>
                @endif
            @endif

            @if(in_array($section,['create','listings','media'],true))
        @if($section === 'create')<section id="agent-editor" class="a-inline-editor" aria-labelledby="agent-editor-title">@else<dialog id="agent-editor" class="p-modal a-editor" aria-labelledby="agent-editor-title">@endif
            <header class="a-dialog-heading"><div><span class="p-eyebrow">PROMERTY · LISTING</span><h2 id="agent-editor-title">Informasi properti</h2></div>@if($section !== 'create')<button type="button" class="p-button p-button--ghost" data-agent-close aria-label="Tutup form listing">&times;</button>@endif</header>
            <p class="p-muted">Simpan sebagai draft. Foto atau video dapat ditambahkan setelah informasi tersimpan.</p>
            <form id="agent-listing-form" novalidate class="p-stack">
                <div id="agent-form-errors" class="p-field-error" role="alert" tabindex="-1" hidden></div>
                <div class="a-form-grid">
                    <label class="p-field">Inventori<select class="p-select" name="listing_type" id="a-listing_type"><option value="secondary">Secondary · resale</option><option value="primary">Primary · proyek/developer</option></select></label>
                    <label class="p-field" data-primary-project hidden>Proyek yang ditugaskan<select class="p-select" name="project_id" id="a-project_id" disabled><option value="">Pilih proyek</option></select><span class="p-muted">Hanya proyek aktif yang ditugaskan ke Anda/cabang. Jenis inventori dan proyek tidak dapat diganti setelah draft dibuat.</span></label>
                    <label class="p-field a-full">Judul properti<input class="p-input" name="title" id="a-title" maxlength="200" required></label>
                    <label class="p-field">Transaksi<select class="p-select" name="transaction_type" id="a-transaction_type"><option value="sale">Jual</option><option value="rent">Sewa</option></select></label>
                    <label class="p-field">Jenis properti<select class="p-select" name="category_id" id="a-category_id" required></select></label>
                    <label class="p-field">Harga (Rp)<input class="p-input" name="price" id="a-price" inputmode="decimal" placeholder="Contoh: 1850000000" required></label>
                    <label class="p-field">Periode harga<select class="p-select" name="price_period" id="a-price_period"><option value="total">Total</option><option value="month">Per bulan</option><option value="year">Per tahun</option></select></label>
                    <label class="p-field a-full" data-secondary-location>Kawasan di Batam<input class="p-input" name="location_label" id="a-location_label" maxlength="180" placeholder="Contoh: Bengkong" required></label>
                    <label class="p-field a-full" data-secondary-location>Alamat lengkap (opsional, privat)<textarea class="p-textarea" name="address" id="a-address" maxlength="2000"></textarea><span class="p-muted">Customer melihat kawasan publik. Alamat lengkap tidak ditampilkan pada feed.</span></label>
                    <label class="p-field a-full">Deskripsi<textarea class="p-textarea" name="description" id="a-description" maxlength="10000"></textarea></label>
                </div>
                <details class="a-specs"><summary>Spesifikasi properti (opsional)</summary><div class="a-form-grid">
                    @foreach(['land_area'=>'Luas tanah (m²)','building_area'=>'Luas bangunan (m²)','bedrooms'=>'Kamar tidur','bathrooms'=>'Kamar mandi','floors'=>'Jumlah lantai','carport'=>'Kapasitas carport','garage'=>'Kapasitas garasi'] as $name=>$label)
                    <label class="p-field">{{ $label }}<input class="p-input" type="number" min="{{ $name === 'floors' ? 1 : 0 }}" step="{{ in_array($name,['land_area','building_area']) ? '0.01' : '1' }}" name="{{ $name }}" id="a-{{ $name }}"></label>
                    @endforeach
                    <label class="p-field">Furnished<select class="p-select" name="furnishing" id="a-furnishing"><option value="unknown">Belum ditentukan</option><option value="unfurnished">Unfurnished</option><option value="semi_furnished">Semi furnished</option><option value="full_furnished">Full furnished</option></select></label>
                    <label class="p-field">Arah properti<select class="p-select" name="orientation" id="a-orientation"><option value="">Belum ditentukan</option>@foreach(['Utara','Timur Laut','Timur','Tenggara','Selatan','Barat Daya','Barat','Barat Laut'] as $direction)<option>{{ $direction }}</option>@endforeach</select></label>
                    <label class="p-field">Status surat<input class="p-input" name="certificate" id="a-certificate" maxlength="100" placeholder="Contoh: SHM"></label>
                    <label class="p-field">Tipe unit<input class="p-input" name="unit_type" id="a-unit_type" maxlength="100"></label>
                    <label class="p-field" data-apartment hidden>Lantai apartemen<input class="p-input" name="apartment_floor" id="a-apartment_floor" type="number" min="0" max="200"></label>
                    <label class="p-field" data-apartment hidden>Tower<input class="p-input" name="tower" id="a-tower" maxlength="100"></label>
                    <label class="p-field" data-apartment hidden>Balkon<input class="p-input" type="number" name="balconies" id="a-balconies" min="0" max="50"></label>
                    <label class="p-field" data-apartment hidden>Konsep<input class="p-input" name="concept" id="a-concept" maxlength="100"></label>
                    <label class="p-field">Unduhan video<select class="p-select" name="allow_video_download"><option value="0">Jangan izinkan</option><option value="1">Izinkan MP4 asli</option></select></label>
                </div></details>
                <div class="a-form-actions"><span id="agent-save-status" class="p-muted" role="status">Belum disimpan</span><button class="p-button p-button--primary" type="submit">Simpan draft</button></div>
            </form>
            <section id="agent-media-panel" hidden class="a-media-panel" aria-labelledby="agent-media-title">
                <h3 id="agent-media-title">Foto &amp; video</h3>
                <div id="agent-media-list" class="a-media-list"></div>
                <label class="p-field">Sumber media<select class="p-input" id="agent-media-source"><option value="upload">Upload file</option><option value="tiktok">TikTok URL</option><option value="instagram">Instagram Reels URL</option></select></label>
                <div id="agent-file-media" class="p-stack">
                    <p class="p-muted">Maksimal {{ config('agent.media_max_count') }} foto JPG, PNG, atau WebP, atau satu video MP4 H.264/HEVC dengan audio AAC. Dukungan HEVC bergantung pada browser. Rasio asli dipertahankan.</p>
                    <p id="agent-upload-limits" class="p-muted"></p>
                    <label class="p-field">Tambahkan media<input class="p-input" type="file" id="agent-upload" name="pending_media" form="agent-listing-form" accept="image/jpeg,image/png,image/webp,video/mp4" multiple></label>
                    <button class="p-button p-button--secondary" type="button" data-agent-upload>Unggah media</button>
                    <button class="p-button p-button--ghost" type="button" data-agent-cancel-video hidden>Batalkan upload video</button>
                    <progress id="agent-upload-progress" max="100" aria-label="Progres pengiriman file" hidden></progress>
                </div>
                <div id="agent-link-media" class="p-stack" hidden>
                    <label class="p-field"><span id="agent-media-url-label">URL TikTok</span><input class="p-input" type="url" inputmode="url" id="agent-media-url" maxlength="512" aria-describedby="agent-link-hint"></label>
                    <p id="agent-link-hint" class="p-muted">Gunakan tautan lengkap ke video publik yang mengizinkan sematan.</p>
                    <button class="p-button p-button--secondary" type="button" data-agent-link>Simpan tautan video</button>
                </div>
                <p id="agent-upload-status" role="status"></p>
            </section>
        @if($section === 'create')</section>@else</dialog>@endif
        <dialog id="agent-preview" class="p-modal a-preview" aria-labelledby="agent-preview-title"><header class="a-dialog-heading"><span class="p-eyebrow">PRATINJAU TERSIMPAN</span><button type="button" class="p-button p-button--ghost" data-agent-close aria-label="Kembali ke listing">Kembali</button></header><div id="agent-preview-body"></div></dialog>
            @endif
            </main>
    </div>
</x-layouts.app>
