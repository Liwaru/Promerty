@if($paginator->hasPages())
    <nav class="p-row a-pagination" aria-label="Halaman hasil">
        @if($paginator->previousPageUrl())
            <a class="p-button p-button--secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">Sebelumnya</a>
        @endif
        <span class="p-muted">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
        @if($paginator->nextPageUrl())
            <a class="p-button p-button--secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya</a>
        @endif
    </nav>
@endif
