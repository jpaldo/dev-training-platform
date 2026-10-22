<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\ValidationLabService;

class ValidationSubmissionPolicy
{
    public function __construct(private readonly ValidationLabService $lab) {}

    /**
     * Determine whether the user can view the Lab record: its owner, or a coach or admin opening it from a submission
     * version that links the record to the same participant.
     */
    public function view(User $user, ValidationSubmission $validationSubmission, ?int $submissionVersionId = null): bool
    {
        return $this->isOwner($user, $validationSubmission)
            || ($user->isCoachOrAdmin() && $submissionVersionId !== null && $this->lab->isLinkedToParticipantSubmission($validationSubmission, $submissionVersionId));
    }

    /**
     * Determine whether the user can submit the Lab record: only its owner.
     */
    public function submit(User $user, ValidationSubmission $validationSubmission): bool
    {
        return $this->isOwner($user, $validationSubmission);
    }

    private function isOwner(User $user, ValidationSubmission $validationSubmission): bool
    {
        return $validationSubmission->user_id !== null && (int) $validationSubmission->user_id === (int) $user->id;
    }
}
