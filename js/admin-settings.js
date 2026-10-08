/**
 * ND Downloader Nextcloud App - Admin Settings Handler
 */
(function () {
    'use strict';

    function initAdminSettings() {
        const container = document.getElementById('nddownloader-admin-settings');
        if (!container) return;

        const saveBtn = document.getElementById('nddownloader_admin_save');
        const testBtn = document.getElementById('nddownloader_admin_test');
        const endpointInput = document.getElementById('nddownloader_admin_endpoint');
        const savedirInput = document.getElementById('nddownloader_admin_savedir');
        const tokenInput = document.getElementById('nddownloader_admin_token');
        const allowlistInput = document.getElementById('nddownloader_admin_allowlist');
        const denylistInput = document.getElementById('nddownloader_admin_denylist');
        const allowPrivateInput = document.getElementById('nddownloader_admin_allow_private');
        const statusEl = document.getElementById('nddownloader_admin_status');

        if (!saveBtn) return;

        const getRequestToken = () => {
            return window.oc_requesttoken || (window.OC && window.OC.requestToken) || document.head?.dataset?.requesttoken || '';
        };

        const getApiUrl = (endpoint) => {
            return window.OC && typeof window.OC.generateUrl === 'function'
                ? window.OC.generateUrl(`/apps/nddownloader${endpoint}`)
                : `/apps/nddownloader${endpoint}`;
        };

        saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            statusEl.textContent = 'Saving...';
            statusEl.style.color = '#38bdf8';

            const endpoint = endpointInput ? endpointInput.value.trim() : '';
            const saveDir = savedirInput ? savedirInput.value.trim() : '';
            const token = tokenInput ? tokenInput.value.trim() : '';
            const domainAllowlist = allowlistInput ? allowlistInput.value.trim() : '';
            const domainDenylist = denylistInput ? denylistInput.value.trim() : '';
            const allowPrivateNetwork = allowPrivateInput && allowPrivateInput.checked ? 'yes' : 'no';

            const payload = {
                endpoint,
                saveDir,
                domainAllowlist,
                domainDenylist,
                allowPrivateNetwork,
            };
            if (token !== '') {
                payload.token = token;
            }

            try {
                const res = await fetch(getApiUrl('/settings'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': getRequestToken(),
                    },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (res.ok && data.success) {
                    statusEl.textContent = '✓ Settings saved successfully!';
                    statusEl.style.color = '#10b981';
                    if (tokenInput) {
                        tokenInput.value = '';
                        tokenInput.placeholder = '••••••••';
                    }
                    if (window.OC?.Notification?.showTemporary) {
                        window.OC.Notification.showTemporary('ND Downloader settings saved');
                    }
                } else {
                    statusEl.textContent = '✕ Error: ' + (data.error || 'Failed to save settings');
                    statusEl.style.color = '#ef4444';
                }
            } catch (err) {
                statusEl.textContent = '✕ Network error: ' + err.message;
                statusEl.style.color = '#ef4444';
            } finally {
                saveBtn.disabled = false;
            }
        });

        if (testBtn) {
            testBtn.addEventListener('click', async () => {
                testBtn.disabled = true;
                statusEl.textContent = 'Testing connection...';
                statusEl.style.color = '#38bdf8';

                try {
                    const res = await fetch(getApiUrl('/api/settings/test'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': getRequestToken(),
                        }
                    });

                    const data = await res.json();
                    if (res.ok && data.success) {
                        const engineState = data.engine?.state || 'ready';
                        statusEl.textContent = `✓ Connected! Engine state: ${engineState}`;
                        statusEl.style.color = '#10b981';
                    } else {
                        statusEl.textContent = '✕ Connection failed: ' + (data.error || 'Unreachable');
                        statusEl.style.color = '#ef4444';
                    }
                } catch (err) {
                    statusEl.textContent = '✕ Error: ' + err.message;
                    statusEl.style.color = '#ef4444';
                } finally {
                    testBtn.disabled = false;
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminSettings);
    } else {
        initAdminSettings();
    }
})();
