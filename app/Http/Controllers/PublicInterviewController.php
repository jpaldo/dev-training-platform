<?php

namespace App\Http\Controllers;

use App\Models\InterviewScheduler;
use App\Rules\ExternalBookingUrl;
use App\Services\InterviewBookingLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicInterviewController extends Controller
{
    public function index(InterviewBookingLinkService $links): View
    {
        $interviewers = InterviewScheduler::query()->where('is_active', true)->where('approval_status', InterviewScheduler::APPROVED)->whereIn('interview_type', [InterviewScheduler::MOCK, InterviewScheduler::FINAL_REASSESSMENT, InterviewScheduler::BOTH])->orderBy('display_order')->orderBy('full_name')->get();

        return view('public.interviews', [
            'mockInterviewers' => $interviewers->filter(fn (InterviewScheduler $interviewer): bool => in_array($interviewer->interview_type, [InterviewScheduler::MOCK, InterviewScheduler::BOTH], true))->values(),
            'finalInterviewers' => $interviewers->filter(fn (InterviewScheduler $interviewer): bool => in_array($interviewer->interview_type, [InterviewScheduler::FINAL_REASSESSMENT, InterviewScheduler::BOTH], true))->values(),
            'logoConfigured' => filled($links->logoPath()),
            'publicTitle' => $links->publicTitle(),
        ]);
    }

    public function register(InterviewBookingLinkService $links): View
    {
        return view('public.interview-register', ['publicTitle' => $links->publicTitle()]);
    }

    public function storeRegistration(Request $request): RedirectResponse
    {
        $key = 'public-interviewer-registration|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['registration' => 'Too many submissions. Please try again later.'])->withInput();
        }
        RateLimiter::hit($key, 600);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'interview_type' => ['required', 'in:mock,final_reassessment,both'],
            'mock_booking_url' => [Rule::requiredIf(fn (): bool => in_array($request->input('interview_type'), ['mock', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(fn (): bool => in_array($request->input('interview_type'), ['final_reassessment', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
        ]);
        $name = trim($data['full_name']);
        $mockUrl = isset($data['mock_booking_url']) ? $this->normalizeBookingUrl((string) $data['mock_booking_url']) : null;
        $finalUrl = isset($data['final_booking_url']) ? $this->normalizeBookingUrl((string) $data['final_booking_url']) : null;
        $duplicate = InterviewScheduler::query()->whereIn('approval_status', [InterviewScheduler::PENDING, InterviewScheduler::APPROVED])->whereRaw('LOWER(full_name) = ?', [mb_strtolower($name)])->where(function ($query) use ($mockUrl, $finalUrl): void {
            $query->where(function ($query) use ($mockUrl, $finalUrl): void {
                $query->where('mock_booking_url', $mockUrl)->where('final_booking_url', $finalUrl);
            })->orWhere(function ($query) use ($mockUrl, $finalUrl): void {
                $query->whereNull('mock_booking_url')->whereNull('final_booking_url')->where('booking_url', $mockUrl ?: $finalUrl);
            });
        })->exists();
        if ($duplicate) {
            return redirect()->route('interviews.register')->with('status', 'Thank you. This interviewer information is already pending review or approved.');
        }

        InterviewScheduler::create(['interview_type' => $data['interview_type'], 'full_name' => $name, 'booking_url' => $mockUrl ?: $finalUrl, 'mock_booking_url' => $mockUrl, 'final_booking_url' => $finalUrl, 'is_active' => false, 'display_order' => 0, 'approval_status' => InterviewScheduler::PENDING, 'submitted_publicly' => true]);

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
