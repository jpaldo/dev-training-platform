<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_sees_the_privacy_policy_sections_in_order(): void
    {
        $response = $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('<title>Privacy Policy</title>', false)
            ->assertSeeInOrder([
                'LEGAL',
                '>Privacy Policy</h1>',
                'Last updated: October 28, 2026',
                'This Privacy Policy explains how the Developer Training Platform (“the platform”) handles information when you use its training services.',
                '>Information used by the platform</h2>',
                'The platform uses account details such as your name, email address, employee ID, job role, role, and profile information you provide.',
                'Mentors and Admins record which participants joined each week’s online session;',
                'For each attended session, the platform keeps who recorded it and when.',
                '>Google Drive connection</h2>',
                '>drive.file</code> permission',
                'The platform does not request Gmail, Calendar, or Contacts access.',
                '>How information is used</h2>',
                'Information is used to authenticate users, deliver training, record progress and attendance,',
                'We do not sell participant information.',
                '>Access and retention</h2>',
                '>Security</h2>',
                '>Third-party services</h2>',
                '>Contact</h2>',
                'Privacy questions can be sent to <a href="mailto:mprestoza@fullscale.ph"',
                '>mprestoza@fullscale.ph</a>.',
                '>Changes</h2>',
                'The updated version and effective date will be posted on this page.',
            ], false)
            ->assertDontSee('QA training')
            ->assertDontSee('QA path')
            ->assertDontSee('check-in')
            ->assertDontSee('check-out');

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
    }

    public function test_guest_sees_the_terms_of_service_sections_in_order(): void
    {
        $response = $this->get(route('terms-of-service'))
            ->assertOk()
            ->assertSee('<title>Terms of Service</title>', false)
            ->assertSeeInOrder([
                'LEGAL',
                '>Terms of Service</h1>',
                'Last updated: September 11, 2026',
                'These Terms govern your use of the Developer Training Platform.',
                '>Acceptable use</h2>',
                'Use the platform only for authorized training and related program activities.',
                '>Training submissions and evidence</h2>',
                '>Content and intellectual property</h2>',
                '>Roles and reviews</h2>',
                '>Google Drive integration</h2>',
                '>drive.file</code> scope',
                '>Prohibited conduct</h2>',
                '>Suspension and termination</h2>',
                '>Availability and disclaimers</h2>',
                '>Limitation of liability</h2>',
                '>Changes and contact</h2>',
                'Questions can be sent to <a href="mailto:mprestoza@fullscale.ph"',
                '>mprestoza@fullscale.ph</a>.',
                '>Governing law</h2>',
                'To be confirmed by the platform owner:</strong> governing law and jurisdiction should be specified here before production publication.',
            ], false)
            ->assertDontSee('QA training');

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
    }
}
