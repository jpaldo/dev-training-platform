<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TrainingGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_dashboard_lists_the_week_guides(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $this->actingAs($participant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('training.guides.view', 2), false)
            ->assertSee(route('training.guides.view', 3), false)
            ->assertSee(route('training.guides.view', 4), false)
            ->assertSee('Download Guide');
    }

    public function test_known_guides_are_served_inline_or_as_downloads(): void
    {
        $participant = User::factory()->create();

        foreach ([2, 3, 4] as $week) {
            $this->actingAs($participant)
                ->get(route('training.guides.view', $week))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'inline; filename="Week_'.$week.'_Activitiy_Guide.pdf"');

            $this->actingAs($participant)
                ->get(route('training.guides.download', $week))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'attachment; filename=Week_'.$week.'_Activitiy_Guide.pdf');
        }
    }

    public function test_unknown_guide_is_not_a_filesystem_proxy(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get('/training/guides/1')->assertNotFound();
        $this->actingAs($participant)->get('/training/guides/%2e%2e%2f.env')->assertNotFound();
        Auth::logout();
        $this->get('/training/guides/2')->assertRedirect(route('login'));
    }
}
