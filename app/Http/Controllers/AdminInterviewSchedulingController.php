<?php

namespace App\Http\Controllers;

use App\Models\InterviewScheduler;
use App\Models\User;
use App\Rules\ExternalBookingUrl;
use App\Services\InterviewBookingLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminInterviewSchedulingController extends Controller
{
    public function index(InterviewBookingLinkService $links): View
    {
        return view('admin.settings.interview-scheduling', [
            'mockInterviewers' => InterviewScheduler::query()->whereIn('interview_type', [InterviewScheduler::MOCK, InterviewScheduler::BOTH])->where('approval_status', InterviewScheduler::APPROVED)->orderBy('display_order')->orderBy('full_name')->get(),
            'finalInterviewers' => InterviewScheduler::query()->whereIn('interview_type', [InterviewScheduler::FINAL_REASSESSMENT, InterviewScheduler::BOTH])->where('approval_status', InterviewScheduler::APPROVED)->orderBy('display_order')->orderBy('full_name')->get(),
            'pendingInterviewers' => InterviewScheduler::query()->where('approval_status', InterviewScheduler::PENDING)->latest('created_at')->get(),
            'users' => User::query()->whereIn('role', ['coach', 'admin'])->orderBy('name')->get(['id', 'name', 'email']),
            'logoConfigured' => filled($links->logoPath()),
            'publicTitle' => $links->publicTitle(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $scheduler = InterviewScheduler::create($this->validated($request));

        return back()->with('status', ucfirst(str_replace('_', ' ', $scheduler->interview_type)).' interviewer added.');
    }

    public function update(Request $request, InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update($this->validated($request));

        return back()->with('status', 'Interviewer updated.');
    }

    public function toggle(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['is_active' => ! $interviewer->is_active]);

        return back()->with('status', 'Interviewer status updated.');
    }

    public function approve(Request $request, InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['approval_status' => InterviewScheduler::APPROVED, 'is_active' => true, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejected_at' => null]);

        return back()->with('status', 'Interviewer request approved.');
    }

    public function reject(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['approval_status' => InterviewScheduler::REJECTED, 'is_active' => false, 'rejected_at' => now()]);

        return back()->with('status', 'Interviewer request rejected.');
    }

    public function destroy(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->delete();

        return back()->with('status', 'Interviewer removed.');
    }

    public function uploadLogo(Request $request, InterviewBookingLinkService $links): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        $links->saveLogo($request->file('logo'));

        return back()->with('status', 'Public interview page logo saved.');
    }

    public function removeLogo(InterviewBookingLinkService $links): RedirectResponse
    {
        $links->removeLogo();

        return back()->with('status', 'Public interview page logo removed.');
    }

    public function updatePublicTitle(Request $request, InterviewBookingLinkService $links): RedirectResponse
    {
        $data = $request->validate(['public_title' => ['nullable', 'string', 'max:100']]);
        $links->savePublicTitle($data['public_title'] ?? null);

        return back()->with('status', 'Public interview page title saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $type = $request->input('interview_type');
        $data = $request->validate([
            'interview_type' => ['required', 'in:mock,final_reassessment,both'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'booking_url' => ['nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'mock_booking_url' => [Rule::requiredIf(in_array($type, ['mock', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'final_booking_url' => [Rule::requiredIf(in_array($type, ['final_reassessment', 'both'], true)), 'nullable', 'string', 'max:2000', new ExternalBookingUrl],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return $data + ['booking_url' => $data['mock_booking_url'] ?? $data['final_booking_url'] ?? $data['booking_url'] ?? null, 'is_active' => $request->boolean('is_active'), 'display_order' => $request->integer('display_order')];
    }
}
