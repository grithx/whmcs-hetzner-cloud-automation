<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/security.php';

function hetznercloud_CreateAccount($params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $hostname = trim((string) ($params['domain'] ?? ''));
    $location = (string) ($params['customfields']['location'] ?? 'fsn1');
    $plan = (string) ($params['configoption1'] ?? 'cx11');
    $osImage = (string) ($params['customfields']['os_image'] ?? 'ubuntu-22.04');

    if ($hostname === '' || strlen($hostname) > 253) {
        return 'Error: Invalid hostname';
    }

    $postData = [
        'name' => $hostname,
        'server_type' => $plan,
        'location' => $location,
        'image' => $osImage,
        'start_after_create' => true,
    ];

    $response = hetznercloud_API_Request('POST', 'create_server', $params, null, $postData);
    if (empty($response['success']) || empty($response['data']['server']['id'])) {
        return 'Error: ' . ($response['message'] ?? 'Unknown error');
    }

    $hetzserverID = (int) $response['data']['server']['id'];
    $rootPassword = (string) ($response['data']['root_password'] ?? '');
    $dedicatedIP = (string) ($response['data']['server']['public_net']['ipv4']['ip'] ?? '');

    $serverIDFieldID = getCustomFieldID('serverID', (int) ($params['pid'] ?? 0));
    if ($serverIDFieldID) {
        update_query('tblcustomfieldsvalues', ['value' => $hetzserverID], [
            'fieldid' => $serverIDFieldID,
            'relid' => $serviceId,
        ]);
    }

    if ($dedicatedIP !== '') {
        update_query('tblhosting', ['dedicatedip' => $dedicatedIP], ['id' => $serviceId]);
    }

    if ($rootPassword !== '') {
        update_query('tblhosting', [
            'username' => 'root',
            'password' => encrypt($rootPassword),
        ], ['id' => $serviceId]);
    }

    logActivity('Hetzner Cloud - Server created for service ID: ' . $serviceId);
    return 'success';
}

function getCustomFieldID($fieldName, $productID)
{
    $query = select_query('tblcustomfields', 'id, fieldname', [
        'type' => 'product',
        'relid' => (int) $productID,
    ]);

    while ($data = mysql_fetch_array($query)) {
        $fieldParts = explode('|', (string) $data['fieldname']);
        if (trim($fieldParts[0]) === $fieldName) {
            return (int) $data['id'];
        }
    }

    return null;
}

/**
 * Secure web console link. Credentials remain in the authenticated WHMCS session;
 * the browser receives only an opaque, short-lived, one-time token.
 */
function hetznercloud_GetConsoleLink($params)
{
    hetznercloud_AssertServiceOwnership($params);

    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return [
            'fa' => 'fa fa-terminal fa-fw',
            'link' => '',
            'text' => 'Web Console (Unavailable)',
            'error' => 'Error: Server ID is missing or invalid.',
        ];
    }

    $response = hetznercloud_API_Request('POST', 'request_console', $params, $serverID);
    $consoleData = $response['data'] ?? null;

    if (empty($response['success']) || !is_array($consoleData)) {
        return [
            'fa' => 'fa fa-terminal fa-fw',
            'link' => '',
            'text' => 'Web Console (Unavailable)',
            'error' => 'Error: Failed to retrieve console session.',
        ];
    }

    $grantToken = hetznercloud_CreateConsoleGrant($params, $consoleData);
    if (!$grantToken) {
        return [
            'fa' => 'fa fa-terminal fa-fw',
            'link' => '',
            'text' => 'Web Console (Unavailable)',
            'error' => 'Error: Failed to create console grant.',
        ];
    }

    return [
        'fa' => 'fa fa-terminal fa-fw',
        'link' => '../modules/servers/hetznercloud/console.php?token=' . rawurlencode($grantToken),
        'text' => 'Web Console',
        'error' => '',
    ];
}

function hetznercloud_GetServerDetails($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['error' => 'Server ID is missing or invalid.'];
    }

    $response = hetznercloud_API_Request('GET', 'server_details', $params, $serverID);
    if (empty($response['success']) || empty($response['data']['server'])) {
        return ['error' => 'Failed to fetch server details.'];
    }

    return $response['data']['server'];
}

function hetznercloud_SuspendAccount($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return 'Error: Server ID is missing or invalid!';
    }

    $response = hetznercloud_API_Request('POST', 'poweroff', $params, $serverID);
    if (empty($response['success'])) {
        return 'Error: ' . ($response['message'] ?? 'Power off failed');
    }

    update_query('tblhosting', ['domainstatus' => 'Suspended'], ['id' => (int) $params['serviceid']]);
    return 'success';
}

