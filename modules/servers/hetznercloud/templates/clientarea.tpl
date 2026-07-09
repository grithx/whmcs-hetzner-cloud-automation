<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hetzner Cloud Server Management</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="modules/servers/hetznercloud/templates/style.css" rel="stylesheet">
</head>
<body>
<div class="htzc-container hetznerbody">
    <div class="dashboard">
        {if $error eq 'no_iso'}
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> No ISO name provided for attachment.</div>
        {elseif $error eq 'iso_failed'}
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Failed to attach ISO.</div>
        {elseif $error eq 'unmount_failed'}
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Failed to unmount ISO.</div>
        {elseif $error eq 'no_image'}
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> No new image selected for rebuild.</div>
        {elseif $error eq 'rebuild_failed'}
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Failed to rebuild server.</div>
        {/if}

        {if $success eq 'iso_attached'}
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> ISO attached successfully!</div>
        {elseif $success eq 'iso_unmounted'}
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> ISO unmounted successfully!</div>
        {elseif $success eq 'rebuild_initiated'}
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> Server rebuild initiated successfully.</div>
        {/if}

        <div class="card status-card">
            <div class="card-header"><i class="fas fa-server"></i><h3>Server Status</h3></div>
            <div class="server-info">
                <div class="info-item"><i class="fas fa-network-wired"></i><span><strong>IP:</strong> {$ip|escape:'html'}</span></div>
                <div class="info-item"><i class="fas fa-hdd"></i><span><strong>OS:</strong> {$image|escape:'html'}</span></div>
                <div class="info-item"><i class="fas fa-tag"></i><span><strong>Name:</strong> {$serverName|escape:'html'}</span></div>
                {if $attachedISO}
                    <div class="info-item">
                        <i class="fas fa-compact-disc"></i>
                        <span><strong>ISO:</strong> {$attachedISO.description|escape:'html'}</span>
                        <button class="btn btn-sm btn-outline-danger unmount-iso-btn" onclick="unmountISO()" title="Unmount ISO"><i class="fas fa-eject"></i> Unmount</button>
                    </div>
                {/if}
                <div class="status-indicator">
                    <div class="status-dot" style="background-color: {$statusColor|escape:'html'};"></div>
                    <span>{$statusMessage|escape:'html'}</span>
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-success" onclick="powerAction('PowerOn')"><i class="fas fa-power-off"></i> Power On</button>
                <button class="btn btn-danger" onclick="powerAction('PowerOff')"><i class="fas fa-times-circle"></i> Power Off</button>
                <button class="btn btn-warning" onclick="powerAction('Reboot')"><i class="fas fa-sync"></i> Reboot</button>
                <button class="btn btn-primary" onclick="openConsole()"><i class="fas fa-terminal"></i> Web Console</button>
            </div>
        </div>

        <div class="row-cards">
            <div class="card access-card">
                <div class="card-header"><i class="fas fa-key"></i><h3>Access Information</h3></div>
                <div class="access-grid card-content">
                    <div class="access-input-group">
                        <input type="text" id="accessIP" value="{$ip|escape:'html'}" readonly>
                        <button class="copy-btn-inline" onclick="copyToClipboard('accessIP', 'IP Address')"><i class="fas fa-copy"></i></button>
                    </div>
                    <div class="access-input-group">
                        <input type="text" id="accessUsername" value="{$username|escape:'html'}" readonly>
                        <button class="copy-btn-inline" onclick="copyToClipboard('accessUsername', 'Username')"><i class="fas fa-copy"></i></button>
                    </div>
                    <div class="access-input-group">
                        <input type="password" id="password-field" value="{$password|escape:'html'}" readonly autocomplete="off">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="togglePassword()" title="Show/Hide Password"><i class="fas fa-eye" id="password-icon"></i></button>
                        <button type="button" class="copy-btn-inline" onclick="copyToClipboard('password-field', 'Password')"><i class="fas fa-copy"></i></button>
                    </div>
                </div>
            </div>

            <div class="card management-card">
                <div class="card-header"><i class="fas fa-cogs"></i><h3>Server Management</h3></div>
                <div class="management-buttons card-content">
                    <button class="btn btn-info" onclick="openRebuildModal()"><i class="fas fa-download"></i> Rebuild OS</button>
                    <button class="btn btn-warning" onclick="openISOModal()"><i class="fas fa-compact-disc"></i> ISO Management</button>
                    <button class="btn btn-secondary" onclick="resetPassword()"><i class="fas fa-key"></i> Reset Password</button>
                </div>
            </div>
        </div>

        <div class="card metrics-section">
            <div class="card-header"><h3><i class="fas fa-chart-line"></i> Server Performance Metrics</h3></div>
            <div class="card-body">
                <div class="metrics-buttons">
                    <button class="metric-btn active" onclick="loadMetrics('cpu')"><i class="fas fa-microchip"></i> CPU Usage</button>
                    <button class="metric-btn" onclick="loadMetrics('disk')"><i class="fas fa-hdd"></i> Disk I/O</button>
                    <button class="metric-btn" onclick="loadMetrics('network')"><i class="fas fa-network-wired"></i> Network</button>
                </div>
                <div id="metrics-chart-container" style="display:none;"><canvas id="metrics-chart"></canvas></div>
                <div id="metricsLoading" class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading metrics...</div>
                <div id="metricsError" class="alert alert-danger" style="display:none;">No metrics data available for this type.</div>
                <div class="metrics-controls">
                    <div class="current-time-range">Current Time Range: <span id="currentTimeRangeDisplay">24 Hours</span></div>
                    <div class="time-presets">
                        <button class="btn btn-sm btn-outline-secondary" onclick="setTimeRange(1)">1 Hour</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="setTimeRange(12)">12 Hours</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="setTimeRange(24)">24 Hours</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="setTimeRange(168)">1 Week</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="setTimeRange(720)">30 Days</button>
                        <button class="btn btn-sm btn-outline-primary" onclick="refreshMetricsChart()"><i class="fas fa-sync-alt"></i> Refresh Chart</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card usage-section">
            <div class="card-header"><h3><i class="fas fa-tachometer-alt"></i> Current Resource Usage</h3></div>
            <div class="card-body">
                <div class="usage-grid">
                    <div class="usage-card"><div class="usage-icon"><i class="fas fa-microchip"></i></div><div class="usage-content"><div class="usage-label">CPU Usage</div><div class="usage-value" id="cpu-usage">Loading...</div></div></div>
                    <div class="usage-card"><div class="usage-icon"><i class="fas fa-hdd"></i></div><div class="usage-content"><div class="usage-label">Disk I/O</div><div class="usage-value" id="disk-usage">Loading...</div></div></div>
                    <div class="usage-card"><div class="usage-icon"><i class="fas fa-network-wired"></i></div><div class="usage-content"><div class="usage-label">Network</div><div class="usage-value" id="network-usage">Loading...</div></div></div>
                </div>
                <div class="usage-footer"><button onclick="refreshUsage()" class="btn btn-sm btn-outline-primary"><i class="fas fa-sync-alt"></i> Refresh</button><small class="text-muted">Last updated: <span id="last-update">Just now</span></small></div>
            </div>
        </div>
    </div>
