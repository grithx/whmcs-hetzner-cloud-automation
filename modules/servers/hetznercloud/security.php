<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Return the authenticated WHMCS client ID, or 0 when no client session exists.
 */
function hetznercloud_GetAuthenticatedClientId()
{
    return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
}

/**
 * Ensure the current client session owns the service represented by module params.
 * WHMCS passes userid and serviceid in the provisioning module parameter array.
 */
function hetznercloud_AssertServiceOwnership(array $params)
{
    $clientId = hetznercloud_GetAuthenticatedClientId();
    $serviceOwnerId = isset($params['userid']) ? (int) $params['userid'] : 0;

    if ($clientId <= 0 || $serviceOwnerId <= 0 || $clientId !== $serviceOwnerId) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/**
 * Generate and retain a CSRF token in the authenticated WHMCS session.
 */
function hetznercloud_GetCsrfToken()
{
    if (empty($_SESSION['hetznercloud_csrf_token'])) {
        $_SESSION['hetznercloud_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['hetznercloud_csrf_token'];
}

/**
 * Validate state-changing client-area requests.
 */
function hetznercloud_ValidateCsrfToken($providedToken)
{
    $sessionToken = $_SESSION['hetznercloud_csrf_token'] ?? '';

    if (!is_string($providedToken) || $providedToken === '' || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $providedToken);
}

/**
 * Create a short-lived, opaque console authorization token.
 * Console credentials are retained server-side in the WHMCS client session and
 * never placed in a URL, activity log, browser history, or DOM data attribute.
 */
function hetznercloud_CreateConsoleGrant(array $params, array $consoleData)
{
    hetznercloud_AssertServiceOwnership($params);

    if (empty($consoleData['wss_url']) || empty($consoleData['password'])) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $now = time();

    if (!isset($_SESSION['hetznercloud_console_grants']) || !is_array($_SESSION['hetznercloud_console_grants'])) {
        $_SESSION['hetznercloud_console_grants'] = [];
    }

    // Remove expired grants before storing a new one.
    foreach ($_SESSION['hetznercloud_console_grants'] as $key => $grant) {
        if (!isset($grant['expires_at']) || (int) $grant['expires_at'] < $now) {
            unset($_SESSION['hetznercloud_console_grants'][$key]);
        }
    }

    $_SESSION['hetznercloud_console_grants'][$token] = [
        'client_id' => hetznercloud_GetAuthenticatedClientId(),
        'service_id' => (int) ($params['serviceid'] ?? 0),
        'host' => (string) $consoleData['wss_url'],
        'password' => (string) $consoleData['password'],
        'expires_at' => $now + 120,
    ];

    return $token;
}

/**
 * Consume a one-time console grant from the current WHMCS session.
 */
function hetznercloud_ConsumeConsoleGrant($token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $grants = $_SESSION['hetznercloud_console_grants'] ?? [];
    if (!isset($grants[$token]) || !is_array($grants[$token])) {
        return null;
    }

    $grant = $grants[$token];
    unset($_SESSION['hetznercloud_console_grants'][$token]);

    if ((int) ($grant['expires_at'] ?? 0) < time()) {
        return null;
    }

    if ((int) ($grant['client_id'] ?? 0) !== hetznercloud_GetAuthenticatedClientId()) {
        return null;
    }

    return $grant;
}
