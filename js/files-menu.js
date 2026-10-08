/**
 * ND Downloader - Nextcloud Files Integration
 * Adds shortcut to "+ New" menu, drag & drop link support, and inline downloader dialog with settings.
 */

(function () {
    'use strict';

    const ND_SVG_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2v9M8 7l4 4 4-4"/>
        <text x="12" y="20.5" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="8" font-weight="900" text-anchor="middle" fill="currentColor" stroke="none">ND</text>
        <path d="M4 14v5a2 2 0 0 0 2 2h1m10 0h1a2 2 0 0 0 2-2v-5"/>
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
            console.debug('[ND Downloader] Error reading current directory:', e);
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
     * Resolves app route URLs dynamically using OC.generateUrl.
     */
    function getApiUrl(endpoint) {
        if (window.OC && typeof window.OC.generateUrl === 'function') {
            return window.OC.generateUrl(`/apps/nddownloader${endpoint}`);
        }
        return `/apps/nddownloader${endpoint}`;
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
     * Escapes HTML entities to prevent XSS.
     */
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Shows a toast notification.
     */
    function showToast(message, type = 'info') {
        if (window.OC?.Notification?.show) {
            window.OC.Notification.show(message, { timeout: 4 });
            return;
        }

        const existingToast = document.querySelector('.nd-downloader-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `nd-downloader-toast nd-downloader-toast-${type}`;

        const iconSpan = document.createElement('span');
        iconSpan.textContent = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';

        const msgSpan = document.createElement('span');
        msgSpan.textContent = message;

        toast.appendChild(iconSpan);
        toast.appendChild(msgSpan);
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
            console.debug('[ND Downloader] Could not trigger file list reload:', e);
        }
    }

    /**
     * Opens the ND Download modal dialog.
     */
    function openNdModal(targetFolder = '') {
        const folder = targetFolder || getCurrentFolder();
        const displayFolder = folder ? '/' + folder : '/';

        // Close any existing modal
        closeNdModal();

        const overlay = document.createElement('div');
        overlay.id = 'nd-downloader-modal-overlay';
        overlay.className = 'nd-downloader-modal-overlay';

        overlay.innerHTML = `
            <div class="nd-downloader-modal" role="dialog" aria-modal="true" aria-labelledby="nd-downloader-modal-title">
                <div class="nd-downloader-modal-header">
                    <h2 class="nd-downloader-modal-title" id="nd-downloader-modal-title">
                        ${ND_SVG_ICON}
                        <span>ND Downloader</span>
                    </h2>
                    <button type="button" class="nd-downloader-modal-close" id="nd-downloader-modal-close" aria-label="Close">✕</button>
                </div>

                <div class="nd-downloader-tabs">
                    <button type="button" class="nd-downloader-tab-btn active" data-tab="download">Download</button>
                    <button type="button" class="nd-downloader-tab-btn" data-tab="settings">ND Settings ⚙️</button>
                </div>

                <div class="nd-downloader-modal-body">
                    <!-- DOWNLOAD TAB -->
                    <div class="nd-downloader-tab-content active" id="nd-downloader-tab-download">
                        <div class="nd-downloader-folder-badge">
                            <span>📁 Saving directly to:</span>
                            <strong id="nd-downloader-current-dir"></strong>
                        </div>

                        <div class="nd-downloader-dropzone" id="nd-downloader-dropzone">
                            <div class="nd-downloader-dropzone-icon">⚡</div>
                            <div class="nd-downloader-dropzone-text">Drop a link or .torrent file here, or paste below</div>
                        </div>

                        <div class="nd-downloader-form-group">
                            <label for="nd-downloader-url-input">Download Link (URL / Magnet / Torrent):</label>
                            <textarea id="nd-downloader-url-input" placeholder="https://example.com/file.zip&#10;magnet:?xt=urn:btih:...&#10;https://.../source.torrent" autofocus></textarea>
                            <div class="nd-downloader-hint">Supports HTTP/HTTPS, FTP, Magnet links, and direct URLs.</div>
                        </div>

                        <div class="nd-downloader-form-group">
                            <label for="nd-downloader-filename-input">Custom File Name (optional):</label>
                            <input type="text" id="nd-downloader-filename-input" placeholder="e.g. video.mp4 (leave empty for auto-detect)">
                        </div>

                        <!-- LIVE PROGRESS CONTAINER -->
                        <div id="nd-downloader-progress-container" style="display: none;"></div>

                        <div class="nd-downloader-modal-actions">
                            <button type="button" class="nd-downloader-btn nd-downloader-btn-secondary" id="nd-downloader-cancel-btn">Cancel</button>
                            <button type="button" class="nd-downloader-btn nd-downloader-btn-primary" id="nd-downloader-start-btn">
                                <span>⚡ Start Download</span>
                            </button>
                        </div>
                    </div>

                    <!-- SETTINGS TAB -->
                    <div class="nd-downloader-tab-content" id="nd-downloader-tab-settings">
                        <div class="nd-downloader-form-group">
                            <label for="nd-downloader-setting-endpoint">Download Server URL (/mdxp):</label>
                            <input type="text" id="nd-downloader-setting-endpoint" placeholder="http://nd-server:16801">
                            <div class="nd-downloader-hint">ND / Motrix RPC endpoint exposing /mdxp (default: http://nd-server:16801).</div>
                        </div>

                        <div class="nd-downloader-form-group">
                            <label for="nd-downloader-setting-token">Secret RPC Token (Optional):</label>
                            <input type="password" id="nd-downloader-setting-token" placeholder="Bearer RPC Token">
                        </div>

                        <div class="nd-downloader-form-group">
                            <label for="nd-downloader-setting-savedir">Default Download Directory:</label>
                            <input type="text" id="nd-downloader-setting-savedir" placeholder="/downloads">
                            <div class="nd-downloader-hint">Mounted path inside container mapping directly to Nextcloud storage.</div>
                        </div>

                        <div id="nd-downloader-settings-status" style="margin-bottom: 12px; font-size: 13px;"></div>

                        <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                            <a href="${getApiUrl('')}" target="_blank" class="nd-downloader-btn nd-downloader-btn-secondary" style="font-size: 12px; text-decoration: none;">
                                <span>Open ND Downloader ↗</span>
                            </a>
                        </div>

                        <div class="nd-downloader-modal-actions">
                            <button type="button" class="nd-downloader-btn nd-downloader-btn-secondary" id="nd-downloader-test-settings-btn">Test Connection</button>
                            <button type="button" class="nd-downloader-btn nd-downloader-btn-primary" id="nd-downloader-save-settings-btn">Save Settings</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const currentDirEl = document.getElementById('nd-downloader-current-dir');
        if (currentDirEl) {
            currentDirEl.textContent = displayFolder;
        }

        // Hook close events
        document.getElementById('nd-downloader-modal-close').addEventListener('click', closeNdModal);
        document.getElementById('nd-downloader-cancel-btn').addEventListener('click', closeNdModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeNdModal();
        });

        // Tab switching
        const tabBtns = overlay.querySelectorAll('.nd-downloader-tab-btn');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                overlay.querySelectorAll('.nd-downloader-tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                const targetTab = btn.getAttribute('data-tab');
                document.getElementById(`nd-downloader-tab-${targetTab}`).classList.add('active');

                if (targetTab === 'settings') {
                    loadSettings();
                }
            });
        });

        // Drag & Drop onto modal dropzone
        const dropzone = document.getElementById('nd-downloader-dropzone');
        const urlInput = document.getElementById('nd-downloader-url-input');

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
        const startBtn = document.getElementById('nd-downloader-start-btn');
        startBtn.addEventListener('click', () => handleStartDownload(folder));

        // Enter key to download
        urlInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                handleStartDownload(folder);
            }
        });

        // Settings Buttons
        document.getElementById('nd-downloader-test-settings-btn').addEventListener('click', testSettings);
        document.getElementById('nd-downloader-save-settings-btn').addEventListener('click', saveSettings);

        // Check and render active downloads immediately upon opening the modal
        checkAndDisplayActiveDownloads(folder);
    }

    /**
     * Set of completed task IDs in this session to prevent spamming rescan & notifications.
     */
    const sessionCompletedTasks = new Set();

    /**
     * Closes the ND modal and cleanly stops background polling.
     */
    function closeNdModal() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        activeTaskId = null;
        const overlay = document.getElementById('nd-downloader-modal-overlay');
        if (overlay) overlay.remove();
    }

    /**
     * Robust parser for task metrics.
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
        const status = String(t.status || 'downloading').toLowerCase();
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
        const container = document.getElementById('nd-downloader-progress-container');
        if (!container || container._hasHandlers) return;
        container._hasHandlers = true;

        container.addEventListener('click', async (e) => {
            const btn = e.target.closest('.nd-downloader-mini-btn');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const taskId = btn.getAttribute('data-task-id');
            if (!action || !taskId) return;

            btn.disabled = true;

            try {
                if (action === 'pause') {
                    btn.textContent = 'Pausing...';
                    await fetch(getApiUrl(`/api/tasks/${taskId}/pause`), {
                        method: 'POST',
                        headers: { 'requesttoken': getRequestToken() },
                    });
                } else if (action === 'resume') {
                    btn.textContent = 'Resuming...';
                    await fetch(getApiUrl(`/api/tasks/${taskId}/resume`), {
                        method: 'POST',
                        headers: { 'requesttoken': getRequestToken() },
                    });
                } else if (action === 'cancel') {
                    if (!confirm('Cancel and remove this download task?')) {
                        btn.disabled = false;
                        return;
                    }
                    btn.textContent = 'Removing...';
                    const delRes = await fetch(getApiUrl(`/api/tasks/${taskId}/delete`), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': getRequestToken(),
                        },
                        body: JSON.stringify({ deleteFiles: false }),
                    });
                    const data = await delRes.json().catch(() => ({}));
                    if (delRes.ok && data.success) {
                        const card = document.getElementById(`nd-downloader-task-${taskId}`);
                        if (card) card.remove();
                        showToast('Download task removed', 'info');
                    } else {
                        const errMsg = data.error || `Failed to remove task (HTTP ${delRes.status})`;
                        showToast(errMsg, 'error');
                        btn.textContent = '✕ Remove';
                    }
                }
            } catch (err) {
                console.error('[ND Downloader] Action failed:', err);
                showToast(`Action failed: ${err.message}`, 'error');
            } finally {
                btn.disabled = false;
            }
        });
    }

    /**
     * Safely renders task statistics using DOM nodes and textContent.
     */
    function renderTaskMetaStats(metaEl, m) {
        if (!metaEl) return;
        metaEl.innerHTML = '';

        const statsSpan = document.createElement('span');
        statsSpan.textContent = `${m.percentDisplay} (${m.completedDisplay} / ${m.totalDisplay})`;
        metaEl.appendChild(statsSpan);

        if (m.status === 'downloading' && m.speed > 0) {
            const speedSpan = document.createElement('span');
            speedSpan.textContent = ` • ${m.speedDisplay}`;
            metaEl.appendChild(speedSpan);
        }

        if (m.eta) {
            const etaSpan = document.createElement('span');
            etaSpan.textContent = ` • ${m.eta}`;
            metaEl.appendChild(etaSpan);
        }

        if (m.error) {
            const errSpan = document.createElement('span');
            errSpan.style.color = '#f87171';
            errSpan.textContent = ` • ${m.error}`;
            metaEl.appendChild(errSpan);
        }
    }

    /**
     * Renders or smoothly updates a live task card in the DOM.
     */
    function updateOrRenderTaskCard(m) {
        const container = document.getElementById('nd-downloader-progress-container');
        if (!container || !m || !m.id) return;

        container.style.display = 'block';

        const isFinished = m.status === 'completed' || m.status === 'complete' || m.percent >= 100;
        const badgeClass = isFinished
            ? 'nd-downloader-status-complete'
            : (m.status === 'error' || m.status === 'failed')
                ? 'nd-downloader-status-error'
                : (m.status === 'paused')
                    ? 'nd-downloader-status-paused'
                    : 'nd-downloader-status-active';

        const statusLabel = isFinished ? 'COMPLETE' : m.status.toUpperCase();
        const safeId = escapeHtml(m.id);

        let actionsHtml = '';
        if (m.status === 'downloading') {
            actionsHtml += `<button type="button" class="nd-downloader-mini-btn" data-action="pause" data-task-id="${safeId}" title="Pause download">⏸ Pause</button>`;
        } else if (m.status === 'paused') {
            actionsHtml += `<button type="button" class="nd-downloader-mini-btn" data-action="resume" data-task-id="${safeId}" title="Resume download">▶ Resume</button>`;
        }
        actionsHtml += `<button type="button" class="nd-downloader-mini-btn nd-downloader-mini-btn-danger" data-action="cancel" data-task-id="${safeId}" title="Remove download">✕ Remove</button>`;

        let card = document.getElementById(`nd-downloader-task-${m.id}`);
        if (!card) {
            card = document.createElement('div');
            card.className = 'nd-downloader-task-card';
            card.id = `nd-downloader-task-${m.id}`;
            card.setAttribute('data-task-id', m.id);
            card.innerHTML = `
                <div class="nd-downloader-task-card-header">
                    <span class="nd-downloader-task-name"></span>
                    <span class="nd-downloader-task-status-badge ${badgeClass}">${escapeHtml(statusLabel)}</span>
                </div>
                <div class="nd-downloader-progress-bar-bg">
                    <div class="nd-downloader-progress-bar-fill ${m.status === 'downloading' ? 'active' : ''}" style="width: ${m.percent}%;"></div>
                </div>
                <div class="nd-downloader-task-meta">
                    <div class="nd-downloader-task-meta-stats"></div>
                    <div class="nd-downloader-task-actions">${actionsHtml}</div>
                </div>
            `;
            const nameEl = card.querySelector('.nd-downloader-task-name');
            if (nameEl) {
                nameEl.textContent = '⚡ ' + (m.name || 'Download Task');
                nameEl.title = m.name || '';
            }
            renderTaskMetaStats(card.querySelector('.nd-downloader-task-meta-stats'), m);
            container.prepend(card);
        } else {
            const nameEl = card.querySelector('.nd-downloader-task-name');
            if (nameEl && m.name) {
                nameEl.textContent = '⚡ ' + m.name;
                nameEl.title = m.name;
            }

            const badgeEl = card.querySelector('.nd-downloader-task-status-badge');
            if (badgeEl) {
                badgeEl.className = `nd-downloader-task-status-badge ${badgeClass}`;
                badgeEl.textContent = statusLabel;
            }

            const fillEl = card.querySelector('.nd-downloader-progress-bar-fill');
            if (fillEl) {
                fillEl.style.width = `${m.percent}%`;
                if (m.status === 'downloading') {
                    fillEl.classList.add('active');
                } else {
                    fillEl.classList.remove('active');
                }
            }

            renderTaskMetaStats(card.querySelector('.nd-downloader-task-meta-stats'), m);

            const actionsEl = card.querySelector('.nd-downloader-task-actions');
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
            const resp = await fetch(getApiUrl('/api/tasks'), {
                headers: { 'requesttoken': getRequestToken() }
            });
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data.success || !Array.isArray(data.tasks)) return;

            // Find active, downloading, queued, or paused tasks
            const activeTasks = data.tasks.filter(t => {
                const st = (t.status || '').toLowerCase();
                return st === 'downloading' || st === 'queued' || st === 'paused';
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
            console.debug('[ND Downloader] Error checking active downloads:', e);
        }
    }

    /**
     * Polls active task status every 1000ms and updates UI in real-time.
     */
    function startTaskPolling(taskId, targetFolder) {
        if (pollInterval) clearInterval(pollInterval);

        const pollTick = async () => {
            const container = document.getElementById('nd-downloader-progress-container');
            if (!container) {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                return;
            }

            try {
                const resp = await fetch(getApiUrl('/api/tasks'), {
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
                    const isActive = m.status === 'downloading' || m.status === 'queued' || m.status === 'paused';
                    const cardExists = !!document.getElementById(`nd-downloader-task-${m.id}`);

                    if (isTargetTask || isActive || cardExists) {
                        updateOrRenderTaskCard(m);

                        // If task just completed
                        if ((m.status === 'completed' || m.percent >= 100) && !sessionCompletedTasks.has(m.id)) {
                            sessionCompletedTasks.add(m.id);

                            showToast(`✓ "${m.name}" download complete! Saved to Nextcloud.`, 'success');

                            // Refresh folder in Files view
                            refreshNextcloudFileList();

                            // Trigger backend rescan
                            fetch(getApiUrl('/api/scan'), {
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
                console.debug('[ND Downloader] Error polling tasks:', err);
            }
        };

        pollTick();
        pollInterval = setInterval(pollTick, 1000);
    }

    /**
     * Handles starting a download task directly into Nextcloud storage.
     */
    async function handleStartDownload(targetFolder) {
        const urlInput = document.getElementById('nd-downloader-url-input');
        const filenameInput = document.getElementById('nd-downloader-filename-input');
        const startBtn = document.getElementById('nd-downloader-start-btn');

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
            const resp = await fetch(getApiUrl('/api/tasks'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': getRequestToken(),
                },
                body: JSON.stringify(bodyPayload),
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.error || 'Failed to add task to ND Downloader');
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
     * Loads ND Downloader settings into settings tab.
     */
    async function loadSettings() {
        try {
            const resp = await fetch(getApiUrl('/api/settings'), {
                headers: { 'requesttoken': getRequestToken() }
            });
            const data = await resp.json();
            if (data.success) {
                const endpointInput = document.getElementById('nd-downloader-setting-endpoint');
                const savedirInput = document.getElementById('nd-downloader-setting-savedir');
                const tokenInput = document.getElementById('nd-downloader-setting-token');
                const saveBtn = document.getElementById('nd-downloader-save-settings-btn');
                const testBtn = document.getElementById('nd-downloader-test-settings-btn');
                const statusDiv = document.getElementById('nd-downloader-settings-status');

                if (data.isAdmin) {
                    if (endpointInput) {
                        endpointInput.value = data.endpoint || '';
                        endpointInput.disabled = false;
                    }
                    if (savedirInput) {
                        savedirInput.value = data.saveDir || '/downloads';
                        savedirInput.disabled = false;
                    }
                    if (tokenInput) tokenInput.disabled = false;
                    if (saveBtn) saveBtn.style.display = '';
                    if (testBtn) testBtn.style.display = '';
                } else {
                    if (endpointInput) {
                        endpointInput.value = '(Administrator only)';
                        endpointInput.disabled = true;
                    }
                    if (savedirInput) {
                        savedirInput.value = data.saveDir || '/downloads';
                        savedirInput.disabled = true;
                    }
                    if (tokenInput) tokenInput.disabled = true;
                    if (saveBtn) saveBtn.style.display = 'none';
                    if (testBtn) testBtn.style.display = 'none';
                    if (statusDiv) {
                        statusDiv.style.color = '#888';
                        statusDiv.textContent = 'Settings can only be configured by administrators.';
                    }
                }
            }
        } catch (e) {
            console.warn('[ND Downloader] Error loading settings:', e);
        }
    }

    /**
     * Tests connection to Aria2 RPC server.
     */
    async function testSettings() {
        const statusDiv = document.getElementById('nd-downloader-settings-status');
        const testBtn = document.getElementById('nd-downloader-test-settings-btn');

        testBtn.disabled = true;
        statusDiv.innerHTML = '';
        const testingSpan = document.createElement('span');
        testingSpan.style.color = '#38bdf8';
        testingSpan.textContent = 'Testing connection to download server...';
        statusDiv.appendChild(testingSpan);

        try {
            const resp = await fetch(getApiUrl('/api/settings/test'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': getRequestToken(),
                }
            });
            const data = await resp.json();

            statusDiv.innerHTML = '';
            if (data.success) {
                const engine = data.engine?.state || 'ready';
                const speed = formatSpeed(data.stats?.totalDownloadSpeed || 0);
                const successSpan = document.createElement('span');
                successSpan.style.color = '#4ade80';
                successSpan.textContent = `✓ Connected! Engine state: ${engine} | Current speed: ${speed}`;
                statusDiv.appendChild(successSpan);
                showToast('Connection successful!', 'success');
            } else {
                const errSpan = document.createElement('span');
                errSpan.style.color = '#f87171';
                errSpan.textContent = `✕ Connection failed: ${data.error || 'Cannot reach server'}`;
                statusDiv.appendChild(errSpan);
                showToast('Failed to connect to server', 'error');
            }
        } catch (err) {
            statusDiv.innerHTML = '';
            const errSpan = document.createElement('span');
            errSpan.style.color = '#f87171';
            errSpan.textContent = `✕ Error: ${err.message || 'Unknown network error'}`;
            statusDiv.appendChild(errSpan);
        } finally {
            testBtn.disabled = false;
        }
    }

    /**
     * Saves settings back to Nextcloud.
     */
    async function saveSettings() {
        const endpoint = document.getElementById('nd-downloader-setting-endpoint').value.trim();
        const token = document.getElementById('nd-downloader-setting-token').value.trim();
        const saveDir = document.getElementById('nd-downloader-setting-savedir').value.trim();
        const saveBtn = document.getElementById('nd-downloader-save-settings-btn');
        const statusDiv = document.getElementById('nd-downloader-settings-status');

        saveBtn.disabled = true;

        try {
            const payload = { endpoint, saveDir };
            if (token) payload.token = token;

            const resp = await fetch(getApiUrl('/api/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': getRequestToken(),
                },
                body: JSON.stringify(payload),
            });

            const data = await resp.json();
            statusDiv.innerHTML = '';
            if (data.success) {
                const successSpan = document.createElement('span');
                successSpan.style.color = '#4ade80';
                successSpan.textContent = '✓ Settings saved successfully!';
                statusDiv.appendChild(successSpan);
                showToast('Settings saved!', 'success');
            } else {
                const errSpan = document.createElement('span');
                errSpan.style.color = '#f87171';
                errSpan.textContent = `✕ Error: ${data.error || 'Failed to save settings'}`;
                statusDiv.appendChild(errSpan);
            }
        } catch (err) {
            statusDiv.innerHTML = '';
            const errSpan = document.createElement('span');
            errSpan.style.color = '#f87171';
            errSpan.textContent = `✕ Error: ${err.message || 'Unknown network error'}`;
            statusDiv.appendChild(errSpan);
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
                if (menu.getEntryIndex('nd-download') === -1) {
                    menu.registerEntry({
                        id: 'nd-download',
                        displayName: 'Download with ND',
                        iconSvgInline: ND_SVG_ICON,
                        order: 35,
                        category: 1, // CreateNew
                        handler: function (destination) {
                            const dir = (destination && destination.path) ? destination.path.replace(/^\/+/, '') : getCurrentFolder();
                            openNdModal(dir);
                        }
                    });
                    console.log('[ND Downloader] Successfully registered into newFileMenu');
                    return true;
                }
            }
        } catch (e) {
            console.debug('[ND Downloader] Error registering in newFileMenu:', e);
        }
        return false;
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
                openNdModal(getCurrentFolder());
                setTimeout(() => {
                    const urlInput = document.getElementById('nd-downloader-url-input');
                    if (urlInput) {
                        urlInput.value = text.trim();
                        urlInput.focus();
                    }
                }, 100);
            }
        });
    }

    function init() {
        registerInNewFileMenu();
        let attempts = 0;
        const regInterval = setInterval(() => {
            attempts++;
            if (registerInNewFileMenu() || attempts > 30) clearInterval(regInterval);
        }, 300);
        attachGlobalDropListener();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
