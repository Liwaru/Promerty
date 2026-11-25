@props(['title' => 'Promerty - Find Your Property'])
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    @include('partials.promerty-head')
</head>
<body>
    <a class="p-skip" href="#main">Lewati navigasi</a>
    {{ $slot }}
</body>
</html>
