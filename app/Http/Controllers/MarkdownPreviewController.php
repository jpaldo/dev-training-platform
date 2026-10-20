<?php

namespace App\Http\Controllers;

use App\Support\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkdownPreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->validate([
            'content' => ['nullable', 'string', 'max:60000'],
        ])['content'] ?? '';

        return response()->json(['html' => Markdown::toHtml($content)]);
    }
}
