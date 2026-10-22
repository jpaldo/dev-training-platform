<?php

namespace App\Enums;

/**
 * A user's access role (stored in `users.role`, default `participant`). Participants follow the training; coaches
 * review the participants assigned to them; admins administer the platform and manage every participant.
 */
enum UserRole: string
{
    case Participant = 'participant';
    case Coach = 'coach';
    case Admin = 'admin';

    /**
     * The generic role text (the sidebar and the profile page show it).
     */
    public function label(): string
    {
        return match ($this) {
            self::Participant => 'Participant',
            self::Coach => 'Coach',
            self::Admin => 'Admin',
        };
    }
}
