@php
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();

    if ($lastPage <= 4) {
        $visiblePages = range(1, $lastPage);
    } elseif ($currentPage <= 3) {
        $visiblePages = [1, 2, 3, null, $lastPage];
    } elseif ($currentPage >= $lastPage - 2) {
        $visiblePages = [1, null, $lastPage - 2, $lastPage - 1, $lastPage];
    } else {
        $visiblePages = [1, null, $currentPage - 1, $currentPage, $currentPage + 1, null, $lastPage];
    }
@endphp

<div class="{{ ($showSummary ?? true) ? 'flex flex-col gap-3 py-2 sm:flex-row sm:items-center sm:justify-between' : 'flex items-center justify-end' }}">
    @if($showSummary ?? true)
        <p class="text-sm text-slate-600">
            Menampilkan <span class="font-semibold text-slate-800">{{ $paginator->firstItem() ?? 0 }}</span>
            sampai <span class="font-semibold text-slate-800">{{ $paginator->lastItem() ?? 0 }}</span>
            dari <span class="font-semibold text-slate-800">{{ $paginator->total() }}</span> {{ $label ?? 'data' }}
        </p>
    @endif

    @if($paginator->hasPages())
        <nav class="flex flex-wrap items-center justify-end gap-2" aria-label="Navigasi halaman">
            @if($paginator->onFirstPage())
                <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-xl text-slate-300" aria-label="Halaman sebelumnya">&lsaquo;</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-xl text-slate-700 hover:bg-slate-50" aria-label="Halaman sebelumnya">&lsaquo;</a>
            @endif

            @foreach($visiblePages as $page)
                @if($page === null)
                    <span class="px-1 text-sm text-slate-500" aria-hidden="true">&hellip;</span>
                @elseif($page === $currentPage)
                    <span class="flex h-10 min-w-10 items-center justify-center rounded-xl bg-[#7B1113] px-3 text-sm font-semibold text-white" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $paginator->url($page) }}" class="flex h-10 min-w-10 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm text-slate-700 hover:bg-slate-50">{{ $page }}</a>
                @endif
            @endforeach

            @if($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-xl text-slate-700 hover:bg-slate-50" aria-label="Halaman berikutnya">&rsaquo;</a>
            @else
                <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-xl text-slate-300" aria-label="Halaman berikutnya">&rsaquo;</span>
            @endif
        </nav>
    @endif
</div>
