<?php
/** @var array $_ */
?>
<div id="nddownloader-admin-settings" class="section">
    <h2>ND Downloader</h2>
    <p class="settings-hint">Configure the remote or local download server for download management.</p>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_endpoint" style="display: block; font-weight: bold; margin-bottom: 4px;">Download Server URL (/mdxp JSON-RPC):</label>
        <input type="text" id="nddownloader_admin_endpoint" value="<?php p($_['endpoint']); ?>" placeholder="http://nd-server:16801" class="text-input" style="width: 350px;" />
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">ND / Motrix download server endpoint exposing <code>/mdxp</code> (default: <code>http://nd-server:16801</code>)</p>
    </div>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_savedir" style="display: block; font-weight: bold; margin-bottom: 4px;">Default Download Directory (on server):</label>
        <input type="text" id="nddownloader_admin_savedir" value="<?php p($_['saveDir']); ?>" placeholder="/downloads" class="text-input" style="width: 350px;" />
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">Mounted path inside the container mapping to storage (e.g. <code>/downloads</code>)</p>
    </div>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_token" style="display: block; font-weight: bold; margin-bottom: 4px;">Bearer Token (write-only, leave blank to keep current):</label>
        <input type="password" id="nddownloader_admin_token" placeholder="<?php p($_['hasToken'] ? '••••••••' : 'No token set'); ?>" class="text-input" style="width: 350px;" autocomplete="new-password" />
    </div>

    <h3 style="margin-top: 24px; font-size: 14px; font-weight: bold;">Security & SSRF Restrictions</h3>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_allowlist" style="display: block; font-weight: bold; margin-bottom: 4px;">Domain Allowlist (optional):</label>
        <input type="text" id="nddownloader_admin_allowlist" value="<?php p($_['domainAllowlist'] ?? ''); ?>" placeholder="*.archive.org, debian.org" class="text-input" style="width: 350px;" />
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">Comma-separated allowed domains. If set, only downloads from these domains are allowed.</p>
    </div>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_denylist" style="display: block; font-weight: bold; margin-bottom: 4px;">Domain Denylist (optional):</label>
        <input type="text" id="nddownloader_admin_denylist" value="<?php p($_['domainDenylist'] ?? ''); ?>" placeholder="internal.corp, badsite.com" class="text-input" style="width: 350px;" />
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">Comma-separated blocked domains. Cloud metadata and loopback addresses are always blocked.</p>
    </div>

    <div class="form-group" style="margin-top: 12px;">
        <label style="display: flex; align-items: center; gap: 8px; font-weight: bold;">
            <input type="checkbox" id="nddownloader_admin_allow_private" <?php p(!empty($_['allowPrivateNetwork']) ? 'checked' : ''); ?> />
            Allow downloads from private RFC1918 subnets (LAN)
        </label>
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">Disable in production to prevent SSRF against internal services. Loopback and cloud metadata remain strictly blocked.</p>
    </div>

    <div style="margin-top: 18px; display: flex; align-items: center; gap: 10px;">
        <button type="button" class="button button-primary" id="nddownloader_admin_save">Save Settings</button>
        <button type="button" class="button" id="nddownloader_admin_test">Test Connection</button>
        <span id="nddownloader_admin_status" style="font-size: 13px; font-weight: 500;"></span>
    </div>
</div>
