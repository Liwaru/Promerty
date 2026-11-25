<x-layouts.app title="Pusat Bantuan - Promerty">
    <x-account-shell active="help">
    <main id="main" class="p-account-page p-stack">
        <a class="p-button p-button--ghost" href="{{ $home }}">← Kembali ke {{ $home === '/' ? 'beranda' : 'workspace' }}</a>
        <h1>Pusat Bantuan</h1>
        <form method="get" action="{{ route('help.index') }}" role="search" class="p-account-panel p-stack">
            <label class="p-field">Cari pertanyaan atau topik
                <input class="p-input" type="search" name="q" maxlength="120" value="{{ $q }}" placeholder="Contoh: reset password">
            </label>
            <label class="p-field">Kategori
                <select class="p-select" name="category">
                    <option value="">Semua kategori</option>
                    @foreach($categories as $key=>$section)
                        <option value="{{ $key }}" @selected($key===$selected)>{{ $section['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="p-button p-button--primary">Cari bantuan</button>
            @if($q!=='' || $selected!=='') <a class="p-button p-button--ghost" href="{{ route('help.index') }}">Hapus pencarian</a> @endif
        </form>
        @forelse($results as $label=>$entries)
            <section class="p-account-panel p-stack">
                <h2>{{ $label }}</h2>
                @foreach($entries as $question=>$answer)
                    <details class="p-account-faq"><summary>{{ $question }}</summary><p>{{ $answer }}</p></details>
                @endforeach
            </section>
        @empty
            <p role="status">Belum ada jawaban yang cocok. Coba kata kunci lain.</p>
        @endforelse
    </main>
    </x-account-shell>
</x-layouts.app>
