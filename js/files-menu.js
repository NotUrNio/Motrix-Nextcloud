/**
 * Motrix Download Manager - Nextcloud Files Integration
 * Adds shortcut to "+ New" menu, drag & drop link support, and inline downloader dialog with settings.
 */

(function () {
    'use strict';

    const MOTRIX_SVG_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>`;

    let activeTaskId = null;
    let pollInterval = null;

    /**
     * Helper to get currently viewed directory in Nextcloud Files.
     */
    function getCurrentFolder() {
        try {
            // Check query param (?dir=/folder)
            const params = new URLSearchParams(window.location.search);
            const queryDir = params.get('dir');
            if (queryDir) {
                return queryDir.replace(/^\/+|\/+$/g, '');
            }

            // Check URL hash (#/folder)
            const hash = window.location.hash;
            if (hash && hash.length > 1) {
                const cleanHash = hash.replace(/^#\/?/, '').split('?')[0];
                if (cleanHash && !cleanHash.startsWith('all-files')) {
                    return decodeURIComponent(cleanHash);
                }
            }

            // Check Nextcloud Files global app state
            if (window.OCA?.Files?.App?.fileList?.getCurrentDir) {
                const dir = window.OCA.Files.App.fileList.getCurrentDir();
                if (dir && dir !== '/') {
                    return dir.replace(/^\/+|\/+$/g, '');
                }
            }

            // Check breadcrumb elements in DOM
            const breadcrumbEl = document.querySelector('.files-list__breadcrumbs [aria-current="page"]');
            if (breadcrumbEl && breadcrumbEl.textContent) {
                const name = breadcrumbEl.textContent.trim();
                if (name && name !== 'All files' && name !== 'Home') {
                    return name;
                }
            }
        } catch (e) {
            console.debug('[Motrix] Error reading current directory:', e);
        }
        return '';
    }

    /**
     * Formats bytes to human-readable size.
     */
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    /**
     * Formats speed in bytes/sec.
     */
    function formatSpeed(bytesPerSec) {
        return formatBytes(bytesPerSec) + '/s';
    }

    /**
     * Shows a toast notification.
     */
    function showToast(message, type = 'info') {
        if (window.OC?.Notification?.show) {
            window.OC.Notification.show(message, { timeout: 4 });
            return;
        }

        const existingToast = document.querySelector('.motrix-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `motrix-toast motrix-toast-${type}`;
        toast.innerHTML = `<span>${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}</span> <span>${message}</span>`;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    /**
     * Refreshes Nextcloud Files file list view.
     */
    function refreshNextcloudFileList() {
        try {
            if (window.OCA?.Files?.App?.fileList?.reload) {
                window.OCA.Files.App.fileList.reload();
            } else {
                const reloadBtn = document.querySelector('[data-cy-files-content-breadcrumbs] [aria-label*="Reload"], [data-cy-files-content-breadcrumbs] [title*="Reload"]');
                if (reloadBtn) reloadBtn.click();
            }
        } catch (e) {
            console.debug('[Motrix] Could not trigger file list reload:', e);
        }
    }

    /**
     * Opens the Motrix Download modal dialog.
     */
    function openMotrixModal(targetFolder = '') {
        const folder = targetFolder || getCurrentFolder();
        const displayFolder = folder ? '/' + folder : '/';

        // Close any existing modal
        closeMotrixModal();

        const overlay = document.createElement('div');
        overlay.id = 'motrix-modal-overlay';
        overlay.className = 'motrix-modal-overlay';

        overlay.innerHTML = `
            <div class="motrix-modal" role="dialog" aria-modal="true" aria-labelledby="motrix-modal-title">
                <div class="motrix-modal-header">
                    <h2 class="motrix-modal-title" id="motrix-modal-title">
                        ${MOTRIX_SVG_ICON}
                        <span>Motrix Downloader</span>
                    </h2>
                    <button type="button" class="motrix-modal-close" id="motrix-modal-close" aria-label="Close">✕</button>
                </div>

                <div class="motrix-tabs">
                    <button type="button" class="motrix-tab-btn active" data-tab="download">Download</button>
                    <button type="button" class="motrix-tab-btn" data-tab="settings">Motrix Settings ⚙️</button>
                </div>

                <div class="motrix-modal-body">
                    <!-- DOWNLOAD TAB -->
                    <div class="motrix-tab-content active" id="motrix-tab-download">
                        <div class="motrix-folder-badge">
                            <span>📁 Saving directly to:</span>
                            <strong id="motrix-current-dir">${displayFolder}</strong>
                        </div>

                        <div class="motrix-dropzone" id="motrix-dropzone">
                            <div class="motrix-dropzone-icon">⚡</div>
                            <div class="motrix-dropzone-text">Drop a link or .torrent file here, or paste below</div>
                        </div>

                        <div class="motrix-form-group">
                            <label for="motrix-url-input">Download Link (URL / Magnet / Torrent):</label>
                            <textarea id="motrix-url-input" placeholder="https://example.com/file.zip&#10;magnet:?xt=urn:btih:...&#10;https://.../source.torrent" autofocus></textarea>
                            <div class="motrix-hint">Supports HTTP/HTTPS, FTP, Magnet links, and direct URLs.</div>
                        </div>

                        <div class="motrix-form-group">
                            <label for="motrix-filename-input">Custom File Name (optional):</label>
                            <input type="text" id="motrix-filename-input" placeholder="e.g. video.mp4 (leave empty for auto-detect)">
                        </div>

                        <!-- LIVE PROGRESS CONTAINER -->
                        <div id="motrix-progress-container" style="display: none;"></div>

                        <div class="motrix-modal-actions">
                            <button type="button" class="motrix-btn motrix-btn-secondary" id="motrix-cancel-btn">Cancel</button>
                            <button type="button" class="motrix-btn motrix-btn-primary" id="motrix-start-btn">
                                <span>⚡ Start Download</span>
                            </button>
                        </div>
                    </div>

                    <!-- SETTINGS TAB -->
                    <div class="motrix-tab-content" id="motrix-tab-settings">
                        <div class="motrix-form-group">
                            <label for="motrix-setting-endpoint">Motrix Server Endpoint:</label>
                            <input type="text" id="motrix-setting-endpoint" placeholder="http://motrix-server:16801">
                            <div class="motrix-hint">MDXP JSON-RPC endpoint of the Motrix server.</div>
                        </div>

                        <div class="motrix-form-group">
                            <label for="motrix-setting-token">Secret RPC Token (Optional):</label>
                            <input type="password" id="motrix-setting-token" placeholder="Bearer RPC Token">
                        </div>

                        <div class="motrix-form-group">
                            <label for="motrix-setting-savedir">Base Direct Storage Directory:</label>
                            <input type="text" id="motrix-setting-savedir" placeholder="/downloads">
                            <div class="motrix-hint">Mounted path inside Motrix server mapping directly to Nextcloud storage.</div>
                        </div>

                        <div id="motrix-settings-status" style="margin-bottom: 12px; font-size: 13px;"></div>

                        <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                            <a href="/apps/motrix" target="_blank" class="motrix-btn motrix-btn-secondary" style="font-size: 12px; text-decoration: none;">
                                <span>Open Motrix App ↗</span>
                            </a>
                            <a href="http://${window.location.hostname}:18080" target="_blank" class="motrix-btn motrix-btn-secondary" style="font-size: 12px; text-decoration: none;">
                                <span>Motrix Web UI (Port 18080) ↗</span>
                            </a>
                        </div>

                        <div class="motrix-modal-actions">
                            <button type="button" class="motrix-btn motrix-btn-secondary" id="motrix-test-settings-btn">Test Connection</button>
                            <button type="button" class="motrix-btn motrix-btn-primary" id="motrix-save-settings-btn">Save Settings</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Hook close events
        document.getElementById('motrix-modal-close').addEventListener('click', closeMotrixModal);
        document.getElementById('motrix-cancel-btn').addEventListener('click', closeMotrixModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeMotrixModal();
        });

        // Tab switching
        const tabBtns = overlay.querySelectorAll('.motrix-tab-btn');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                overlay.querySelectorAll('.motrix-tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                const targetTab = btn.getAttribute('data-tab');
                document.getElementById(`motrix-tab-${targetTab}`).classList.add('active');

                if (targetTab === 'settings') {
                    loadSettings();
                }
            });
        });

        // Drag & Drop onto modal dropzone
        const dropzone = document.getElementById('motrix-dropzone');
        const urlInput = document.getElementById('motrix-url-input');

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            const text = e.dataTransfer.getData('text/uri-list') || e.dataTransfer.getData('text/plain');
            if (text) {
                urlInput.value = text.trim();
                urlInput.focus();
            }
        });

        // Start Download Click
        const startBtn = document.getElementById('motrix-start-btn');
        startBtn.addEventListener('click', () => handleStartDownload(folder));

        // Enter key to download
        urlInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                handleStartDownload(folder);
            }
        });

        // Settings Buttons
        document.getElementById('motrix-test-settings-btn').addEventListener('click', testSettings);
        document.getElementById('motrix-save-settings-btn').addEventListener('click', saveSettings);
    }

    /**
     * Closes the Motrix modal.
     */
    function closeMotrixModal() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        activeTaskId = null;
        const overlay = document.getElementById('motrix-modal-overlay');
        if (overlay) overlay.remove();
    }

    /**
     * Handles starting a download task directly into Nextcloud storage.
     */
    async function handleStartDownload(targetFolder) {
        const urlInput = document.getElementById('motrix-url-input');
        const filenameInput = document.getElementById('motrix-filename-input');
        const startBtn = document.getElementById('motrix-start-btn');
        const progressContainer = document.getElementById('motrix-progress-container');

        const rawUrl = urlInput.value.trim();
        const customFilename = filenameInput.value.trim();

        if (!rawUrl) {
            showToast('Please enter a download link', 'error');
            urlInput.focus();
            return;
        }

        startBtn.disabled = true;
        startBtn.innerHTML = '<span>Adding task...</span>';

        let kind = 'url';
        let bodyPayload = {
            kind: 'url',
            url: rawUrl,
            targetFolder: targetFolder,
        };

        if (rawUrl.startsWith('magnet:?')) {
            kind = 'magnet';
            bodyPayload = {
                kind: 'magnet',
                magnet: rawUrl,
                targetFolder: targetFolder,
            };
        }

        if (customFilename) {
            bodyPayload.filename = customFilename;
        }

        try {
            const resp = await fetch('/apps/motrix/api/tasks', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': window.oc_requesttoken || OC.requestToken || '',
                },
                body: JSON.stringify(bodyPayload),
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.error || 'Failed to add task to Motrix');
            }

            const taskId = data.task?.taskId || data.task?.id;
            activeTaskId = taskId;

            showToast(`Task started! Downloading to ${targetFolder ? '/' + targetFolder : '/'}...`, 'success');

            // Render live progress inside modal
            renderLiveTaskCard(data.task, targetFolder);

            // Poll task status
            startTaskPolling(taskId, targetFolder);

        } catch (err) {
            showToast(`Error: ${err.message}`, 'error');
            startBtn.disabled = false;
            startBtn.innerHTML = '<span>⚡ Start Download</span>';
        }
    }

    /**
     * Renders live task progress inside the modal.
     */
    function renderLiveTaskCard(task, targetFolder) {
        const progressContainer = document.getElementById('motrix-progress-container');
        if (!progressContainer) return;

        progressContainer.style.display = 'block';
        const taskName = task.name || task.filename || 'Downloading...';

        progressContainer.innerHTML = `
            <div class="motrix-task-card">
                <div class="motrix-task-card-header">
                    <span class="motrix-task-name" id="motrix-card-name" title="${taskName}">⚡ ${taskName}</span>
                    <span class="motrix-task-status-badge motrix-status-active" id="motrix-card-status">DOWNLOADING</span>
                </div>
                <div class="motrix-progress-bar-bg">
                    <div class="motrix-progress-bar-fill" id="motrix-card-fill" style="width: 0%;"></div>
                </div>
                <div class="motrix-task-meta">
                    <span id="motrix-card-speed">Speed: 0 B/s</span>
                    <span id="motrix-card-progress">0%</span>
                    <span id="motrix-card-size">0 B</span>
                </div>
            </div>
        `;
    }

    /**
     * Polls active task status and triggers Nextcloud auto-rescan on finish.
     */
    function startTaskPolling(taskId, targetFolder) {
        if (pollInterval) clearInterval(pollInterval);

        pollInterval = setInterval(async () => {
            if (!taskId) return;

            try {
                const resp = await fetch(`/apps/motrix/api/tasks/${taskId}?targetFolder=${encodeURIComponent(targetFolder)}`, {
                    headers: {
                        'requesttoken': window.oc_requesttoken || OC.requestToken || '',
                    }
                });

                if (!resp.ok) return;
                const data = await resp.json();
                if (!data.success || !data.task) return;

                const t = data.task;
                const cardFill = document.getElementById('motrix-card-fill');
                const cardStatus = document.getElementById('motrix-card-status');
                const cardSpeed = document.getElementById('motrix-card-speed');
                const cardProgress = document.getElementById('motrix-card-progress');
                const cardSize = document.getElementById('motrix-card-size');
                const cardName = document.getElementById('motrix-card-name');

                if (cardName && t.name) {
                    cardName.textContent = '⚡ ' + t.name;
                }

                const total = t.totalLength || t.size || 0;
                const completed = t.completedLength || t.downloaded || 0;
                const percent = total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 0;
                const speed = t.downloadSpeed || 0;

                if (cardFill) cardFill.style.width = `${percent}%`;
                if (cardProgress) cardProgress.textContent = `${percent}%`;
                if (cardSpeed) cardSpeed.textContent = `Speed: ${formatSpeed(speed)}`;
                if (cardSize) cardSize.textContent = `${formatBytes(completed)} / ${formatBytes(total)}`;

                const status = (t.status || '').toLowerCase();
                if (status === 'complete' || status === 'completed' || percent === 100) {
                    clearInterval(pollInterval);
                    pollInterval = null;

                    if (cardStatus) {
                        cardStatus.className = 'motrix-task-status-badge motrix-status-complete';
                        cardStatus.textContent = 'COMPLETE';
                    }

                    showToast('Download complete! File saved directly in Nextcloud.', 'success');

                    // Trigger Nextcloud Files refresh
                    refreshNextcloudFileList();

                    // Re-scan folder via API to guarantee indexing
                    fetch('/apps/motrix/api/scan', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': window.oc_requesttoken || OC.requestToken || '',
                        },
                        body: JSON.stringify({ targetFolder: targetFolder }),
                    }).then(() => {
                        refreshNextcloudFileList();
                    }).catch(console.error);

                } else if (status === 'error' || status === 'failed') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    if (cardStatus) {
                        cardStatus.className = 'motrix-task-status-badge motrix-status-error';
                        cardStatus.textContent = 'ERROR';
                    }
                    showToast('Download encountered an error in Motrix', 'error');
                }
            } catch (err) {
                console.debug('[Motrix] Error polling task:', err);
            }
        }, 1200);
    }

    /**
     * Loads Motrix settings into settings tab.
     */
    async function loadSettings() {
        try {
            const resp = await fetch('/apps/motrix/api/settings', {
                headers: { 'requesttoken': window.oc_requesttoken || OC.requestToken || '' }
            });
            const data = await resp.json();
            if (data.success) {
                const endpointInput = document.getElementById('motrix-setting-endpoint');
                const savedirInput = document.getElementById('motrix-setting-savedir');
                if (endpointInput) endpointInput.value = data.endpoint || 'http://motrix-server:16801';
                if (savedirInput) savedirInput.value = data.saveDir || '/downloads';
            }
        } catch (e) {
            console.warn('[Motrix] Error loading settings:', e);
        }
    }

    /**
     * Tests connection to Motrix MDXP.
     */
    async function testSettings() {
        const statusDiv = document.getElementById('motrix-settings-status');
        const testBtn = document.getElementById('motrix-test-settings-btn');

        testBtn.disabled = true;
        statusDiv.innerHTML = '<span style="color: #38bdf8;">Testing connection to Motrix...</span>';

        try {
            const resp = await fetch('/apps/motrix/api/settings/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': window.oc_requesttoken || OC.requestToken || '',
                }
            });
            const data = await resp.json();

            if (data.success) {
                const engine = data.engine?.state || 'ready';
                const speed = formatSpeed(data.stats?.totalDownloadSpeed || 0);
                statusDiv.innerHTML = `<span style="color: #4ade80;">✓ Connected! Engine state: <strong>${engine}</strong> | Current speed: <strong>${speed}</strong></span>`;
                showToast('Motrix connection successful!', 'success');
            } else {
                statusDiv.innerHTML = `<span style="color: #f87171;">✕ Connection failed: ${data.error || 'Cannot reach Motrix server'}</span>`;
                showToast('Failed to connect to Motrix', 'error');
            }
        } catch (err) {
            statusDiv.innerHTML = `<span style="color: #f87171;">✕ Error: ${err.message}</span>`;
        } finally {
            testBtn.disabled = false;
        }
    }

    /**
     * Saves settings back to Nextcloud.
     */
    async function saveSettings() {
        const endpoint = document.getElementById('motrix-setting-endpoint').value.trim();
        const token = document.getElementById('motrix-setting-token').value.trim();
        const saveDir = document.getElementById('motrix-setting-savedir').value.trim();
        const saveBtn = document.getElementById('motrix-save-settings-btn');
        const statusDiv = document.getElementById('motrix-settings-status');

        saveBtn.disabled = true;

        try {
            const payload = { endpoint, saveDir };
            if (token) payload.token = token;

            const resp = await fetch('/apps/motrix/api/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': window.oc_requesttoken || OC.requestToken || '',
                },
                body: JSON.stringify(payload),
            });

            const data = await resp.json();
            if (data.success) {
                statusDiv.innerHTML = '<span style="color: #4ade80;">✓ Settings saved successfully!</span>';
                showToast('Settings saved!', 'success');
            } else {
                statusDiv.innerHTML = `<span style="color: #f87171;">✕ Error: ${data.error || 'Failed to save settings'}</span>`;
            }
        } catch (err) {
            statusDiv.innerHTML = `<span style="color: #f87171;">✕ Error: ${err.message}</span>`;
        } finally {
            saveBtn.disabled = false;
        }
    }

    /**
     * Registers entry in Nextcloud Files "+ New" menu (Vue scope).
     */
    function registerInNewFileMenu() {
        try {
            const scope = window._nc_files_scope?.v4_0;
            if (scope && scope.newFileMenu && typeof scope.newFileMenu.registerEntry === 'function') {
                if (scope.newFileMenu.getEntryIndex('motrix-download') === -1) {
                    scope.newFileMenu.registerEntry({
                        id: 'motrix-download',
                        displayName: 'Download with Motrix',
                        iconSvgInline: MOTRIX_SVG_ICON,
                        order: 35,
                        category: 1, // CreateNew
                        handler: function (destination) {
                            const dir = (destination && destination.path) ? destination.path.replace(/^\/+/, '') : getCurrentFolder();
                            openMotrixModal(dir);
                        }
                    });
                    console.log('[Motrix] Successfully registered into window._nc_files_scope.v4_0.newFileMenu');
                    return true;
                }
            }
        } catch (e) {
            console.debug('[Motrix] Error registering in newFileMenu:', e);
        }
        return false;
    }

    /**
     * Fallback DOM injector for the "+ New" menu popover.
     * When user clicks "+ New", checks if the menu item is rendered; if not, inserts it cleanly.
     */
    function attachDOMMenuObserver() {
        const observer = new MutationObserver(() => {
            // Find open upload picker or menu popover
            const menuContainers = document.querySelectorAll('.upload-picker, [data-cy-upload-picker-menu], .popover__wrapper [role="menu"]');
            menuContainers.forEach(container => {
                // Check if already injected
                if (container.querySelector('[data-cy-upload-picker-menu-entry="motrix-download"]') ||
                    container.querySelector('#motrix-dom-menu-entry')) {
                    return;
                }

                // Find "Create new" section or menu list
                const menuList = container.querySelector('ul[role="menu"]') || container.querySelector('ul') || container;
                if (!menuList) return;

                // Create clean menu item matching Nextcloud styling
                const li = document.createElement('li');
                li.id = 'motrix-dom-menu-entry';
                li.setAttribute('role', 'presentation');

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'upload-picker__menu-entry button-vue';
                btn.setAttribute('role', 'menuitem');
                btn.setAttribute('data-cy-upload-picker-menu-entry', 'motrix-download');
                btn.style.cssText = 'display: flex; align-items: center; width: 100%; text-align: left; cursor: pointer;';

                btn.innerHTML = `
                    <span class="motrix-menu-icon">${MOTRIX_SVG_ICON}</span>
                    <span class="action-text">Download with Motrix</span>
                `;

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    // Close menu
                    const trigger = document.querySelector('[data-cy-upload-picker-trigger], #controls .new');
                    if (trigger) trigger.click();

                    openMotrixModal(getCurrentFolder());
                });

                li.appendChild(btn);
                menuList.appendChild(li);
            });

            // Also attach quick action button in header breadcrumbs if not present
            attachQuickHeaderButton();
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    /**
     * Attaches a quick Motrix button directly in the Files breadcrumb / control bar.
     */
    function attachQuickHeaderButton() {
        if (document.getElementById('motrix-quick-header-btn')) return;

        const breadcrumbs = document.querySelector('.files-list__breadcrumbs, #controls .actions');
        if (breadcrumbs) {
            const quickBtn = document.createElement('button');
            quickBtn.id = 'motrix-quick-header-btn';
            quickBtn.className = 'motrix-quick-action-btn';
            quickBtn.type = 'button';
            quickBtn.title = 'Download to this folder with Motrix';
            quickBtn.innerHTML = `
                ${MOTRIX_SVG_ICON}
                <span>Motrix</span>
            `;
            quickBtn.addEventListener('click', () => {
                openMotrixModal(getCurrentFolder());
            });

            breadcrumbs.appendChild(quickBtn);
        }
    }

    /**
     * Global drag & drop listener on Files view.
     */
    function attachGlobalDropListener() {
        window.addEventListener('dragover', (e) => {
            const types = e.dataTransfer?.types || [];
            if (types.includes('text/uri-list') || types.includes('text/plain')) {
                e.preventDefault();
            }
        });

        window.addEventListener('drop', (e) => {
            // Ignore if dropped inside an existing input/textarea
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            const text = e.dataTransfer?.getData('text/uri-list') || e.dataTransfer?.getData('text/plain');
            if (text && (text.startsWith('http://') || text.startsWith('https://') || text.startsWith('magnet:?'))) {
                e.preventDefault();
                openMotrixModal(getCurrentFolder());
                setTimeout(() => {
                    const urlInput = document.getElementById('motrix-url-input');
                    if (urlInput) {
                        urlInput.value = text.trim();
                        urlInput.focus();
                    }
                }, 100);
            }
        });
    }

    /**
     * Initialization routine.
     */
    function init() {
        console.log('[Motrix] Initializing Nextcloud Files shortcut integration...');

        // Try immediate registration
        registerInNewFileMenu();

        // Retry registration at intervals until Nextcloud Vue scope is ready
        let attempts = 0;
        const regInterval = setInterval(() => {
            attempts++;
            if (registerInNewFileMenu() || attempts > 20) {
                clearInterval(regInterval);
            }
        }, 300);

        // Attach fallback DOM observer
        attachDOMMenuObserver();

        // Attach global drop listener
        attachGlobalDropListener();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
