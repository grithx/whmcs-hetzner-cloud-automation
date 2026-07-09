<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Send a request to the Hetzner Cloud API.
 */
function hetznercloud_API_Request($method, $endpoint, $params, $serverID = null, $postData = [])
{
    $apiToken = null;

    // For service operations, WHMCS supplies the access hash of the selected server.
    if (is_array($params) && !empty($params['serveraccesshash'])) {
        $apiToken = (string) $params['serveraccesshash'];
    }

    // ConfigOptions is the only provisioning module function without service context.
    // Keep a limited fallback for server type discovery only.
    if (!$apiToken && $endpoint === 'server_types') {
        $apiToken = hetznercloud_GetAPITokenForConfiguration();
    }

    if (!$apiToken) {
        logActivity("Hetzner Cloud - API request rejected: no service-bound API token available");
        return ['success' => false, 'message' => 'API token not configured'];
    }

    $url = hetznercloud_BuildAPIUrl($endpoint, $serverID, $method, $postData);
    if (!$url) {
        return ['success' => false, 'message' => 'Invalid endpoint or missing server ID'];
    }

    return hetznercloud_ExecuteRequest($url, $method, $apiToken, $postData, $endpoint, $serverID);
}

/**
 * Build an allow-listed Hetzner API URL.
 */
function hetznercloud_BuildAPIUrl($endpoint, $serverID, $method, $postData)
{
    $baseUrl = 'https://api.hetzner.cloud/v1';

    $directEndpoints = [
        'create_server' => '/servers',
        'server_types' => '/server_types',
        'images' => '/images',
        'isos' => '/isos',
    ];

    if (isset($directEndpoints[$endpoint])) {
        return $baseUrl . $directEndpoints[$endpoint];
    }

    if ($serverID !== null && $serverID !== '') {
        if (!hetznercloud_ValidateServerID($serverID)) {
            return false;
        }

        $serverID = (int) $serverID;

        if ($endpoint === 'metrics') {
            $url = $baseUrl . '/servers/' . $serverID . '/metrics';
            return !empty($postData) ? $url . '?' . http_build_query($postData, '', '&', PHP_QUERY_RFC3986) : $url;
        }

        if ($endpoint === 'server_details') {
            return $baseUrl . '/servers/' . $serverID;
        }

        $allowedActions = [
            'poweron',
            'poweroff',
            'reboot',
            'reset',
            'shutdown',
            'attach_iso',
            'detach_iso',
            'rebuild',
            'reset_password',
            'request_console',
        ];

        if (in_array($endpoint, $allowedActions, true)) {
            return $baseUrl . '/servers/' . $serverID . '/actions/' . $endpoint;
        }

        if (strtoupper($method) === 'DELETE' && $endpoint === 'servers') {
            return $baseUrl . '/servers/' . $serverID;
        }

        return false;
    }

    // Metrics helper calls use servers/{numeric-id}/metrics.
    if (preg_match('#^servers/([1-9][0-9]*)/metrics$#', (string) $endpoint, $matches)) {
        $url = $baseUrl . '/servers/' . (int) $matches[1] . '/metrics';
        return strtoupper($method) === 'GET' && !empty($postData)
            ? $url . '?' . http_build_query($postData, '', '&', PHP_QUERY_RFC3986)
            : $url;
    }

    // Server details helper call used by the attached ISO lookup.
    if (preg_match('#^servers/([1-9][0-9]*)$#', (string) $endpoint, $matches)) {
        return $baseUrl . '/servers/' . (int) $matches[1];
    }

    return false;
}

/**
 * Execute the HTTPS request and record a WHMCS module log entry with secrets scrubbed.
 */
function hetznercloud_ExecuteRequest($url, $method, $apiToken, $postData, $endpoint, $serverID)
{
    $headers = [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
        'User-Agent: WHMCS-HetznerCloud-Module/2.0',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    if (strtoupper($method) === 'POST' && !empty($postData)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result = is_string($response) ? json_decode($response, true) : null;

    $replaceVars = [$apiToken];
    if (is_array($result) && !empty($result['root_password'])) {
        $replaceVars[] = (string) $result['root_password'];
    }
    if (is_array($result) && !empty($result['password'])) {
        $replaceVars[] = (string) $result['password'];
    }

    // WHMCS documents logModuleCall for module request/response debugging and
    // recommends replaceVars for secrets such as passwords and credentials.
    logModuleCall(
        'hetznercloud',
        (string) $endpoint,
        [
            'method' => strtoupper($method),
            'serverID' => $serverID ? (int) $serverID : null,
            'payload' => $postData,
        ],
        is_string($response) ? $response : '',
        $result,
        array_values(array_filter($replaceVars, 'strlen'))
    );

    if ($curlError) {
        logActivity("Hetzner Cloud - API connection error for endpoint: " . $endpoint);
        return ['success' => false, 'message' => 'Connection error'];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'message' => ucfirst((string) $endpoint) . ' command sent successfully!',
            'data' => is_array($result) ? $result : [],
        ];
    }

    $errorMessage = is_array($result) ? ($result['error']['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
    logActivity("Hetzner Cloud - API request failed for endpoint: " . $endpoint . " (HTTP " . $httpCode . ")");

    return [
        'success' => false,
        'message' => $errorMessage,
    ];
}

/**
 * Get server types with 24-hour caching.
 */
function hetznercloud_GetServerTypes($params = [])
{
    $cacheFile = __DIR__ . '/cache/server_types_cache.json';
    $cacheTime = 86400;

    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0750, true);
    }

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cachedData) && is_array($cachedData)) {
            return $cachedData;
        }
    }

    $response = hetznercloud_API_Request('GET', 'server_types', $params, null, []);

    if (!empty($response['success']) && isset($response['data']['server_types'])) {
        $serverTypes = array_map(function ($type) {
            return $type['name'];
        }, $response['data']['server_types']);

        file_put_contents($cacheFile, json_encode($serverTypes), LOCK_EX);
        return $serverTypes;
    }

    return [];
}

/**
 * ConfigOptions has no service context. Use a token only for read-only server type discovery.
 */
function hetznercloud_GetAPITokenForConfiguration()
{
    $result = select_query('tblservers', 'accesshash', ['type' => 'hetznercloud', 'disabled' => 0]);
    $data = mysql_fetch_array($result);
    return !empty($data['accesshash']) ? (string) $data['accesshash'] : null;
}

/**
 * Backward-compatible alias retained for existing integrations.
 */
function hetznercloud_GetAPIToken()
{
    return hetznercloud_GetAPITokenForConfiguration();
}

function hetznercloud_ValidateServerID($serverID)
{
    return filter_var($serverID, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

function hetznercloud_GetCachedData($cacheKey, $callback, $ttl = 3600)
{
    $cacheFile = __DIR__ . '/cache/' . md5((string) $cacheKey) . '.json';

    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0750, true);
    }

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < (int) $ttl) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cachedData)) {
            return $cachedData;
        }
    }

    $data = $callback();
    if (!empty($data)) {
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    }

    return $data;
}
