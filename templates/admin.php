<?php
/** @var array $_ */
?>
<div class="section" id="motrix-admin-settings">
    <h2>Motrix Download Manager</h2>
    <p class="settings-hint">Configure the remote or local Motrix server for download management.</p>

    <div class="form-group">
        <label for="motrix_admin_endpoint">Motrix MDXP Endpoint:</label>
        <input type="text" id="motrix_admin_endpoint" value="<?php p($_['endpoint']); ?>" placeholder="http://127.0.0.1:16801" class="text-input" />
    </div>

    <div class="form-group">
        <label for="motrix_admin_savedir">Default Download Directory (on Motrix Server):</label>
        <input type="text" id="motrix_admin_savedir" value="<?php p($_['saveDir']); ?>" placeholder="/downloads" class="text-input" />
    </div>

    <div class="form-group">
        <label for="motrix_admin_token">Bearer Token (leave blank to keep current):</label>
        <input type="password" id="motrix_admin_token" placeholder="<?php p($_['hasToken'] ? '••••••••' : 'No token set'); ?>" class="text-input" />
    </div>
</div>
