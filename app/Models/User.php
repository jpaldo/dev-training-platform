<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Observers\ParticipantProgressObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'training_path', 'drive_link', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([ParticipantProgressObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'training_path' => TrainingPath::class,
            'role' => UserRole::class,
        ];
    }

    /**
     * Whether the user has one of the roles. Only cases are accepted, so a role string is a `TypeError` rather than a
     * comparison that never matches (the `role:` middleware maps its string parameters with `UserRole::from()`).
     */
    public function hasAnyRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function trainingPathLabel(): string
    {
        return $this->training_path?->label() ?? 'Unassigned';
    }

    /**
     * Paths whose requirements should be available to this participant (none without a path).
     *
     * @return list<TrainingPath>
     */
    public function eligibleTrainingPaths(): array
    {
        return $this->training_path?->eligiblePaths() ?? [];
    }

    public function isCoachOrAdmin(): bool
    {
        return $this->hasAnyRole(UserRole::Coach, UserRole::Admin);
    }

    public function activitySubmissions(): HasMany
    {
        return $this->hasMany(ActivitySubmission::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function validationSubmissions(): HasMany
    {
        return $this->hasMany(ValidationSubmission::class);
    }

    public function weekThreeWorkspaces(): HasMany
    {
        return $this->hasMany(WeekThreeWorkspace::class);
    }

    public function weeklyVideoRecordings(): HasMany
    {
        return $this->hasMany(WeeklyVideoRecording::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function googleDriveConnection(): HasOne
    {
        return $this->hasOne(GoogleDriveConnection::class);
    }

    public function googleDriveSyncs(): HasMany
    {
        return $this->hasMany(GoogleDriveSync::class);
    }

    public function assignedParticipants(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'coach_participant_assignments', 'coach_id', 'participant_id')->withTimestamps();
    }

    public function assignedCoaches(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'coach_participant_assignments', 'participant_id', 'coach_id')->withTimestamps();
    }

    public function canManageParticipant(User $participant): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }
        if ($this->role !== UserRole::Coach || $participant->role !== UserRole::Participant) {
            return false;
        }
        if ($participant->relationLoaded('assignedCoaches')) {
            return $participant->assignedCoaches->contains($this->id);
        }

        return $this->assignedParticipants()->whereKey($participant->id)->exists();
    }

    public function gateEvaluations(): HasMany
    {
        return $this->hasMany(GateEvaluation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SubmissionReview::class, 'reviewer_id');
    }

    public function participantNotifications(): HasMany
    {
        return $this->hasMany(ParticipantNotification::class);
    }
}
