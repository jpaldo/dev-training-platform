@if ($paginator->hasPages())
    @php
        $itemClasses = 'inline-flex min-h-9.5 items-center justify-center gap-1.5 rounded-[10px] border px-3 py-2 text-[13px]';
        $linkClasses = $itemClasses.' border-line text-muted no-underline transition hover:border-brand hover:text-white focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50';
        $disabledClasses = $itemClasses.' border-line text-muted opacity-50';
        $ellipsisClasses = $itemClasses.' border-line text-muted';
        $currentClasses = $itemClasses.' border-brand bg-brand-soft font-extrabold text-white';
        $iconClasses = 'size-4.5 shrink-0';
    @endphp

    <nav class="flex flex-wrap items-center justify-between gap-3 max-sm:items-stretch max-sm:[&>a]:flex-1 max-sm:[&>span]:flex-1" role="navigation" aria-label="Pagination Navigation">
        @if ($paginator->onFirstPage())
            <span class="{{ $disabledClasses }}" aria-disabled="true">
                <svg class="{{ $iconClasses }}" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 15.293a1 1 0 01-1.414 0L6.586 10l4.707-5.293a1 1 0 011.414 1.414L8.914 10l3.793 3.879a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                <span>Previous</span>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}" class="{{ $linkClasses }}">
                <svg class="{{ $iconClasses }}" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 15.293a1 1 0 01-1.414 0L6.586 10l4.707-5.293a1 1 0 011.414 1.414L8.914 10l3.793 3.879a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                <span>Previous</span>
            </a>
        @endif

        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $ellipsisClasses }}" aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $currentClasses }}" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" class="{{ $linkClasses }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}" class="{{ $linkClasses }}">
                <span>Next</span>
                <svg class="{{ $iconClasses }}" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 4.707a1 1 0 011.414 0L13.414 10l-4.707 5.293a1 1 0 01-1.414-1.414L11.086 10 7.293 6.121a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </a>
        @else
            <span class="{{ $disabledClasses }}" aria-disabled="true">
                <span>Next</span>
                <svg class="{{ $iconClasses }}" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 4.707a1 1 0 011.414 0L13.414 10l-4.707 5.293a1 1 0 01-1.414 1.414L11.086 10 7.293 6.121a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </span>
        @endif
    </nav>
@endif
