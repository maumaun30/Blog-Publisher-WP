/* Blog Publisher — Admin UI (vanilla JS, no build step) */
(function () {
    'use strict';

    const { restUrl, nonce, page, hasYoast, hasRankMath, hasAioseo } = window.BP || {};

    // ── API helpers ──────────────────────────────────────────────────────

    async function api(method, path, body, isFormData = false) {
        const opts = {
            method,
            headers: { 'X-WP-Nonce': nonce },
        };
        if (body) {
            if (isFormData) {
                opts.body = body;
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
        }
        const r = await fetch(restUrl + path, opts);
        return r.json();
    }

    // ── State ────────────────────────────────────────────────────────────

    const state = {
        tab: page === 'blog-publisher-settings' ? 'settings' : 'upload',
        files: [],           // { file: File, id: string }
        postTypes: [],
        selectedPostType: 'post',
        jobs: {},            // job_id → job row
        batchId: null,
        polling: null,
        uploading: false,
        uploadError: null,
        settings: { anthropic_key: '', pexels_key: '' },
        settingsSaved: false,
    };

    // ── Root render ──────────────────────────────────────────────────────

    function render() {
        const root = document.getElementById('bp-app');
        if (!root) return;
        root.innerHTML = '';
        root.appendChild(renderApp());
    }

    function renderApp() {
        const wrap = el('div');

        // Tabs
        const tabs = el('div', { className: 'bp-tabs' });
        tabs.appendChild(tab('upload',   '📤  Upload Posts', 'upload'));
        tabs.appendChild(tab('settings', '⚙️  Settings',     'settings'));
        wrap.appendChild(tabs);

        // Page content
        if (state.tab === 'upload')   wrap.appendChild(renderUpload());
        if (state.tab === 'settings') wrap.appendChild(renderSettings());

        return wrap;
    }

    function tab(id, label, icon) {
        const btn = el('button', { className: 'bp-tab' + (state.tab === id ? ' active' : '') }, label);
        btn.onclick = () => { state.tab = id; render(); };
        return btn;
    }

    // ── Upload tab ───────────────────────────────────────────────────────

    function renderUpload() {
        const wrap = el('div');

        // API key warning
        if (!state.settings._hasKeys) {
            const notice = el('div', { className: 'bp-notice bp-notice-warning' },
                '⚠️  API keys are not configured. Go to <b>Settings</b> before uploading.');
            notice.querySelector('b').style.fontWeight = '700';
            wrap.appendChild(notice);
        }

        // SEO plugin status
        const seoPlugin = hasYoast || hasRankMath || hasAioseo;
        const seoBadge = el('div', { style: 'margin-bottom:16px' });
        if (seoPlugin) {
            seoBadge.innerHTML = `<span class="bp-seo-badge">✓ ${seoPlugin} detected — SEO fields will be auto-filled</span>`;
        } else {
            seoBadge.innerHTML = `<span class="bp-seo-none">No SEO plugin detected — meta fields will be skipped</span>`;
        }
        wrap.appendChild(seoBadge);

        // Upload card
        const card = el('div', { className: 'bp-card' });
        card.appendChild(el('h2', {}, 'Upload Blog Posts'));
        card.appendChild(el('p', { className: 'bp-sub' }, 'Drop one or more .docx files. Each post will be processed in order with AI images and SEO.'));

        // Drop zone
        const dz = el('div', { className: 'bp-dropzone' });
        const fileInput = el('input', { type: 'file', accept: '.docx', multiple: true });
        fileInput.onchange = (e) => addFiles(Array.from(e.target.files));
        dz.appendChild(fileInput);
        dz.appendChild(el('span', { className: 'bp-dz-icon' }, '📄'));
        dz.appendChild(el('div', { className: 'bp-dz-text' }, 'Drag & drop .docx files here'));
        dz.appendChild(el('div', { className: 'bp-dz-hint' }, 'or click to browse — multiple files supported'));

        dz.ondragover  = (e) => { e.preventDefault(); dz.classList.add('drag-over'); };
        dz.ondragleave = ()  => dz.classList.remove('drag-over');
        dz.ondrop      = (e) => {
            e.preventDefault();
            dz.classList.remove('drag-over');
            addFiles(Array.from(e.dataTransfer.files).filter(f => f.name.endsWith('.docx')));
        };
        card.appendChild(dz);

        // File queue
        if (state.files.length > 0) {
            const list = el('div', { className: 'bp-file-list' });
            state.files.forEach(({ file, id }) => {
                const job   = Object.values(state.jobs).find(j => j._localId === id);
                const item  = el('div', { className: 'bp-file-item' });

                item.appendChild(el('span', { className: 'bp-file-name' }, '📄 ' + file.name));

                const status = job ? job.status : 'pending';
                const badge  = el('span', { className: `bp-file-status status-${status}` },
                    status === 'processing' ? '⏳ processing…' :
                    status === 'done'       ? '✓ done'         :
                    status === 'error'      ? '✕ error'        :
                    status === 'queued'     ? '⌛ queued'       : '• pending');
                item.appendChild(badge);

                const msg = job ? job.message : '';
                const msgEl = el('span', { className: 'bp-file-msg', title: msg }, msg);
                item.appendChild(msgEl);

                if (job && job.status === 'done' && job.post_url) {
                    const linkWrap = el('span', { className: 'bp-file-link' });
                    const a = el('a', { href: job.post_url, target: '_blank' }, 'Edit draft →');
                    linkWrap.appendChild(a);
                    item.appendChild(linkWrap);
                }

                if (!job) {
                    const rm = el('button', { className: 'bp-remove-btn', title: 'Remove' }, '×');
                    rm.onclick = () => { state.files = state.files.filter(f => f.id !== id); render(); };
                    item.appendChild(rm);
                }

                list.appendChild(item);
            });

            // Live log panel for processing jobs
            const processingJobs = Object.values(state.jobs).filter(j => j.status === 'processing' || j.status === 'queued' || j.status === 'done' || j.status === 'error');
            if (processingJobs.length > 0) {
                const logPanel = el('div', { className: 'bp-log-panel' });
                logPanel.appendChild(el('h4', { className: 'bp-log-title' }, '📋 Processing Log'));

                const logList = el('div', { className: 'bp-log-list' });
                processingJobs.forEach(job => {
                    const jobInfo = state.files.find(f => f.id === job._localId);
                    const fileName = jobInfo ? jobInfo.file.name : 'Unknown file';
                    const logEntry = el('div', { className: `bp-log-entry log-${job.status}` });

                    const logHeader = el('div', { className: 'bp-log-header' });
                    logHeader.appendChild(el('span', { className: 'bp-log-file' }, fileName));
                    logHeader.appendChild(el('span', { className: `bp-log-status status-${job.status}` },
                        job.status === 'processing' ? '⏳ Processing' :
                        job.status === 'done' ? '✓ Complete' :
                        job.status === 'error' ? '✕ Error' : '⌛ Queued'));
                    logEntry.appendChild(logHeader);

                    logEntry.appendChild(el('div', { className: 'bp-log-message' }, job.message || 'No message'));
                    logList.appendChild(logEntry);
                });

                logPanel.appendChild(logList);
                list.appendChild(logPanel);
            }

            // Progress bar
            const total = state.files.length;
            const done  = Object.values(state.jobs).filter(j => j.status === 'done' || j.status === 'error').length;
            if (done > 0 && state.batchId) {
                const pct = Math.round((done / total) * 100);
                const pbw = el('div', { className: 'bp-progress-bar-wrap' });
                const pb  = el('div', { className: 'bp-progress-bar', style: `width:${pct}%` });
                pbw.appendChild(pb);
                list.appendChild(pbw);
            }

            card.appendChild(list);
        }

        // Upload error display
        if (state.uploadError) {
            const errorNotice = el('div', { className: 'bp-notice bp-notice-error' }, '⚠️  ' + state.uploadError);
            wrap.appendChild(errorNotice);
        }

        // Controls
        const controls = el('div', { className: 'bp-controls' });

        // Post type select
        const selWrap = el('div', { className: 'bp-select-wrap' });
        selWrap.appendChild(el('label', {}, 'Post type:'));
        const sel = el('select');
        (state.postTypes.length ? state.postTypes : [{ value: 'post', label: 'Post' }]).forEach(pt => {
            const opt = el('option', { value: pt.value }, pt.label);
            if (pt.value === state.selectedPostType) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.onchange = () => { state.selectedPostType = sel.value; };
        selWrap.appendChild(sel);
        controls.appendChild(selWrap);

        // Upload button
        const hasFiles  = state.files.length > 0;
        const allQueued = !state.batchId;
        const uploadBtn = el('button', {
            className: 'bp-btn bp-btn-primary',
            disabled: !hasFiles || state.uploading || !allQueued,
        }, state.uploading ? '' : '🚀  Start Publishing');

        if (state.uploading) {
            uploadBtn.appendChild(el('span', { className: 'bp-spinner' }));
            uploadBtn.appendChild(document.createTextNode(' Uploading…'));
        }

        uploadBtn.onclick = handleUpload;
        controls.appendChild(uploadBtn);

        // Clear button
        if (state.files.length > 0 && state.batchId) {
            const clearBtn = el('button', { className: 'bp-btn bp-btn-secondary' }, 'New batch');
            clearBtn.onclick = () => {
                state.files   = [];
                state.jobs    = {};
                state.batchId = null;
                if (state.polling) { clearInterval(state.polling); state.polling = null; }
                render();
            };
            controls.appendChild(clearBtn);
        }

        card.appendChild(controls);
        wrap.appendChild(card);
        return wrap;
    }

    // ── Settings tab ─────────────────────────────────────────────────────

    function renderSettings() {
        const wrap = el('div');
        const card = el('div', { className: 'bp-card' });
        card.appendChild(el('h2', {}, 'API Keys'));
        card.appendChild(el('p', { className: 'bp-sub' }, 'Keys are stored securely in the WordPress database and never exposed in the browser.'));

        const form = el('div', { className: 'bp-settings-form' });

        form.appendChild(field('Anthropic API Key', 'anthropic_key',
            'sk-ant-…', 'Get your key at console.anthropic.com'));
        form.appendChild(field('Pexels API Key', 'pexels_key',
            'Your Pexels API key', 'Get your key at pexels.com/api'));

        const saveRow = el('div', { className: 'bp-save-row' });
        const saveBtn = el('button', { className: 'bp-btn bp-btn-primary' }, '💾  Save Settings');
        saveBtn.onclick = handleSaveSettings;
        saveRow.appendChild(saveBtn);

        if (state.settingsSaved) {
            saveRow.appendChild(el('span', { className: 'bp-saved-msg' }, '✓ Saved'));
        }

        form.appendChild(saveRow);
        card.appendChild(form);
        wrap.appendChild(card);
        return wrap;
    }

    function field(label, key, placeholder, hint) {
        const wrap = el('div', { className: 'bp-field' });
        wrap.appendChild(el('label', {}, label));
        const input = el('input', { type: 'text', placeholder });
        input.value = state.settings[key] || '';
        input.oninput = () => { state.settings[key] = input.value; };
        wrap.appendChild(input);
        if (hint) wrap.appendChild(el('span', { className: 'bp-hint' }, hint));
        return wrap;
    }

    // ── Handlers ─────────────────────────────────────────────────────────

    function addFiles(newFiles) {
        newFiles.forEach(f => {
            if (!f.name.endsWith('.docx')) return;
            if (state.files.find(e => e.file.name === f.name)) return; // skip dupe
            state.files.push({ file: f, id: Math.random().toString(36).slice(2) });
        });
        render();
    }

    async function handleUpload() {
        if (!state.files.length || state.uploading) return;
        state.uploading = true;
        state.uploadError = null;
        render();

        const fd = new FormData();
        state.files.forEach(({ file }) => fd.append('files[]', file));
        fd.append('post_type', state.selectedPostType);

        try {
            const res = await api('POST', '/upload', fd, true);
            if (res.error) {
                state.uploadError = res.error;
                render();
                state.uploading = false;
                return;
            }

            state.batchId = res.batch_id;

            // Map local file IDs to job IDs by order
            res.job_ids.forEach((jobId, i) => {
                if (state.files[i]) {
                    state.jobs[jobId] = { id: jobId, status: 'queued', message: 'Queued…', _localId: state.files[i].id };
                }
            });

            startPolling();
        } catch (e) {
            state.uploadError = 'Upload failed: ' + e.message;
            render();
        } finally {
            state.uploading = false;
            render();
        }
    }

    function startPolling() {
        if (state.polling) clearInterval(state.polling);
        state.polling = setInterval(pollJobs, 3000);
    }

    async function pollJobs() {
        if (!state.batchId) return;
        try {
            const jobs = await api('GET', `/jobs?batch_id=${state.batchId}`);
            let allDone = true;
            jobs.forEach(job => {
                const existing = state.jobs[job.id] || {};
                state.jobs[job.id] = { ...existing, ...job };
                if (job.status !== 'done' && job.status !== 'error') allDone = false;
            });
            render();
            if (allDone) {
                clearInterval(state.polling);
                state.polling = null;
                // Trigger cron in case WP cron is slow on this host
                api('POST', '/trigger');
            }
        } catch (e) {
            // Silent — keep polling
        }
    }

    async function handleSaveSettings() {
        await api('POST', '/settings', {
            anthropic_key: state.settings.anthropic_key,
            pexels_key:    state.settings.pexels_key,
        });
        state.settingsSaved = true;
        state.settings._hasKeys = !!(state.settings.anthropic_key && state.settings.pexels_key);
        render();
        setTimeout(() => { state.settingsSaved = false; render(); }, 3000);
    }

    // ── Bootstrap ────────────────────────────────────────────────────────

    async function boot() {
        // Load settings + post types in parallel
        const [settings, postTypes] = await Promise.all([
            api('GET', '/settings'),
            api('GET', '/post-types'),
        ]);

        state.settings   = settings;
        state.settings._hasKeys = !!(settings.anthropic_key && settings.pexels_key);
        state.postTypes  = Array.isArray(postTypes) ? postTypes : [];

        render();
    }

    // ── Tiny createElement helper ─────────────────────────────────────────

    function el(tag, attrs = {}, text = null) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'className') node.className = v;
            else if (k === 'disabled' && v) node.setAttribute('disabled', '');
            else node[k] = v;
        });
        if (text !== null) node.textContent = text;
        return node;
    }

    // ── Init ─────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('bp-app')) boot();
    });

})();
