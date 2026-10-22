<?php

namespace App\Services;

use App\Enums\InterviewType;
use App\Models\ApplicationSetting;
use App\Models\InterviewScheduler;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class InterviewBookingLinkService
{
    public const LOGO_KEY = 'interview_scheduling.public_logo_path';

    public const TITLE_KEY = 'interview_scheduling.public_page_title';

    /**
     * The mentor's active Mock Interview booking listing: the newest active `Mock` or `Both` listing linked to their
     * account.
     */
    public function forMentor(User $mentor): ?InterviewScheduler
    {
        return InterviewScheduler::query()->whereIn('interview_type', InterviewType::offering(InterviewType::Mock))->where('user_id', $mentor->id)->where('is_active', true)->latest('id')->first();
    }

    public function bookingUrl(InterviewScheduler $interviewer, InterviewType $type): ?string
    {
        $url = $type === InterviewType::FinalReassessment
            ? $interviewer->final_booking_url
            : $interviewer->mock_booking_url;

        return $url ?: $interviewer->booking_url;
    }

    public function publicTitle(): string
    {
        $title = trim((string) ApplicationSetting::query()->where('key', self::TITLE_KEY)->value('value'));

        return $title !== '' ? $title : 'Developer Training Platform';
    }

    public function savePublicTitle(?string $title): void
    {
        $title = trim((string) $title);
        if ($title === '') {
            ApplicationSetting::query()->where('key', self::TITLE_KEY)->delete();

            return;
        }
        ApplicationSetting::query()->updateOrCreate(['key' => self::TITLE_KEY], ['value' => $title, 'is_encrypted' => false]);
    }

    public function logoPath(): ?string
    {
        return ApplicationSetting::query()->where('key', self::LOGO_KEY)->value('value');
    }

    public function saveLogo(UploadedFile $file): string
    {
        $old = $this->logoPath();
        $path = $file->store('public-interview', 'local');
        if ($old && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
        }
        ApplicationSetting::query()->updateOrCreate(['key' => self::LOGO_KEY], ['value' => $path, 'is_encrypted' => false]);

        return $path;
    }

    public function removeLogo(): void
    {
        $path = $this->logoPath();
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
        ApplicationSetting::query()->where('key', self::LOGO_KEY)->delete();
    }
}
