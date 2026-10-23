<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrainingSessionRequest;
use App\Models\TrainingSession;
use App\Services\AdminTrainingSessionPageService;
use App\Services\TrainingSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminTrainingSessionController extends Controller
{
    public function index(AdminTrainingSessionPageService $page): View
    {
        Gate::authorize('viewAny', TrainingSession::class);

        return view('admin.sessions.index', $page->indexData());
    }

    public function create(AdminTrainingSessionPageService $page): View
    {
        Gate::authorize('create', TrainingSession::class);

        return view('admin.sessions.form', $page->formData());
    }

    public function store(StoreTrainingSessionRequest $request, TrainingSessionService $sessions): RedirectResponse
    {
        $session = $sessions->create($request->validated(), $request->user());

        return redirect()->route('admin.sessions.index')->with('status', "Training session \"{$session->title}\" created.");
    }
}
