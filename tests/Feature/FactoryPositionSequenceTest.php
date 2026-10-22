<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TrainingWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryPositionSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_rows_take_the_next_free_position_within_their_parent(): void
    {
        [$week, $otherWeek] = TrainingWeek::factory()->count(2)->create()->all();

        $first = Activity::factory()->for($week, 'week')->create();
        $pinned = Activity::factory()->for($week, 'week')->create(['position' => 5]);
        $batch = Activity::factory()->for($week, 'week')->count(2)->create();
        $otherWeekActivity = Activity::factory()->for($otherWeek, 'week')->create();

        $this->assertSame(1, $first->position);
        $this->assertSame(5, $pinned->position);
        $this->assertSame([6, 7], $batch->pluck('position')->all());
        $this->assertSame(1, $otherWeekActivity->position);
    }
}
