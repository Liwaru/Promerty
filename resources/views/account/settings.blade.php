<x-layouts.app title="Umum - Promerty">
    <x-account-shell active="settings">
    <main id="main" class="p-account-page p-stack">
        <a class="p-button p-button--ghost" href="{{ $home }}">← Kembali ke {{ $home === '/' ? 'beranda' : 'workspace' }}</a>
        <h1>Umum</h1>
        <section aria-labelledby="settings-theme" class="p-account-panel p-stack">
            <h2 id="settings-theme">Tampilan</h2>
            <label class="p-field">Tema di browser ini
                <select class="p-select" data-theme-select>
                    <option value="dark">Gelap</option><option value="light">Terang</option><option value="system">Sistem</option>
                </select>
            </label>
            <p>Bahasa antarmuka: Bahasa Indonesia.</p>
        </section>
        @if($actor?->isActive() && $actor->hasRole('customer'))
        <section aria-labelledby="settings-notices" class="p-account-panel p-stack">
            <h2 id="settings-notices">Pemberitahuan</h2>
            <label class="p-field">Daftar di browser ini
                <select class="p-select" data-notification-preference>
                    <option value="all">Semua pemberitahuan</option><option value="unread">Hanya yang belum dibaca</option>
                </select>
            </label>
            <a class="p-button p-button--secondary" href="/?panel=notifications">Buka pemberitahuan</a>
            <p>Pengaturan ini berlaku untuk daftar di Promerty pada browser ini.</p>
        </section>
        @endif
        <section aria-labelledby="settings-account" class="p-account-panel p-stack">
            <h2 id="settings-account">Akun</h2>
            @if($actor?->isActive())
                @if($actor->hasRole('customer'))
                    <a class="p-button p-button--secondary" href="{{ route('account.profile') }}">Lihat dan edit profil</a>
                @endif
                @if(session('password_updated')) <p role="status">Password sudah diperbarui.</p> @endif
                @error('current_password') <p class="p-field-error" role="alert">{{ $message }}</p> @enderror
                @if($actor->getAuthPassword() && $actor->email)
                    @if(config('security.email_otp') && (!session('email_otp.password_approved') || $errors->has('current_password')))
                    <form method="post" action="{{ route('account.password.otp') }}" class="p-stack">
                        @csrf
                        <h3>Ubah password</h3>
                        <label class="p-field">Password saat ini<input class="p-input" type="password" name="current_password" autocomplete="current-password" required></label>
                        <button class="p-button p-button--primary" type="submit">Kirim kode email</button>
                    </form>
                    @else
                    <form method="post" action="{{ route('account.password.update') }}" class="p-stack" data-password-policy
                        data-password-min="{{ \App\Support\NewPassword::MIN_LENGTH }}" data-password-max-bytes="{{ \App\Support\NewPassword::MAX_BYTES }}">
                        @csrf
                        @method('PATCH')
                        <h3>Ubah password</h3>
                        @unless(config('security.email_otp'))
                        <label class="p-field">Password saat ini<input class="p-input" type="password" name="current_password" autocomplete="current-password" required></label>
                        @endunless
                        <label class="p-field">Password baru<input class="p-input" type="password" name="password" autocomplete="new-password" minlength="{{ \App\Support\NewPassword::MIN_LENGTH }}" required>
                            <small>Minimal {{ \App\Support\NewPassword::MIN_LENGTH }} karakter, maksimal {{ \App\Support\NewPassword::MAX_BYTES }} byte, dan mengandung huruf.</small>
                        </label>
                        @error('password')<p class="p-field-error" role="alert">{{ $message }}</p>@enderror
                        <label class="p-field">Ulangi password baru<input class="p-input" type="password" name="password_confirmation" autocomplete="new-password" minlength="{{ \App\Support\NewPassword::MIN_LENGTH }}" required></label>
                        <button class="p-button p-button--primary" type="submit">Simpan password baru</button>
                    </form>
                    @endif
                @endif
                <form method="post" action="/web-auth/logout">@csrf<button class="p-button p-button--secondary" type="submit">Keluar</button></form>
            @else
                <a class="p-button p-button--secondary" href="/?panel=profile">Masuk atau daftar</a>
            @endif
            <a class="p-button p-button--ghost" href="{{ route('help.index') }}">Pusat Bantuan</a>
        </section>
    </main>
    </x-account-shell>
</x-layouts.app>
