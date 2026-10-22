<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_sees_the_hero_with_its_auth_actions_the_three_tracks_and_legal_links(): void
    {
        $response = $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder([
                'DEVELOPER TRAINING PLATFORM',
                '>Validate with confidence.</h1>',
                'A practical training platform for Manual QA and Automation QA participants. Build evidence-backed AI-assisted QA habits across Weeks 2, 3, and 4.',
                'href="'.route('login').'"',
                '>Log in</a>',
                'href="'.route('register').'"',
                '>Create account</a>',
                '>MANUAL QA</h2>',
                'Validate requirements, behavior, evidence, and risk with disciplined human judgment.',
                '>AUTOMATION QA</h2>',
                'Generate, execute, and review framework-aligned tests with evidence.',
                '>WEEKS 2–4</h2>',
                'Progress sequentially through activities, Gates, feedback, and completion.',
                'href="'.route('privacy-policy').'"',
                '>Privacy Policy</a>',
                'href="'.route('terms-of-service').'"',
                '>Terms of Service</a>',
            ], false);

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('privacy-policy').'"'));
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('terms-of-service').'"'));
    }
}
