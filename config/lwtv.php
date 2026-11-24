<?php

return [

    /*
     * Base URL of the LezWatch.TV WordPress site the catalogue is read from.
     * Production: https://lezwatchtv.com. Local development: https://lwtv.local.
     */
    'api_url' => rtrim((string) env('LWTV_API_URL', 'https://lezwatchtv.com'), '/'),

    /*
     * Verify the API's TLS certificate. Only turn this off locally, where the
     * Local site uses a self-signed certificate.
     */
    'verify_tls' => filter_var(env('LWTV_API_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),

    /*
     * HTTP timeout in seconds for catalogue requests.
     */
    'timeout' => (int) env('LWTV_API_TIMEOUT', 30),

];
