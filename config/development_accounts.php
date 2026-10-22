<?php

use App\Enums\UserRole;

return [
    'accounts' => [
        [
            'name' => 'Development Coach',
            'email' => 'coach.dev@example.test',
            'password' => 'CoachDev!2026',
            'role' => UserRole::Coach->value,
        ],
        [
            'name' => 'Development Admin',
            'email' => 'admin.dev@example.test',
            'password' => 'AdminDev!2026',
            'role' => UserRole::Admin->value,
        ],
    ],
];
