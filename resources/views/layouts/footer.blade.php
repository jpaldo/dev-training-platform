<footer class="mt-12 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 border-t border-line py-6 text-center text-sm text-muted" aria-label="Legal">
    <span>© {{ date('Y') }} Developer Training Platform</span>
    <span class="flex items-center gap-x-2 whitespace-nowrap">
        <a class="rounded underline underline-offset-3 hover:text-slate-100 focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('privacy-policy') }}">Privacy Policy</a>
        <span aria-hidden="true">·</span>
        <a class="rounded underline underline-offset-3 hover:text-slate-100 focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('terms-of-service') }}">Terms of Service</a>
    </span>
</footer>
