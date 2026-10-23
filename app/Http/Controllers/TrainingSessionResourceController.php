<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingSessionResourceController extends Controller
{
    /**
     * Open a session resource: a file streams inline from its private disk, a link redirects to its URL. The resource is
     * bound through the session, so a resource of another session is not found. Only admins can open resources until
     * the participant Resources tab (9.6) opens them to the session's members.
     */
    public function show(TrainingSession $trainingSession, TrainingSessionResource $resource): RedirectResponse|StreamedResponse
    {
        Gate::authorize('manage', $trainingSession);

        if (! $resource->isFile()) {
            return redirect()->away($resource->url);
        }

        abort_unless($resource->disk && $resource->path && Storage::disk($resource->disk)->exists($resource->path), 404);

        return Storage::disk($resource->disk)->response(
            $resource->path,
            $resource->original_filename,
            ['Content-Type' => $resource->mime_type, 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}
