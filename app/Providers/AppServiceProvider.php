<?php

namespace App\Providers;

use App\Contracts\ScoringSuggestionProvider;
use App\Models\GoogleDriveSync;
use App\Services\AiScoringProviderResolver;
use App\Services\EmailConfigurationService;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveReadinessService;
use App\Services\ParticipantNotificationService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ScoringSuggestionProvider::class, function (Application $app): ScoringSuggestionProvider {
            return $app->make(AiScoringProviderResolver::class)->resolve();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(EmailConfigurationService::class)->applyRuntimeConfiguration();

        View::composer('layouts.app', function (ViewContract $view): void {
            $user = Auth::user();
            if (! $user || $user->role !== 'participant') {
                $view->with(['participantDriveHeader' => null, 'participantNotificationUnreadCount' => 0]);

                return;
            }

            $user->loadMissing('googleDriveConnection');
            $connection = $user->googleDriveConnection;
            $latestSync = GoogleDriveSync::query()->where('user_id', $user->id)->latest('id')->first();
            $configuration = app(GoogleDriveConfigurationService::class);
            $readiness = app(GoogleDriveReadinessService::class);

            $view->with(['participantDriveHeader' => [
                'connected' => (bool) $connection,
                'destination_selected' => $readiness->isReady($user),
                'configuration_ready' => $configuration->canConnect() && filled($configuration->getPickerApiKey()) && filled($configuration->getProjectNumber()),
                'active' => $latestSync && in_array($latestSync->status, [GoogleDriveSync::ENQUEUED, GoogleDriveSync::IN_PROGRESS], true),
            ], 'participantNotificationUnreadCount' => app(ParticipantNotificationService::class)->unreadCount($user)]);
        });
    }
}
