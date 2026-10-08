<?php
/** @var array $_ */
?>
<div id="nddownloader-admin-settings" class="section">
    <h2>ND Downloader</h2>
    <p class="settings-hint">Configure the remote or local download server for download management.</p>

    <div class="form-group" style="margin-top: 12px;">
        <label for="nddownloader_admin_endpoint" style="display: block; font-weight: bold; margin-bottom: 4px;">Aria2 RPC server:</label>
        <input type="text" id="nddownloader_admin_endpoint" value="<?php p($_['endpoint']); ?>" placeholder="http://127.0.0.1:16801" class="text-input" style="width: 350px;" />
        <p class="hint" style="color: #888; font-size: 12px; margin-top: 2px;">RPC endpoint (default: <code>http://127.0.0.1:16801</code>)</p>
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

    <div style="margin-top: 18px; display: flex; align-items: center; gap: 10px;">
        <button type="button" class="button button-primary" id="nddownloader_admin_save">Save Settings</button>
        <button type="button" class="button" id="nddownloader_admin_test">Test Connection</button>
        <span id="nddownloader_admin_status" style="font-size: 13px; font-weight: 500;"></span>
    </div>
</div>
