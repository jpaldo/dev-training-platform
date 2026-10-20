<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class EvidenceFilePolicy
{
    public function extensions(): array
    {
        return config('training.evidence_extensions', []);
    }

    public function mimeTypes(): array
    {
        return config('training.evidence_mimes', []);
    }

    public function videoExtensions(): array
    {
        return config('training.weekly_video_extensions', config('training.weekly_video_mimes', []));
    }

    public function videoMimeTypes(): array
    {
        return config('training.weekly_video_mime_types', [
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/x-m4v',
        ]);
    }

    public function isVideoMimeType(?string $mimeType): bool
    {
        return filled($mimeType) && (str_starts_with($mimeType, 'video/') || in_array($mimeType, $this->videoMimeTypes(), true));
    }

    public function generalRules(): array
    {
        return [
            'file',
            'max:'.(int) config('training.evidence_max_kb', 2048),
            'extensions:'.implode(',', $this->extensions()),
            'mimetypes:'.implode(',', $this->mimeTypes()),
        ];
    }

    public function videoRules(): array
    {
        return [
            'file',
            'max:'.(int) config('training.weekly_video_max_kb', 358400),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    $fail('Please upload a valid video file.');

                    return;
                }

                $extension = strtolower($value->getClientOriginalExtension());
                if (! in_array($extension, $this->videoExtensions(), true)) {
                    $fail('Please upload a valid video file.');

                    return;
                }

                $serverMime = strtolower((string) $value->getMimeType());
                if ($this->isVideoMimeType($serverMime) || $this->matchesVideoContainerSignature($value, $extension)) {
                    return;
                }

                $fail('Please upload a valid video file.');
            },
        ];
    }

    public function reusableAssetRules(): array
    {
        return ['file', 'max:'.(int) config('training.evidence_max_kb', 307200), function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof UploadedFile || ! in_array(strtolower($value->getClientOriginalExtension()), ['txt', 'md', 'markdown', 'csv', 'json', 'xml', 'log', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'tar'], true)) {
                $fail('Please upload a supported Reusable AI Asset file.');

                return;
            }
            $extension = strtolower($value->getClientOriginalExtension());
            $mime = strtolower((string) $value->getMimeType());
            $safeMime = in_array($mime, $this->mimeTypes(), true) || $mime === 'application/x-tar';
            $signature = @file_get_contents($value->getRealPath(), false, null, 0, 512) ?: '';
            $archive = ($extension === 'zip' && str_starts_with($signature, 'PK')) || ($extension === 'tar' && substr($signature, 257, 5) === 'ustar');
            if (! $safeMime && ! $archive) {
                $fail('The uploaded file does not match a supported Reusable AI Asset format.');
            }
        }];
    }

    public function reusableAssetAcceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $extension): string => '.'.$extension, ['txt', 'md', 'markdown', 'csv', 'json', 'xml', 'log', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'tar']));
    }

    /**
     * Confirm a video container when Fileinfo reports a generic/unsupported
     * MIME. Only narrowly recognised signatures are accepted; arbitrary
     * application/octet-stream uploads are never treated as videos.
     */
    private function matchesVideoContainerSignature(UploadedFile $file, string $extension): bool
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        if (! is_resource($handle)) {
            return false;
        }

        $header = fread($handle, 65536);
        fclose($handle);

        if (! is_string($header) || $header === '') {
            return false;
        }

        return match ($extension) {
            'webm' => str_starts_with($header, "\x1A\x45\xDF\xA3") && str_contains(strtolower($header), 'webm'),
            'mkv' => str_starts_with($header, "\x1A\x45\xDF\xA3") && str_contains(strtolower($header), 'matroska'),
            'mp4', 'm4v', 'mov', '3gp', '3g2', 'f4v' => substr($header, 4, 4) === 'ftyp',
            'avi' => substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'AVI ',
            'ogv', 'ogg' => str_starts_with($header, 'OggS'),
            'flv' => str_starts_with($header, 'FLV'),
            'wmv' => str_starts_with($header, "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C"),
            'mpg', 'mpeg', 'm2v', 'vob' => str_starts_with($header, "\x00\x00\x01\xBA")
                || str_starts_with($header, "\x00\x00\x01\xB3")
                || str_starts_with($header, "\x00\x00\x01\xB8"),
            'mts', 'm2ts', 'ts' => $this->looksLikeTransportStream($header),
            default => false,
        };
    }

    private function looksLikeTransportStream(string $header): bool
    {
        if (! isset($header[0]) || ord($header[0]) !== 0x47) {
            return false;
        }

        foreach ([188, 192] as $packetSize) {
            if (isset($header[$packetSize]) && ord($header[$packetSize]) === 0x47) {
                return true;
            }
        }

        return false;
    }

    public function acceptAttribute(bool $videoOnly = false): string
    {
        $extensions = $videoOnly ? $this->videoExtensions() : $this->extensions();

        return implode(',', array_map(static fn (string $extension): string => '.'.$extension, $extensions));
    }
}
