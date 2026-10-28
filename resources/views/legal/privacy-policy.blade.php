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
            <x-ui.page-title id="legal-title">Privacy Policy</x-ui.page-title>
            <p class="m-0 text-sm text-muted">Last updated: October 28, 2026</p>
        </header>

        <p class="{{ $paragraphClasses }}">This Privacy Policy explains how the Developer Training Platform (“the platform”) handles information when you use its training services.</p>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Information used by the platform</h2>
            <p class="{{ $paragraphClasses }}">The platform uses account details such as your name, email address, employee ID, job role, role, and profile information you provide. It also stores the training information you submit, including activity responses, artifacts, evidence, participant notes, reviews, scores, and related feedback.</p>
            <p class="{{ $paragraphClasses }}">Mentors and Admins record which participants joined each week’s online session; a Mentor records attendance only for the participants assigned to them. For each attended session, the platform keeps who recorded it and when. Mentors and Admins see this attendance on the Attendance page, in its CSV export, and in the participant progress report and its CSV export; participants do not see it. Mentors and Admins may access training records needed to operate, review, and administer the program.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Google Drive connection</h2>
            <p class="{{ $paragraphClasses }}">Connecting Google Drive is optional and is separate from the manually entered Training Drive link. If you connect Google Drive, Google provides account identity information such as your Google account ID and email address. The platform requests the narrow <code class="{{ $codeClasses }}">drive.file</code> permission, which is used to work with files created by the platform or files you explicitly make available to it.</p>
            <p class="{{ $paragraphClasses }}">The connection is intended to support participant-owned training files and future supported exports. Automatic training exports and Google Sheets synchronization are not currently active features of this platform. The platform does not request Gmail, Calendar, or Contacts access.</p>
            <p class="{{ $paragraphClasses }}">OAuth access and refresh tokens are stored using application encryption and are not shown to participants, Mentors, or other users. You can disconnect Google Drive from your profile; this removes the stored connection information and the application can no longer use that authorization. You may also revoke access through your Google Account settings.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">How information is used</h2>
            <p class="{{ $paragraphClasses }}">Information is used to authenticate users, deliver training, record progress and attendance, review submissions, calculate configured scores, evaluate Gates, provide feedback, protect platform security, and administer accounts. We do not sell participant information.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Access and retention</h2>
            <p class="{{ $paragraphClasses }}">Access is limited by role. Participants can access their own training information. Assigned Mentors can perform authorized reviews for assigned participants, while Admins administer the platform. Historical submissions, evidence, reviews, scores, and Gate records may be retained to preserve training history and reporting. Information is retained for as long as reasonably needed for the training and administration purposes described here, subject to the platform owner’s retention requirements.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Security</h2>
            <p class="{{ $paragraphClasses }}">The platform uses authentication, role-based authorization, server-side access checks, encrypted OAuth token storage, and protected private evidence storage. No online service can guarantee absolute security, so please protect your account credentials and report suspected unauthorized access.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Third-party services</h2>
            <p class="{{ $paragraphClasses }}">Google OAuth and Google Drive are third-party services. Their handling of information is governed by Google’s own terms and privacy policies. The platform does not receive unrelated Google services data through this connection.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Contact</h2>
            <p class="{{ $paragraphClasses }}">Privacy questions can be sent to <a href="mailto:mprestoza@fullscale.ph" class="{{ $linkClasses }}">mprestoza@fullscale.ph</a>.</p>
        </section>

        <section class="{{ $sectionClasses }}">
            <h2 class="{{ $headingClasses }}">Changes</h2>
            <p class="{{ $paragraphClasses }}">We may update this policy when the platform or its data practices change. The updated version and effective date will be posted on this page.</p>
        </section>
    </x-ui.card>
@endsection
