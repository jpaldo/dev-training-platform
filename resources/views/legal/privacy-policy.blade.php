@extends('layouts.app')
@section('content')
<article class="card legal-page" style="max-width:900px;margin:0 auto">
    <div class="eyebrow">LEGAL</div><h1>Privacy Policy</h1>
    <p class="muted">Last updated: September 11, 2026</p>
    <p>This Privacy Policy explains how the Developer Training Platform (“the platform”) handles information when you use its QA training services.</p>
    <h2>Information used by the platform</h2>
    <p>The platform uses account details such as your name, email address, role, QA path, and profile information you provide. It also stores the training information you submit, including activity responses, artifacts, evidence, participant notes, Validation Lab records, reviews, scores, and related feedback.</p>
    <p>When you use attendance features, the platform records check-in and check-out timestamps and the resulting session duration. Coaches/Mentors and Admins may access training records needed to operate, review, and administer the program.</p>
    <h2>Google Drive connection</h2>
    <p>Connecting Google Drive is optional and is separate from the manually entered Training Drive link. If you connect Google Drive, Google provides account identity information such as your Google account ID and email address. The platform requests the narrow <code>drive.file</code> permission, which is used to work with files created by the platform or files you explicitly make available to it.</p>
    <p>The connection is intended to support participant-owned training files and future supported exports. Automatic training exports and Google Sheets synchronization are not currently active features of this platform. The platform does not request Gmail, Calendar, or Contacts access.</p>
    <p>OAuth access and refresh tokens are stored using application encryption and are not shown to participants, Coaches/Mentors, or other users. You can disconnect Google Drive from your profile; this removes the stored connection information and the application can no longer use that authorization. You may also revoke access through your Google Account settings.</p>
    <h2>How information is used</h2>
    <p>Information is used to authenticate users, deliver QA training, record progress and attendance, review submissions, calculate configured scores, evaluate Gates, provide feedback, protect platform security, and administer accounts. We do not sell participant information.</p>
    <h2>Access and retention</h2>
    <p>Access is limited by role. Participants can access their own training information. Assigned Coaches/Mentors can perform authorized reviews for assigned participants, while Admins administer the platform. Historical submissions, evidence, reviews, scores, and Gate records may be retained to preserve training history and reporting. Information is retained for as long as reasonably needed for the training and administration purposes described here, subject to the platform owner’s retention requirements.</p>
    <h2>Security</h2>
    <p>The platform uses authentication, role-based authorization, server-side access checks, encrypted OAuth token storage, and protected private evidence storage. No online service can guarantee absolute security, so please protect your account credentials and report suspected unauthorized access.</p>
    <h2>Third-party services</h2>
    <p>Google OAuth and Google Drive are third-party services. Their handling of information is governed by Google’s own terms and privacy policies. The platform does not receive unrelated Google services data through this connection.</p>
    <h2>Contact</h2>
    <p>Privacy questions can be sent to <a href="mailto:mprestoza@fullscale.ph">mprestoza@fullscale.ph</a>.</p>
    <h2>Changes</h2>
    <p>We may update this policy when the platform or its data practices change. The updated version and effective date will be posted on this page.</p>
</article>
@endsection
