@php
    $prefix=['branch_manager'=>'branch','admin'=>'admin','owner'=>'owner'][$role];
    $roleLabel=['branch_manager'=>'Ruang Cabang','admin'=>'Administrasi','owner'=>'Ruang Owner'][$role];
    $branchManagers = $section==='users' && ($f['account_role']??null)==='branch_manager';
    $directory=in_array($section,['customers','agent-data'],true);
    $type=$section==='agent-data'?'agent':'customer';
@endphp
<x-layouts.app :title="$roleLabel.' | Promerty'">
<div @class(['a-workspace','o-workspace','o-owner-workspace'=>$role==='owner'])>
    <x-operations-navigation :role="$role" :sections="$sections" :section="$section" :branch-managers="$branchManagers" />
    <main id="main" class="a-main">
        @if(app()->environment('testing'))<p class="a-qa-note">Lingkungan pengujian — data sintetis terisolasi.</p>@endif
        <header class="a-header"><div><span class="p-eyebrow">PROMERTY · {{ $roleLabel }}</span><h1>{{ $sections[$section][0] }}</h1><p class="p-muted">{{ auth()->user()->name }}@if($role==='branch_manager') · {{ auth()->user()->branch->name }}@endif</p></div></header>
        <div id="operations-status" role="status" aria-live="polite">{{ session('workspace_status') }}</div>
        @if($errors->any())<div class="p-field-error" role="alert"><p>Periksa isian berikut.</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @unless($section==='audit')
        <form method="get" class="o-filters" role="search">
            @if($branchManagers)<input type="hidden" name="account_role" value="branch_manager">@endif
            @if(!in_array($section,['overview','reports']))<label class="p-field">Cari {{ $directory?'nama atau email':($section==='listings'?'judul':'nama') }}<input class="p-input" name="q" value="{{ $f['q']??'' }}" maxlength="150"></label>@endif
            @if($directory||in_array($section,['users','agents','listings']))<label class="p-field">Status<select class="p-select" name="status"><option value="">Semua status</option>@foreach(($section==='listings'?['draft'=>'Draft','active'=>'Aktif','archived'=>'Arsip']:['active'=>'Aktif','inactive'=>'Tidak aktif','suspended'=>'Ditangguhkan']) as $key=>$label)<option value="{{ $key }}" @selected(($f['status']??'')===$key)>{{ $label }}</option>@endforeach</select></label>@endif
            @if($section==='agent-data')
            <label class="p-field">Cabang<select class="p-select" name="branch_id"><option value="">Semua cabang</option>@foreach($branchOptions as $branch)<option value="{{ $branch->id }}" @selected(($f['branch_id']??null)==$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
            <label class="p-field">Verifikasi<select class="p-select" name="verification"><option value="">Semua verifikasi</option>@foreach(['pending'=>'Menunggu','verified'=>'Terverifikasi','rejected'=>'Ditolak'] as $value=>$label)<option value="{{ $value }}" @selected(($f['verification']??null)===$value)>{{ $label }}</option>@endforeach</select></label>
            @endif
            @if(in_array($section,['overview','reports','listings']))
            <label class="p-field">Dibuat sejak<input class="p-input" type="date" name="from" value="{{ $f['from']??'' }}"></label><label class="p-field">Sampai<input class="p-input" type="date" name="to" value="{{ $f['to']??'' }}"></label>
            @if($role!=='branch_manager')<label class="p-field">Cabang<select class="p-select" name="branch_id"><option value="">Semua cabang</option>@foreach($branchOptions as $branchOption)<option value="{{ $branchOption->id }}" @selected((string)($f['branch_id']??'')===(string)$branchOption->id)>{{ $branchOption->name }}</option>@endforeach</select></label>@endif
            @endif
            @if($section==='listings')<label class="p-field">Agent<select class="p-select" name="agent_id"><option value="">Semua Agent</option>@foreach($agentOptions as $agentOption)<option value="{{ $agentOption->id }}" @selected((string)($f['agent_id']??'')===(string)$agentOption->id)>{{ $agentOption->name }}</option>@endforeach</select></label>@endif
            <button type="submit" class="p-button p-button--primary">Terapkan</button><a class="p-button p-button--secondary" href="/{{ $prefix }}/{{ $section }}">Hapus filter</a>
        </form>
        @endunless
        @if($directory&&($type==='customer'||auth()->user()->hasPermission('verify_agents')))
            @include('operations.directory-form',['account'=>null])
        @endif
        @if($metrics)
            @if($role==='branch_manager')<h2 id="branch-activity">Aktivitas Cabang</h2>@endif
            <section class="a-summary" aria-label="Ringkasan data">@foreach($metrics as $label=>$count)<div><span class="p-muted">{{ $label }}</span><strong>{{ number_format($count,0,',','.') }}</strong></div>@endforeach</section><p class="p-muted">Periode berlaku untuk waktu pembuatan listing. Jumlah Agent adalah kondisi saat ini. Tidak ada estimasi pendapatan atau komisi.</p>
        @endif
        @if($role==='admin'&&$section==='branches')
        <details class="a-specs"><summary>Tambah cabang</summary><form action="/admin/branches/create" method="post" class="o-filters">@csrf<label class="p-field">Nama cabang<input class="p-input" name="name" maxlength="150" required value="{{ old('name') }}"></label><label class="p-field">Agency<select class="p-select" name="agency_id" required>@foreach($agencies as $agency)<option value="{{ $agency->id }}">{{ $agency->name }}</option>@endforeach</select></label><button type="submit" class="p-button p-button--primary" @disabled($agencies->isEmpty())>Simpan cabang</button></form>@if($agencies->isEmpty())<p>Agency belum tersedia. Cabang memerlukan agency existing.</p>@endif</details>
        @endif
        @if($role==='admin'&&$section==='agents')
        <details class="a-specs"><summary>Tambah akun Agent</summary><p class="p-muted">Akun baru menunggu verifikasi. Login memakai alur OTP/pemulihan existing; ketersediaannya bergantung pada provider. Form ini tidak mengirim pesan otomatis.</p><form action="/admin/agents/create" method="post" class="o-filters">@csrf<label class="p-field">Nama Agent<input class="p-input" name="name" maxlength="150" required></label><label class="p-field">Email<input class="p-input" type="email" name="email" maxlength="255" required></label><label class="p-field">Cabang<select class="p-select" name="branch_id" required>@foreach($branchOptions as $branchOption)<option value="{{ $branchOption->id }}">{{ $branchOption->name }}</option>@endforeach</select></label><button class="p-button p-button--primary" type="submit">Buat akun Agent</button></form></details>
        @endif
        @if($role==='admin'&&$section==='catalog')
        <details class="a-specs"><summary>Tambah proyek</summary><form method="post" action="/admin/catalog/create" class="o-filters">@csrf
            <label class="p-field">Nama proyek<input class="p-input" name="name" maxlength="180" required value="{{ old('name') }}"></label>
            <label class="p-field">Developer<select class="p-select" name="developer_id" required>@foreach($developers as $developer)<option value="{{ $developer->id }}">{{ $developer->name }}</option>@endforeach</select></label>
            <label class="p-field">Kawasan Batam<select class="p-select" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->label }}</option>@endforeach</select></label>
            <label class="p-field">Deskripsi<textarea class="p-textarea" name="description" maxlength="10000"></textarea></label>
            <button type="submit" class="p-button p-button--primary" @disabled($developers->isEmpty()||$locations->isEmpty())>Simpan proyek</button>
        </form><p class="p-muted">Memakai developer dan pilihan kawasan existing. Lokasi proyek disimpan terpisah; alamat privat tidak ikut disalin. Proyek baru belum ditugaskan ke Agent/cabang.</p></details>
        @endif
        @if($rows)
        @if($section==='projects'&&!$projectReady)<p role="status">Relasi penugasan proyek belum tersedia pada lingkungan ini. Tidak ada penugasan yang diasumsikan.</p>@endif
        <section class="o-records" aria-label="Hasil {{ $sections[$section][0] }}">
        @forelse($rows as $row)
            <article class="a-listing"><div>
                <h2>{{ $section==='content'?($row->listing?->title??\Illuminate\Support\Str::limit($row->caption??'Konten',100)):($row->title??$row->name) }}</h2>
                <p class="p-muted">ID {{ $row->id }} · {{ ['active'=>'Aktif','inactive'=>'Tidak aktif','suspended'=>'Ditangguhkan','draft'=>'Draft','archived'=>'Arsip','published'=>'Terpublikasi','completed'=>'Selesai'][$row->status]??$row->status }}</p>
                @if($directory)
                    <p>{{ $row->email??'Email belum tersedia' }}</p>
                    <p class="p-muted">Dibuat {{ $row->created_at?->format('d/m/Y')??'—' }}</p>
                    @if($type==='agent')<p>{{ $row->branch?->name??'Tanpa cabang' }} · {{ $row->listings_count }} listing</p>
                    <p>Verifikasi {{ ['pending'=>'menunggu','verified'=>'terverifikasi','rejected'=>'ditolak'][$row->agentProfile?->verification_status]??'belum tersedia' }}</p>@endif
                @endif
                @if($section==='listings')<p>{{ $row->agent?->name }} · {{ $row->branch?->name }}</p><p class="a-price">Rp {{ number_format((float)$row->price,0,',','.') }}</p><span>{{ $row->listing_type==='primary'?'Primary · proyek/developer':'Secondary · resale' }}</span>@endif
                @if(in_array($section,['agents','users']))<p>{{ $row->role?->name }} · {{ $row->branch?->name??'Belum terhubung cabang' }}</p>@if($row->agentProfile)<p>Verifikasi: {{ ['verified'=>'Terverifikasi','pending'=>'Menunggu','rejected'=>'Ditolak'][$row->agentProfile->verification_status]??$row->agentProfile->verification_status }}</p>@endif @endif
                @if(in_array($section,['branches','reports']))<p>{{ $row->agents_count }} Agent · {{ $row->listings_count }} listing</p>@endif
                @if($section==='content')<p>Moderasi: {{ ['active'=>'Aktif','under_review'=>'Dalam peninjauan','removed'=>'Dihapus dari publik'][$row->moderation_status]??$row->moderation_status }}</p>@endif
                @if($section==='projects'&&$projectReady)
                    @foreach($assignments->get($row->id,collect()) as $assignment)
                    <div class="p-row"><span>{{ $assignment->branch?->name }} · {{ $assignment->agent_id?$assignment->agent?->name:'Seluruh Agent cabang' }}</span><form method="post" action="/{{ $prefix }}/assignments/{{ $assignment->id }}/revoke">@csrf<button type="submit" class="p-button p-button--secondary">Cabut penugasan</button></form></div>
                    @endforeach
                @endif
            </div>
            @if($directory) @include('operations.directory-form',['account'=>$row]) @endif
            @if($section==='catalog')<form method="post" action="/admin/catalog/{{ $row->id }}/update" class="p-stack">@csrf<label class="p-field">Nama proyek<input class="p-input" name="name" maxlength="180" required value="{{ $row->name }}"></label><label class="p-field">Status proyek<select class="p-select" name="status">@foreach(['active'=>'Aktif','inactive'=>'Tidak aktif','completed'=>'Selesai'] as $value=>$label)<option value="{{ $value }}" @selected($row->status===$value)>{{ $label }}</option>@endforeach</select></label><label class="p-field">Deskripsi<textarea class="p-textarea" name="description" maxlength="10000">{{ $row->description }}</textarea></label><p class="p-muted">Status nonaktif/selesai menolak publikasi Primary baru. Listing terpublikasi tidak diarsipkan otomatis.</p><button type="submit" class="p-button p-button--secondary">Perbarui proyek</button></form>@endif
            @if($section==='content')<form method="post" action="/admin/content/{{ $row->id }}/moderate" class="p-stack">@csrf<label class="p-field">Status moderasi<select class="p-select" name="moderation_status">@foreach(['active'=>'Aktif','under_review'=>'Dalam peninjauan','removed'=>'Dihapus dari publik'] as $value=>$label)<option value="{{ $value }}" @selected($row->moderation_status===$value) @disabled($value==='active'&&$row->moderation_status==='removed'&&!auth()->user()->hasPermission('restore_content'))>{{ $label }}</option>@endforeach</select></label><label class="p-field">Alasan<textarea class="p-textarea" name="moderation_reason" required maxlength="1000"></textarea></label><button type="submit" class="p-button p-button--secondary">Simpan moderasi</button></form>@endif
            @if($section==='projects'&&$projectReady)<form method="post" action="/{{ $prefix }}/projects/{{ $row->id }}/assign" class="p-stack">@csrf<label class="p-field">Cabang<select class="p-select" name="branch_id" required>@foreach($branchOptions as $branchOption)<option value="{{ $branchOption->id }}">{{ $branchOption->name }}</option>@endforeach</select></label><label class="p-field">Agent<select class="p-select" name="agent_id"><option value="">Seluruh Agent dalam cabang</option>@foreach($agentOptions as $agentOption)<option value="{{ $agentOption->id }}">{{ $agentOption->name }} · {{ $agentOption->branch?->name }}</option>@endforeach</select></label><button type="submit" class="p-button p-button--primary">Simpan penugasan</button></form>@endif
            @if($role==='admin'&&$section==='users')
                <div class="p-stack">
                    <form action="/admin/users/{{ $row->id }}/status" method="post" class="p-stack">@csrf<label class="p-field">Status baru<select class="p-select" name="status">@foreach(['active'=>'Aktif','inactive'=>'Tidak aktif','suspended'=>'Ditangguhkan'] as $value=>$label)<option value="{{ $value }}" @selected($row->status===$value)>{{ $label }}</option>@endforeach</select></label><button class="p-button p-button--secondary" type="submit">Simpan status</button></form>
                    @can('initiatePasswordReset',$row)
                        @if($row->isActive() && $row->email && $row->email_verified_at)
                            <form action="{{ route('admin.users.password-reset',$row) }}" method="post" class="p-stack">
                                @csrf
                                <label class="p-field">Password Admin saat ini<input class="p-input" type="password" name="current_password" autocomplete="current-password" required></label>
                                <label class="p-row"><input type="checkbox" name="confirm_reset" value="1" required> Konfirmasi kirim tautan reset</label>
                                <button type="submit" class="p-button p-button--secondary">Atur Ulang Password</button>
                            </form>
                        @else
                            <p class="p-muted">Reset memerlukan akun aktif dan email terverifikasi.</p>
                        @endif
                    @endcan
                </div>
            @endif
            @if($role==='admin'&&$section==='agents'&&$row->agentProfile?->verification_status==='pending')<form action="/admin/agents/{{ $row->id }}/verify" method="post" class="p-stack">@csrf<label class="p-field">Catatan verifikasi<textarea class="p-textarea" name="verification_note" maxlength="1000"></textarea></label><button class="p-button p-button--secondary" type="submit">Verifikasi Agent</button></form>@endif
            </article>
        @empty<div class="a-listing"><p>Belum ada data yang sesuai. Coba ubah pencarian atau filter.</p></div>@endforelse
        </section><div class="o-pagination">{{ $rows->links() }}</div>
        @endif
        @if($section==='audit')<section class="a-listing"><div><h2>Integritas audit keamanan</h2>@if($audit)<p>Status: {{ $audit['status'] }}</p><p>{{ $audit['scanned_rows'] }} diperiksa · {{ $audit['invalid_rows'] }} tidak valid · {{ $audit['expired_valid_rows'] }} melewati retensi {{ $audit['retention_days'] }} hari.</p><p class="p-muted">Pemeriksaan read-only. Tidak menghapus atau menampilkan payload sensitif.</p>@if($audit['next_after_id'])<a class="p-button p-button--secondary" href="?after_id={{ $audit['next_after_id'] }}">Periksa bagian berikutnya</a>@endif @else<p>Audit keamanan belum diaktifkan pada lingkungan ini. Tidak ada data atau hasil PASS yang diasumsikan.</p>@endif</div></section>@endif
    </main>
</div>
</x-layouts.app>
