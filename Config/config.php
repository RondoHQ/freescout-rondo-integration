<?php

return [
    'base_url' => env('RONDO_BASE_URL', ''),
    'client_id' => env('RONDO_OIDC_CLIENT_ID', ''),
    'client_secret' => env('RONDO_OIDC_CLIENT_SECRET', ''),
    'signing_key' => env('RONDO_SIGNING_KEY', ''),
    'force_login' => env('RONDO_FORCE_OAUTH_LOGIN', null),
    'automatic_user_creation' => env('RONDO_AUTOMATIC_USER_CREATION', null),
    'managed_mailbox_mappings' => env('RONDO_MANAGED_MAILBOX_MAPPINGS', ''),
    'limit_user_customer_visibility' => env('APP_LIMIT_USER_CUSTOMER_VISIBILITY', false),
    'local_http' => env('RONDO_ALLOW_LOCAL_HTTP', false),
    'connect_timeout' => 2.0,
    'timeout' => 5.0,
    'max_response_bytes' => 262144,
];
