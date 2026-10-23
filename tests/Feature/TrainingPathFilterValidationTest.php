<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainingPathFilterValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('pathFilteredRoutes')]
    public function test_path_filter_accepts_a_training_path(string $routeName, string $role): void
    {
        Program::factory()->create();

        $this->actingAs(User::factory()->create(['role' => $role, 'training_path' => null]))
            ->get(route($routeName, ['path' => 'automation_manual']))
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    #[DataProvider('pathFilteredRoutes')]
    public function test_path_filter_rejects_a_value_that_is_not_a_training_path(string $routeName, string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role, 'training_path' => null]))
            ->get(route($routeName, ['path' => 'qa_manual']))
            ->assertSessionHasErrors(['path' => 'The selected path is invalid.']);
    }

    #[DataProvider('pathFilterPages')]
    public function test_path_filter_offers_every_training_path_with_its_label(string $routeName, string $role): void
    {
        Program::factory()->create();

        $this->actingAs(User::factory()->create(['role' => $role, 'training_path' => null]))
            ->get(route($routeName, ['path' => 'automation_manual']))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="manual" >Manual</option>',
                '<option value="automation" >Automation</option>',
                '<option value="automation_manual" selected>Automation / Manual</option>',
            ], false);
    }

    /**
     * Every page with a QA Path filter select, and the role that opens it.
     *
     * @return array<string, array{string, string}>
     */
    public static function pathFilterPages(): array
    {
        return [
            'attendance' => ['coach.attendance.index', 'coach'],
            'participants' => ['coach.participants', 'coach'],
            'gate board' => ['coach.gates', 'coach'],
            'progress report' => ['coach.reports', 'coach'],
            'missing submissions' => ['coach.reports.missing-submissions', 'coach'],
            'scoring overview' => ['coach.scoring.index', 'coach'],
            'scoring reports' => ['coach.scoring.reports', 'coach'],
        ];
    }

    /**
     * Every page and export with a QA Path filter, and the role that opens it.
     *
     * @return array<string, array{string, string}>
     */
    public static function pathFilteredRoutes(): array
    {
        return [
            'attendance' => ['coach.attendance.index', 'coach'],
            'participants' => ['coach.participants', 'coach'],
            'gate board' => ['coach.gates', 'coach'],
            'review queue' => ['coach.reviews', 'coach'],
            'progress report' => ['coach.reports', 'coach'],
            'progress export' => ['coach.reports.export', 'coach'],
            'missing submissions' => ['coach.reports.missing-submissions', 'coach'],
            'scoring overview' => ['coach.scoring.index', 'coach'],
            'scoring reports' => ['coach.scoring.reports', 'coach'],
        ];
    }
}
