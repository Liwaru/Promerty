<details class="o-account-form">
    <summary>{{ $account ? 'Detail dan edit' : ($type==='agent'?'Tambah Agent':'Tambah Customer') }}</summary>
    <form class="p-stack" method="post" action="/admin/directory/{{ $type }}{{ $account?'/'.$account->id:'' }}">
        @csrf
        <label class="p-field">Nama<input class="p-input" name="name" required maxlength="150" value="{{ $account?->name }}"></label>
        @unless($account)<label class="p-field">Email<input class="p-input" type="email" name="email" maxlength="255" required></label>@endunless
        @if($type==='agent' && (!$account || auth()->user()->hasPermission('verify_agents') && auth()->user()->hasPermission('manage_branches')))
            <label class="p-field">Cabang<select class="p-select" name="branch_id" @unless($account)required @endunless>
                <option value="">{{ $account?'Pertahankan cabang':'Pilih cabang' }}</option>
                @foreach($branchOptions->where('status','active') as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
            </select></label>
        @endif
        @if($account)
            <label class="p-field">Status akun<select class="p-select" name="status">
                @foreach(['active'=>'Aktif','inactive'=>'Tidak aktif','suspended'=>'Ditangguhkan'] as $value=>$label)
                    <option value="{{ $value }}" @selected($value===$account->status)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label class="p-row"><input type="checkbox" name="confirm_status" value="1"> Konfirmasi jika status diubah</label>
            <p class="p-muted">Penonaktifan memblokir akses dan mempertahankan riwayat. Listing Agent tidak tampil selama akunnya nonaktif.</p>
        @else
            <p class="p-muted">Akun baru tidak aktif. Verifikasi pemilik email dan gunakan alur pemulihan privat setelah aktivasi.</p>
        @endif
        <button type="submit" class="p-button p-button--primary">{{ $account?'Simpan perubahan':'Buat akun' }}</button>
    </form>
</details>
