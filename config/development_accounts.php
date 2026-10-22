<?php

use App\Enums\JobRole;
use App\Enums\TrainingPath;
use App\Enums\UserRole;

/**
 * Demo accounts that `DevelopmentAccountProvisioner` keeps in place in local and testing environments. Employee IDs
 * continue the `DEV-` sequence that `DatabaseSeeder` starts with `test@example.com` (`DEV-0001`). Participants also
 * carry a training path.
 */
return [
    'accounts' => [
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
            'training_path' => TrainingPath::Automation->value,
        ],
        [
            'employee_id' => 'DEV-0005',
            'first_name' => 'Jordan',
            'last_name' => 'Reyes',
            'email' => 'participant2.dev@example.test',
            'password' => 'ParticipantDev2!2026',
            'role' => UserRole::Participant->value,
            'job_role' => JobRole::Developer->value,
            'training_path' => TrainingPath::AutomationManual->value,
        ],
    ],
];