</div>

<div id="rebuildModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-download"></i> Rebuild Server with New OS</h3><span class="close" onclick="closeModal('rebuildModal')">&times;</span></div>
        <div class="modal-body">
            <div class="alert alert-warning"><strong>Warning:</strong> This action will erase all data on your server and install a new operating system.</div>
            <form id="rebuildForm" method="POST" action="clientarea.php?action=productdetails&id={$serviceid|escape:'url'}">
                <input type="hidden" name="modop" value="custom">
                <input type="hidden" name="a" value="RebuildOS">
                <input type="hidden" name="hetznercloud_csrf_token" value="{$csrfToken|escape:'html'}">
                <div class="form-group">
                    <label for="newImage">Select New Operating System:</label>
                    <select id="newImage" name="new_image" required>
                        <option value="">Choose an OS...</option>
                        {foreach from=$availableImages item=image}
                            <option value="{$image.name|escape:'html'}">{$image.description|escape:'html'}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="btn-group"><button type="submit" class="btn btn-danger"><i class="fas fa-exclamation-triangle"></i> Confirm Rebuild</button><button type="button" class="btn btn-secondary" onclick="closeModal('rebuildModal')">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

<div id="isoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-compact-disc"></i> ISO Management</h3><span class="close" onclick="closeModal('isoModal')">&times;</span></div>
        <div class="modal-body">
            <div class="alert alert-info"><strong>Info:</strong> Attach an ISO to your server to boot from it.</div>
            <div class="iso-section">
                <div class="iso-header-row"><h4>Available ISOs</h4><button class="btn btn-sm btn-outline-secondary" onclick="refreshISOCache()"><i class="fas fa-sync"></i> Refresh Cache</button></div>
                <div id="isoLoading" class="loading"><i class="fas fa-spinner"></i><br>Loading available ISOs...</div>
                <div id="isoList" style="display:none;">
                    <div class="iso-filters"><input type="text" id="isoSearch" placeholder="Search ISOs..." class="form-control"><select id="isoArchitecture" class="form-control"><option value="">All Architectures</option><option value="x86">x86</option><option value="arm">ARM</option></select></div>
                    <div class="iso-grid" id="isoGrid"></div>
                </div>
                <div id="isoError" class="alert alert-danger" style="display:none;">Failed to load ISOs. Please try again.</div>
            </div>
        </div>
    </div>
</div>

<div id="serviceId" data-id="{$serviceid|escape:'html'}" style="display:none;"></div>
<div id="consoleLink" data-url="" style="display:none;"></div>
<form id="serviceForm" action="" method="POST" style="display:none;"><input type="hidden" name="hetznercloud_csrf_token" value="{$csrfToken|escape:'html'}"></form>
<div id="metricsUrl" data-url="" style="display:none;"></div>
<div id="statusUrl" data-url="" style="display:none;"></div>

<script>
window.hetznerCloudCsrfToken = {$csrfToken|json_encode nofilter};
</script>
<script src="modules/servers/hetznercloud/templates/script.js"></script>
<script src="modules/servers/hetznercloud/templates/security.js"></script>

<div class="copyright-notice"><div class="container"><p>&copy; 2025 WHMCS Hetzner Cloud Module v{$version|escape:'html'}</p></div></div>
</body>
</html>
