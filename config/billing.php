<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trial Limits
    |--------------------------------------------------------------------------
    |
    | Caps applied to tenants on a free trial (no paid plan yet). A value of
    | -1 means unlimited. Used by the CheckUsageLimits middleware.
    |
    */

    'trial_limits' => [
        'conversations' => (int) env('TRIAL_CONVERSATIONS_LIMIT', 50),
        'tokens' => (int) env('TRIAL_TOKENS_LIMIT', 50000),
        'leads' => (int) env('TRIAL_LEADS_LIMIT', 25),
        'knowledge_items' => (int) env('TRIAL_KNOWLEDGE_ITEMS_LIMIT', 10),
    ],

];
