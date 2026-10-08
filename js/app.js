/**
 * ND Downloader Nextcloud App Frontend Controller
 */
(function () {
    'use strict';

    let currentFilter = 'all';
    let pollTimer = null;
    let allTasks = [];
    const sessionSyncedTasks = window._ndDownloaderSyncedTasks || (window._ndDownloaderSyncedTasks = new Map());

    const formatBytes = (bytes) => {
        if (!bytes || bytes <= 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    const formatSpeed = (bytesPerSec) => {
        return formatBytes(bytesPerSec) + '/s';
    };

    const formatEta = (seconds) => {
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
    };

    const getRequestToken = () => {
        return window.oc_requesttoken || (window.OC && window.OC.requestToken) || document.head?.dataset?.requesttoken || '';
    };

    const escapeHtml = (str) => {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const getApiUrl = (endpoint) => {
        const appPrefix = '/apps/nddownloader';
        return OC.generateUrl ? OC.generateUrl(`${appPrefix}${endpoint}`) : `${appPrefix}${endpoint}`;
    };

    const showNotification = (msg, type = 'info') => {
        if (window.OC?.Notification?.showTemporary) {
            window.OC.Notification.showTemporary(msg);
            return;
        }
        if (window.OC?.Notification?.show) {
            window.OC.Notification.show(msg, { timeout: 4 });
            return;
        }

        const existingToast = document.querySelector('.nd-downloader-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `nd-downloader-toast nd-downloader-toast-${type}`;
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 18px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
            color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 99999;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.3s ease;
        `;
        const iconSpan = document.createElement('span');
        iconSpan.textContent = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
        const msgSpan = document.createElement('span');
        msgSpan.textContent = msg;
        toast.appendChild(iconSpan);
        toast.appendChild(msgSpan);
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    };

    const safeParseJson = async (res) => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            if (!res.ok) {
                return { success: false, error: `HTTP ${res.status}: Server communication error` };
            }
            return { success: false, error: text || 'Invalid JSON response' };
        }
    };

    const fetchTasks = async () => {
        try {
            const res = await fetch(getApiUrl('/api/tasks'), {
                headers: { 'requesttoken': getRequestToken() }
            });
            const data = await safeParseJson(res);
            if (data.success) {
                allTasks = data.tasks || [];
                renderTasks();
                updateCounts();
                hideError();
                updateEngineStatus('ready', 'Ready');
            } else {
                showError(data.error || 'Failed to fetch tasks');
                updateEngineStatus('offline', 'Offline');
            }
        } catch (err) {
            showError('Could not reach ND Downloader bridge: ' + err.message);
            updateEngineStatus('offline', 'Offline');
        }
    };

    const fetchStats = async () => {
        try {
            const res = await fetch(getApiUrl('/api/stats'), {
                headers: { 'requesttoken': getRequestToken() }
            });
            const data = await safeParseJson(res);
            if (data.success && data.stats) {
                document.getElementById('speed-download').textContent = formatSpeed(data.stats.totalDownloadSpeed || 0);
                document.getElementById('speed-upload').textContent = formatSpeed(data.stats.totalUploadSpeed || 0);
                updateEngineStatus('ready', 'Ready');
            }
        } catch (_) {}
    };


    const renderTasks = () => {
        const container = document.getElementById('nddownloader-task-list');
        const emptyState = document.getElementById('nddownloader-empty');

        let filtered = allTasks;
        if (currentFilter !== 'all') {
            if (currentFilter === 'downloading') {
                filtered = allTasks.filter(t => t.status === 'downloading' || t.status === 'queued');
            } else {
                filtered = allTasks.filter(t => t.status === currentFilter);
            }
        }

        container.innerHTML = '';

        if (filtered.length === 0) {
            emptyState.classList.remove('hidden');
            return;
        }

        emptyState.classList.add('hidden');

        filtered.forEach(t => {
            const progress = (t.progress != null ? (t.progress * 100).toFixed(1) : 0);
            const done = formatBytes(t.bytesDone || 0);
            const total = t.bytesTotal ? formatBytes(t.bytesTotal) : 'Unknown size';
            const speed = t.speedBps ? formatSpeed(t.speedBps) : '0 B/s';
            const eta = formatEta(t.etaSec);

            const card = document.createElement('div');
            card.className = 'nd-downloader-task-card';
            card.id = `task-${t.id}`;

            const header = document.createElement('div');
            header.className = 'task-card-header';

            const title = document.createElement('span');
            title.className = 'task-title';
            const titleText = t.name || ('Task ' + t.id);
            title.textContent = titleText;
            title.title = titleText;

            const badge = document.createElement('span');
            badge.className = `task-status-badge badge-${t.status || 'unknown'}`;
            badge.textContent = t.status || '';

            header.appendChild(title);
            header.appendChild(badge);
            card.appendChild(header);

            const progressBar = document.createElement('div');
            progressBar.className = 'task-progress-bar';
            const progressFill = document.createElement('div');
            progressFill.className = 'task-progress-fill';
            progressFill.style.width = `${progress}%`;
            progressBar.appendChild(progressFill);
            card.appendChild(progressBar);

            const meta = document.createElement('div');
            meta.className = 'task-card-meta';

            const metaInfo = document.createElement('div');
            let metaText = `${progress}% (${done} / ${total})`;
            if (t.status === 'downloading') {
                metaText += ` • ${speed}`;
            }
            if (eta) {
                metaText += ` • ${eta}`;
            }
            metaInfo.textContent = metaText;
            meta.appendChild(metaInfo);

            const actions = document.createElement('div');
            actions.className = 'task-actions';

            if (t.status === 'downloading' || t.status === 'queued') {
                const pauseBtn = document.createElement('button');
                pauseBtn.className = 'task-btn';
                pauseBtn.textContent = 'Pause';
                pauseBtn.addEventListener('click', () => window.ndDownloaderApp.pauseTask(t.id));
                actions.appendChild(pauseBtn);
            } else if (t.status === 'paused') {
                const resumeBtn = document.createElement('button');
                resumeBtn.className = 'task-btn';
                resumeBtn.textContent = 'Resume';
                resumeBtn.addEventListener('click', () => window.ndDownloaderApp.resumeTask(t.id));
                actions.appendChild(resumeBtn);
            }

            if (t.status === 'completed') {
                if (sessionSyncedTasks.has(t.id)) {
                    const info = sessionSyncedTasks.get(t.id);
                    const folder = info.folder || '';
                    const filesUrl = `/apps/files/?dir=/${encodeURIComponent(folder)}`;
                    const openLink = document.createElement('a');
                    openLink.href = filesUrl;
                    openLink.target = '_blank';
                    openLink.className = 'task-btn primary';
                    openLink.style.cssText = 'text-decoration:none; display:inline-flex; align-items:center; gap:4px;';
                    openLink.title = 'Open in Nextcloud Files';
                    openLink.textContent = '📂 Open in Files ↗';
                    actions.appendChild(openLink);
                } else {
                    const syncBtn = document.createElement('button');
                    syncBtn.className = `task-btn primary btn-sync-${t.id}`;
                    syncBtn.textContent = '📂 Save to Files';
                    syncBtn.addEventListener('click', () => window.ndDownloaderApp.syncTask(t.id));
                    actions.appendChild(syncBtn);
                }
            }

            const removeBtn = document.createElement('button');
            removeBtn.className = 'task-btn danger';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', () => window.ndDownloaderApp.deleteTask(t.id));
            actions.appendChild(removeBtn);

            meta.appendChild(actions);
            card.appendChild(meta);

            container.appendChild(card);
        });
    };

    const updateCounts = () => {
        document.getElementById('count-all').textContent = allTasks.length;
        document.getElementById('count-downloading').textContent = allTasks.filter(t => t.status === 'downloading' || t.status === 'queued').length;
        document.getElementById('count-paused').textContent = allTasks.filter(t => t.status === 'paused').length;
        document.getElementById('count-completed').textContent = allTasks.filter(t => t.status === 'completed').length;
    };

    const showError = (msg) => {
        const errBox = document.getElementById('nddownloader-error');
        const errText = document.getElementById('nddownloader-error-text');
        errText.textContent = msg;
        errBox.classList.remove('hidden');
    };

    const hideError = () => {
        document.getElementById('nddownloader-error').classList.add('hidden');
    };

    const updateEngineStatus = (status, label) => {
        const dot = document.getElementById('engine-dot');
        const text = document.getElementById('engine-status-label');
        if (!dot || !text) return;

        dot.className = 'engine-dot';
        if (status === 'ready') {
            dot.classList.add('engine-dot-ready');
            text.textContent = label ? `Engine: ${label}` : 'Engine: Ready';
        } else if (status === 'starting') {
            dot.classList.add('engine-dot-starting');
            text.textContent = 'Engine: Starting...';
        } else if (status === 'offline') {
            dot.classList.add('engine-dot-offline');
            text.textContent = label ? `Engine: ${label}` : 'Engine: Offline';
        } else {
            dot.classList.add('engine-dot-unknown');
            text.textContent = label || 'Engine: Unknown';
        }
    };

    const startNdEngine = async () => {
        const btns = [
            document.getElementById('btn-start-engine'),
            document.getElementById('btn-banner-start-engine'),
            document.getElementById('btn-start-engine-settings')
        ].filter(Boolean);

        btns.forEach(b => {
            b.disabled = true;
            b.dataset.origText = b.textContent;
            b.textContent = 'Starting Engine...';
        });

        updateEngineStatus('starting');

        try {
            const res = await fetch(getApiUrl('/api/engine/start'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': getRequestToken()
                }
            });
            const data = await safeParseJson(res);
            if (data.success) {
                showNotification(data.message || 'ND engine is active and ready!', 'success');
                hideError();
                updateEngineStatus('ready', 'Ready');
                fetchTasks();
                fetchStats();
            } else {
                showNotification(data.error || 'Failed to start ND engine', 'error');
                showError('ND engine could not be started: ' + (data.error || 'Unreachable'));
                updateEngineStatus('offline', 'Offline');
            }
        } catch (err) {
            showNotification('Request failed: ' + err.message, 'error');
            showError('Could not communicate with server: ' + err.message);
            updateEngineStatus('offline', 'Offline');
        } finally {
            btns.forEach(b => {
                b.disabled = false;
                if (b.dataset.origText) b.textContent = b.dataset.origText;
            });
        }
    };

    // Public actions exposed on window.ndDownloaderApp
    window.ndDownloaderApp = {
        startEngine: startNdEngine,
        pauseTask: async (taskId) => {
            await fetch(getApiUrl(`/api/tasks/${taskId}/pause`), {
                method: 'POST',
                headers: { 'requesttoken': getRequestToken() }
            });
            fetchTasks();
        },
        resumeTask: async (taskId) => {
            await fetch(getApiUrl(`/api/tasks/${taskId}/resume`), {
                method: 'POST',
                headers: { 'requesttoken': getRequestToken() }
            });
            fetchTasks();
        },
        deleteTask: async (taskId) => {
            if (!confirm('Remove this download task?')) return;
            const card = document.getElementById(`task-${taskId}`);
            if (card) {
                card.style.opacity = '0.4';
                card.style.pointerEvents = 'none';
            }

            try {
                const res = await fetch(getApiUrl(`/api/tasks/${encodeURIComponent(taskId)}/delete`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken(),
                    },
                    body: JSON.stringify({ deleteFiles: false }),
                });

                const data = await safeParseJson(res);
                if (data.success) {
                    allTasks = allTasks.filter(t => t.id !== taskId);
                    renderTasks();
                    updateCounts();
                } else {
                    showNotification(data.error || 'Could not remove task', 'error');
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                }
            } catch (err) {
                console.error('[ND Downloader] Error deleting task:', err);
                showNotification('Error removing task: ' + err.message, 'error');
                if (card) {
                    card.style.opacity = '1';
                    card.style.pointerEvents = 'auto';
                }
            }
        },
        syncTask: async (taskId) => {
            const task = allTasks.find(t => t.id === taskId);
            const card = document.getElementById(`task-${taskId}`);
            const syncBtn = card ? card.querySelector(`.btn-sync-${taskId}, .task-btn.primary`) : null;
            const originalText = syncBtn ? syncBtn.textContent : '📂 Save to Files';

            if (syncBtn) {
                syncBtn.disabled = true;
                syncBtn.textContent = '⏳ Saving to Files...';
            }

            // Automatically deduce target folder from task saveDir if available
            let folder = '';
            if (task && task.saveDir) {
                const match = task.saveDir.match(/\/files(?:\/(.*))?$/);
                if (match && match[1]) {
                    folder = match[1];
                }
            }

            try {
                const res = await fetch(getApiUrl(`/api/tasks/${taskId}/sync`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken()
                    },
                    body: JSON.stringify({ targetFolder: folder })
                });

                const data = await safeParseJson(res);
                if (data.success && data.result && data.result.synced) {
                    const destFolder = data.result.folder !== undefined ? data.result.folder : (folder || '');
                    const destPath = data.result.destination || (task?.name || 'File');

                    sessionSyncedTasks.set(taskId, { folder: destFolder, destination: destPath });

                    if (syncBtn) {
                        const filesUrl = `/apps/files/?dir=/${encodeURIComponent(destFolder)}`;
                        const openLink = document.createElement('a');
                        openLink.href = filesUrl;
                        openLink.target = '_blank';
                        openLink.className = 'task-btn primary';
                        openLink.style.cssText = 'text-decoration:none; display:inline-flex; align-items:center; gap:4px;';
                        openLink.title = 'Open in Nextcloud Files';
                        openLink.textContent = '📂 Open in Files ↗';
                        syncBtn.replaceWith(openLink);
                    }

                    showNotification(`✓ File saved to Nextcloud: ${destPath}`, 'success');
                } else {
                    const errMsg = data.result?.message || data.error || 'Failed to save download to Nextcloud Files';
                    showNotification(errMsg, 'error');
                    if (syncBtn) {
                        syncBtn.disabled = false;
                        syncBtn.textContent = originalText;
                    }
                }
            } catch (e) {
                console.error('[ND Downloader] Sync failed:', e);
                showNotification('Sync failed: ' + e.message, 'error');
                if (syncBtn) {
                    syncBtn.disabled = false;
                    syncBtn.textContent = originalText;
                }
            }
        }
    };

    // Event Listeners initialization
    document.addEventListener('DOMContentLoaded', () => {
        // Navigation Filters
        document.querySelectorAll('.nd-downloader-filter-list li').forEach(li => {
            li.addEventListener('click', (e) => {
                e.preventDefault();
                document.querySelectorAll('.nd-downloader-filter-list li').forEach(el => el.classList.remove('active'));
                li.classList.add('active');
                currentFilter = li.getAttribute('data-filter') || 'all';
                renderTasks();
            });
        });

        // Toolbar buttons
        document.getElementById('btn-refresh').addEventListener('click', () => {
            fetchTasks();
            fetchStats();
        });

        const startEngineBtn = document.getElementById('btn-start-engine');
        if (startEngineBtn) {
            startEngineBtn.addEventListener('click', startNdEngine);
        }

        const bannerStartBtn = document.getElementById('btn-banner-start-engine');
        if (bannerStartBtn) {
            bannerStartBtn.addEventListener('click', startNdEngine);
        }

        const settingsStartBtn = document.getElementById('btn-start-engine-settings');
        if (settingsStartBtn) {
            settingsStartBtn.addEventListener('click', startNdEngine);
        }


        // Add Task Modal
        const addModal = document.getElementById('modal-add-task');
        document.getElementById('btn-new-task').addEventListener('click', () => {
            addModal.classList.remove('hidden');
        });
        document.getElementById('modal-add-close').addEventListener('click', () => {
            addModal.classList.add('hidden');
        });
        document.getElementById('btn-cancel-add').addEventListener('click', () => {
            addModal.classList.add('hidden');
        });

        // Kind switcher
        const kindSelect = document.getElementById('input-task-kind');
        kindSelect.addEventListener('change', () => {
            const isUrl = kindSelect.value === 'url';
            document.getElementById('group-url').classList.toggle('hidden', !isUrl);
            document.getElementById('group-filename').classList.toggle('hidden', !isUrl);
            document.getElementById('group-magnet').classList.toggle('hidden', isUrl);
        });

        // Submit new download
        document.getElementById('btn-submit-add').addEventListener('click', async () => {
            const kind = kindSelect.value;
            const targetFolder = document.getElementById('input-task-folder').value.trim();
            const filename = document.getElementById('input-task-filename').value.trim();

            const payload = { kind, targetFolder };
            if (filename) payload.filename = filename;

            if (kind === 'url') {
                const url = document.getElementById('input-task-url').value.trim();
                if (!url) {
                    showNotification('Please enter a download URL', 'error');
                    return;
                }
                payload.url = url;
            } else if (kind === 'magnet') {
                const magnet = document.getElementById('input-task-magnet').value.trim();
                if (!magnet) {
                    showNotification('Please enter a Magnet link', 'error');
                    return;
                }
                payload.magnet = magnet;
            }

            try {
                const res = await fetch(getApiUrl('/api/tasks'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken()
                    },
                    body: JSON.stringify(payload)
                });

                const data = await safeParseJson(res);
                if (data.success) {
                    addModal.classList.add('hidden');
                    document.getElementById('input-task-url').value = '';
                    document.getElementById('input-task-magnet').value = '';
                    document.getElementById('input-task-filename').value = '';
                    fetchTasks();
                    showNotification('Download started successfully!', 'success');
                } else {
                    showNotification('Failed to add download: ' + (data.error || 'Server error'), 'error');
                }
            } catch (err) {
                showNotification('Request failed: ' + err.message, 'error');
            }
        });

        // Settings Modal
        const settingsModal = document.getElementById('modal-settings');
        document.getElementById('nddownloader-open-settings').addEventListener('click', async () => {
            settingsModal.classList.remove('hidden');
            try {
                const res = await fetch(getApiUrl('/api/settings'), {
                    headers: { 'requesttoken': getRequestToken() }
                });
                const data = await safeParseJson(res);
                if (data.success) {
                    const endpointInput = document.getElementById('input-setting-endpoint');
                    const savedirInput = document.getElementById('input-setting-savedir');
                    const tokenInput = document.getElementById('input-setting-token');
                    const saveBtn = document.getElementById('btn-save-settings');
                    const testBtn = document.getElementById('btn-test-connection');
                    const resDiv = document.getElementById('test-connection-result');

                    if (data.isAdmin) {
                        endpointInput.value = data.endpoint || '';
                        endpointInput.disabled = false;
                        savedirInput.value = data.saveDir || '';
                        savedirInput.disabled = false;
                        tokenInput.disabled = false;
                        saveBtn.style.display = '';
                        testBtn.style.display = '';
                        resDiv.classList.add('hidden');
                    } else {
                        endpointInput.value = '(Administrator only)';
                        endpointInput.disabled = true;
                        savedirInput.value = data.saveDir || '';
                        savedirInput.disabled = true;
                        tokenInput.disabled = true;
                        saveBtn.style.display = 'none';
                        testBtn.style.display = 'none';
                        resDiv.classList.remove('hidden');
                        resDiv.style.color = '#888';
                        resDiv.textContent = 'Settings can only be configured by administrators.';
                    }
                }
            } catch (_) {}
        });

        document.getElementById('modal-settings-close').addEventListener('click', () => {
            settingsModal.classList.add('hidden');
        });
        document.getElementById('btn-cancel-settings').addEventListener('click', () => {
            settingsModal.classList.add('hidden');
        });

        // Test Connection
        document.getElementById('btn-test-connection').addEventListener('click', async () => {
            const resDiv = document.getElementById('test-connection-result');
            resDiv.classList.remove('hidden');
            resDiv.textContent = 'Testing connection...';
            try {
                const res = await fetch(getApiUrl('/api/settings/test'), {
                    method: 'POST',
                    headers: { 'requesttoken': getRequestToken() }
                });
                const data = await safeParseJson(res);
                if (data.success) {
                    resDiv.style.color = '#28a745';
                    resDiv.textContent = 'Connected successfully to ND Downloader engine!';
                } else {
                    resDiv.style.color = '#dc3545';
                    resDiv.textContent = 'Connection failed: ' + (data.error || 'Unreachable');
                }
            } catch (e) {
                resDiv.style.color = '#dc3545';
                resDiv.textContent = 'Test error: ' + e.message;
            }
        });

        // Save Settings
        document.getElementById('btn-save-settings').addEventListener('click', async () => {
            const endpoint = document.getElementById('input-setting-endpoint').value.trim();
            const token = document.getElementById('input-setting-token').value.trim();
            const saveDir = document.getElementById('input-setting-savedir').value.trim();

            try {
                const res = await fetch(getApiUrl('/api/settings'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken()
                    },
                    body: JSON.stringify({ endpoint, token, saveDir })
                });
                const data = await safeParseJson(res);
                if (data.success) {
                    settingsModal.classList.add('hidden');
                    fetchTasks();
                    fetchStats();
                    showNotification('Settings saved successfully', 'success');
                } else {
                    showNotification('Failed to save settings: ' + data.error, 'error');
                }
            } catch (e) {
                showNotification('Save failed: ' + e.message, 'error');
            }
        });

        // Initial fetch and start polling every 3 seconds
        fetchTasks();
        fetchStats();
        pollTimer = setInterval(() => {
            fetchTasks();
            fetchStats();
        }, 3000);
    });
})();
