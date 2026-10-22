<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_unknown_url_shows_the_themed_not_found_page_without_querying_the_database(): void
    {
        DB::enableQueryLog();

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSeeInOrder([
                '<title>Page not found · Developer Training Platform</title>',
                '<body class="m-0 min-h-screen bg-canvas',
                'href="'.route('home').'">⚡ DEVELOPER TRAINING PLATFORM</a>',
                'rounded-2xl border border-line bg-surface', 'aria-labelledby="error-title"',
                '>Error 404</div>',
                'id="error-title"', '>Page not found</h1>',
                'The page you are looking for does not exist or has moved.',
                'href="'.route('home').'"', '>Go to the home page</a>',
                'aria-label="Legal"',
            ], false);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_signed_in_user_without_access_sees_the_themed_forbidden_page_without_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('dashboard'))
            ->assertForbidden()
            ->assertSeeInOrder(['bg-canvas', '>Error 403</div>', '>Access denied</h1>', 'You do not have permission to view this page.'], false)
            ->assertDontSee('id="app-sidebar"', false);
    }

    public function test_the_forbidden_page_shows_the_abort_message_escaped(): void
    {
        $this->view('errors.403', ['exception' => new HttpException(403, 'Please <b>check in</b> to continue editing this activity.')])
            ->assertSee('Please &lt;b&gt;check in&lt;/b&gt; to continue editing this activity.', false)
            ->assertDontSee('You do not have permission to view this page.');
    }

    #[DataProvider('directlyRenderedPages')]
    public function test_each_error_page_renders_with_the_theme_without_a_user_or_database(string $view, string $code, string $title, string $message): void
    {
        DB::enableQueryLog();

        $this->view($view)
            ->assertSeeInOrder(['bg-canvas', ">Error {$code}</div>", ">{$title}</h1>", $message, '>Go to the home page</a>'], false);

        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * The error views rendered directly (a 500 would otherwise show the debug page in tests), with their code, title
     * and message.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function directlyRenderedPages(): array
    {
        return [
            '419' => ['errors.419', '419', 'Page expired', 'Your session expired. Go back, refresh the page and try again.'],
            '500' => ['errors.500', '500', 'Something went wrong', 'An unexpected error occurred on our side. Please try again in a moment.'],
            '503' => ['errors.503', '503', 'Service unavailable', 'The platform is down for maintenance. Please try again shortly.'],
        ];
    }

    public function test_framework_error_pages_without_their_own_view_use_the_themed_shell(): void
    {
        Route::get('/too-many-requests-test', fn (): never => abort(429));

        $this->get('/too-many-requests-test')
            ->assertTooManyRequests()
            ->assertSeeInOrder(['bg-canvas', '>Error 429</div>', '>Too Many Requests</h1>', '>Go to the home page</a>'], false);
    }
}
