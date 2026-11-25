<x-layouts.app title="Ruang Agent | Promerty">
    <div class="a-workspace">
        <x-workspace-sidebar label="Ruang Agent" active="workspace" :agent="true"
            :groups="['Workspace Agent'=>['workspace'=>['Dashboard Agent',route('agent.workspace'),'monitor']]]" />
        <main id="main" class="a-main p-stack">
            <header class="a-header"><h1>Ruang Agent</h1></header>
            <section class="p-account-panel p-stack" aria-labelledby="agent-access-title">
                <h2 id="agent-access-title">{{ $verification === 'pending' ? 'Menunggu verifikasi Agent' : 'Akses listing belum tersedia' }}</h2>
                <p>Pengelolaan listing memerlukan Agent terverifikasi, cabang aktif, dan izin pengelolaan listing.</p>
                <div class="p-row">
                    <a class="p-button p-button--primary" href="{{ route('account.profile') }}">Buka profil</a>
                    <a class="p-button p-button--secondary" href="{{ route('help.index') }}">Pusat Bantuan</a>
                </div>
            </section>
        </main>
    </div>
</x-layouts.app>
