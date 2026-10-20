<?php

return [
    'feature_areas' => [
        'User Login / OTP Verification', 'Registration', 'Password Reset',
        'Membership', 'Profile', 'Other',
    ],
    'artifact_types' => [
        'Test Case', 'Test Scenario', 'Test Plan', 'Test Script', 'Test Data',
        'Acceptance Criteria', 'User Story', 'Bug Report', 'Defect Analysis',
        'Regression Test Plan', 'Test Checklist', 'Test Automation Script',
        'API Test', 'Test Report', 'Risk Assessment', 'Root Cause Analysis',
        'Requirements Analysis', 'AI-generated test case', 'Other',
    ],
    'default_ai_tool' => 'Claude',
    'default_artifact' => [
        'feature_area' => 'User Login / OTP Verification',
        'artifact_type' => 'Test Case',
        'artifact_name' => 'Verify OTP expiration',
        'artifact_content' => "TC-014 — Verify OTP expiration\n\nPrecondition: User has valid credentials.\nSteps:\n1. Open the login page.\n2. Enter valid email and password.\n3. Enter the OTP sent to the user.\n4. Wait 5 minutes.\n5. Enter the same OTP.\n\nExpected Result:\nThe OTP should still be valid after 5 minutes and the user should be authenticated.",
    ],
];
