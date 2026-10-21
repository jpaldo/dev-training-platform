<?php

namespace App\Http\Controllers;

use App\Enums\InterviewerApprovalStatus;
use App\Http\Requests\SaveInterviewSchedulerRequest;
use App\Http\Requests\UpdateInterviewPagePublicTitleRequest;
use App\Http\Requests\UploadInterviewPageLogoRequest;
use App\Models\InterviewScheduler;
use App\Services\AdminSettingsPageService;
use App\Services\InterviewBookingLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminInterviewSchedulingController extends Controller
{
    public function index(AdminSettingsPageService $page): View
    {
        return view('admin.settings.interview-scheduling', $page->interviewSchedulingData());
    }

    public function store(SaveInterviewSchedulerRequest $request): RedirectResponse
    {
        $scheduler = InterviewScheduler::create($this->interviewerAttributes($request));

        return back()->with('status', ucfirst(str_replace('_', ' ', $scheduler->interview_type)).' interviewer added.');
    }

    public function update(SaveInterviewSchedulerRequest $request, InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update($this->interviewerAttributes($request));

        return back()->with('status', 'Interviewer updated.');
    }

    public function toggle(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['is_active' => ! $interviewer->is_active]);

        return back()->with('status', 'Interviewer status updated.');
    }

    public function approve(Request $request, InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['approval_status' => InterviewerApprovalStatus::Approved, 'is_active' => true, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejected_at' => null]);

        return back()->with('status', 'Interviewer request approved.');
    }

    public function reject(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->update(['approval_status' => InterviewerApprovalStatus::Rejected, 'is_active' => false, 'rejected_at' => now()]);

        return back()->with('status', 'Interviewer request rejected.');
    }

    public function destroy(InterviewScheduler $interviewer): RedirectResponse
    {
        $interviewer->delete();

        return back()->with('status', 'Interviewer removed.');
    }

    public function uploadLogo(UploadInterviewPageLogoRequest $request, InterviewBookingLinkService $links): RedirectResponse
    {
        $links->saveLogo($request->file('logo'));

        return back()->with('status', 'Public interview page logo saved.');
    }

    public function removeLogo(InterviewBookingLinkService $links): RedirectResponse
    {
        $links->removeLogo();

        return back()->with('status', 'Public interview page logo removed.');
    }

    public function updatePublicTitle(UpdateInterviewPagePublicTitleRequest $request, InterviewBookingLinkService $links): RedirectResponse
    {
        $data = $request->validated();
        $links->savePublicTitle($data['public_title'] ?? null);

        return back()->with('status', 'Public interview page title saved.');
    }

    /** @return array<string, mixed> */
    private function interviewerAttributes(SaveInterviewSchedulerRequest $request): array
    {
        $data = $request->validated();

        return $data + ['booking_url' => $data['mock_booking_url'] ?? $data['final_booking_url'] ?? $data['booking_url'] ?? null, 'is_active' => $request->boolean('is_active'), 'display_order' => $request->integer('display_order')];
    }
}
