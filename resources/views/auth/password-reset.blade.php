<x-layouts.app title="Atur Ulang Kata Sandi - Promerty">
    <div class="f-app c-password-reset-page">
        <main id="main" class="c-password-reset-card" aria-labelledby="password-reset-title">
            <a class="p-brand c-password-reset-brand" href="/" aria-label="Promerty beranda"><x-brand-logo />Promerty</a>
            <h1 id="password-reset-title">Atur ulang kata sandi</h1>
            <p class="p-muted">Buat kata sandi baru untuk akun Promerty Anda.</p>
            <form class="c-auth-form" method="post" action="{{ route('password.update') }}" data-password-policy
                data-password-min="{{ \App\Support\NewPassword::MIN_LENGTH }}" data-password-max-bytes="{{ \App\Support\NewPassword::MAX_BYTES }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="p-field">Email<input class="p-input" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" maxlength="255" required></label>
                @error('email')<p class="p-field-error" role="alert">{{ $message }}</p>@enderror
                <label class="p-field">Password baru (minimal {{ \App\Support\NewPassword::MIN_LENGTH }} karakter)<input class="p-input" type="password" name="password" autocomplete="new-password" minlength="{{ \App\Support\NewPassword::MIN_LENGTH }}" required></label>
                @error('password')<p class="p-field-error" role="alert">{{ $message }}</p>@enderror
                <label class="p-field">Ulangi password baru<input class="p-input" type="password" name="password_confirmation" autocomplete="new-password" minlength="{{ \App\Support\NewPassword::MIN_LENGTH }}" required></label>
                <button class="p-button p-button--primary c-auth-submit" type="submit">Simpan password baru</button>
            </form>
            <a class="c-auth-link c-password-reset-back" href="/">Kembali ke Promerty</a>
        </main>
    </div>
</x-layouts.app>
