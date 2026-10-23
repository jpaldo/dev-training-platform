<?php

use App\Enums\JobRole;
use App\Enums\UserRole;

/**
 * Demo accounts that `DevelopmentAccountProvisioner` keeps in place in local, staging and testing environments, with
 * employee IDs in the `DEV-` sequence: three participants and three mentors (`coach` role), all job role Developer,
 * plus one admin.
 */
return [
    'accounts' => [
        [
            'employee_id' => 'DEV-0001',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'role' => UserRole::Participant->value,
            'job_role' => JobRole::Developer->value,
        ],
        [
            'employee_id' => 'DEV-0002',
            'first_name' => 'Development',
            'last_name' => 'Coach',
            'email' => 'coach.dev@example.test',
            'password' => 'CoachDev!2026',
            'role' => UserRole::Coach->value,
            'job_role' => JobRole::Developer->value,
        ],
        [
            'employee_id' => 'DEV-0003',
            'first_name' => 'Development',
            'last_name' => 'Admin',
            'email' => 'admin.dev@example.test',
            'password' => 'AdminDev!2026',
            'role' => UserRole::Admin->value,
            'job_role' => JobRole::ProjectManager->value,
        ],
        [
            'employee_id' => 'DEV-0004',
            'first_name' => 'Avery',
            'last_name' => 'Santos',
            'email' => 'participant1.dev@example.test',
            'password' => 'ParticipantDev1!2026',
            'role' => UserRole::Participant->value,
            'job_role' => JobRole::Developer->value,
        ],
        [
            'employee_id' => 'DEV-0005',
            'first_name' => 'Jordan',
            'last_name' => 'Reyes',
            'email' => 'participant2.dev@example.test',
            'password' => 'ParticipantDev2!2026',
            'role' => UserRole::Participant->value,
            'job_role' => JobRole::Developer->value,
        ],
        [
            'employee_id' => 'DEV-0006',
            'first_name' => 'Casey',
            'last_name' => 'Lim',
            'email' => 'mentor1.dev@example.test',
            'password' => 'MentorDev1!2026',
            'role' => UserRole::Coach->value,
            'job_role' => JobRole::Developer->value,
        ],
        [
            'employee_id' => 'DEV-0007',
            'first_name' => 'Riley',
            'last_name' => 'Tan',
            'email' => 'mentor2.dev@example.test',
            'password' => 'MentorDev2!2026',
            'role' => UserRole::Coach->value,
            'job_role' => JobRole::Developer->value,
        ],
    ],
];
