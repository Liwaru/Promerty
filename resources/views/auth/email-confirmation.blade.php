<x-layouts.app title="Verifikasi Email | Promerty">
    <x-account-shell :active="auth('web')->user()?->hasRole('agent') ? 'security' : 'profile'">
    <main id="main" class="p-account-page p-stack">
        <h1>Verifikasi email</h1>
        <p>Konfirmasi bahwa Anda memiliki akses ke alamat email akun ini.</p>
        <form method="post" action="{{ $confirmationUrl }}">
            @csrf
            <button type="submit" class="p-button p-button--primary">Konfirmasi email</button>
        </form>
    </main>
    </x-account-shell>
</x-layouts.app>
