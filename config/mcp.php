<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        '*',
        // 'https://example.com',
        // 'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The maximum
    | number of tool calls limits how many tools each search request can run
    | while the maximum output bytes value caps the size of every result.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quote MCP static bearer token
    |--------------------------------------------------------------------------
    |
    | The Laravel application stores only the SHA-256 hash of the raw token.
    | The MCP client keeps the raw token in its own environment as
    | MCP_QUOTE_TOKEN and sends it as Authorization: Bearer <token>.
    |
    | Generate a raw token (at least 32 random bytes):
    |   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    |
    | Hash it for this configuration:
    |   php -r "echo hash('sha256', getenv('MCP_QUOTE_TOKEN')), PHP_EOL;"
    |
    | Quote MCP request authentication uses rows in mcp_client_tokens,
    | not MCP_QUOTE_TOKEN_HASH. Keep MCP_QUOTE_SELLER_ACCOUNT_CODE for
    | seller identity resolution.
    |
    */

    'quotes' => [
        'token_hash' => env('MCP_QUOTE_TOKEN_HASH'),
        'seller_account_code' => env('MCP_QUOTE_SELLER_ACCOUNT_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP client token administration
    |--------------------------------------------------------------------------
    |
    | Protects REST endpoints that issue, list, update, and revoke MCP client
    | tokens. Laravel stores only the SHA-256 hash of the raw administration
    | bearer token. Do not reuse MCP client tokens as this credential.
    |
    | Generate a raw administration token:
    |   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    |
    | Hash it for this configuration:
    |   php -r "echo hash('sha256', getenv('MCP_ADMINISTRATION_TOKEN')), PHP_EOL;"
    |
    */

    'administration' => [
        'token_hash' => env('MCP_ADMINISTRATION_TOKEN_HASH'),
    ],

];
