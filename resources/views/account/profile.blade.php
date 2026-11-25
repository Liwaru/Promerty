<x-layouts.app title="Profil | Promerty">
    <x-account-shell :active="$actor->hasRole('agent') ? 'security' : 'profile'">
    <main id="main" class="p-account-page p-stack">
        <a class="p-button p-button--ghost" href="{{ $home }}">Kembali</a>
        <h1>Profil saya</h1>
        <section class="p-account-panel p-stack" aria-label="Identitas akun">
            <h2>{{ $actor->name }}</h2>
            @if($actor->email)
                <p>{{ $actor->email }}</p>
            @endif
            @if($actor->branch)<p>{{ $actor->branch->name }}</p>@endif
            @if($actor->hasRole('customer') || $actor->hasRole('agent'))
                <a class="p-button p-button--secondary" href="/?panel=profile">Edit profil</a>
            @endif
            @if($actor->hasRole('agent'))
                <a class="p-button p-button--secondary" href="{{ route('agent.public', $actor->id) }}">Portofolio publik</a>
            @endif
            <a class="p-button p-button--secondary" href="{{ route('account.settings') }}">Pengaturan akun</a>
        </section>
        <section class="p-account-panel p-stack" aria-labelledby="account-security">
            <h2 id="account-security">Keamanan Akun</h2>
            @if($actor->email)
                <h3>Verifikasi email</h3>
                @if(session('email_verification_status'))
                    <p role="status">{{ session('email_verification_status') }}</p>
                @endif
                @error('verification') <p role="alert">{{ $message }}</p> @enderror
                @if($actor->email_verified_at)
                    <p class="p-muted">Email terverifikasi</p>
                @elseif(config('security.email_otp'))
                    <p class="p-muted">Keluar lalu masuk kembali untuk memverifikasi email akun Anda.</p>
                @else
                    <form method="post" action="{{ route('account.email.send') }}">
                        @csrf
                        <button type="submit" class="p-button p-button--secondary">Kirim tautan verifikasi email</button>
                    </form>
                @endif
            @endif
            @if($actor->email && $actor->getAuthPassword())
                <a class="p-button p-button--secondary" href="{{ route('account.settings') }}#settings-account">Ubah password</a>
            @endif
            <h3 id="connected-accounts">Akun Terhubung</h3>
            <div class="p-connected-account p-stack">
                <div class="p-connected-heading">
                    <h4>Google</h4>
                    <span class="p-connected-status" data-google-status="{{ $googleLinked ? 'linked' : 'unlinked' }}">
                        {{ $googleLinked ? 'Terhubung' : 'Belum Terhubung' }}
                    </span>
                </div>
                @if(session('google_status'))<p role="status">{{ session('google_status') }}</p>@endif
                @if(session('google_oauth_message'))<p class="p-field-error" role="alert">{{ session('google_oauth_message') }}</p>@endif
                @if(!$googleLinked)
                    @if(!$googleConfigured)
                        <p class="p-muted">Pengaitan Google belum tersedia.</p>
                    @elseif(!$actor->password)
                        <p class="p-muted">Pengaitan Google memerlukan password akun Promerty untuk autentikasi ulang.</p>
                    @else
                        <details @if($errors->has('password')) open @endif>
                            <summary class="p-button p-button--secondary">Hubungkan Google</summary>
                            <form class="p-stack p-connected-form" method="post" action="{{ route('google.link', absolute: false) }}">
                                @csrf
                                <label class="p-field">Password akun Promerty
                                    <input class="p-input" type="password" name="password" autocomplete="current-password" required maxlength="4096"
                                        aria-describedby="google-link-help{{ $errors->has('password') ? ' google-link-error' : '' }}"
                                        @error('password') aria-invalid="true" @enderror>
                                </label>
                                @error('password')<p class="p-field-error" role="alert" id="google-link-error">{{ $message }}</p>@enderror
                                <p id="google-link-help" class="p-muted">Setelah verifikasi password, pilih akun Google Anda dalam lima menit.</p>
                                <button class="p-button p-button--primary" type="submit">Verifikasi dan hubungkan Google</button>
                            </form>
                        </details>
                    @endif
                @endif
            </div>
        </section>
    </main>
    </x-account-shell>
</x-layouts.app>
