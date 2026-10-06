/**
 * Motrix Nextcloud App Frontend Controller
 */
(function () {
    'use strict';

    let currentFilter = 'all';
    let pollTimer = null;
    let allTasks = [];
    const sessionSyncedTasks = window._motrixSyncedTasks || (window._motrixSyncedTasks = new Map());

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

    const getApiUrl = (endpoint) => {
        return OC.generateUrl ? OC.generateUrl(`/apps/motrix${endpoint}`) : `/apps/motrix${endpoint}`;
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

        const existingToast = document.querySelector('.motrix-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `motrix-toast motrix-toast-${type}`;
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
        toast.innerHTML = `<span>${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}</span> <span>${msg}</span>`;
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
            } else {
                showError(data.error || 'Failed to fetch tasks');
            }
        } catch (err) {
            showError('Could not reach Motrix bridge: ' + err.message);
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
            }
        } catch (_) {}
    };

    const renderTasks = () => {
        const container = document.getElementById('motrix-task-list');
        const emptyState = document.getElementById('motrix-empty');

        let filtered = allTasks;
        if (currentFilter !== 'all') {
            filtered = allTasks.filter(t => t.status === currentFilter);
        }

        if (filtered.length === 0) {
            container.innerHTML = '';
            emptyState.classList.remove('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        container.innerHTML = filtered.map(t => {
            const progress = (t.progress != null ? (t.progress * 100).toFixed(1) : 0);
            const done = formatBytes(t.bytesDone || 0);
            const total = t.bytesTotal ? formatBytes(t.bytesTotal) : 'Unknown size';
            const speed = t.speedBps ? formatSpeed(t.speedBps) : '0 B/s';
            const eta = formatEta(t.etaSec);

            let actionsHtml = '';
            if (t.status === 'downloading') {
                actionsHtml += `<button class="task-btn" onclick="window.motrixApp.pauseTask('${t.id}')">Pause</button>`;
            } else if (t.status === 'paused') {
                actionsHtml += `<button class="task-btn" onclick="window.motrixApp.resumeTask('${t.id}')">Resume</button>`;
            }

            if (t.status === 'completed') {
                if (sessionSyncedTasks.has(t.id)) {
                    const info = sessionSyncedTasks.get(t.id);
                    const folder = info.folder || '';
                    const filesUrl = `/apps/files/?dir=/${encodeURIComponent(folder)}`;
                    actionsHtml += `<a href="${filesUrl}" target="_blank" class="task-btn primary" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px;" title="Open in Nextcloud Files">📂 Open in Files ↗</a>`;
                } else {
                    actionsHtml += `<button class="task-btn primary btn-sync-${t.id}" onclick="window.motrixApp.syncTask('${t.id}')">📂 Save to Files</button>`;
                }
            }

            actionsHtml += `<button class="task-btn danger" onclick="window.motrixApp.deleteTask('${t.id}')">Remove</button>`;

            return `
                <div class="motrix-task-card" id="task-${t.id}">
                    <div class="task-card-header">
                        <span class="task-title" title="${t.name || t.id}">${t.name || 'Task ' + t.id}</span>
                        <span class="task-status-badge badge-${t.status}">${t.status}</span>
                    </div>

                    <div class="task-progress-bar">
                        <div class="task-progress-fill" style="width: ${progress}%"></div>
                    </div>

                    <div class="task-card-meta">
                        <div>
                            <span>${progress}% (${done} / ${total})</span>
                            ${t.status === 'downloading' ? ` • <span>${speed}</span>` : ''}
                            ${eta ? ` • <span>${eta}</span>` : ''}
                        </div>
                        <div class="task-actions">
                            ${actionsHtml}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    };

    const updateCounts = () => {
        document.getElementById('count-all').textContent = allTasks.length;
        document.getElementById('count-downloading').textContent = allTasks.filter(t => t.status === 'downloading').length;
        document.getElementById('count-paused').textContent = allTasks.filter(t => t.status === 'paused').length;
        document.getElementById('count-completed').textContent = allTasks.filter(t => t.status === 'completed').length;
    };

    const showError = (msg) => {
        const errBox = document.getElementById('motrix-error');
        const errText = document.getElementById('motrix-error-text');
        errText.textContent = msg;
        errBox.classList.remove('hidden');
    };

    const hideError = () => {
        document.getElementById('motrix-error').classList.add('hidden');
    };

    // Public actions exposed on window.motrixApp
    window.motrixApp = {
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
                const token = getRequestToken();
                let res = await fetch(getApiUrl(`/api/tasks/${taskId}`), {
                    method: 'DELETE',
                    headers: { 'requesttoken': token }
                });

                if (!res.ok) {
                    res = await fetch(getApiUrl(`/api/tasks/${taskId}/delete`), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': token,
                        }
                    });
                }

                const data = await safeParseJson(res);
                if (data.success) {
                    allTasks = allTasks.filter(t => t.id !== taskId);
                    renderTasks();
                    updateCounts();
                    showNotification('Task removed', 'info');
                } else {
                    showNotification('Could not remove task: ' + (data.error || 'Unknown error'), 'error');
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                }
            } catch (err) {
                console.error('[Motrix] Error deleting task:', err);
                try {
                    const token = getRequestToken();
                    await fetch(getApiUrl(`/api/tasks/${taskId}/delete`), {
                        method: 'POST',
                        headers: { 'requesttoken': token }
                    });
                    allTasks = allTasks.filter(t => t.id !== taskId);
                    renderTasks();
                    updateCounts();
                } catch (_) {
                    showNotification('Error removing task: ' + err.message, 'error');
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                }
            }
            fetchTasks();
        },
        syncTask: async (taskId) => {
            const task = allTasks.find(t => t.id === taskId);
            const card = document.getElementById(`task-${taskId}`);
            const syncBtn = card ? card.querySelector(`.btn-sync-${taskId}, .task-btn.primary`) : null;
            const originalText = syncBtn ? syncBtn.innerHTML : '📂 Save to Files';

            if (syncBtn) {
                syncBtn.disabled = true;
                syncBtn.innerHTML = '⏳ Saving to Files...';
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
                        syncBtn.outerHTML = `<a href="${filesUrl}" target="_blank" class="task-btn primary" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px;" title="Open in Nextcloud Files">📂 Open in Files ↗</a>`;
                    }

                    showNotification(`✓ File saved to Nextcloud: ${destPath}`, 'success');
                } else {
                    const errMsg = data.result?.message || data.error || 'Failed to save download to Nextcloud Files';
                    showNotification(errMsg, 'error');
                    if (syncBtn) {
                        syncBtn.disabled = false;
                        syncBtn.innerHTML = originalText;
                    }
                }
            } catch (e) {
                console.error('[Motrix] Sync failed:', e);
                showNotification('Sync failed: ' + e.message, 'error');
                if (syncBtn) {
                    syncBtn.disabled = false;
                    syncBtn.innerHTML = originalText;
                }
            }
        }
    };

    // Event Listeners initialization
    document.addEventListener('DOMContentLoaded', () => {
        // Navigation Filters
        document.querySelectorAll('.motrix-filter-list li').forEach(li => {
            li.addEventListener('click', (e) => {
                e.preventDefault();
                document.querySelectorAll('.motrix-filter-list li').forEach(el => el.classList.remove('active'));
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

        // Submit Add Task
        document.getElementById('btn-submit-add').addEventListener('click', async () => {
            const kind = kindSelect.value;
            const payload = { kind };

            if (kind === 'url') {
                payload.url = document.getElementById('input-task-url').value.trim();
                payload.filename = document.getElementById('input-task-filename').value.trim() || undefined;
            } else {
                payload.magnet = document.getElementById('input-task-magnet').value.trim();
            }

            const folderInput = document.getElementById('input-task-folder');
            if (folderInput && folderInput.value.trim()) {
                payload.targetFolder = folderInput.value.trim();
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
                    fetchTasks();
                    showNotification('Download started', 'success');
                } else {
                    showNotification('Error adding download: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                showNotification('Request failed: ' + err.message, 'error');
            }
        });

        // Settings Modal
        const settingsModal = document.getElementById('modal-settings');
        document.getElementById('motrix-open-settings').addEventListener('click', async () => {
            settingsModal.classList.remove('hidden');
            try {
                const res = await fetch(getApiUrl('/api/settings'), {
                    headers: { 'requesttoken': getRequestToken() }
                });
                const data = await safeParseJson(res);
                if (data.success) {
                    document.getElementById('input-setting-endpoint').value = data.endpoint || '';
                    document.getElementById('input-setting-savedir').value = data.saveDir || '';
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
                    resDiv.textContent = 'Connected successfully to Motrix engine!';
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
