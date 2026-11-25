@props(['label', 'groups' => [], 'active' => '', 'agent' => false])
@php
    $actor = auth('web')->user();
    $signedIn = $actor?->isActive();
    $customer = $signedIn && $actor->hasRole('customer');
    $general = ['feed'=>['Untuk Anda','/','home'], 'search'=>['Jelajahi',route('search'),'search']];
    if ($signedIn) {
        $general['following'] = ['Mengikuti','/?view=following','user'];
        if ($actor->hasPermission('interact_posts')) {
            $general['saved'] = ['Tersimpan','/?view=saved','saved'];
        }
        if ($customer || ($agent && $actor->hasPermission('message_agents'))) $general['messages'] = ['Pesan','/?panel=messages','chat'];
        if ($customer || ($agent && $actor->hasPermission('request_surveys'))) $general['surveys'] = ['Survei','/?panel=surveys','home'];
        if ($actor->hasPermission('interact_posts')) $general['activity'] = ['Aktivitas','/?panel=activity','activity'];
        $general['profile'] = ['Profil',$agent ? route('agent.public', $actor->id) : route('account.profile'),'user'];
    }
    $account = ['settings'=>['Pengaturan',route('account.settings'),'settings'],'help'=>['Pusat Bantuan',route('help.index'),'help']];
    if ($agent) $account = ['security'=>['Keamanan Akun',route('account.profile').'#account-security','lock'],...$account];
    $groups = [...$groups,'Umum'=>$general,'Akun dan bantuan'=>$account];
@endphp
<aside class="a-sidebar ws-sidebar" aria-label="Navigasi {{ $label }}">
    <div class="ws-brand">
        <a class="p-brand f-sidebar-brand" href="/" aria-label="Promerty beranda"><x-brand-logo /><span>Promerty</span></a>
        <button type="button" class="p-button p-button--secondary ws-toggle" aria-controls="workspace-links" aria-expanded="false">Navigasi</button>
        <span class="p-eyebrow ws-role">{{ $label }}</span>
    </div>
    <div class="ws-links" id="workspace-links">
        <nav class="ws-nav f-nav-list" aria-label="Menu {{ $label }}">
            @foreach($groups as $heading=>$links)
                @continue(!$links)
                <section class="ws-nav-group" aria-label="{{ $heading }}">
                    <h2 class="ws-group">{{ $heading }}</h2>
                    @foreach($links as $key=>[$title,$href,$icon])
                        <a class="f-nav" href="{{ $href }}" @if($key===$active)aria-current="page"@endif><x-icon :name="$icon" /><span>{{ $title }}</span></a>
                    @endforeach
                </section>
            @endforeach
        </nav>
        <div class="ws-footer p-stack">
            <label class="p-field">Tampilan<select class="p-select" data-theme-select><option value="system">Sistem</option><option value="dark">Gelap</option><option value="light">Terang</option></select></label>
            @if($signedIn)
                <form method="post" action="/web-auth/logout">@csrf<button type="submit" class="f-nav"><x-icon name="login" /><span>Keluar</span></button></form>
            @else
                <a class="f-nav" href="/?panel=profile"><x-icon name="login" /><span>Masuk / Daftar</span></a>
            @endif
        </div>
    </div>
</aside>
