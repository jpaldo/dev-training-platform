<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrainingGuideController extends Controller
{
    /** @var array<int, string> */
    private const GUIDES = [
        2 => 'Week_2_Activitiy_Guide.pdf',
        3 => 'Week_3_Activitiy_Guide.pdf',
        4 => 'Week_4_Activitiy_Guide.pdf',
    ];

    public function view(Request $request, int $week): BinaryFileResponse
    {
        return $this->serve($request, $week, false);
    }

    public function download(Request $request, int $week): BinaryFileResponse
    {
        return $this->serve($request, $week, true);
    }

    private function serve(Request $request, int $week, bool $download): BinaryFileResponse
    {
        abort_unless(array_key_exists($week, self::GUIDES), 404);

        /** @var User $user */
        $user = $request->user();
        $path = base_path('training-guides/'.self::GUIDES[$week]);
        abort_unless(is_file($path) && is_readable($path), 404);

        return $download
            ? response()->download($path, self::GUIDES[$week], ['Content-Type' => 'application/pdf'])
            : response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.self::GUIDES[$week].'"']);
    }
}
