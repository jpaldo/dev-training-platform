<?php

namespace App\Models;

use Database\Factories\ActivityRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ActivityRequirement extends Model
{
    /** @use HasFactory<ActivityRequirementFactory> */
    use HasFactory;

    /**
     * The submission field types an admin can choose, each with its display label. A field's type is stored in
     * `configuration.type`; an existing `select` field keeps its type and options but is not offered here.
     *
     * @var array<string, string>
     */
    public const FIELD_TYPES = [
        'textarea' => 'Long text',
        'text' => 'Short text',
        'url' => 'Link',
        'github_url' => 'GitHub link',
    ];

    protected $fillable = ['activity_id', 'requirement_type', 'title', 'description', 'is_required', 'position', 'configuration'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function evidenceRequirements(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class);
    }

    /**
     * Whether the requirement is a field participants fill in on the submission form.
     */
    public function isSubmissionField(): bool
    {
        return $this->requirement_type === 'submission_field';
    }

    /**
     * Whether the submission field was removed from its activity's form (`configuration.deprecated`). Its answers are
     * kept under its key.
     */
    public function isRemovedField(): bool
    {
        return (bool) ($this->configuration['deprecated'] ?? false);
    }

    /**
     * The submission field's settings as participants get them: the label, help and "required" of its configuration,
     * falling back to the mirrored `title`, `description` and `is_required` columns, and its type.
     *
     * @return array{label: string, help: ?string, type: string, required: bool}
     */
    public function fieldSettings(): array
    {
        $configuration = $this->configuration ?? [];

        return [
            'label' => (string) ($configuration['label'] ?? $this->title),
            'help' => $configuration['help'] ?? $this->description,
            'type' => $this->fieldType(),
            'required' => (bool) ($configuration['required'] ?? $this->is_required),
        ];
    }

    /**
     * The submission field's type (`configuration.type`), a long text field when none is stored, as the submission form
     * reads it.
     */
    public function fieldType(): string
    {
        return (string) ($this->configuration['type'] ?? 'textarea');
    }

    /**
     * The submission field's type for display: its FIELD_TYPES label, or the stored type in headline case for a type
     * without one (such as `select`).
     */
    public function fieldTypeLabel(): string
    {
        return self::FIELD_TYPES[$this->fieldType()] ?? Str::headline($this->fieldType());
    }
}
