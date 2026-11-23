<?php

return [

    /*
    | The admin lives at /{path}. Empty means the admin is off: no routes.
    | Keep the real value out of the repo; it filters scanners, it is not the lock.
    */
    'path' => trim((string) env('ADMIN_PATH', ''), '/'),

    // Only members of this Google Group (including nested groups) get in.
    'group' => env('ADMIN_GROUP', 'pwa-admins@lezwatchtv.com'),

    // How often a signed-in admin's membership is checked again. Default: daily.
    'recheck_minutes' => (int) env('ADMIN_RECHECK_MINUTES', 1440),

    // Google sign-in is limited to this Workspace domain.
    'domain' => env('GOOGLE_WORKSPACE_DOMAIN', 'lezwatchtv.com'),

    // Path, inside the container, to the service account's JSON key.
    'service_account_key' => env('GOOGLE_SERVICE_ACCOUNT_KEY'),

];
