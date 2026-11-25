@php
    $labels=\App\Support\AccessMatrix::LABELS;
    $editable=\App\Support\AccessMatrix::EDITABLE;
    $columns=empty($filters['role']) ? $roles : $roles->only([$filters['role']]);
    $visible=$rows;
    if (!empty($filters['category'])) $visible=array_intersect_key($visible,[$filters['category']=>true]);
    if (!empty($filters['q'])) {
        foreach ($visible as $group=>$items) $visible[$group]=array_filter($items,
            fn ($label,$code)=>mb_stripos($label.' '.$code,$filters['q'])!==false,ARRAY_FILTER_USE_BOTH);
    }
    $visible=array_filter($visible);
    $visibleCodes=array_keys(array_merge(...array_values($visible)));
@endphp
<x-layouts.app title="Hak Akses | Promerty">
    <div class="a-workspace o-workspace">
        <x-operations-navigation role="admin" :sections="$sections" section="access" />
        <main id="main" class="a-main p-stack">
            <header class="a-header"><div><span class="p-eyebrow">Administrasi</span><h1>Hak Akses</h1>
                <p>Izin berlaku untuk seluruh akun pada role terkait. Hak operasional tetap diperiksa pada tiap halaman.</p></div></header>
            @if(session('workspace_status'))<p role="status">{{ session('workspace_status') }}</p>@endif
            @if($errors->any())<p role="alert" class="p-field-error">{{ $errors->first() }}</p>@endif
            <section class="p-account-panel p-stack" aria-labelledby="role-summary">
                <h2 id="role-summary">Ringkasan role</h2>
                <ul class="o-role-summary">@foreach($labels as $name=>$label)
                    <li><strong>{{ $label }}</strong><span>{{ $roles->has($name) ? count($grants[$name]).' izin aktif' : 'Belum tersedia' }}</span></li>
                @endforeach</ul>
            </section>
            <form class="o-filters" method="get" action="{{ route('admin.access.index') }}" aria-label="Filter hak akses">
                <label class="p-field">Cari izin<input class="p-input" name="q" maxlength="100" value="{{ $filters['q']??'' }}"></label>
                <label class="p-field">Role<select class="p-select" name="role"><option value="">Semua role</option>@foreach($labels as $name=>$label)<option value="{{ $name }}" @selected(($filters['role']??null)===$name)>{{ $label }}</option>@endforeach</select></label>
                <label class="p-field">Kategori<select class="p-select" name="category"><option value="">Semua kategori</option>@foreach(array_keys($rows) as $group)<option value="{{ $group }}" @selected(($filters['category']??null)===$group)>{{ $group }}</option>@endforeach</select></label>
                <button type="submit" class="p-button p-button--secondary">Terapkan filter</button>
            </form>
            <section class="p-stack" aria-labelledby="access-table-title">
                <h2 id="access-table-title">Matriks izin</h2>
                <p>Feed, Jelajahi, profil sendiri, Pengaturan dan Pusat Bantuan mengikuti aturan publik atau sesi. Izin terkunci hanya dapat diubah melalui proses terpisah.</p>
                @if($visible && $columns->isNotEmpty())
                    <div class="o-access-scroll" tabindex="0" role="region" aria-label="Tabel matriks izin, dapat digulir">
                        <table class="o-access-table"><thead><tr><th scope="col">Izin</th>@foreach($columns as $name=>$role)<th scope="col">{{ $labels[$name] }}</th>@endforeach</tr></thead>
                            @foreach($visible as $group=>$items)
                                <tbody><tr class="o-access-group"><th scope="rowgroup" colspan="{{ $columns->count()+1 }}">{{ $group }}</th></tr>
                                    @foreach($items as $code=>$label)
                                        <tr><th scope="row"><span>{{ $label }}</span><code>{{ $code }}</code></th>
                                            @foreach($columns as $name=>$role)
                                                @php($enabled=in_array($code,$grants[$name],true))
                                                <td>@if(in_array($code,$editable[$name]??[],true) && in_array($code,$permissionNames,true))
                                                    <input form="access-{{ $name }}" type="hidden" name="changes[{{ $code }}]" value="0">
                                                    <label class="o-access-toggle"><input form="access-{{ $name }}" type="checkbox" name="changes[{{ $code }}]" value="1" @checked($enabled) aria-label="{{ $labels[$name] }}: {{ $label }}"><span class="o-only-on" aria-hidden="true">Aktif</span><span class="o-only-off" aria-hidden="true">Nonaktif</span></label>
                                                @else
                                                    <span>{{ in_array($code,$permissionNames,true) ? ($enabled?'Aktif':'Nonaktif') : 'Belum tersedia' }}</span><small> Terkunci</small>
                                                @endif</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                    @foreach($columns as $name=>$role)
                        @continue(!array_intersect($visibleCodes,$editable[$name]??[],$permissionNames))
                        <form id="access-{{ $name }}" method="post" action="{{ route('admin.access.update',$name) }}" class="p-row o-access-actions">
                            @csrf @method('PATCH')
                            <input type="hidden" name="revision" value="{{ $revisions[$name] }}">
                            <label class="o-access-toggle"><input type="checkbox" required name="confirm_change" value="1"> Saya mengonfirmasi perubahan untuk {{ $labels[$name] }}.</label>
                            <button class="p-button p-button--primary" type="submit">Simpan Perubahan {{ $labels[$name] }}</button>
                        </form>
                    @endforeach
                @else <p role="status">Tidak ada izin yang cocok dengan filter.</p> @endif
            </section>
            <section class="p-account-panel p-stack" aria-labelledby="access-history">
                <h2 id="access-history">Riwayat perubahan</h2>
                <p>Pemulihan hanya tersedia untuk perubahan terbaru pada setiap role dan memerlukan konfirmasi.</p>
                @forelse($history as $entry)
                    @php($state=$entry->new_values)
                    <details class="a-specs"><summary>{{ $labels[$state['role_name']]??$state['role_name'] }} · {{ $entry->user_id ? 'Admin #'.$entry->user_id : 'Operator lokal' }} · {{ $entry->created_at->format('Y-m-d H:i') }}</summary>
                        <p>Hasil: {{ $state['outcome']==='success'?'Berhasil':'Tidak berhasil' }}</p>
                        <p>Ditambahkan: {{ implode(', ',array_diff($state['permissions_after'],$state['permissions_before'])) ?: 'Tidak ada' }}</p>
                        <p>Dicabut: {{ implode(', ',array_diff($state['permissions_before'],$state['permissions_after'])) ?: 'Tidak ada' }}</p>
                        @if(isset($editable[$state['role_name']]) && $history->firstWhere('target_id',$entry->target_id)?->id===$entry->id)
                            <form method="post" class="p-row o-access-actions" action="{{ route('admin.access.rollback',$entry) }}">
                                @csrf <label class="o-access-toggle"><input type="checkbox" name="confirm_change" value="1" required> Konfirmasi pemulihan</label>
                                <button class="p-button p-button--secondary" type="submit">Pulihkan hak akses</button>
                            </form>
                        @endif
                    </details>
                @empty <p>Belum ada perubahan tercatat.</p> @endforelse
            </section>
        </main>
    </div>
</x-layouts.app>
