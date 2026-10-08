<div id="app" class="nd-downloader-app">
    <!-- App Sidebar Navigation -->
    <div id="app-navigation" class="nd-downloader-nav">
        <ul class="nd-downloader-filter-list">
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
                <button class="settings-button" id="nddownloader-open-settings" title="Settings">
                    <span>ND Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- App Content Area -->
    <div id="app-content" class="nd-downloader-content">
        <!-- Top Toolbar -->
        <div class="nd-downloader-toolbar">
            <div class="toolbar-left">
                <button id="btn-new-task" class="primary button">
                    <span class="icon-add"></span> + New Download
                </button>
                <button id="btn-refresh" class="button" title="Refresh list">
                    Refresh
                </button>
                <button id="btn-start-engine" class="button" title="Start or check ND Engine">
                    ▶ Start ND Engine
                </button>
            </div>

            <div class="toolbar-right">
                <div id="engine-status-indicator" class="engine-indicator" title="ND Engine Status">
                    <span id="engine-dot" class="engine-dot engine-dot-unknown"></span>
                    <span id="engine-status-label">Engine: Checking...</span>
                </div>
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
        <div class="nd-downloader-task-container">
            <div id="nddownloader-loading" class="nd-downloader-banner hidden">
                <span class="icon-loading-small"></span> Loading tasks...
            </div>

            <div id="nddownloader-error" class="nd-downloader-banner nd-downloader-banner-error hidden">
                <span id="nddownloader-error-text"></span>
                <button id="btn-banner-start-engine" class="button primary banner-action-btn">
                    Start ND Engine
                </button>
            </div>

            <div id="nddownloader-empty" class="nd-downloader-empty hidden">
                <div class="empty-icon">📥</div>
                <h2>No downloads yet</h2>
                <p>Click <strong>+ New Download</strong> above to start downloading HTTP, FTP, or Magnet links directly into Nextcloud.</p>
            </div>

            <div id="nddownloader-task-list" class="nd-downloader-task-list"></div>
        </div>
    </div>

    <!-- Modal: Add Download -->
    <div id="modal-add-task" class="nd-downloader-modal-backdrop hidden">
        <div class="nd-downloader-modal">
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
    <div id="modal-settings" class="nd-downloader-modal-backdrop hidden">
        <div class="nd-downloader-modal">
            <div class="modal-header">
                <h3>ND Downloader Connection Settings</h3>
                <button class="modal-close" id="modal-settings-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="input-setting-endpoint">Download Server URL (/mdxp):</label>
                    <input type="text" id="input-setting-endpoint" class="form-control" placeholder="http://nd-server:16801">
                    <small>ND / Motrix download server endpoint exposing <code>/mdxp</code> (default: <code>http://nd-server:16801</code>)</small>
                </div>

                <div class="form-group">
                    <label for="input-setting-token">Bearer Token (optional):</label>
                    <input type="password" id="input-setting-token" class="form-control" placeholder="Leave empty if authentication is not required">
                </div>

                <div class="form-group">
                    <label for="input-setting-savedir">Server Download Directory:</label>
                    <input type="text" id="input-setting-savedir" class="form-control" placeholder="/downloads">
                </div>

                <div id="test-connection-result" class="nd-downloader-test-result hidden"></div>
            </div>
            <div class="modal-footer">
                <button class="button" id="btn-start-engine-settings">Start ND Engine</button>
                <button class="button" id="btn-test-connection">Test Connection</button>
                <button class="button" id="btn-cancel-settings">Cancel</button>
                <button class="button primary" id="btn-save-settings">Save Settings</button>
            </div>
        </div>
    </div>
</div>
