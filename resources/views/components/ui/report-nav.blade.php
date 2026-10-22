@props(['current'])

@php
    $reportLinks = [
        'progress' => ['label' => 'Progress', 'url' => route('coach.reports')],
        'missing-submissions' => ['label' => 'Missing Submissions', 'url' => route('coach.reports.missing-submissions')],
    ];
@endphp

<nav {{ $attributes }} aria-label="Reports">
    <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
        @foreach($reportLinks as $key => $link)
            <li>
                <a href="{{ $link['url'] }}" @if($key === $current) aria-current="page" @endif @class([
                    'inline-flex rounded-full border px-3.5 py-2 text-sm font-bold no-underline transition focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50',
                    'border-brand bg-brand-soft text-white' => $key === $current,
                    'border-line text-muted hover:border-brand hover:text-slate-100' => $key !== $current,
                ])>{{ $link['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