function hetznercloud_UnsuspendAccount($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return 'Error: Server ID is missing or invalid!';
    }

    $response = hetznercloud_API_Request('POST', 'poweron', $params, $serverID);
    if (empty($response['success'])) {
        return 'Error: ' . ($response['message'] ?? 'Power on failed');
    }

    update_query('tblhosting', ['domainstatus' => 'Active'], ['id' => (int) $params['serviceid']]);
    return 'success';
}

function hetznercloud_TerminateAccount($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return 'Error: Server ID is missing or invalid!';
    }

    $response = hetznercloud_API_Request('DELETE', 'servers', $params, $serverID);
    if (empty($response['success'])) {
        return 'Error: ' . ($response['message'] ?? 'Delete failed');
    }

    update_query('tblhosting', ['domainstatus' => 'Terminated'], ['id' => (int) $params['serviceid']]);
    return 'success';
}

function hetznercloud_PowerOn($params)
{
    return hetznercloud_RunPowerAction($params, 'poweron');
}

function hetznercloud_PowerOff($params)
{
    return hetznercloud_RunPowerAction($params, 'poweroff');
}

function hetznercloud_Reboot($params)
{
    return hetznercloud_RunPowerAction($params, 'reboot');
}

function hetznercloud_RunPowerAction($params, $action)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return 'Error: Server ID is missing or invalid!';
    }

    $response = hetznercloud_API_Request('POST', $action, $params, $serverID);
    return !empty($response['success']) ? 'success' : ($response['message'] ?? 'Action failed');
}

function hetznercloud_GetServerStatus($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['status' => 'unknown', 'color' => 'gray', 'message' => 'Server ID missing'];
    }

    $response = hetznercloud_API_Request('GET', 'server_details', $params, $serverID);
    if (empty($response['success']) || empty($response['data']['server']['status'])) {
        return ['status' => 'unknown', 'color' => 'gray', 'message' => 'Failed to fetch status'];
    }

    $status = (string) $response['data']['server']['status'];
    $statusMap = [
        'running' => ['color' => 'green', 'message' => 'Online'],
        'off' => ['color' => 'red', 'message' => 'Offline'],
        'starting' => ['color' => 'orange', 'message' => 'Starting...'],
        'stopping' => ['color' => 'orange', 'message' => 'Stopping...'],
        'rebuilding' => ['color' => 'blue', 'message' => 'Rebuilding...'],
        'unknown' => ['color' => 'gray', 'message' => 'Unknown'],
    ];

    return [
        'status' => $status,
        'color' => $statusMap[$status]['color'] ?? 'gray',
        'message' => $statusMap[$status]['message'] ?? 'Unknown',
    ];
}

function hetznercloud_RebuildServer($params, $newImage = null)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    $newImage = $newImage ?: ($params['customfields']['new_image'] ?? 'ubuntu-22.04');

    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['success' => false, 'message' => 'Server ID is missing or invalid!'];
    }

    if (!is_string($newImage) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $newImage)) {
        return ['success' => false, 'message' => 'Invalid image name'];
    }

    $powerOff = hetznercloud_API_Request('POST', 'poweroff', $params, $serverID);
    if (empty($powerOff['success'])) {
        return ['success' => false, 'message' => 'Failed to power off server'];
    }

    sleep(5);
    $response = hetznercloud_API_Request('POST', 'rebuild', $params, $serverID, ['image' => $newImage]);

    if (empty($response['success'])) {
        return ['success' => false, 'message' => $response['message'] ?? 'Rebuild failed'];
    }

    if (!empty($response['data']['root_password'])) {
        update_query('tblhosting', [
            'password' => encrypt((string) $response['data']['root_password']),
        ], ['id' => (int) $params['serviceid']]);
    }

    return ['success' => true, 'message' => 'Server rebuild initiated successfully'];
}

function hetznercloud_ResetPassword($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return 'Error: Server ID is missing or invalid!';
    }

    $response = hetznercloud_API_Request('POST', 'reset_password', $params, $serverID);
    if (empty($response['success']) || empty($response['data']['root_password'])) {
        return 'Error: ' . ($response['message'] ?? 'Failed to reset password');
    }

    update_query('tblhosting', [
        'password' => encrypt((string) $response['data']['root_password']),
    ], ['id' => (int) $params['serviceid']]);

    return 'success';
}

function hetznercloud_GetServerMetrics($params, $startTime = null, $endTime = null)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['error' => 'Server ID is missing or invalid.'];
    }

    $endTime = $endTime ?: time();
    $startTime = $startTime ?: ($endTime - 86400);

    $query = [
        'start' => date('c', (int) $startTime),
        'end' => date('c', (int) $endTime),
    ];

    $cpu = hetznercloud_API_Request('GET', 'servers/' . (int) $serverID . '/metrics', $params, null, $query + ['type' => 'cpu']);
    $disk = hetznercloud_API_Request('GET', 'servers/' . (int) $serverID . '/metrics', $params, null, $query + ['type' => 'disk']);
    $network = hetznercloud_API_Request('GET', 'servers/' . (int) $serverID . '/metrics', $params, null, $query + ['type' => 'network']);

    return [
        'cpu' => !empty($cpu['success']) ? $cpu['data'] : null,
        'disk' => !empty($disk['success']) ? $disk['data'] : null,
        'network' => !empty($network['success']) ? $network['data'] : null,
        'timestamp' => time(),
    ];
}

