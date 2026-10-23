<?php

namespace App\Enums;

/**
 * What a training session resource is (`training_session_resources.type`): an uploaded file kept on the private disk,
 * or a link to another site.
 */
enum TrainingSessionResourceType: string
{
    case File = 'file';
    case Link = 'link';

    /**
     * The generic type text.
     */
    public function label(): string
    {
        return match ($this) {
            self::File => 'File',
            self::Link => 'Link',
        };
    }
}
