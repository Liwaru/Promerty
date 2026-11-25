<x-layouts.app title="Kode Verifikasi Email | Promerty">
    <main id="main" class="p-account-page p-stack">
        <section class="p-account-panel p-stack p-email-otp" aria-labelledby="email-otp-title">
            <h1 id="email-otp-title">Verifikasi email</h1>
            <p id="email-otp-help">Masukkan enam digit dari email Promerty. Kode berlaku selama 10 menit.</p>
            <form method="post" action="{{ route('email-otp.verify', $purpose) }}" data-email-otp class="p-stack">
                @csrf
                <fieldset aria-describedby="email-otp-help email-otp-error">
                    <legend>Kode verifikasi</legend>
                    <div class="p-email-otp-digits">
                        @for($digit = 1; $digit <= 6; $digit++)
                            <input name="digits[]" type="text" inputmode="numeric" pattern="[0-9]"
                                maxlength="1" class="p-input" required aria-label="Digit {{ $digit }}"
                                autocomplete="{{ $digit === 1 ? 'one-time-code' : 'off' }}"
                                @if($digit === 1) autofocus @endif
                                @if($errors->any()) aria-invalid="true" @endif>
                        @endfor
                    </div>
                </fieldset>
                <p id="email-otp-error" class="p-field-error" role="alert">{{ $errors->first() }}</p>
                <button type="submit" class="p-button p-button--primary">Verifikasi</button>
            </form>
            <form action="{{ route('email-otp.resend', $purpose) }}" method="post" data-email-otp-resend
                data-retry="{{ session('otp_retry', 0) }}" class="p-stack">
                @csrf
                <p role="status" data-otp-status>{{ session('otp_notice') }}</p>
                <button type="submit" class="p-button p-button--secondary">Kirim ulang</button>
            </form>
            <a class="p-button p-button--ghost" href="{{ $purpose === 'login' ? '/?panel=profile' : route('account.settings') }}">Kembali</a>
        </section>
    </main>
</x-layouts.app>
