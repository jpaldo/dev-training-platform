<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriveLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_save_optional_drive_link_and_it_is_trimmed(): void
    {
        $participant = User::factory()->create();
        $this->actingAs($participant)->get(route('profile.show'))->assertOk()->assertSee('Drive Link')->assertSee('Google Drive folder');
        $this->actingAs($participant)->patch(route('profile.update'), ['name' => $participant->name, 'email' => $participant->email, 'training_path' => 'manual', 'drive_link' => '  https://drive.google.com/drive/folders/example  '])->assertRedirect();
        $this->assertSame('https://drive.google.com/drive/folders/example', $participant->fresh()->drive_link);
        $this->actingAs($participant)->patch(route('profile.update'), ['name' => $participant->name, 'email' => $participant->email, 'training_path' => 'manual', 'drive_link' => ''])->assertRedirect();
        $this->assertNull($participant->fresh()->drive_link);
    }

    public function test_invalid_or_excessively_long_drive_link_is_rejected(): void
    {
        $participant = User::factory()->create();
        $payload = ['name' => $participant->name, 'email' => $participant->email, 'training_path' => 'manual', 'drive_link' => 'not-a-url'];
        $this->actingAs($participant)->patch(route('profile.update'), $payload)->assertSessionHasErrors('drive_link');
        $payload['drive_link'] = 'https://example.com/'.str_repeat('a', 2048);
        $this->actingAs($participant)->patch(route('profile.update'), $payload)->assertSessionHasErrors('drive_link');
    }

    public function test_non_folder_google_drive_links_are_rejected(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->patch(route('profile.update'), [
            'name' => $participant->name,
            'email' => $participant->email,
            'training_path' => 'manual',
            'drive_link' => 'https://drive.google.com/file/d/file-id/view',
        ])->assertSessionHasErrors('drive_link');
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
