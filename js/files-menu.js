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
     * Helper to get request token reliably across Nextcloud versions.
     */
    function getRequestToken() {
        return window.oc_requesttoken || (window.OC && window.OC.requestToken) || document.head?.dataset?.requesttoken || '';
    }

    /**
     * Formats remaining time in seconds to human-readable string (hours, minutes, seconds).
     */
    function formatEta(seconds) {
        if (!seconds || seconds <= 0 || !isFinite(seconds)) return '';
        const totalSec = Math.round(seconds);
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;

        if (h > 0) {
            return `ETA: ${h}h ${m}m ${s}s`;
        }
        if (m > 0) {
            return `ETA: ${m}m ${s}s`;
        }
        return `ETA: ${s}s`;
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

        // Check and render active downloads immediately upon opening the modal
        checkAndDisplayActiveDownloads(folder);
    }

    /**
     * Set of completed task IDs in this session to prevent spamming rescan & notifications.
     */
    const sessionCompletedTasks = new Set();

    /**
     * Closes the Motrix modal and cleanly stops background polling.
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
     * Robust parser for Motrix task metrics.
     */
    function parseTaskMetrics(t) {
        if (!t) return null;
        const total = Number(t.bytesTotal ?? t.totalLength ?? t.size ?? 0);
        const completed = Number(t.bytesDone ?? t.completedLength ?? t.downloaded ?? 0);

        let percent = 0;
        if (t.progress != null && !isNaN(t.progress)) {
            const p = Number(t.progress);
            percent = (p <= 1 && p > 0) ? (p * 100).toFixed(1) : Math.min(100, p.toFixed(1));
        } else if (total > 0) {
            percent = Math.min(100, ((completed / total) * 100).toFixed(1));
        }

        const speed = Number(t.speedBps ?? t.downloadSpeed ?? 0);
        const etaSec = Number(t.etaSec ?? t.eta ?? 0);
        const eta = formatEta(etaSec);
        const rawStatus = String(t.status || 'downloading').toLowerCase();
        const status = (rawStatus === 'active' || rawStatus === 'waiting') ? 'downloading' : rawStatus;
        const name = t.name || t.filename || 'Download Task';
        const id = t.id || t.taskId;

        return {
            id,
            name,
            status,
            percent: parseFloat(percent) || 0,
            percentDisplay: (parseFloat(percent) || 0) + '%',
            completed,
            completedDisplay: formatBytes(completed),
            total,
            totalDisplay: total > 0 ? formatBytes(total) : 'Unknown size',
            speed,
            speedDisplay: formatSpeed(speed),
            eta,
            error: t.error || null,
        };
    }

    /**
     * Initializes action handlers on the progress container (delegated once).
     */
    function initProgressContainerHandlers() {
        const container = document.getElementById('motrix-progress-container');
        if (!container || container._hasHandlers) return;
        container._hasHandlers = true;

        container.addEventListener('click', async (e) => {
            const btn = e.target.closest('.motrix-mini-btn');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const taskId = btn.getAttribute('data-task-id');
            if (!action || !taskId) return;

            btn.disabled = true;

            try {
                if (action === 'pause') {
                    btn.textContent = 'Pausing...';
                    await fetch(`/apps/motrix/api/tasks/${taskId}/pause`, {
                        method: 'POST',
                        headers: { 'requesttoken': getRequestToken() },
                    });
                } else if (action === 'resume') {
                    btn.textContent = 'Resuming...';
                    await fetch(`/apps/motrix/api/tasks/${taskId}/resume`, {
                        method: 'POST',
                        headers: { 'requesttoken': getRequestToken() },
                    });
                } else if (action === 'cancel') {
                    if (!confirm('Cancel and remove this download task?')) {
                        btn.disabled = false;
                        return;
                    }
                    btn.textContent = 'Removing...';
                    let delRes = await fetch(`/apps/motrix/api/tasks/${taskId}`, {
                        method: 'DELETE',
                        headers: { 'requesttoken': getRequestToken() },
                    });
                    if (!delRes.ok) {
                        await fetch(`/apps/motrix/api/tasks/${taskId}/delete`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'requesttoken': getRequestToken()
                            },
                        });
                    }
                    const card = document.getElementById(`motrix-task-${taskId}`);
                    if (card) card.remove();
                    showToast('Download task removed', 'info');
                }
            } catch (err) {
                console.error('[Motrix] Action failed:', err);
                showToast(`Action failed: ${err.message}`, 'error');
            } finally {
                btn.disabled = false;
            }
        });
    }

    /**
     * Renders or smoothly updates a live task card in the DOM.
     */
    function updateOrRenderTaskCard(m) {
        const container = document.getElementById('motrix-progress-container');
        if (!container || !m || !m.id) return;

        container.style.display = 'block';

        const isFinished = m.status === 'completed' || m.status === 'complete' || m.percent >= 100;
        const badgeClass = isFinished
            ? 'motrix-status-complete'
            : (m.status === 'error' || m.status === 'failed')
                ? 'motrix-status-error'
                : (m.status === 'paused')
                    ? 'motrix-status-paused'
                    : 'motrix-status-active';

        const statusLabel = isFinished ? 'COMPLETE' : m.status.toUpperCase();

        const metaStatsHtml = `
            <span>${m.percentDisplay} (${m.completedDisplay} / ${m.totalDisplay})</span>
            ${(m.status === 'downloading' && m.speed > 0) ? ` • <span>${m.speedDisplay}</span>` : ''}
            ${m.eta ? ` • <span>${m.eta}</span>` : ''}
            ${m.error ? ` • <span style="color: #f87171;">${m.error}</span>` : ''}
        `;

        let actionsHtml = '';
        if (m.status === 'downloading') {
            actionsHtml += `<button type="button" class="motrix-mini-btn" data-action="pause" data-task-id="${m.id}" title="Pause download">⏸ Pause</button>`;
        } else if (m.status === 'paused') {
            actionsHtml += `<button type="button" class="motrix-mini-btn" data-action="resume" data-task-id="${m.id}" title="Resume download">▶ Resume</button>`;
        }
        actionsHtml += `<button type="button" class="motrix-mini-btn motrix-mini-btn-danger" data-action="cancel" data-task-id="${m.id}" title="Remove download">✕ Remove</button>`;

        let card = document.getElementById(`motrix-task-${m.id}`);
        if (!card) {
            card = document.createElement('div');
            card.className = 'motrix-task-card';
            card.id = `motrix-task-${m.id}`;
            card.setAttribute('data-task-id', m.id);
            card.innerHTML = `
                <div class="motrix-task-card-header">
                    <span class="motrix-task-name" title="${m.name}">⚡ ${m.name}</span>
                    <span class="motrix-task-status-badge ${badgeClass}">${statusLabel}</span>
                </div>
                <div class="motrix-progress-bar-bg">
                    <div class="motrix-progress-bar-fill ${m.status === 'downloading' ? 'active' : ''}" style="width: ${m.percent}%;"></div>
                </div>
                <div class="motrix-task-meta">
                    <div class="motrix-task-meta-stats">${metaStatsHtml}</div>
                    <div class="motrix-task-actions">${actionsHtml}</div>
                </div>
            `;
            container.prepend(card);
        } else {
            const nameEl = card.querySelector('.motrix-task-name');
            if (nameEl && m.name) {
                nameEl.textContent = '⚡ ' + m.name;
                nameEl.title = m.name;
            }

            const badgeEl = card.querySelector('.motrix-task-status-badge');
            if (badgeEl) {
                badgeEl.className = `motrix-task-status-badge ${badgeClass}`;
                badgeEl.textContent = statusLabel;
            }

            const fillEl = card.querySelector('.motrix-progress-bar-fill');
            if (fillEl) {
                fillEl.style.width = `${m.percent}%`;
                if (m.status === 'downloading') {
                    fillEl.classList.add('active');
                } else {
                    fillEl.classList.remove('active');
                }
            }

            const metaEl = card.querySelector('.motrix-task-meta-stats');
            if (metaEl) {
                metaEl.innerHTML = metaStatsHtml;
            }

            const actionsEl = card.querySelector('.motrix-task-actions');
            if (actionsEl) {
                actionsEl.innerHTML = actionsHtml;
            }
        }
    }

    /**
     * Checks if there are active downloads when modal is opened and displays them live.
     */
    async function checkAndDisplayActiveDownloads(targetFolder) {
        initProgressContainerHandlers();

        try {
            const resp = await fetch('/apps/motrix/api/tasks', {
                headers: { 'requesttoken': getRequestToken() }
            });
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data.success || !Array.isArray(data.tasks)) return;

            // Find active or downloading tasks
            const activeTasks = data.tasks.filter(t => {
                const st = (t.status || '').toLowerCase();
                return st === 'downloading' || st === 'active' || st === 'waiting' || st === 'paused';
            });

            if (activeTasks.length > 0) {
                activeTasks.forEach(t => {
                    const m = parseTaskMetrics(t);
                    if (m) updateOrRenderTaskCard(m);
                });

                // Start polling right away
                startTaskPolling(activeTasks[0].id, targetFolder);
            }
        } catch (e) {
            console.debug('[Motrix] Error checking active downloads:', e);
        }
    }

    /**
     * Polls active task status every 1000ms and updates UI in real-time.
     */
    function startTaskPolling(taskId, targetFolder) {
        if (pollInterval) clearInterval(pollInterval);

        const pollTick = async () => {
            const container = document.getElementById('motrix-progress-container');
            if (!container) {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                return;
            }

            try {
                const resp = await fetch('/apps/motrix/api/tasks', {
                    headers: { 'requesttoken': getRequestToken() }
                });
                if (!resp.ok) return;
                const data = await resp.json();
                if (!data.success || !Array.isArray(data.tasks)) return;

                const allTasks = data.tasks;

                allTasks.forEach(t => {
                    const m = parseTaskMetrics(t);
                    if (!m) return;

                    const isTargetTask = taskId && m.id === taskId;
                    const isActive = m.status === 'downloading' || m.status === 'paused';
                    const cardExists = !!document.getElementById(`motrix-task-${m.id}`);

                    if (isTargetTask || isActive || cardExists) {
                        updateOrRenderTaskCard(m);

                        // If task just completed
                        if ((m.status === 'complete' || m.status === 'completed' || m.percent >= 100) && !sessionCompletedTasks.has(m.id)) {
                            sessionCompletedTasks.add(m.id);

                            showToast(`✓ "${m.name}" download complete! Saved to Nextcloud.`, 'success');

                            // Refresh folder in Files view
                            refreshNextcloudFileList();

                            // Trigger backend rescan
                            fetch('/apps/motrix/api/scan', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'requesttoken': getRequestToken(),
                                },
                                body: JSON.stringify({ targetFolder: targetFolder || '' }),
                            }).then(() => {
                                refreshNextcloudFileList();
                            }).catch(console.error);
                        }
                    }
                });

            } catch (err) {
                console.debug('[Motrix] Error polling tasks:', err);
            }
        };

        pollTick();
        pollInterval = setInterval(pollTick, 1000);
    }

    /**
     * Handles starting a download task directly into Nextcloud storage.
     */
    async function handleStartDownload(targetFolder) {
        const urlInput = document.getElementById('motrix-url-input');
        const filenameInput = document.getElementById('motrix-filename-input');
        const startBtn = document.getElementById('motrix-start-btn');

        initProgressContainerHandlers();

        const rawUrl = urlInput.value.trim();
        const customFilename = filenameInput.value.trim();

        if (!rawUrl) {
            showToast('Please enter a download link', 'error');
            urlInput.focus();
            return;
        }

        startBtn.disabled = true;
        startBtn.innerHTML = '<span>Adding task...</span>';

        let bodyPayload = {
            kind: 'url',
            url: rawUrl,
            targetFolder: targetFolder,
        };

        if (rawUrl.startsWith('magnet:?')) {
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
                    'requesttoken': getRequestToken(),
                },
                body: JSON.stringify(bodyPayload),
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.error || 'Failed to add task to Motrix');
            }

            const rawTask = data.task;
            const taskId = rawTask?.taskId || rawTask?.id || rawTask?.task?.id || null;
            activeTaskId = taskId;

            // Clear inputs so user is ready for another download
            urlInput.value = '';
            filenameInput.value = '';

            // Reset start button so user can add another download
            startBtn.disabled = false;
            startBtn.innerHTML = '<span>⚡ Add Another Download</span>';

            showToast(`Task started! Downloading to ${targetFolder ? '/' + targetFolder : '/'}...`, 'success');

            // Render placeholder card immediately while polling starts
            if (rawTask) {
                const initialMetric = parseTaskMetrics(rawTask) || {
                    id: taskId || ('temp-' + Date.now()),
                    name: customFilename || (rawUrl.split('?')[0].split('/').pop()) || 'Downloading...',
                    status: 'downloading',
                    percent: 0,
                    percentDisplay: '0%',
                    completed: 0,
                    completedDisplay: '0 B',
                    total: 0,
                    totalDisplay: 'Calculating size...',
                    speed: 0,
                    speedDisplay: '0 B/s',
                    eta: '',
                };
                updateOrRenderTaskCard(initialMetric);
            }

            // Immediately start live polling
            startTaskPolling(taskId, targetFolder);

        } catch (err) {
            showToast(`Error: ${err.message}`, 'error');
            startBtn.disabled = false;
            startBtn.innerHTML = '<span>⚡ Start Download</span>';
        }
    }

    /**
     * Loads Motrix settings into settings tab.
     */
    async function loadSettings() {
        try {
            const resp = await fetch('/apps/motrix/api/settings', {
                headers: { 'requesttoken': getRequestToken() }
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
                    'requesttoken': getRequestToken(),
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
                    'requesttoken': getRequestToken(),
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
            const menu = window._nc_newfilemenu || window._nc_files_scope?.v4_0?.newFileMenu;
            if (menu && typeof menu.registerEntry === 'function') {
                if (menu.getEntryIndex('motrix-download') === -1) {
                    menu.registerEntry({
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
                    console.log('[Motrix] Successfully registered into newFileMenu');
                    return true;
                }
            }
        } catch (e) {
            console.debug('[Motrix] Error registering in newFileMenu:', e);
        }
        return false;
    }

    /**
     * Attaches a primary header shortcut button right next to "+ New".
     */
    function attachHeaderButton() {
        // Clean up any old duplicate breadcrumb button
        const oldQuick = document.getElementById('motrix-quick-header-btn');
        if (oldQuick) oldQuick.remove();

        // If button already exists in header, do not duplicate
        if (document.getElementById('motrix-header-btn')) return;

        // Find upload picker (+ New button container)
        const uploadPicker = document.querySelector('.upload-picker, [data-cy-upload-picker]');
        if (!uploadPicker) return;

        // Clean up any stray child mistakenly appended into uploadPicker
        const strayLis = uploadPicker.querySelectorAll('li#motrix-dom-menu-entry, li');
        strayLis.forEach(el => el.remove());

        const btn = document.createElement('button');
        btn.id = 'motrix-header-btn';
        btn.className = 'motrix-header-btn button-vue';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Download with Motrix');
        btn.title = 'Download directly to this folder with Motrix';
        btn.innerHTML = `
            <span class="motrix-btn-icon">${MOTRIX_SVG_ICON}</span>
            <span class="motrix-btn-text">Download with Motrix</span>
        `;

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openMotrixModal(getCurrentFolder());
        });

        // Insert immediately after the + New container
        uploadPicker.insertAdjacentElement('afterend', btn);
    }

    /**
     * Fallback DOM injector for the "+ New" popover menu.
     * ONLY injects into the open popover menu. NEVER touches .upload-picker.
     */
    function attachPopoverMenuObserver() {
        const observer = new MutationObserver(() => {
            // Keep header shortcut button attached
            attachHeaderButton();

            // Find open upload picker or menu popovers
            const openMenuLists = document.querySelectorAll('.popover__wrapper ul[role="menu"], [data-cy-upload-picker-menu] ul[role="menu"], .popover__wrapper .action-menu');
            openMenuLists.forEach(menuList => {
                // If entry already present, skip
                if (menuList.querySelector('[data-cy-upload-picker-menu-entry="motrix-download"]') ||
                    menuList.querySelector('#motrix-popover-menu-entry')) {
                    return;
                }

                // Create clean menu item matching Nextcloud styling
                const li = document.createElement('li');
                li.id = 'motrix-popover-menu-entry';
                li.className = 'action-menu__item';
                li.setAttribute('role', 'presentation');

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'action-button action-button--primary upload-picker__menu-entry button-vue';
                btn.setAttribute('role', 'menuitem');
                btn.setAttribute('data-cy-upload-picker-menu-entry', 'motrix-download');

                btn.innerHTML = `
                    <span class="action-button__icon motrix-menu-icon">${MOTRIX_SVG_ICON}</span>
                    <span class="action-button__title">Download with Motrix</span>
                `;

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    // Close menu
                    const trigger = document.querySelector('[data-cy-upload-picker] button, .upload-picker button');
                    if (trigger) trigger.click();

                    openMotrixModal(getCurrentFolder());
                });

                li.appendChild(btn);
                menuList.appendChild(li);
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
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

        // Clean up any old duplicate buttons
        const oldQuick = document.getElementById('motrix-quick-header-btn');
        if (oldQuick) oldQuick.remove();

        // Attach header button
        attachHeaderButton();

        // Try immediate registration
        registerInNewFileMenu();

        // Retry registration at intervals until Nextcloud Vue scope / newFileMenu is ready
        let attempts = 0;
        const regInterval = setInterval(() => {
            attempts++;
            attachHeaderButton();
            if (registerInNewFileMenu() || attempts > 20) {
                if (registerInNewFileMenu()) {
                    clearInterval(regInterval);
                }
            }
            if (attempts > 30) {
                clearInterval(regInterval);
            }
        }, 300);

        // Attach popover menu observer
        attachPopoverMenuObserver();

        // Attach global drop listener
        attachGlobalDropListener();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
