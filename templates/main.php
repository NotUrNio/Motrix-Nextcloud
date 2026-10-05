<?php
script('motrix', 'app');
style('motrix', 'style');
?>

<div id="app" class="motrix-app">
    <!-- App Sidebar Navigation -->
    <div id="app-navigation" class="motrix-nav">
        <ul class="motrix-filter-list">
            <li class="active" data-filter="all">
                <a href="#all" class="icon-folder">
                    <span class="nav-label">All Downloads</span>
                    <span class="nav-count" id="count-all">0</span>
                </a>
            </li>
            <li data-filter="downloading">
                <a href="#downloading" class="icon-download">
                    <span class="nav-label">Downloading</span>
                    <span class="nav-count" id="count-downloading">0</span>
                </a>
            </li>
            <li data-filter="paused">
                <a href="#paused" class="icon-pause">
                    <span class="nav-label">Paused</span>
                    <span class="nav-count" id="count-paused">0</span>
                </a>
            </li>
            <li data-filter="completed">
                <a href="#completed" class="icon-checkmark">
                    <span class="nav-label">Completed</span>
                    <span class="nav-count" id="count-completed">0</span>
                </a>
            </li>
        </ul>

        <div id="app-settings">
            <div id="app-settings-header">
                <button class="settings-button" id="motrix-open-settings" title="Settings">
                    <span>Motrix Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- App Content Area -->
    <div id="app-content" class="motrix-content">
        <!-- Top Toolbar -->
        <div class="motrix-toolbar">
            <div class="toolbar-left">
                <button id="btn-new-task" class="primary button">
                    <span class="icon-add"></span> + New Download
                </button>
                <button id="btn-refresh" class="button" title="Refresh list">
                    Refresh
                </button>
            </div>

            <div class="toolbar-right">
                <div class="speed-indicator">
                    <span class="speed-item speed-down" title="Download speed">
                        ⬇ <span id="speed-download">0 B/s</span>
                    </span>
                    <span class="speed-item speed-up" title="Upload speed">
                        ⬆ <span id="speed-upload">0 B/s</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Task List Container -->
        <div class="motrix-task-container">
            <div id="motrix-loading" class="motrix-banner hidden">
                <span class="icon-loading-small"></span> Loading tasks...
            </div>

            <div id="motrix-error" class="motrix-banner motrix-banner-error hidden">
                <span id="motrix-error-text"></span>
            </div>

            <div id="motrix-empty" class="motrix-empty hidden">
                <div class="empty-icon">📥</div>
                <h2>No downloads yet</h2>
                <p>Click <strong>+ New Download</strong> above to start downloading HTTP, FTP, or Magnet links directly into Nextcloud.</p>
            </div>

            <div id="motrix-task-list" class="motrix-task-list"></div>
        </div>
    </div>

    <!-- Modal: Add Download -->
    <div id="modal-add-task" class="motrix-modal-backdrop hidden">
        <div class="motrix-modal">
            <div class="modal-header">
                <h3>Add New Download</h3>
                <button class="modal-close" id="modal-add-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="input-task-kind">Download Type:</label>
                    <select id="input-task-kind" class="form-control">
                        <option value="url">Direct URL (HTTP / HTTPS / FTP)</option>
                        <option value="magnet">Magnet Link</option>
                    </select>
                </div>

                <div class="form-group" id="group-url">
                    <label for="input-task-url">Download URL:</label>
                    <input type="text" id="input-task-url" class="form-control" placeholder="https://example.com/file.zip or ftp://...">
                </div>

                <div class="form-group hidden" id="group-magnet">
                    <label for="input-task-magnet">Magnet URI:</label>
                    <input type="text" id="input-task-magnet" class="form-control" placeholder="magnet:?xt=urn:btih:...">
                </div>

                <div class="form-group" id="group-filename">
                    <label for="input-task-filename">Optional Custom Filename:</label>
                    <input type="text" id="input-task-filename" class="form-control" placeholder="custom-name.iso">
                </div>

                <div class="form-group">
                    <label for="input-task-folder">Destination in Nextcloud:</label>
                    <input type="text" id="input-task-folder" class="form-control" value="Downloads" placeholder="Downloads">
                </div>
            </div>
            <div class="modal-footer">
                <button class="button" id="btn-cancel-add">Cancel</button>
                <button class="button primary" id="btn-submit-add">Start Download</button>
            </div>
        </div>
    </div>

    <!-- Modal: Settings -->
    <div id="modal-settings" class="motrix-modal-backdrop hidden">
        <div class="motrix-modal">
            <div class="modal-header">
                <h3>Motrix Connection Settings</h3>
                <button class="modal-close" id="modal-settings-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="input-setting-endpoint">Motrix Server Endpoint (MDXP):</label>
                    <input type="text" id="input-setting-endpoint" class="form-control" placeholder="http://127.0.0.1:16801">
                    <small>Default is <code>http://127.0.0.1:16801</code> (or Docker internal network <code>http://motrix:16801</code>)</small>
                </div>

                <div class="form-group">
                    <label for="input-setting-token">Bearer Token (optional):</label>
                    <input type="password" id="input-setting-token" class="form-control" placeholder="Leave empty if authentication is not required">
                </div>

                <div class="form-group">
                    <label for="input-setting-savedir">Server Download Directory:</label>
                    <input type="text" id="input-setting-savedir" class="form-control" placeholder="/downloads">
                </div>

                <div id="test-connection-result" class="motrix-test-result hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="button" id="btn-test-connection">Test Connection</button>
                <button class="button" id="btn-cancel-settings">Cancel</button>
                <button class="button primary" id="btn-save-settings">Save Settings</button>
            </div>
        </div>
    </div>
</div>
