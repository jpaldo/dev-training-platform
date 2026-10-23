<?php

namespace App\Services;

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

    public function generalRules(): array
    {
        return [
            'file',
            'max:'.(int) config('training.evidence_max_kb', 2048),
            'extensions:'.implode(',', $this->extensions()),
            'mimetypes:'.implode(',', $this->mimeTypes()),
        ];
    }

    public function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $extension): string => '.'.$extension, $this->extensions()));
    }
}
