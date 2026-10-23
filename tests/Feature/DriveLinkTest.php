<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriveLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_ignores_a_posted_drive_link_and_keeps_the_stored_one(): void
    {
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/stored']);

        $this->actingAs($participant)->patch(route('profile.update'), [
            'first_name' => 'Renamed',
            'last_name' => 'Participant',
            'email' => $participant->email,
            'drive_link' => 'not-a-url',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.show'));

        $participant->refresh();
        $this->assertSame('Renamed Participant', $participant->name);
        $this->assertSame('https://drive.google.com/drive/folders/stored', $participant->drive_link);
    }

    public function test_coach_and_admin_can_view_drive_link_but_participant_cannot_view_other_profiles(): void
    {
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/example', 'training_path' => 'automation']);
        $other = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/private']);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk()->assertSee('Open Training Drive')->assertSee('target="_blank"', false)->assertSee('rel="noopener noreferrer"', false)->assertSee($participant->drive_link);
        $this->actingAs($admin)->get(route('admin.users.edit', $participant))->assertOk()->assertSee('Open Training Drive')->assertSee($participant->drive_link);
        $this->actingAs($participant)->get(route('coach.participants.show', $other))->assertForbidden();
    }

    public function test_missing_drive_link_is_displayed_as_not_provided(): void
    {
        $participant = User::factory()->create(['drive_link' => null]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);

        $this->actingAs($coach)->get(route('coach.participants.show', $participant))->assertOk()->assertSee('Training Drive')->assertSee('Not provided');
    }
}
