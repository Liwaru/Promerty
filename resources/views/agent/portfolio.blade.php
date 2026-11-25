<x-layouts.app :title="$profile['name'].' | Promerty'">
    <div class="a-workspace a-public-profile" data-agent-portfolio>
        <x-promerty-sidebar :active="$ownProfile ? 'profile' : ''" />
        <div class="a-content-with-sidebar">
        <main class="a-public-main" id="main">
            <header class="a-profile-hero">
                <div class="a-profile-avatar">
                    <span aria-hidden="true">{{ mb_strtoupper(mb_substr($profile['name'],0,2)) }}</span>
                    @if($profile['photo'])<img src="{{ $profile['photo'] }}" alt="Foto {{ $profile['name'] }}" decoding="async">@endif
                </div>
                <div class="a-profile-details">
                    <div>
                        <div class="a-profile-name">
                            <h1>{{ $profile['name'] }}</h1>
                            @if($profile['verified'])<span class="a-badge a-profile-verified">Agent terverifikasi</span>@endif
                        </div>
                        <div class="a-profile-byline">
                            @if($profile['username'])<span class="p-muted">{{ '@'.$profile['username'] }}</span>@endif
                            @if($profile['branch'] || $profile['agency'])<span class="a-profile-company">{{ implode(' · ', array_unique(array_filter([$profile['branch'], $profile['agency']]))) }}</span>@endif
                        </div>
                    </div>
                    @if($profile['bio'])<p class="a-profile-bio">{{ $profile['bio'] }}</p>@endif
                    <dl class="a-profile-stats">
                        @foreach($stats as $label => $value)
                            <div><dt>{{ $label }}</dt><dd @if($label === 'Pengikut') data-profile-followers @endif>{{ number_format($value, 0, ',', '.') }}</dd></div>
                        @endforeach
                    </dl>
                    <div class="p-row">
                        @if($ownProfile)
                            <a class="p-button p-button--primary" href="/?panel=profile">Edit Profil</a>
                            <a class="p-button p-button--secondary" href="{{ route('account.profile') }}#account-security">Keamanan Akun</a>
                        @elseif($canFollow)
                            <button class="p-button p-button--primary" type="button" data-follow-agent="{{ $profile['id'] }}" aria-pressed="{{ $following ? 'true' : 'false' }}">{{ $following ? 'Berhenti mengikuti' : 'Ikuti Agent' }}</button>
                        @elseif(!$viewer)
                            <a class="p-button p-button--secondary" href="/?panel=profile">Masuk untuk mengikuti</a>
                        @endif
                    </div>
                    @if($canFollow)<p class="p-field-error" data-profile-follow-error role="status"></p>@endif
                </div>
            </header>
            <section aria-labelledby="portfolio-heading" class="a-public-portfolio">
                <h2 id="portfolio-heading">Portofolio properti</h2>
                <nav class="a-tab-links" aria-label="Jenis media portofolio">
                    @foreach($tabs as $key => $label)
                        <a href="{{ route('agent.public', ['agent'=>$profile['id'], ...($key==='all' ? [] : ['media'=>$key])]) }}" @if($tab===$key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
                <div class="a-video-grid">
                    @forelse($cards as $card)
                        <a class="a-video-card a-public-card" href="{{ route('post.public', $card['post']) }}">
                            <div class="a-video-cover">
                                <span class="a-video-placeholder" aria-hidden="true"><x-icon :name="$card['type'] === 'video' ? 'play' : 'home'" /></span>
                                @if($card['photo'])<img src="{{ $card['photo'] }}" alt="" loading="lazy" decoding="async">@endif
                                @if($card['frame'])<video preload="none" muted playsinline aria-hidden="true" data-portfolio-frame="{{ $card['frame'] }}#t=0.1"></video>@endif
                                <span class="a-video-cover-label">{{ $card['type'] === 'video' ? 'Video' : 'Foto' }}</span>
                            </div>
                            <span class="a-public-caption">{{ $card['title'] }}</span>
                        </a>
                    @empty
                        <p class="p-muted a-portfolio-empty">Belum ada {{ $tab === 'video' ? 'video' : ($tab === 'photo' ? 'foto' : 'listing') }} yang ditampilkan.</p>
                    @endforelse
                </div>
                {{ $page->links('agent.pagination') }}
            </section>
        </main>
        </div>
    </div>
</x-layouts.app>
