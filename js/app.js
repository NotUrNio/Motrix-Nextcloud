/**
 * Motrix Nextcloud App Frontend Controller
 */
(function () {
    'use strict';

    let currentFilter = 'all';
    let pollTimer = null;
    let allTasks = [];

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

    const getRequestToken = () => {
        return window.oc_requesttoken || (window.OC && window.OC.requestToken) || document.head?.dataset?.requesttoken || '';
    };

    const getApiUrl = (endpoint) => {
        return OC.generateUrl(`/apps/motrix${endpoint}`);
    };

    const fetchTasks = async () => {
        try {
            const res = await fetch(getApiUrl('/api/tasks'), {
                headers: { 'requesttoken': getRequestToken() }
            });
            const data = await res.json();
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
            const data = await res.json();
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
            const eta = t.etaSec ? `ETA: ${Math.round(t.etaSec)}s` : '';

            let actionsHtml = '';
            if (t.status === 'downloading') {
                actionsHtml += `<button class="task-btn" onclick="window.motrixApp.pauseTask('${t.id}')">Pause</button>`;
            } else if (t.status === 'paused') {
                actionsHtml += `<button class="task-btn" onclick="window.motrixApp.resumeTask('${t.id}')">Resume</button>`;
            }

            if (t.status === 'completed') {
                actionsHtml += `<button class="task-btn primary" onclick="window.motrixApp.syncTask('${t.id}')">📂 Save to Files</button>`;
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

                const data = await res.json();
                if (data.success) {
                    allTasks = allTasks.filter(t => t.id !== taskId);
                    renderTasks();
                    updateCounts();
                } else {
                    alert('Could not remove task: ' + (data.error || 'Unknown error'));
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
                    alert('Error removing task: ' + err.message);
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                }
            }
            fetchTasks();
        },
        syncTask: async (taskId) => {
            const folder = prompt('Enter target Nextcloud folder name:', 'Downloads') || 'Downloads';
            try {
                const res = await fetch(getApiUrl(`/api/tasks/${taskId}/sync`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken()
                    },
                    body: JSON.stringify({ targetFolder: folder })
                });
                const data = await res.json();
                if (data.success && data.result && data.result.synced) {
                    OC.dialogs.info(`File successfully saved to ${data.result.destination}`, 'Download Saved');
                } else {
                    alert(data.result?.message || data.error || 'Sync failed');
                }
            } catch (e) {
                alert('Sync failed: ' + e.message);
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
                const data = await res.json();
                if (data.success) {
                    addModal.classList.add('hidden');
                    document.getElementById('input-task-url').value = '';
                    document.getElementById('input-task-magnet').value = '';
                    fetchTasks();
                } else {
                    alert('Error adding download: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Request failed: ' + err.message);
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
                const data = await res.json();
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
                const data = await res.json();
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
                const data = await res.json();
                if (data.success) {
                    settingsModal.classList.add('hidden');
                    fetchTasks();
                    fetchStats();
                } else {
                    alert('Failed to save settings: ' + data.error);
                }
            } catch (e) {
                alert('Save failed: ' + e.message);
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
