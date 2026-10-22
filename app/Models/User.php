<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\JobRole;
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

#[Fillable(['name', 'first_name', 'last_name', 'employee_id', 'job_role', 'email', 'password', 'training_path', 'drive_link', 'role', 'is_active'])]
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
            'job_role' => JobRole::class,
        ];
    }

    /**
     * Keeps `name` equal to "first last" whenever the first or last name changes, so every screen, sort and export
     * that reads `name` stays correct. A user saved with a `name` but neither a first nor a last name (such as one
     * created from `name` alone) gets them by splitting `name`. Changing only `name` on a user who already has a
     * first or last name leaves them as they are.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (blank($user->first_name) && blank($user->last_name) && filled($user->name)) {
                [$user->first_name, $user->last_name] = self::splitFullName($user->name);

                return;
            }

            if (! $user->isDirty(['first_name', 'last_name'])) {
                return;
            }

            $fullName = trim("{$user->first_name} {$user->last_name}");

            if ($fullName !== '') {
                $user->name = $fullName;
            }
        });
    }

    /**
     * Splits a full name at its first space: the first word is the first name and the rest the last name (empty for a
     * one-word name), so "Ana Maria Cruz" becomes "Ana" and "Maria Cruz". The 1.1 identity migration backfilled
     * existing users with the same split.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitFullName(string $fullName): array
    {
        [$firstName, $lastName] = array_pad(explode(' ', trim($fullName), 2), 2, '');

        return [$firstName, trim($lastName)];
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

    /**
     * The name of the route the user starts on after signing in or adding their employee ID: the admin dashboard,
     * the coach dashboard or the participant dashboard.
     */
    public function landingRouteName(): string
    {
        return match ($this->role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Coach => 'coach.dashboard',
            UserRole::Participant => 'dashboard',
        };
    }

    public function activitySubmissions(): HasMany
    {
        return $this->hasMany(ActivitySubmission::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
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
