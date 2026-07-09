<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/security.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src wss:; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

if (hetznercloud_GetAuthenticatedClientId() <= 0) {
    http_response_code(403);
    exit('Forbidden');
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$grant = hetznercloud_ConsumeConsoleGrant($token);

if (!$grant) {
    http_response_code(403);
    exit('Invalid or expired console session');
}

$hostVal = $grant['host'];
$passwordVal = $grant['password'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>noVNC</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="./noVNC/app/styles/lite.css">
    <script src="./noVNC/vendor/promise.js"></script>
    <script type="module">
        import * as WebUtil from './noVNC/app/webutil.js';
        import RFB from './noVNC/core/rfb.js';

        let rfb;
        let desktopName;
        const consoleHost = <?php echo json_encode($hostVal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const consolePassword = <?php echo json_encode($passwordVal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function status(text, level = 'warn') {
            const allowedLevels = ['normal', 'warn', 'error'];
            const safeLevel = allowedLevels.includes(level) ? level : 'warn';
            const bar = document.getElementById('noVNC_status_bar');
            const label = document.getElementById('noVNC_status');
            bar.className = 'noVNC_status_' + safeLevel;
            label.textContent = text;
        }

        function updateDesktopName(event) {
            desktopName = event.detail.name;
        }

        function connected() {
            document.getElementById('sendCtrlAltDelButton').disabled = false;
            status('Connected to ' + (desktopName || 'server'), 'normal');
        }

        function disconnected(event) {
            document.getElementById('sendCtrlAltDelButton').disabled = true;
            status(event.detail.clean ? 'Disconnected' : 'Connection closed unexpectedly', event.detail.clean ? 'normal' : 'error');
        }

        function updatePowerButtons() {
            const powerButtons = document.getElementById('noVNC_power_buttons');
            powerButtons.className = rfb.capabilities.power ? 'noVNC_shown' : 'noVNC_hidden';
        }

        function sendCtrlAltDel() {
            rfb.sendCtrlAltDel();
            return false;
        }

        function machineShutdown() {
            rfb.machineShutdown();
            return false;
        }

        function machineReboot() {
            rfb.machineReboot();
            return false;
        }

        function machineReset() {
            rfb.machineReset();
            return false;
        }

        document.getElementById('sendCtrlAltDelButton').onclick = sendCtrlAltDel;
        document.getElementById('machineShutdownButton').onclick = machineShutdown;
        document.getElementById('machineRebootButton').onclick = machineReboot;
        document.getElementById('machineResetButton').onclick = machineReset;

        WebUtil.init_logging('warn');
        status('Connecting', 'normal');

        if (!consoleHost || !consoleHost.startsWith('wss://')) {
            status('Invalid console endpoint', 'error');
            throw new Error('Invalid console endpoint');
        }

        rfb = new RFB(document.body, consoleHost, {
            shared: true,
            credentials: { password: consolePassword }
        });
        rfb.viewOnly = false;
        rfb.scaleViewport = true;
        rfb.resizeSession = false;
        rfb.addEventListener('connect', connected);
        rfb.addEventListener('disconnect', disconnected);
        rfb.addEventListener('capabilities', updatePowerButtons);
        rfb.addEventListener('desktopname', updateDesktopName);
    </script>
</head>
<body>
    <div id="noVNC_status_bar">
        <div id="noVNC_left_dummy_elem"></div>
        <div id="noVNC_status">Loading</div>
        <div id="noVNC_buttons">
            <input type="button" value="Send Ctrl+Alt+Delete" id="sendCtrlAltDelButton" class="noVNC_shown" disabled>
            <span id="noVNC_power_buttons" class="noVNC_hidden">
                <input type="button" value="Shutdown" id="machineShutdownButton">
                <input type="button" value="Reboot" id="machineRebootButton">
                <input type="button" value="Reset" id="machineResetButton">
            </span>
        </div>
    </div>
</body>
</html>
