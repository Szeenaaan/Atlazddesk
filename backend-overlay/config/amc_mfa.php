<?php

return [
    'required_roles' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('AMC_MFA_REQUIRED_ROLES', ''))
    ))),
    'challenge_minutes' => (int) env('AMC_MFA_CHALLENGE_MINUTES', 5),
    'email_cooldown_seconds' => (int) env('AMC_MFA_EMAIL_COOLDOWN_SECONDS', 60),
];
