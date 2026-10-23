{{--
    A participant's GitHub repository for a training session, on the mentor's review, scoring and Gate checklist pages:
    the link (opening in a new tab), or "No repository yet" when the participant has not added one.

    $url: ?string (a link GitHubRepositoryUrl accepted)
--}}
@props(['url' => null])

<p {{ $attributes->class(['m-0 leading-relaxed wrap-anywhere text-muted']) }}>
    Repository:
    @if($url)
        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="font-bold break-all text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $url }}<span class="sr-only"> (opens in a new tab)</span></a>
    @else
        No repository yet
    @endif
</p>
