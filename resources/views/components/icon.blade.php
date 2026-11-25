@props(['name' => 'home'])
<svg {{ $attributes->class(['p-icon']) }} viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('search') <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/> @break
        @case('heart') <path d="M20 4c-3-2-6 0-8 2-2-2-5-4-8-2-4 3-1 8 8 15C21 12 24 7 20 4Z"/> @break
        @case('chat') <path d="M4 4h16v12H9l-5 4Z"/><path d="M8 8h8M8 12h5"/> @break
        @case('user') <circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/> @break
        @case('arrow') <path d="M3 12h18m-6-6 6 6-6 6"/> @break
        @case('check') <path d="m5 12 4 4L19 6"/> @break
        @case('play') <path d="m9 5 11 7-11 7Z"/> @break
        @case('saved') <path d="M6 3h12v18l-6-4-6 4Z"/> @break
        @case('activity') <path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5ZM10 21h4"/> @break
        @case('upload') <path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/> @break
        @case('more') <circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/> @break
        @case('settings') <path d="m10 3-1 3-3 1-3 3 2 2-1 3 3 3 3-1 2 2 3-2 1-3 3-1v-4l-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/> @break
        @case('sun') <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/> @break
        @case('moon') <path d="M20 14A8.5 8.5 0 0 1 10 4a8.5 8.5 0 1 0 10 10Z"/> @break
        @case('monitor') <rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/> @break
        @case('help') <circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 5m0 3h.01"/> @break
        @case('login') <path d="M10 4H4v16h6m4-12 4 4-4 4m-6-4h13"/> @break
        @case('lock') <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/> @break
        @default <path d="m3 10 9-7 9 7v11H3Z"/><path d="M9 21v-8h6v8"/>
    @endswitch
</svg>
