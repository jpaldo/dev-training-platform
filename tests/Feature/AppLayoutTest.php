<?php

namespace Tests\Feature;

use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_pages_show_the_brand_and_account_links_around_the_main_content_without_a_sidebar(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeInOrder([
                '<header',
                'href="'.route('home').'">⚡ DEVELOPER TRAINING PLATFORM</a>',
                'aria-label="Account"',
                'href="'.route('login').'">Login</a>',
                'href="'.route('register').'">Register</a>',
                '</header>',
                '<main>',
                '>Sign in</h1>',
                '</main>',
                'aria-label="Legal"',
                'href="'.route('privacy-policy').'">Privacy Policy</a>',
                'href="'.route('terms-of-service').'">Terms of Service</a>',
            ], false)
            ->assertDontSee('id="app-sidebar"', false)
            ->assertDontSee('data-sidebar-toggle', false)
            ->assertDontSee('<style', false)
            ->assertDontSee('style="', false);
    }

    public function test_guest_account_links_mark_only_the_current_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeInOrder(['aria-current="page"', 'border-brand bg-brand-soft', 'href="'.route('login').'">Login</a>', 'border-line text-muted', 'href="'.route('register').'">Register</a>'], false);
        $this->assertSame(1, substr_count($this->get(route('login'))->getContent(), 'aria-current="page"'));

        $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder(['border-line text-muted', 'href="'.route('login').'">Login</a>', 'aria-current="page"', 'border-brand bg-brand-soft', 'href="'.route('register').'">Register</a>'], false);
        $this->assertSame(1, substr_count($this->get(route('register'))->getContent(), 'aria-current="page"'));

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('href="'.route('login').'">Login</a>', false)
            ->assertDontSee('aria-current="page"', false);
    }

    public function test_participant_sidebar_lists_the_training_links_with_the_unread_count_and_marks_the_current_page(): void
    {
        $participant = User::factory()->create(['name' => 'Pat Participant']);
        $participant->participantNotifications()->delete();
        ParticipantNotification::factory()->count(2)->create(['user_id' => $participant->id]);
        ParticipantNotification::factory()->create(['user_id' => $participant->id, 'is_read' => true]);

        $response = $this->actingAs($participant)->get(route('participant.notifications'))->assertOk();
        $sidebar = $this->sidebar($response);

        $this->assertSame(['Training'], $this->groupLabels($sidebar));
        $this->assertSame([
            ['Dashboard', route('dashboard'), false, 0],
            ['Attendance', route('participant.attendance.index'), false, 0],
            ['Notifications', route('participant.notifications'), true, 2],
            ['Profile', route('profile.show'), false, 0],
        ], $this->sidebarLinks($sidebar));
        $this->assertStringContainsString('<strong class="text-slate-100">Pat Participant</strong>', $sidebar);
        $this->assertStringContainsString('>Participant</span>', $sidebar);
        $this->assertStringContainsString('action="'.route('logout').'"', $sidebar);
        $this->assertStringContainsString(' data-sliding:transition ', strstr($sidebar, '>', true));
        $this->assertStringNotContainsString(' transition ', strstr($sidebar, '>', true));
        $response->assertSeeInOrder([
            'data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false">☰ Menu</button>',
            '<main>',
            '</main>',
            'aria-label="Legal"',
        ], false);
    }

    public function test_coach_sidebar_lists_the_coach_groups_without_admin_links_and_marks_the_current_page(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $sidebar = $this->sidebar($this->actingAs($coach)->get(route('coach.reviews'))->assertOk());

        $this->assertSame(['Overview', 'People', 'Review'], $this->groupLabels($sidebar));
        $this->assertSame([
            ['Mentor Dashboard', route('coach.dashboard'), false, 0],
            ['Participants', route('coach.participants'), false, 0],
            ['Attendance', route('coach.attendance.index'), false, 0],
            ['Reviews', route('coach.reviews'), true, 0],
            ['Gates', route('coach.gates'), false, 0],
            ['Scoring', route('coach.scoring.index'), false, 0],
            ['Recovery Requests', route('coach.recovery.index'), false, 0],
            ['Reports', route('coach.reports'), false, 0],
            ['Profile', route('profile.show'), false, 0],
        ], $this->sidebarLinks($sidebar));
        $this->assertStringContainsString('>Mentor</span>', $sidebar);
    }

    public function test_admin_sidebar_adds_the_admin_group_and_marks_only_the_matching_settings_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $sidebar = $this->sidebar($this->actingAs($admin)->get(route('admin.settings.google-drive'))->assertOk());

        $this->assertSame(['Overview', 'People', 'Review', 'Admin'], $this->groupLabels($sidebar));
        $this->assertSame([
            ['Admin Dashboard', route('admin.dashboard'), false, 0],
            ['Training Sessions', route('admin.sessions.index'), false, 0],
            ['Curriculum', route('admin.programs.index'), false, 0],
            ['Users / Participants', route('admin.users.index'), false, 0],
            ['Settings', route('admin.settings.index'), false, 0],
            ['Google Drive', route('admin.settings.google-drive'), true, 0],
            ['AI', route('admin.settings.ai'), false, 0],
            ['Profile', route('profile.show'), false, 0],
        ], array_slice($this->sidebarLinks($sidebar), -8));
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));
        $this->assertStringContainsString('>Admin</span>', $sidebar);
    }

    public function test_admin_sidebar_marks_curriculum_as_the_current_page_on_a_program_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();

        $sidebar = $this->sidebar($this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk());

        $this->assertSame([['Curriculum', route('admin.programs.index'), true, 0]], array_values(array_filter(
            $this->sidebarLinks($sidebar),
            fn (array $link): bool => $link[2],
        )));
    }

    public function test_profile_link_is_marked_as_the_current_page_on_the_profile(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $sidebar = $this->sidebar($this->actingAs($coach)->get(route('profile.show'))->assertOk());

        $this->assertSame([['Profile', route('profile.show'), true, 0]], array_values(array_filter(
            $this->sidebarLinks($sidebar),
            fn (array $link): bool => $link[2],
        )));
    }

    /**
     * The rendered `#app-sidebar` aside.
     */
    private function sidebar(TestResponse $response): string
    {
        $html = $response->getContent();
        $start = strpos($html, 'id="app-sidebar"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</aside>', $start) - $start);
    }

    /**
     * @return list<string>
     */
    private function groupLabels(string $sidebar): array
    {
        preg_match_all('/id="sidebar-group-\d+"[^>]*>([^<]*)</', $sidebar, $matches);

        return $matches[1];
    }

    /**
     * Each sidebar link in order as [label, URL, is current page, badge count].
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: int}>
     */
    private function sidebarLinks(string $sidebar): array
    {
        preg_match_all('/<a href="([^"]*)"(\s+aria-current="page")?[^>]*>\s*<span>([^<]*)<\/span>(?:\s*<span[^>]*>(\d+)<\/span>)?/', $sidebar, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            $match[3],
            html_entity_decode($match[1]),
            filled($match[2] ?? null),
            (int) ($match[4] ?? 0),
        ], $matches);
    }
}
