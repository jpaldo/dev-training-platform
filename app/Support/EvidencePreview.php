<?php

namespace App\Support;

use App\Models\SubmissionEvidence;

/**
 * How the shared evidence preview modal (`x-evidence-actions`) shows an evidence file: the name and format shown in
 * its header, and whether it is previewed as an image, a PDF, a video, structured text / table data, or not at all.
 */
final class EvidencePreview
{
    public const IMAGE = 'image';

    public const PDF = 'pdf';

    public const VIDEO = 'video';

    public const STRUCTURED = 'structured';

    public const UNSUPPORTED = 'unsupported';

    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    private const STRUCTURED_EXTENSIONS = ['txt', 'md', 'log', 'json', 'xml', 'csv', 'xlsx', 'docx'];

    public function __construct(
        public readonly string $filename,
        public readonly string $formatLabel,
        public readonly string $kind,
        public readonly string $videoSourceType,
    ) {}

    public static function for(SubmissionEvidence $evidence): self
    {
        $filename = $evidence->original_filename ?: ($evidence->title ?: 'Evidence');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $evidence->mime_type);

        return new self(
            filename: $filename,
            formatLabel: strtoupper($extension ?: $mimeType ?: 'file'),
            kind: self::kind($mimeType, $extension),
            videoSourceType: $mimeType ?: 'video/mp4',
        );
    }

    public function isPreviewable(): bool
    {
        return $this->kind !== self::UNSUPPORTED;
    }

    private static function kind(string $mimeType, string $extension): string
    {
        return match (true) {
            in_array($mimeType, self::IMAGE_MIME_TYPES, true) || in_array($extension, self::IMAGE_EXTENSIONS, true) => self::IMAGE,
            $mimeType === 'application/pdf' || $extension === 'pdf' => self::PDF,
            str_starts_with($mimeType, 'video/') || in_array($extension, self::VIDEO_EXTENSIONS, true) => self::VIDEO,
            in_array($extension, self::STRUCTURED_EXTENSIONS, true) => self::STRUCTURED,
            default => self::UNSUPPORTED,
        };
    }
}
