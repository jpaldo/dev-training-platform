@extends('layouts.app')

@section('content')
    @php
        $sectionClasses = 'grid gap-3';
        $headingClasses = 'm-0 text-xl font-extrabold text-white';
        $paragraphClasses = 'm-0 leading-relaxed';
        $codeClasses = 'rounded-md border border-line bg-field px-1.5 py-0.5 text-sm';
        $linkClasses = 'font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <x-ui.card as="article" class="mx-auto grid w-full max-w-3xl min-w-0 grid-cols-1 gap-8 wrap-break-word sm:p-8" aria-labelledby="legal-title">
        <header class="grid gap-2">
            <x-ui.eyebrow>LEGAL</x-ui.eyebrow>
            <h1 id="legal-title" class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black text-transparent sm:text-5xl/tight">Terms of Service</h1>
            <p class="m-0 text-sm text-muted">Last updated: September 11, 2026</p>
        </header>

        <p class="{{ $paragraphClasses }}">These Terms govern your use of the Developer Training Platform. By using the platform, you agree to use it responsibly and consistently with these Terms.</p>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Acceptable use</h2>
            <p class="{{ $paragraphClasses }}">Use the platform only for authorized QA training and related program activities. Keep your account information accurate, protect your credentials, and do not attempt to access another person’s account, submissions, evidence, scores, files, or private data.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Training submissions and evidence</h2>
            <p class="{{ $paragraphClasses }}">You are responsible for the accuracy, legality, and appropriateness of content you submit, including artifacts, notes, links, files, screenshots, and other evidence. Do not upload malware, credentials, confidential information you are not authorized to share, or content that infringes another person’s rights.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Content and intellectual property</h2>
            <p class="{{ $paragraphClasses }}">You retain ownership of content you are entitled to own. You grant the platform the limited permission needed to store, display, review, score, and administer that content within the training program. Platform software, design, documentation, and program materials remain the property of their respective owners and may not be copied or misused without permission.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Roles and reviews</h2>
            <p class="{{ $paragraphClasses }}">Participants use the platform for training. Coaches/Mentors review assigned participants’ work and provide feedback. Admins manage users, assignments, configuration, and program operations. Scores, reviews, and Gate decisions are subject to the platform’s configured workflows and authorization controls.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Google Drive integration</h2>
            <p class="{{ $paragraphClasses }}">Google Drive connection is optional. If you authorize it, you authorize the platform to use the limited <code class="{{ $codeClasses }}">drive.file</code> scope for supported participant-owned files. You remain responsible for your Google account and Drive content. Automatic exports and Google Sheets synchronization are not currently active features. You can disconnect the integration from your profile or revoke it through Google.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Prohibited conduct</h2>
            <p class="{{ $paragraphClasses }}">You must not interfere with the platform, bypass access controls, probe or attack its systems, impersonate another user, submit harmful code or content, misuse evidence or third-party links, or use the platform for unlawful purposes.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Suspension and termination</h2>
            <p class="{{ $paragraphClasses }}">The platform owner may suspend or terminate access for security, misuse, non-compliance, program administration, or other legitimate operational reasons. Historical training records may be retained where needed for program integrity, reporting, or legal obligations.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Availability and disclaimers</h2>
            <p class="{{ $paragraphClasses }}">The platform is provided for training and administrative use. Availability, third-party services, links, and connected integrations may occasionally be interrupted or unavailable. Training feedback and scores are not a guarantee of employment, certification, or any particular outcome.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Limitation of liability</h2>
            <p class="{{ $paragraphClasses }}">To the extent permitted by applicable law, the platform owner is not liable for indirect, incidental, special, or consequential loss arising from use of the platform or third-party services. Nothing in these Terms limits rights or liability that cannot lawfully be limited.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Changes and contact</h2>
            <p class="{{ $paragraphClasses }}">These Terms may be updated as the platform changes. The current version and effective date will be posted here. Questions can be sent to <a href="mailto:mprestoza@fullscale.ph" class="{{ $linkClasses }}">mprestoza@fullscale.ph</a>.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Governing law</h2>
            <p class="{{ $paragraphClasses }}"><strong class="font-extrabold">To be confirmed by the platform owner:</strong> governing law and jurisdiction should be specified here before production publication.</p>
        </section>
    </x-ui.card>
@endsection
