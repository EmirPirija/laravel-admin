<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'social' => [
        'oauth_state_ttl' => (int) env('SOCIAL_OAUTH_STATE_TTL', 10),
        'frontend_url' => env('SOCIAL_FRONTEND_URL', env('FRONTEND_URL')),
        'facebook' => [
            'client_id' => env('SOCIAL_FACEBOOK_CLIENT_ID'),
            'client_secret' => env('SOCIAL_FACEBOOK_CLIENT_SECRET'),
            'graph_version' => env('SOCIAL_FACEBOOK_GRAPH_VERSION', 'v20.0'),
            'redirect_uri_facebook' => env('SOCIAL_FACEBOOK_REDIRECT_URI'),
            'redirect_uri_instagram' => env('SOCIAL_INSTAGRAM_REDIRECT_URI'),
            'scopes_facebook' => env(
                'SOCIAL_FACEBOOK_SCOPES',
                'public_profile,pages_show_list,pages_read_engagement,pages_manage_posts,business_management'
            ),
            'scopes_instagram' => env(
                'SOCIAL_INSTAGRAM_SCOPES',
                'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management'
            ),
        ],
        'tiktok' => [
            'client_key' => env('SOCIAL_TIKTOK_CLIENT_KEY'),
            'client_secret' => env('SOCIAL_TIKTOK_CLIENT_SECRET'),
            'redirect_uri' => env('SOCIAL_TIKTOK_REDIRECT_URI'),
            'scopes' => env('SOCIAL_TIKTOK_SCOPES', 'user.info.basic'),
        ],
    ],

];
