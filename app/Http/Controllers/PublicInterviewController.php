<?php

namespace App\Http\Controllers;

use App\Enums\InterviewerApprovalStatus;
use App\Http\Requests\StoreInterviewerRegistrationRequest;
use App\Models\InterviewScheduler;
use App\Services\InterviewBookingLinkService;
use App\Services\PublicInterviewPageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicInterviewController extends Controller
{
    public function index(PublicInterviewPageService $page): View
    {
        return view('public.interviews', $page->indexData());
    }

    public function register(PublicInterviewPageService $page): View
    {
        return view('public.interview-register', $page->registerData());
    }

    public function storeRegistration(StoreInterviewerRegistrationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $name = trim($data['full_name']);
        $mockUrl = isset($data['mock_booking_url']) ? $this->normalizeBookingUrl((string) $data['mock_booking_url']) : null;
        $finalUrl = isset($data['final_booking_url']) ? $this->normalizeBookingUrl((string) $data['final_booking_url']) : null;
        $duplicate = InterviewScheduler::query()->whereIn('approval_status', [InterviewerApprovalStatus::Pending, InterviewerApprovalStatus::Approved])->whereRaw('LOWER(full_name) = ?', [mb_strtolower($name)])->where(function (Builder $query) use ($mockUrl, $finalUrl): void {
            $query->where(function (Builder $query) use ($mockUrl, $finalUrl): void {
                $query->where('mock_booking_url', $mockUrl)->where('final_booking_url', $finalUrl);
            })->orWhere(function (Builder $query) use ($mockUrl, $finalUrl): void {
                $query->whereNull('mock_booking_url')->whereNull('final_booking_url')->where('booking_url', $mockUrl ?: $finalUrl);
            });
        })->exists();
        if ($duplicate) {
            return redirect()->route('interviews.register')->with('status', 'Thank you. This interviewer information is already pending review or approved.');
        }

        InterviewScheduler::create(['interview_type' => $data['interview_type'], 'full_name' => $name, 'booking_url' => $mockUrl ?: $finalUrl, 'mock_booking_url' => $mockUrl, 'final_booking_url' => $finalUrl, 'is_active' => false, 'display_order' => 0, 'approval_status' => InterviewerApprovalStatus::Pending, 'submitted_publicly' => true]);

        return redirect()->route('interviews.register')->with('status', 'Thank you. Your interviewer information has been submitted for review.');
    }

    private function normalizeBookingUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public function logo(InterviewBookingLinkService $links): Response
    {
        $path = $links->logoPath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }
}