function hetznercloud_GetAvailableImages($params)
{
    $cacheFolder = __DIR__ . '/cache';
    $cacheFile = $cacheFolder . '/images_cache.json';
    $cacheTime = 86400;

    hetznercloud_EnsureCacheFolder();

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) {
            return $cached;
        }
    }

    $response = hetznercloud_API_Request('GET', 'images', $params, null, []);
    if (empty($response['success']) || empty($response['data']['images'])) {
        return [];
    }

    $images = [];
    foreach ($response['data']['images'] as $image) {
        if (($image['type'] ?? '') === 'system') {
            $images[] = [
                'id' => (int) $image['id'],
                'name' => (string) $image['name'],
                'description' => (string) $image['description'],
                'os_flavor' => (string) $image['os_flavor'],
                'os_version' => (string) $image['os_version'],
            ];
        }
    }

    file_put_contents($cacheFile, json_encode($images), LOCK_EX);
    return $images;
}

function hetznercloud_EnsureCacheFolder()
{
    $cacheFolder = __DIR__ . '/cache';

    if (!file_exists($cacheFolder) && !mkdir($cacheFolder, 0750, true)) {
        return false;
    }

    @chmod($cacheFolder, 0750);
    return is_writable($cacheFolder);
}

function hetznercloud_GetAvailableISOs($params)
{
    if (!hetznercloud_EnsureCacheFolder()) {
        return [];
    }

    $cacheFile = __DIR__ . '/cache/isos_cache.json';
    $cacheTime = 86400;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached['isos']) && is_array($cached['isos'])) {
            return $cached['isos'];
        }
    }

    $response = hetznercloud_API_Request('GET', 'isos', $params, null, []);
    if (empty($response['success']) || empty($response['data']['isos'])) {
        return [];
    }

    $isos = [];
    foreach ($response['data']['isos'] as $iso) {
        $isos[] = [
            'id' => (int) $iso['id'],
            'name' => (string) $iso['name'],
            'description' => (string) $iso['description'],
            'type' => (string) $iso['type'],
            'architecture' => isset($iso['architecture']) ? (string) $iso['architecture'] : null,
        ];
    }

    $cacheData = [
        'timestamp' => time(),
        'count' => count($isos),
        'isos' => $isos,
    ];
    file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT), LOCK_EX);

    return $isos;
}

function hetznercloud_RefreshISOCache($params)
{
    $cacheFile = __DIR__ . '/cache/isos_cache.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
    return hetznercloud_GetAvailableISOs($params);
}

function hetznercloud_AttachISO($params, $isoName)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    $isoName = trim((string) $isoName);

    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['success' => false, 'message' => 'Server ID is missing or invalid!'];
    }

    if ($isoName === '' || strlen($isoName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $isoName)) {
        return ['success' => false, 'message' => 'Invalid ISO name'];
    }

    $response = hetznercloud_API_Request('POST', 'attach_iso', $params, $serverID, ['iso' => $isoName]);
    return !empty($response['success'])
        ? ['success' => true, 'message' => 'ISO attached successfully']
        : ['success' => false, 'message' => $response['message'] ?? 'ISO attachment failed'];
}

function hetznercloud_UnmountISO($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['success' => false, 'message' => 'Server ID is missing or invalid!'];
    }

    $response = hetznercloud_API_Request('POST', 'detach_iso', $params, $serverID);
    return !empty($response['success'])
        ? ['success' => true, 'message' => 'ISO unmounted successfully']
        : ['success' => false, 'message' => $response['message'] ?? 'ISO unmount failed'];
}

function hetznercloud_GetAttachedISO($params)
{
    $serverID = $params['customfields']['serverID'] ?? null;
    if (!hetznercloud_ValidateServerID($serverID)) {
        return ['success' => false, 'message' => 'Server ID is missing or invalid'];
    }

    $response = hetznercloud_API_Request('GET', 'server_details', $params, $serverID);
    if (empty($response['success']) || empty($response['data']['server'])) {
        return ['success' => false, 'message' => 'Failed to get server details'];
    }

    $iso = $response['data']['server']['iso'] ?? null;
    if (!$iso) {
        return ['success' => true, 'iso' => null];
    }

    return [
        'success' => true,
        'iso' => [
            'id' => (int) $iso['id'],
            'name' => (string) $iso['name'],
            'description' => (string) ($iso['description'] ?? ''),
        ],
    ];
}
