<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/security.php';

/**
 * Client Area Output
 */
function hetznercloud_ClientAreaOutput($params)
{
    hetznercloud_AssertServiceOwnership($params);

    $serviceId = (int) ($params['serviceid'] ?? 0);
    $csrfToken = hetznercloud_GetCsrfToken();

    logActivity("Hetzner Cloud - Client Area accessed for service ID: " . $serviceId);

    if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
        header('Content-Type: application/json; charset=utf-8');
        $statusInfo = hetznercloud_GetServerStatus($params);
        echo json_encode([
            'success' => true,
            'serverStatus' => $statusInfo['status'] ?? 'Unknown',
            'statusColor' => $statusInfo['color'] ?? 'grey',
            'statusMessage' => $statusInfo['message'] ?? 'No status available',
        ]);
        exit;
    }

    if (isset($_GET['ajax']) && $_GET['ajax'] === 'metrics') {
        header('Content-Type: application/json; charset=utf-8');

        $start = isset($_GET['start']) ? (int) $_GET['start'] : null;
        $end = isset($_GET['end']) ? (int) $_GET['end'] : null;

        if (!$start || !$end || $start <= 0 || $end <= 0 || $start > $end) {
            $end = time();
            $start = $end - 3600;
        }

        $maxRange = 31 * 24 * 60 * 60;
        if (($end - $start) > $maxRange) {
            $start = $end - $maxRange;
        }

        $metrics = hetznercloud_GetServerMetrics($params, $start, $end);
        echo json_encode([
            'success' => true,
            'metrics' => $metrics,
            'timeRange' => [
                'start' => $start,
                'end' => $end,
                'startFormatted' => date('Y-m-d H:i:s', $start),
                'endFormatted' => date('Y-m-d H:i:s', $end),
            ],
        ]);
        exit;
    }

    if (isset($_GET['ajax']) && $_GET['ajax'] === 'isos') {
        header('Content-Type: application/json; charset=utf-8');
        $isos = hetznercloud_GetAvailableISOs($params);
        echo json_encode([
            'success' => true,
            'isos' => $isos,
        ]);
        exit;
    }

    // Generate a console session only when the authenticated service owner clicks Web Console.
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'console_grant') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (!hetznercloud_ValidateCsrfToken($_POST['hetznercloud_csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid request token']);
            exit;
        }

        $serverID = $params['customfields']['serverID'] ?? null;
        if (!hetznercloud_ValidateServerID($serverID)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Server ID is missing or invalid']);
            exit;
        }

        $consoleResponse = hetznercloud_API_Request('POST', 'request_console', $params, $serverID);
        $consoleData = $consoleResponse['data'] ?? null;

        if (empty($consoleResponse['success']) || !is_array($consoleData)) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Unable to create console session']);
            exit;
        }

        $grantToken = hetznercloud_CreateConsoleGrant($params, $consoleData);
        if (!$grantToken) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to create console grant']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'url' => '../modules/servers/hetznercloud/console.php?token=' . rawurlencode($grantToken),
        ]);
        exit;
    }

    if (isset($_POST['ajax']) && $_POST['ajax'] === 'refresh_isos') {
        header('Content-Type: application/json; charset=utf-8');

        if (!hetznercloud_ValidateCsrfToken($_POST['hetznercloud_csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid request token']);
            exit;
        }

        $isos = hetznercloud_RefreshISOCache($params);
        echo json_encode([
            'success' => true,
            'isos' => $isos,
            'message' => 'ISO cache refreshed successfully',
        ]);
        exit;
    }

    if (isset($_POST['modop'], $_POST['a']) && $_POST['modop'] === 'custom' && $_POST['a'] === 'AttachISO') {
        $isoName = trim((string) ($_POST['iso_name'] ?? ''));

        if ($isoName === '') {
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '&error=no_iso');
            exit;
        }

        if (strlen($isoName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $isoName)) {
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '&error=iso_failed');
            exit;
        }

        $result = hetznercloud_AttachISO($params, $isoName);
        header('Location: clientarea.php?action=productdetails&id=' . $serviceId . ($result['success'] ? '&success=iso_attached' : '&error=iso_failed'));
        exit;
    }

    if (isset($_POST['modop'], $_POST['a']) && $_POST['modop'] === 'custom' && $_POST['a'] === 'RebuildOS') {
        $newImage = trim((string) ($_POST['new_image'] ?? ''));

        if ($newImage === '') {
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '&error=no_image');
            exit;
        }

        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $newImage)) {
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '&error=rebuild_failed');
            exit;
        }

        $result = hetznercloud_RebuildServer($params, $newImage);
        header('Location: clientarea.php?action=productdetails&id=' . $serviceId . ($result['success'] ? '&success=rebuild_initiated' : '&error=rebuild_failed'));
        exit;
    }

    if (isset($_POST['modop'], $_POST['a']) && $_POST['modop'] === 'custom' && $_POST['a'] === 'UnmountISO') {
        $result = hetznercloud_UnmountISO($params);
        header('Location: clientarea.php?action=productdetails&id=' . $serviceId . ($result['success'] ? '&success=iso_unmounted' : '&error=unmount_failed'));
        exit;
    }

    try {
        $statusInfo = hetznercloud_GetServerStatus($params);
        $serverInfo = hetznercloud_GetServerDetails($params);
        $availableImages = hetznercloud_GetAvailableImages($params);
        $attachedISO = hetznercloud_GetAttachedISO($params);

        if (isset($serverInfo['error'])) {
            $name = 'Unknown';
            $ip = 'Unknown';
            $image = 'Unknown';
        } else {
            $name = $serverInfo['name'] ?? 'Unknown';
            $ip = $serverInfo['public_net']['ipv4']['ip'] ?? 'Unknown';
            $image = $serverInfo['image']['description'] ?? 'Unknown';
        }
    } catch (Throwable $e) {
        logActivity("Hetzner Cloud - Client Area error for service ID: " . $serviceId);
        $statusInfo = ['status' => 'unknown', 'color' => 'gray', 'message' => 'Error loading data'];
        $name = 'Error';
        $ip = 'Error';
        $image = 'Error';
        $availableImages = [];
        $attachedISO = ['success' => true, 'iso' => null];
    }

    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'serviceid' => $serviceId,
            'serverID' => $params['customfields']['serverID'] ?? null,
            'serverName' => $name,
            'ip' => $ip,
            'image' => $image,
            'serverStatus' => $statusInfo['status'] ?? 'Unknown',
            'statusColor' => $statusInfo['color'] ?? 'grey',
            'statusMessage' => $statusInfo['message'] ?? 'No status available',
            'consoleLink' => '',
            'consoleText' => 'Web Console',
            'consoleError' => '',
            'availableImages' => $availableImages,
            'attachedISO' => !empty($attachedISO['success']) ? ($attachedISO['iso'] ?? null) : null,
            'username' => $params['username'] ?? 'root',
            'password' => $params['password'] ?? 'Click to set password',
            'error' => isset($_GET['error']) ? preg_replace('/[^a-z_]/', '', (string) $_GET['error']) : null,
            'success' => isset($_GET['success']) ? preg_replace('/[^a-z_]/', '', (string) $_GET['success']) : null,
            'message' => null,
            'csrfToken' => $csrfToken,
            'version' => HETZNERCLOUD_VERSION,
        ],
    ];
}
