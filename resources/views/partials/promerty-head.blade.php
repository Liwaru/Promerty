@php($faviconVersion = substr(hash_file('sha256', public_path('favicon.ico')), 0, 12))
<link rel="icon" type="image/x-icon" sizes="16x16 32x32 48x48" href="{{ asset('favicon.ico') }}?v={{ $faviconVersion }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}?v={{ $faviconVersion }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('favicon-192x192.png') }}?v={{ $faviconVersion }}">
<script>{!! file_get_contents(resource_path('js/theme-init.js')) !!}</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
