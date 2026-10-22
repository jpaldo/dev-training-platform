<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_to_the_login_page(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    #[DataProvider('landingPages')]
    public function test_signed_in_user_is_redirected_to_their_role_landing_page(string $role, string $path): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('home'))->assertRedirect($path);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function landingPages(): array
    {
        return [
            'participant' => ['participant', '/dashboard'],
            'coach' => ['coach', '/coach'],
            'admin' => ['admin', '/admin'],
        ];
    }
}
