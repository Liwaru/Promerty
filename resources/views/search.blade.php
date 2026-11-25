<x-layouts.app title="Jelajahi - Promerty">
    <div id="promerty-search" class="f-app s-app">
        <x-customer-navigation :active="'search'" />
        <main id="main" class="s-main">
            <div class="s-intro"><span class="p-eyebrow">Jelajahi Promerty</span><h1>Temukan ruang Anda.</h1><p class="p-muted">Telusuri video, bandingkan properti, dan kenali Agent Anda.</p></div>
            <form id="search-form" class="s-form" role="search" action="/search" method="get">
                <div class="s-query-row"><label class="p-field s-query">Cari properti atau Agent<input class="p-input" type="search" id="search-input" name="q" maxlength="120" placeholder="Nama properti, kawasan, atau Agent" autocomplete="off"></label><button class="p-button p-button--primary" type="submit" aria-label="Cari"><x-icon name="search" /><span>Cari</span></button></div>
                <input type="hidden" name="kind" value="property">
                <details id="search-filters" class="s-filters"><summary>Filter properti <span id="search-filter-count" role="status" aria-live="polite" aria-atomic="true"></span></summary>
                    <fieldset><legend class="sr-only">Filter utama</legend><div class="s-filter-grid s-main-filters">
                    <label class="p-field">Lokasi atau proyek<input class="p-input" name="location" maxlength="100" placeholder="Kota, kawasan, atau proyek"></label>
                    <label class="p-field">Primary / secondary<select class="p-select" name="type"><option value="">Semua jenis</option><option value="primary">Primary</option><option value="secondary">Secondary</option></select></label>
                    <label class="p-field">Transaksi<select class="p-select" name="transaction"><option value="">Jual &amp; sewa</option><option value="sale">Dijual</option><option value="rent">Disewakan</option></select></label>
                    <label class="p-field">Kategori<select class="p-select" name="category" id="search-category"><option value="">Semua kategori</option></select></label>
                    </div></fieldset>
                    <button type="button" id="toggle-advanced-filters" class="s-advanced-toggle" aria-expanded="false" aria-controls="advanced-search-filters"><x-icon name="arrow" /><span data-toggle-label>Tampilkan filter lanjutan</span><span id="advanced-filter-count" class="p-muted"></span></button>
                    <div id="advanced-search-filters" hidden inert><fieldset><legend class="sr-only">Filter lanjutan</legend><div class="s-filter-grid s-advanced-grid">
                    <label class="p-field">Harga minimum (Rp)<input class="p-input" name="min_price" type="number" inputmode="numeric" min="0" max="9999999999999999" placeholder="Tanpa batas"></label>
                    <label class="p-field">Harga maksimum (Rp)<input class="p-input" name="max_price" type="number" inputmode="numeric" min="0" max="9999999999999999" placeholder="Tanpa batas" aria-describedby="search-filter-error"></label>
                    <label class="p-field">Periode harga<select class="p-select" name="price_period"><option value="">Semua periode</option><option value="total">Total</option><option value="month">Per bulan</option><option value="year">Per tahun</option></select></label>
                    <label class="p-field">Kamar tidur minimal<input class="p-input" type="number" inputmode="numeric" name="bedrooms" min="0" max="100" placeholder="Semua"></label>
                    <label class="p-field">Kamar mandi minimal<input class="p-input" type="number" inputmode="numeric" name="bathrooms" min="0" max="100" placeholder="Semua"></label>
                    <label class="p-field">Luas tanah minimal (m²)<input class="p-input" type="number" inputmode="decimal" name="min_area" min="0" step="0.01" max="999999999" placeholder="Tanpa batas"></label>
                    <label class="p-field">Luas bangunan minimal (m²)<input class="p-input" type="number" inputmode="decimal" name="min_building_area" min="0" step="0.01" max="999999999" placeholder="Tanpa batas"></label>
                    <label class="p-field">Lantai minimal<input class="p-input" type="number" inputmode="numeric" name="floors" min="0" max="100" placeholder="Semua"></label>
                    <label class="p-field">Balkon minimal<input class="p-input" type="number" inputmode="numeric" name="balconies" min="0" max="50" placeholder="Semua"></label>
                    </div></fieldset></div>
                    <p id="search-filter-error" class="p-field-error" role="alert" hidden></p>
                    <div class="s-filter-footer"><div class="s-filter-actions"><button class="p-button p-button--secondary" type="reset">Hapus Filter</button><button id="apply-search-filters" class="p-button p-button--primary" type="submit">Terapkan Filter</button></div></div>
                </details>
                <div class="s-results-toolbar"><nav class="s-result-tabs" aria-label="Jenis hasil pencarian"><a href="?kind=property" data-kind="property" aria-current="page">Properti</a><a href="?kind=video" data-kind="video">Video</a><a href="?kind=agent" data-kind="agent">Agen</a></nav><label class="s-sort p-field"><span class="sr-only">Urutan hasil</span><select class="p-select" name="sort" aria-label="Urutan hasil"><option value="relevance">Paling relevan</option><option value="latest">Terbaru</option><option value="price_asc">Harga terendah</option><option value="price_desc">Harga tertinggi</option></select></label></div>
            </form>
            <p id="search-interpretation" class="p-muted c-search-status" role="status" aria-live="polite" hidden></p>
            <div id="search-suggestions" class="s-suggestions" aria-label="Saran kategori"></div>
            <div class="s-result-heading"><h2 id="search-heading">Properti pilihan</h2><p id="search-summary" class="p-muted" role="status" aria-live="polite"></p></div>
            <section id="search-results" aria-labelledby="search-heading" aria-busy="true"><div id="search-grid" class="s-grid"></div></section>
            <div id="search-state" class="s-state" role="status"></div>
            <div class="s-pagination"><button id="search-more" class="p-button p-button--secondary" hidden>Muat hasil berikutnya</button></div>
            <noscript><p class="s-state">Aktifkan JavaScript untuk memuat grid video dan filter interaktif. <a href="/">Kembali ke feed</a>.</p></noscript>
        </main>
        <x-customer-navigation mobile active="search" />
    </div>
</x-layouts.app>
