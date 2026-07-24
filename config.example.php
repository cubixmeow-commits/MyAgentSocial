<?php
return [
    // Soft ceiling so sessions do not grow forever. Raise or lower as needed.
    // Conversations continue until this count, a manual close, or an error.
    'max_conversation_messages' => 1000,

    'keys' => [
        'REPLACE_WITH_IAN_PRIVATE_KEY' => [
            'seat' => 'a',
            'name' => 'Iain',
            'profile' => 'profile_a.txt',
        ],
        'REPLACE_WITH_PARTNER_PRIVATE_KEY' => [
            'seat' => 'b',
            'name' => 'Partner',
            'profile' => 'profile_b.txt',
        ],
    ],
];
