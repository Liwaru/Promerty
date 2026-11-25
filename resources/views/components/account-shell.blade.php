@props(['active'])
<div class="a-workspace">
    <x-promerty-sidebar :active="$active" />
    <div class="a-content-with-sidebar">
        {{ $slot }}
    </div>
</div>
