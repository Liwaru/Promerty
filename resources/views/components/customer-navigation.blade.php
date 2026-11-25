@props(['mobile' => false, 'active' => 'for-you'])
@php
    $items = ['for-you'=>['home','Untuk Anda'],'search'=>['search','Jelajahi'],'following'=>['user','Mengikuti'],'saved'=>['saved','Tersimpan'],'messages'=>['chat','Pesan'],'surveys'=>['home','Survei'],'activity'=>['activity','Aktivitas'],'profile'=>['user','Profil'],'settings'=>['more','Lainnya']];
    $viewer = auth('web')->user();
    $link = null;
    if ($viewer?->isActive()) {
        $home = \App\Support\LoginDestination::for($viewer);
        $link = match($viewer->role?->name) {
            'agent'=>['Workspace Agent',route('agent.workspace')],
            'branch_manager'=>$home['dashboard_available'] ? ['Dashboard Cabang',$home['redirect_to']] : null,
            'admin'=>$home['dashboard_available'] ? ['Administrasi',$home['redirect_to']] : null,
            'owner'=>$home['dashboard_available'] ? ['Ruang Owner',$home['redirect_to']] : null,
            default=>null,
        };
        if (!$viewer->hasPermission('interact_posts')) unset($items['saved'], $items['activity']);
        if (!$viewer->hasRole('customer')) {
            foreach (['messages'=>'message_agents','surveys'=>'request_surveys'] as $action => $permission) {
                if (!$viewer->hasRole('agent') || !$viewer->hasPermission($permission)) unset($items[$action]);
            }
        }
    }
    $visible = $mobile
        ? ['for-you','search',isset($items['saved'])?'saved':'following',isset($items['messages'])?'messages':'profile','settings']
        : array_keys($items);
    $short = ['for-you'=>'Home','search'=>'Cari','saved'=>'Simpan'];
@endphp
@if(!$mobile)
<aside class="f-sidebar" aria-label="Sidebar Promerty">
    <div class="f-sidebar-top">
        <a class="p-brand f-sidebar-brand" href="/" aria-label="Promerty beranda"><x-brand-logo /><span>Promerty</span></a>
        <form class="f-compact-search" id="sidebar-search" role="search" action="/search"><input name="q" aria-label="Cari properti, lokasi, atau Agent" placeholder="Cari properti" maxlength="120" value="{{ is_string(request('q')) ? request('q') : '' }}"><button type="submit" aria-label="Cari properti"><x-icon name="search" /></button></form>
    </div>
@endif
    <nav class="{{ $mobile ? 'f-mobile-nav' : 'f-nav-list' }}" aria-label="{{ $mobile ? 'Navigasi mobile' : ($viewer?->hasRole('agent') ? 'Navigasi umum Agent' : 'Navigasi Promerty') }}">
        @foreach($visible as $action)
            @continue(!isset($items[$action]))
            @php([$icon,$label] = $items[$action])
            <button @class(['f-nav' => !$mobile]) data-feed-action="{{ $action }}" aria-label="{{ $label }}" title="{{ $label }}" @if($active === $action) aria-current="page" @endif @if($action === 'settings') aria-expanded="false" aria-controls="customer-more" aria-haspopup="dialog" @endif><x-icon :name="$icon" /><span>{{ $mobile ? ($short[$action] ?? $label) : $label }}</span></button>
        @endforeach
        @if(!$mobile && $link)
            <a class="f-nav f-workspace-link" href="{{ $link[1] }}" aria-label="{{ $link[0] }}" title="{{ $link[0] }}"><x-icon name="monitor" /><span>{{ $link[0] }}</span></a>
        @endif
    </nav>
@if(!$mobile)
</aside>
<dialog id="customer-more" class="c-more-drawer" aria-labelledby="more-title" aria-modal="false">
    <header class="c-more-head"><h2 id="more-title">Lainnya</h2><button type="button" class="p-button p-button--ghost" data-more-close aria-label="Tutup Lainnya">&times;</button></header>
    <div class="c-more-body">
        <nav class="c-more-mobile-nav" aria-label="Navigasi Customer tambahan">
            @foreach(['following','surveys','activity','profile'] as $action)
                @continue(!isset($items[$action]))
                @php([$icon, $label] = $items[$action])
                <button type="button" class="c-more-row" data-feed-action="{{ $action }}" aria-label="{{ $label }}"><x-icon :name="$icon" /><span>{{ $label }}</span></button>
            @endforeach
        </nav>
        <section aria-labelledby="more-settings"><h3 id="more-settings">Pengaturan</h3>
            <a class="c-more-row" href="{{ route('account.settings') }}"><x-icon name="settings" /><span>Umum</span></a>
            @if($link)<a class="c-more-row c-more-workspace" href="{{ $link[1] }}"><x-icon name="monitor" /><span>{{ $link[0] }}</span></a>@endif
            <fieldset class="c-more-appearance"><legend>Tampilan</legend><div class="c-theme-segments">
                @foreach(['light'=>['sun','Terang'],'dark'=>['moon','Gelap'],'system'=>['monitor','Sistem']] as $value => [$icon, $label])
                    <label><input type="radio" name="customer-theme" value="{{ $value }}"><span><x-icon :name="$icon" />{{ $label }}</span></label>
                @endforeach
            </div></fieldset>
        </section>
        <section aria-labelledby="more-other"><h3 id="more-other">Lainnya</h3>
            <a class="c-more-row" href="{{ route('help.index') }}"><x-icon name="help" /><span>Pusat Bantuan</span></a>
            <button type="button" class="c-more-row" data-more-account><x-icon name="login" /><span data-more-account-label>Masuk / Daftar</span></button>
            <p class="p-field-error" data-more-error role="alert"></p>
        </section>
    </div>
</dialog>
<dialog id="customer-search-drawer" class="c-more-drawer c-search-drawer" aria-labelledby="search-drawer-title" aria-modal="false" data-dialog-disposable="true">
    <header class="c-more-head"><h2 id="search-drawer-title">Cari Properti</h2><button type="button" class="p-button p-button--ghost" data-dialog-close aria-label="Tutup Cari Properti">&times;</button></header>
    <div class="c-search-controls">
        <form action="/search" role="search" class="c-search-drawer-form">
            <label class="sr-only" for="search-drawer-input">Kebutuhan properti Anda</label>
            <input id="search-drawer-input" class="p-input" type="search" name="q" maxlength="120" placeholder="Cari kawasan atau properti..." autocomplete="off" aria-describedby="search-drawer-status">
            <button class="p-button p-button--primary" type="submit" aria-label="Cari properti sesuai kebutuhan"><x-icon name="search" /></button>
        </form>
        <p id="search-drawer-status" class="sr-only" role="status" aria-live="polite" data-search-status></p>
    </div>
    <div class="c-more-body c-search-results"><div data-search-options aria-label="Saran pencarian"></div></div>
    <footer class="c-search-footer">
        <button type="button" class="p-button p-button--ghost c-search-clear" data-search-clear hidden>Hapus riwayat</button>
        <a class="c-more-row" href="/search">Buka Jelajahi &amp; filter lengkap <span aria-hidden="true">&rarr;</span></a>
    </footer>
</dialog>
@endif
