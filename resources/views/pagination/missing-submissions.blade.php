@if ($paginator->hasPages())
    <nav class="report-pagination" role="navigation" aria-label="Pagination Navigation">
        @if ($paginator->onFirstPage())
            <span class="disabled" aria-disabled="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 15.293a1 1 0 01-1.414 0L6.586 10l4.707-5.293a1 1 0 011.414 1.414L8.914 10l3.793 3.879a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                <span>Previous</span>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 15.293a1 1 0 01-1.414 0L6.586 10l4.707-5.293a1 1 0 011.414 1.414L8.914 10l3.793 3.879a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                <span>Previous</span>
            </a>
        @endif

        <div class="pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="current" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">
                <span>Next</span>
                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 4.707a1 1 0 011.414 0L13.414 10l-4.707 5.293a1 1 0 01-1.414-1.414L11.086 10 7.293 6.121a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </a>
        @else
            <span class="disabled" aria-disabled="true">
                <span>Next</span>
                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 4.707a1 1 0 011.414 0L13.414 10l-4.707 5.293a1 1 0 01-1.414 1.414L11.086 10 7.293 6.121a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </span>
        @endif
    </nav>
@endif
