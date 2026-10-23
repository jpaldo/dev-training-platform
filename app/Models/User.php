<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\JobRole;
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

#[Fillable(['name', 'first_name', 'last_name', 'employee_id', 'job_role', 'email', 'password', 'drive_link', 'role', 'is_active'])]
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

    /**
     * Whether the user may act as the participant's mentor in the session: an admin (in every session), or a coach
     * holding the participant's current mentor assignment there. A previous mentor of the participant, or their mentor
     * in another session, may not. Records carry their session, so callers pass the record's session.
     */
    public function mentorsParticipantInSession(User $participant, TrainingSession $trainingSession): bool
    {
        if ($this->hasAnyRole(UserRole::Admin)) {
            return true;
        }

        if (! $this->hasAnyRole(UserRole::Coach)) {
            return false;
        }

        return MentorAssignment::query()
            ->current()
            ->forParticipantInSession($trainingSession->id, $participant->id)
            ->where('mentor_id', $this->id)
            ->exists();
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

    /**
     * The training sessions the user is a participant in.
     */
    public function trainingSessions(): BelongsToMany
    {
        return $this->belongsToMany(TrainingSession::class, 'training_session_participants')
            ->using(TrainingSessionParticipant::class)
            ->withPivot(['joined_at', 'added_by'])
            ->withTimestamps();
    }

    /**
     * The training sessions the user (a coach) is a mentor of.
     */
    public function mentoredSessions(): BelongsToMany
    {
        return $this->belongsToMany(TrainingSession::class, 'training_session_mentors')
            ->using(TrainingSessionMentor::class)
            ->withTimestamps();
    }

    /**
     * The user's current assignments as a mentor, in every session: those that have not ended.
     */
    public function currentMentorAssignments(): HasMany
    {
        return $this->hasMany(MentorAssignment::class, 'mentor_id')->current();
    }

    /**
     * The user's (a participant's) current mentors, through their current mentor assignments in every session: one
     * per session that has assigned them one, so a coach who mentors them in two sessions is listed twice.
     */
    public function currentMentors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'mentor_assignments', 'participant_id', 'mentor_id')
            ->wherePivotNull('ended_at');
    }
}
