<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewMarkdownRequest;
use App\Support\Markdown;
use Illuminate\Http\JsonResponse;

class MarkdownPreviewController extends Controller
{
    public function __invoke(PreviewMarkdownRequest $request): JsonResponse
    {
        $content = $request->validated('content') ?? '';

        return response()->json(['html' => Markdown::toHtml($content)]);
    }
}
