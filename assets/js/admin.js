/**
 * 后台管理系统 JavaScript
 * 2026 现代方案：原生 ES6 + Fetch API + SPA Tab 切换
 */

(function() {
    'use strict';
    
    // ============================================
    // 初始化
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        initSidebar();
        initThemeToggle();
        initClock();
        initSettingsForm();
        loadInitialData();
    });
    
    // ============================================
    // Tab 切换
    // ============================================
    function initTabs() {
        const navItems = document.querySelectorAll('.nav-item[data-tab]');
        const tabs = document.querySelectorAll('.tab-content');
        const pageTitle = document.getElementById('pageTitle');
        
        // 从 URL hash 读取当前 tab
        const hash = window.location.hash.replace('#', '');
        let activeTab = hash || 'dashboard';
        
        function activateTab(tabName) {
            // 更新导航
            navItems.forEach(item => {
                item.classList.toggle('active', item.dataset.tab === tabName);
            });
            
            // 更新内容区
            tabs.forEach(tab => {
                tab.classList.toggle('active', tab.id === 'tab-' + tabName);
            });
            
            // 更新URL hash
            window.location.hash = tabName;
            
            // 更新页面标题
            const titles = {
                'dashboard': '📊 仪表盘',
                'settings': '⚙️ 系统设置',
                'rules': '🧬 识别规则',
                'updates': '🔄 更新管理',
                'api': '🔌 API 管理',
                'spiders': '🕸️ 蜘蛛IP管理',
                'confirmed': '✅ 已确认蜘蛛',
                'logs': '📋 查询日志',
                'account': '👤 账户安全',
            };
            if (pageTitle) pageTitle.textContent = titles[tabName] || tabName;
            
            // 加载对应Tab的数据
            if (tabName === 'spiders') loadSpiderRanges();
            if (tabName === 'confirmed') loadConfirmedSpiders();
            if (tabName === 'logs') loadLogs();
            if (tabName === 'api') loadApiChart();
            if (tabName === 'updates') loadGoogleSources();
            if (tabName === 'rules') { loadRdnsRules(); loadUaRules(); }
            
            // 关闭移动端侧边栏
            document.getElementById('sidebar')?.classList.remove('open');
        }
        
        navItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                activateTab(this.dataset.tab);
            });
        });
        
        activateTab(activeTab);
    }
    
    // ============================================
    // 移动端侧边栏
    // ============================================
    function initSidebar() {
        const btn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        
        if (btn && sidebar) {
            btn.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
            
            // 点击内容区关闭侧边栏
            document.querySelector('.admin-main')?.addEventListener('click', function() {
                sidebar.classList.remove('open');
            });
        }
    }
    
    // ============================================
    // 主题切换
    // ============================================
    function initThemeToggle() {
        const toggle = document.getElementById('themeToggle');
        if (!toggle) return;
        
        toggle.addEventListener('click', function() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'auto';
            const themes = ['auto', 'light', 'dark'];
            const nextIndex = (themes.indexOf(current) + 1) % themes.length;
            const next = themes[nextIndex];
            
            html.setAttribute('data-theme', next);
            
            // 保存到服务器
            apiPost('save_settings', {
                settings: [{ key: 'admin_theme', value: next, type: 'string' }]
            }).catch(() => {});
        });
    }
    
    // ============================================
    // 时钟
    // ============================================
    function initClock() {
        const el = document.getElementById('topbarTime');
        if (!el) return;
        
        function update() {
            const now = new Date();
            el.textContent = now.toLocaleString('zh-CN', {
                timeZone: 'Asia/Shanghai',
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: false
            });
        }
        update();
        setInterval(update, 1000);
    }
    
    // ============================================
    // 初始加载
    // ============================================
    function loadInitialData() {
        const hash = window.location.hash.replace('#', '');
        if (hash === 'spiders') loadSpiderRanges();
        if (hash === 'confirmed') loadConfirmedSpiders();
        if (hash === 'logs') loadLogs();
        if (hash === 'api') loadApiChart();
        if (hash === 'updates') loadGoogleSources();
        if (hash === 'rules') { loadRdnsRules(); loadUaRules(); }
    }
    
    // ============================================
    // 仪表盘图表渲染
    // ============================================
    function renderDailyChart() {
        const canvas = document.getElementById('dailyChart');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const w = rect.width, h = rect.height;
        
        // 从 PHP 注入的数据（JSON）
        var chartData = window.__dailyChartData;
        if (!chartData) return;
        
        const labels = chartData.labels;
        const totalData = chartData.total;
        const spiderData = chartData.spider;
        const maxVal = Math.max(...totalData, ...spiderData, 1);
        const padding = { top: 20, right: 16, bottom: 28, left: 36 };
        const pw = w - padding.left - padding.right;
        const ph = h - padding.top - padding.bottom;
        
        // 背景
        ctx.clearRect(0, 0, w, h);
        
        // 网格线
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padding.top + (ph / 4) * i;
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(w - padding.right, y);
            ctx.stroke();
        }
        
        // Y 轴标签
        ctx.fillStyle = 'var(--admin-text-dim, #94a3b8)';
        ctx.font = '10px system-ui';
        ctx.textAlign = 'right';
        for (let i = 0; i <= 4; i++) {
            const val = Math.round(maxVal * (4 - i) / 4);
            const y = padding.top + (ph / 4) * i + 4;
            ctx.fillText(val, padding.left - 6, y);
        }
        
        // 绘制线条的函数
        function drawLine(data, color, dash) {
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            ctx.lineJoin = 'round';
            if (dash) ctx.setLineDash([4, 2]); else ctx.setLineDash([]);
            ctx.beginPath();
            for (let i = 0; i < data.length; i++) {
                const x = padding.left + (pw / (data.length - 1 || 1)) * i;
                const y = padding.top + ph - (data[i] / maxVal) * ph;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.stroke();
            ctx.setLineDash([]);
            
            // 数据点
            for (let i = 0; i < data.length; i++) {
                const x = padding.left + (pw / (data.length - 1 || 1)) * i;
                const y = padding.top + ph - (data[i] / maxVal) * ph;
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(x, y, 3, 0, Math.PI * 2);
                ctx.fill();
                
                // 数值标签
                ctx.fillStyle = 'var(--admin-text, #e2e8f0)';
                ctx.font = '9px system-ui';
                ctx.textAlign = 'center';
                ctx.fillText(data[i], x, y - 8);
            }
        }
        
        drawLine(totalData, 'var(--admin-primary, #6366f1)', false);
        drawLine(spiderData, 'var(--admin-warning, #f59e0b)', false);
        
        // X 轴标签
        ctx.fillStyle = 'var(--admin-text-dim, #94a3b8)';
        ctx.font = '10px system-ui';
        ctx.textAlign = 'center';
        for (let i = 0; i < labels.length; i++) {
            const x = padding.left + (pw / (labels.length - 1 || 1)) * i;
            ctx.fillText(labels[i], x, h - 6);
        }
    }
    
    // ============================================
    // 设置表单
    // ============================================
    function initSettingsForm() {
        const form = document.getElementById('settingsForm');
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const settings = [];
            
            // 收集所有带有 data-key 的输入元素
            form.querySelectorAll('[data-key]').forEach(el => {
                const key = el.dataset.key;
                let value;
                const type = el.dataset.type || 'string';
                
                if (el.type === 'checkbox') {
                    value = el.checked ? '1' : '0';
                } else if (el.tagName === 'SELECT') {
                    value = el.value;
                } else {
                    value = el.value;
                }
                
                settings.push({ key, value, type });
            });
            
            const statusEl = document.getElementById('settingsSaveStatus');
            if (statusEl) {
                statusEl.textContent = '保存中...';
                statusEl.style.color = 'var(--admin-warning)';
            }
            
            apiPost('save_settings', { settings })
                .then(data => {
                    if (statusEl) {
                        statusEl.textContent = '✅ 设置已保存';
                        statusEl.style.color = 'var(--admin-success)';
                    }
                    // 仅当 admin_path 实际变更时才显示服务器配置弹窗
                    if (data.server_config) {
                        const cfg = data.server_config;
                        if (cfg.server_type === 'nginx') {
                            showNginxConfigModal(cfg);
                        } else if (cfg.server_type === 'apache') {
                            showToast(cfg.success ? '✅ ' + cfg.message : '⚠️ ' + cfg.message, cfg.success ? 'success' : 'warning');
                        }
                    } else {
                        showToast('设置已成功保存', 'success');
                    }
                    setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
                })
                .catch(err => {
                    if (statusEl) {
                        statusEl.textContent = '❌ 保存失败: ' + err.message;
                        statusEl.style.color = 'var(--admin-error)';
                    }
                    showToast('保存失败: ' + err.message, 'error');
                });
        });
    }
    
    // ============================================
    // 蜘蛛IP段管理
    // ============================================
    function loadSpiderRanges(page = 1) {
        const tableBody = document.querySelector('#spiderRangesTable tbody');
        if (!tableBody) return;
        
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>';
        
        const search = document.getElementById('spiderSearch')?.value?.trim() || '';
        const filterType = document.getElementById('spiderTypeFilter')?.value || '';
        const filterSource = document.getElementById('spiderSourceFilter')?.value || '';
        
        apiGet('get_spider_ranges', { page, per_page: 50, search, filter_type: filterType, filter_source: filterSource })
            .then(data => {
                if (!data.ranges || data.ranges.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无IP段数据</td></tr>';
                    renderPagination('spiderRangesPagination', data.total_pages, data.page, loadSpiderRanges);
                    return;
                }
                
                tableBody.innerHTML = data.ranges.map(r => `
                    <tr>
                        <td><input type="checkbox" class="range-checkbox" value="${r.id}" onchange="updateBatchDeleteBtn()"></td>
                        <td><code>${escHtml(formatIPRange(r.ip_range))}</code></td>
                        <td><span class="badge badge-warning">${escHtml(r.spider_type)}</span></td>
                        <td>${escHtml(r.source)}</td>
                        <td>${renderConfidence(r.confidence)}</td>
                        <td>${r.is_active == 1 
                            ? '<span class="badge badge-success">活跃</span>' 
                            : '<span class="badge badge-error">已禁用</span>'}</td>
                        <td>${r.created_at || '-'}</td>
                        <td>
                            <button class="btn btn-sm ${r.is_active == 1 ? 'btn-danger' : 'btn-primary'}" onclick="toggleRange(${r.id}, ${r.is_active == 1 ? 0 : 1})" title="${r.is_active == 1 ? '禁用' : '启用'}">${r.is_active == 1 ? '⏸️' : '▶️'}</button>
                            <button class="btn btn-sm btn-danger" onclick="if(confirm('确定删除?'))deleteRange(${r.id})" title="删除">🗑️</button>
                        </td>
                    </tr>
                `).join('');
                
                renderPagination('spiderRangesPagination', data.total_pages, data.page, loadSpiderRanges);
                document.getElementById('selectAllRanges').checked = false;
                updateBatchDeleteBtn();
            })
            .catch(err => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    window.showAddRangeModal = function() {
        document.getElementById('rangeModalTitle').textContent = '添加 IP 段';
        document.getElementById('rangeId').value = '';
        document.getElementById('rangeCidr').value = '';
        document.getElementById('rangeType').value = 'Baiduspider';
        document.getElementById('rangeTypeOther').value = '';
        document.getElementById('rangeTypeOther').style.display = 'none';
        document.getElementById('rangeSource').value = 'manual';
        document.getElementById('rangeModal').style.display = 'flex';
    };
    
    window.closeModal = function(id) {
        document.getElementById(id).style.display = 'none';
    };
    
    window.validateBaiduRanges = function() {
        const textarea = document.getElementById('set_baidu_ranges');
        const resultSpan = document.getElementById('baiduRangesValidateResult');
        const ranges = textarea.value.trim();
        
        if (!ranges) {
            showToast('请先输入 IP 段', 'warning');
            return;
        }
        
        resultSpan.innerHTML = '<span style="color:var(--admin-text-muted)">⏳ 验证中...</span>';
        
        apiPost('validate_baidu_ranges', { ranges })
            .then(data => {
                // 收集通过的 CIDR
                const passedCIDRs = data.results
                    .filter(r => r.status === 'pass')
                    .map(r => r.cidr);
                
                // 剔除未通过的，更新 textarea
                textarea.value = passedCIDRs.join('\n');
                
                // 显示结果
                let msg = `${data.passed}/${data.total} 通过`;
                if (data.failed > 0) msg += `，已自动剔除 ${data.failed} 条未通过项`;
                
                resultSpan.innerHTML = `<span style="color:${data.failed === 0 ? 'var(--admin-success)' : 'var(--admin-warning)'}">${msg}</span>`;
                showToast(msg, data.failed === 0 ? 'success' : 'warning');
            })
            .catch(err => {
                resultSpan.innerHTML = '<span style="color:var(--admin-error)">验证失败</span>';
                showToast(err.message || '验证失败', 'error');
            });
    };
    
    window.saveRange = function(e) {
        e.preventDefault();
        const id = document.getElementById('rangeId').value;
        const cidr = document.getElementById('rangeCidr').value.trim();
        let type = document.getElementById('rangeType').value;
        const source = document.getElementById('rangeSource').value.trim();
        
        // 如果选择了"其他"，使用手动输入的值
        if (type === 'Other') {
            type = document.getElementById('rangeTypeOther').value.trim();
            if (!type) {
                showToast('请输入自定义蜘蛛类型', 'error');
                return;
            }
        }
        
        if (!cidr) {
            showToast('请输入CIDR表示法', 'error');
            return;
        }
        
        apiPost('add_spider_range', {
            cidr, spider_type: type, source: source || 'manual'
        })
        .then(data => {
            showToast(data.message || '添加成功', 'success');
            closeModal('rangeModal');
            document.getElementById('rangeCidr').value = '';
            document.getElementById('rangeTypeOther').value = '';
            document.getElementById('rangeTypeOther').style.display = 'none';
            document.getElementById('rangeType').value = 'Baiduspider';
            loadSpiderRanges();
        })
        .catch(err => {
            showToast(err.message, 'error');
        });
    };
    
    window.deleteRange = function(id) {
        apiPost('delete_spider_range', { id })
            .then(data => {
                showToast(data.message, 'success');
                loadSpiderRanges();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.toggleAllRanges = function(checkbox) {
        document.querySelectorAll('.range-checkbox').forEach(cb => {
            cb.checked = checkbox.checked;
        });
        updateBatchDeleteBtn();
    };
    
    window.updateBatchDeleteBtn = function() {
        const checked = document.querySelectorAll('.range-checkbox:checked').length;
        const btn = document.getElementById('batchDeleteBtn');
        if (btn) btn.style.display = checked > 0 ? '' : 'none';
    };
    
    window.batchDeleteRanges = function() {
        const checked = document.querySelectorAll('.range-checkbox:checked');
        if (checked.length === 0) {
            showToast('请先选择要删除的 IP 段', 'warning');
            return;
        }
        if (!confirm(`确定要删除选中的 ${checked.length} 条 IP 段吗？此操作不可撤销。`)) return;
        
        const ids = Array.from(checked).map(cb => parseInt(cb.value));
        apiPost('batch_delete_spider_ranges', { ids })
            .then(data => {
                showToast(data.message, 'success');
                loadSpiderRanges();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.toggleRange = function(id, active) {
        apiPost('toggle_spider_range', { id, active })
            .then(data => {
                showToast(data.message, 'success');
                loadSpiderRanges();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    // ============================================
    // 查询日志
    // ============================================
    function loadLogs(page = 1) {
        const tableBody = document.querySelector('#logsTable tbody');
        if (!tableBody) return;
        
        const filter = document.getElementById('logFilter')?.value || '';
        
        tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>';
        
        apiGet('get_logs', { page, per_page: 50, filter })
            .then(data => {
                if (!data.logs || data.logs.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无查询记录</td></tr>';
                    renderPagination('logsPagination', 0, 1, loadLogs);
                    return;
                }
                
                tableBody.innerHTML = data.logs.map(l => {
                    const countBadge = l.query_count > 1
                        ? `<span class="badge" style="background:rgba(99,102,241,0.2);color:#a5b4fc">${l.query_count}</span>`
                        : `<span class="badge badge-muted">${l.query_count}</span>`;
                    return `
                    <tr>
                        <td><code>${escHtml(l.ip_address)}</code></td>
                        <td>${countBadge}</td>
                        <td>${l.is_spider == 1 ? '<span class="badge badge-warning">🕷️ 蜘蛛</span>' : '<span class="badge badge-success">👤 访客</span>'}</td>
                        <td>${escHtml(l.spider_type || '-')}${l.match_method ? '<br><small class="text-muted">' + escHtml(l.match_method) + '</small>' : ''}</td>
                        <td style="font-size:0.82rem">${l.first_query_time || '-'}</td>
                        <td style="font-size:0.82rem">${l.last_query_time || '-'}</td>
                    </tr>
                `}).join('');
                
                renderPagination('logsPagination', data.total_pages, data.page, loadLogs);
            })
            .catch(err => {
                tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    window.clearAllLogs = function() {
        if (!confirm('确定清空所有查询日志？此操作不可恢复！')) return;
        
        apiPost('clear_logs')
            .then(data => {
                showToast(data.message, 'success');
                loadLogs();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    // 暴露 loadLogs 到全局作用域供 onchange 使用
    window.loadLogs = loadLogs;
    window.loadSpiderRanges = loadSpiderRanges;
    
    // ============================================
    // Google 数据源管理
    // ============================================
    function loadGoogleSources() {
        const tableBody = document.querySelector('#googleSourcesTable tbody');
        if (!tableBody) return;
        
        tableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">加载中...</td></tr>';
        
        apiGet('get_google_sources')
            .then(data => {
                if (!data.sources || data.sources.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">暂无数据源，点击"添加数据源"按钮添加</td></tr>';
                    return;
                }
                
                tableBody.innerHTML = data.sources.map(s => {
                    const nameEnc = escHtml(s.source_name);
                    const urlEnc = escHtml(s.endpoint_url);
                    const keyEnc = escHtml(s.source_key);
                    return `
                    <tr>
                        <td><code>${keyEnc}</code></td>
                        <td>${nameEnc}</td>
                        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${urlEnc}">${urlEnc}</td>
                        <td>${s.is_active == 1
                            ? '<span class="badge badge-success">启用</span>'
                            : '<span class="badge badge-error">禁用</span>'}</td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm edit-source-btn" data-id="${s.id}" data-key="${keyEnc}" data-name="${nameEnc}" data-url="${urlEnc}" data-active="${s.is_active}" title="编辑">✏️</button>
                            <button class="btn btn-sm ${s.is_active == 1 ? 'btn-danger' : 'btn-primary'}" onclick="toggleSource(${s.id}, ${s.is_active == 1 ? 0 : 1})" title="${s.is_active == 1 ? '禁用' : '启用'}">${s.is_active == 1 ? '⏸️' : '▶️'}</button>
                            <button class="btn btn-sm btn-danger" onclick="if(confirm('确定删除此数据源？'))deleteSource(${s.id})" title="删除">🗑️</button>
                        </td>
                    </tr>
                `}).join('');
                
                // 绑定编辑按钮事件（使用事件委托避免内联复杂参数）
                document.querySelectorAll('.edit-source-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        editSource(
                            parseInt(this.dataset.id),
                            this.dataset.key,
                            this.dataset.name,
                            this.dataset.url,
                            parseInt(this.dataset.active)
                        );
                    });
                });
            })
            .catch(err => {
                tableBody.innerHTML = `<tr><td colspan="5" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    window.showAddSourceModal = function() {
        document.getElementById('sourceModalTitle').textContent = '添加数据源';
        document.getElementById('sourceId').value = '';
        document.getElementById('sourceKey').value = '';
        document.getElementById('sourceKey').disabled = false;
        document.getElementById('sourceName').value = '';
        document.getElementById('sourceUrl').value = '';
        document.getElementById('sourceActive').value = '1';
        document.getElementById('sourceModal').style.display = 'flex';
    };
    
    window.editSource = function(id, key, name, url, active) {
        document.getElementById('sourceModalTitle').textContent = '编辑数据源';
        document.getElementById('sourceId').value = id;
        document.getElementById('sourceKey').value = key;
        document.getElementById('sourceKey').disabled = true; // 标识不可修改
        document.getElementById('sourceName').value = name;
        document.getElementById('sourceUrl').value = url;
        document.getElementById('sourceActive').value = String(active);
        document.getElementById('sourceModal').style.display = 'flex';
    };
    
    window.saveSource = function(e) {
        e.preventDefault();
        const id = document.getElementById('sourceId').value;
        const key = document.getElementById('sourceKey').value.trim();
        const name = document.getElementById('sourceName').value.trim();
        const url = document.getElementById('sourceUrl').value.trim();
        const active = document.getElementById('sourceActive').value;
        
        if (!key || !name || !url) {
            showToast('请填写所有必填字段', 'warning');
            return;
        }
        
        if (id) {
            // 更新
            apiPost('update_google_source', {
                id: parseInt(id), source_name: name, endpoint_url: url, is_active: parseInt(active)
            })
            .then(data => {
                showToast(data.message || '更新成功', 'success');
                closeModal('sourceModal');
                loadGoogleSources();
            })
            .catch(err => showToast(err.message, 'error'));
        } else {
            // 新增
            apiPost('add_google_source', {
                source_key: key, source_name: name, endpoint_url: url
            })
            .then(data => {
                showToast(data.message || '添加成功', 'success');
                closeModal('sourceModal');
                loadGoogleSources();
            })
            .catch(err => showToast(err.message, 'error'));
        }
    };
    
    window.toggleSource = function(id, active) {
        apiPost('toggle_google_source', { id, active })
            .then(data => {
                showToast(data.message, 'success');
                loadGoogleSources();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.deleteSource = function(id) {
        apiPost('delete_google_source', { id })
            .then(data => {
                showToast(data.message, 'success');
                loadGoogleSources();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.loadGoogleSources = loadGoogleSources;
    
    // ============================================
    // 自定义反向DNS规则管理
    // ============================================
    function loadRdnsRules() {
        const tableBody = document.querySelector('#rdnsRulesTable tbody');
        if (!tableBody) return;
        
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>';
        
        apiGet('get_rdns_rules')
            .then(data => {
                if (!data.rules || data.rules.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无自定义规则，点击"添加规则"按钮添加</td></tr>';
                    return;
                }
                
                const matchLabels = { 'contains': '包含', 'regex': '正则', 'exact': '精确' };
                
                tableBody.innerHTML = data.rules.map(r => {
                    return `
                    <tr>
                        <td><input type="checkbox" class="rdns-rule-checkbox" value="${r.id}" onchange="updateRdnsBatchDeleteBtn()"></td>
                        <td><span class="badge badge-muted">${r.sort_order}</span></td>
                        <td><code>${escHtml(r.hostname_pattern)}</code></td>
                        <td><span class="badge badge-warning">${escHtml(r.spider_type)}</span></td>
                        <td><span class="badge" style="background:rgba(99,102,241,0.15);color:#a5b4fc">${matchLabels[r.match_type] || r.match_type}</span></td>
                        <td>${r.is_active == 1
                            ? '<span class="badge badge-success">启用</span>'
                            : '<span class="badge badge-error">禁用</span>'}</td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm edit-rdns-btn"
                                data-id="${r.id}"
                                data-pattern="${escHtml(r.hostname_pattern)}"
                                data-type="${escHtml(r.spider_type)}"
                                data-match="${escHtml(r.match_type)}"
                                data-active="${r.is_active}"
                                title="编辑">✏️</button>
                            <button class="btn btn-sm ${r.is_active == 1 ? 'btn-danger' : 'btn-primary'}" onclick="toggleRdnsRule(${r.id}, ${r.is_active == 1 ? 0 : 1})" title="${r.is_active == 1 ? '禁用' : '启用'}">${r.is_active == 1 ? '⏸️' : '▶️'}</button>
                            <button class="btn btn-sm btn-danger" onclick="if(confirm('确定删除此规则？'))deleteRdnsRule(${r.id})" title="删除">🗑️</button>
                        </td>
                    </tr>
                `}).join('');
                
                document.getElementById('selectAllRdnsRules').checked = false;
                updateRdnsBatchDeleteBtn();
                
                // 绑定编辑按钮事件
                document.querySelectorAll('.edit-rdns-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        editRdnsRule(
                            parseInt(this.dataset.id),
                            this.dataset.pattern,
                            this.dataset.type,
                            this.dataset.match,
                            parseInt(this.dataset.active)
                        );
                    });
                });
            })
            .catch(err => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    window.showAddRdnsRuleModal = function() {
        document.getElementById('rdnsRuleModalTitle').textContent = '添加规则';
        document.getElementById('rdnsRuleId').value = '';
        document.getElementById('rdnsRulePattern').value = '';
        document.getElementById('rdnsRuleType').value = '';
        document.getElementById('rdnsRuleMatchType').value = 'contains';
        document.getElementById('rdnsRuleActiveGroup').style.display = 'none';
        document.getElementById('rdnsRuleModal').style.display = 'flex';
    };
    
    window.editRdnsRule = function(id, pattern, spiderType, matchType, active) {
        document.getElementById('rdnsRuleModalTitle').textContent = '编辑规则';
        document.getElementById('rdnsRuleId').value = id;
        document.getElementById('rdnsRulePattern').value = pattern;
        document.getElementById('rdnsRuleType').value = spiderType;
        document.getElementById('rdnsRuleMatchType').value = matchType;
        document.getElementById('rdnsRuleActive').value = String(active);
        document.getElementById('rdnsRuleActiveGroup').style.display = 'block';
        document.getElementById('rdnsRuleModal').style.display = 'flex';
    };
    
    window.saveRdnsRule = function(e) {
        e.preventDefault();
        const id = document.getElementById('rdnsRuleId').value;
        const rawText = document.getElementById('rdnsRulePattern').value.trim();
        const spiderType = document.getElementById('rdnsRuleType').value.trim();
        const matchType = document.getElementById('rdnsRuleMatchType').value;
        const isActive = document.getElementById('rdnsRuleActive')?.value || '1';
        
        if (!rawText) {
            showToast('请填写主机名关键词', 'warning');
            return;
        }
        
        // 多行 = 批量添加模式
        const lines = rawText.split('\n').map(l => l.trim()).filter(l => l !== '');
        if (id) {
            // 编辑模式：使用第一行
            const firstLine = lines[0];
            const parts = firstLine.includes('|') ? firstLine.split('|').map(s => s.trim()) : [firstLine, spiderType, matchType];
            apiPost('update_rdns_rule', {
                id: parseInt(id),
                hostname_pattern: parts[0],
                spider_type: parts[1] || spiderType,
                match_type: parts[2] || matchType,
                is_active: parseInt(isActive)
            })
            .then(data => {
                showToast(data.message || '规则已更新', 'success');
                closeModal('rdnsRuleModal');
                loadRdnsRules();
            })
            .catch(err => showToast(err.message, 'error'));
        } else if (lines.length > 1) {
            // 批量添加模式
            const rules = lines.map(line => {
                const parts = line.includes('|') ? line.split('|').map(s => s.trim()) : [line, spiderType, matchType];
                return { hostname_pattern: parts[0], spider_type: parts[1] || spiderType, match_type: parts[2] || matchType };
            });
            apiPost('batch_add_rdns_rules', { rules })
                .then(data => {
                    showToast(data.message || '批量添加完成', 'success');
                    closeModal('rdnsRuleModal');
                    document.getElementById('rdnsRulePattern').value = '';
                    loadRdnsRules();
                })
                .catch(err => showToast(err.message, 'error'));
        } else {
            // 单条添加
            const parts = rawText.includes('|') ? rawText.split('|').map(s => s.trim()) : [rawText, spiderType, matchType];
            apiPost('add_rdns_rule', {
                hostname_pattern: parts[0],
                spider_type: parts[1] || spiderType,
                match_type: parts[2] || matchType
            })
            .then(data => {
                showToast(data.message || '规则已添加', 'success');
                closeModal('rdnsRuleModal');
                document.getElementById('rdnsRulePattern').value = '';
                loadRdnsRules();
            })
            .catch(err => showToast(err.message, 'error'));
        }
    };
    
    window.toggleRdnsRule = function(id, active) {
        apiPost('toggle_rdns_rule', { id, active })
            .then(data => {
                showToast(data.message, 'success');
                loadRdnsRules();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.deleteRdnsRule = function(id) {
        apiPost('delete_rdns_rule', { id })
            .then(data => {
                showToast(data.message, 'success');
                loadRdnsRules();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.loadRdnsRules = loadRdnsRules;
    
    window.toggleAllRdnsRules = function(checkbox) {
        document.querySelectorAll('.rdns-rule-checkbox').forEach(cb => { cb.checked = checkbox.checked; });
        updateRdnsBatchDeleteBtn();
    };
    
    window.updateRdnsBatchDeleteBtn = function() {
        const checked = document.querySelectorAll('.rdns-rule-checkbox:checked').length;
        const btn = document.getElementById('batchDeleteRdnsBtn');
        if (btn) btn.style.display = checked > 0 ? '' : 'none';
    };
    
    window.batchDeleteRdnsRules = function() {
        const checked = document.querySelectorAll('.rdns-rule-checkbox:checked');
        if (checked.length === 0) { showToast('请先选择要删除的规则', 'warning'); return; }
        if (!confirm(`确定要删除选中的 ${checked.length} 条 rDNS 规则吗？此操作不可撤销。`)) return;
        
        const ids = Array.from(checked).map(cb => parseInt(cb.value));
        apiPost('batch_delete_rdns_rules', { ids })
            .then(data => { showToast(data.message, 'success'); loadRdnsRules(); })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.resetRdnsRules = function() {
        if (!confirm('确定要导入系统默认的 rDNS 规则吗？已存在的规则将自动跳过。')) return;
        apiPost('reset_rdns_rules')
            .then(data => { showToast(data.message, 'success'); loadRdnsRules(); })
            .catch(err => showToast(err.message, 'error'));
    };
    
    // ============================================
    // User-Agent 自定义规则管理
    // ============================================
    function loadUaRules() {
        const tableBody = document.querySelector('#uaRulesTable tbody');
        if (!tableBody) return;
        
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>';
        
        apiGet('get_ua_rules')
            .then(data => {
                if (!data.rules || data.rules.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无自定义规则，点击"添加规则"按钮添加<br><small>系统内置了 Baiduspider、Googlebot 等常见 UA 关键词无需额外添加</small></td></tr>';
                    return;
                }
                
                const matchLabels = { 'contains': '包含', 'regex': '正则' };
                
                tableBody.innerHTML = data.rules.map(r => {
                    return `
                    <tr>
                        <td><input type="checkbox" class="ua-rule-checkbox" value="${r.id}" onchange="updateUaBatchDeleteBtn()"></td>
                        <td><span class="badge badge-muted">${r.sort_order}</span></td>
                        <td><code>${escHtml(r.ua_keyword)}</code></td>
                        <td><span class="badge badge-warning">${escHtml(r.spider_type)}</span></td>
                        <td><span class="badge" style="background:rgba(99,102,241,0.15);color:#a5b4fc">${matchLabels[r.match_type] || r.match_type}</span></td>
                        <td>${r.is_active == 1
                            ? '<span class="badge badge-success">启用</span>'
                            : '<span class="badge badge-error">禁用</span>'}</td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm edit-ua-btn"
                                data-id="${r.id}"
                                data-keyword="${escHtml(r.ua_keyword)}"
                                data-type="${escHtml(r.spider_type)}"
                                data-match="${escHtml(r.match_type)}"
                                data-active="${r.is_active}"
                                title="编辑">✏️</button>
                            <button class="btn btn-sm ${r.is_active == 1 ? 'btn-danger' : 'btn-primary'}" onclick="toggleUaRule(${r.id}, ${r.is_active == 1 ? 0 : 1})" title="${r.is_active == 1 ? '禁用' : '启用'}">${r.is_active == 1 ? '⏸️' : '▶️'}</button>
                            <button class="btn btn-sm btn-danger" onclick="if(confirm('确定删除此规则？'))deleteUaRule(${r.id})" title="删除">🗑️</button>
                        </td>
                    </tr>
                `}).join('');
                
                document.getElementById('selectAllUaRules').checked = false;
                updateUaBatchDeleteBtn();
                
                // 绑定编辑按钮事件
                document.querySelectorAll('.edit-ua-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        editUaRule(
                            parseInt(this.dataset.id),
                            this.dataset.keyword,
                            this.dataset.type,
                            this.dataset.match,
                            parseInt(this.dataset.active)
                        );
                    });
                });
            })
            .catch(err => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    window.showAddUaRuleModal = function() {
        document.getElementById('uaRuleModalTitle').textContent = '添加规则';
        document.getElementById('uaRuleId').value = '';
        document.getElementById('uaRulePattern').value = '';
        document.getElementById('uaRuleType').value = '';
        document.getElementById('uaRuleMatchType').value = 'contains';
        document.getElementById('uaRuleActiveGroup').style.display = 'none';
        document.getElementById('uaRuleModal').style.display = 'flex';
    };
    
    window.editUaRule = function(id, keyword, spiderType, matchType, active) {
        document.getElementById('uaRuleModalTitle').textContent = '编辑规则';
        document.getElementById('uaRuleId').value = id;
        document.getElementById('uaRulePattern').value = keyword;
        document.getElementById('uaRuleType').value = spiderType;
        document.getElementById('uaRuleMatchType').value = matchType;
        document.getElementById('uaRuleActive').value = String(active);
        document.getElementById('uaRuleActiveGroup').style.display = 'block';
        document.getElementById('uaRuleModal').style.display = 'flex';
    };
    
    window.saveUaRule = function(e) {
        e.preventDefault();
        const id = document.getElementById('uaRuleId').value;
        const rawText = document.getElementById('uaRulePattern').value.trim();
        const spiderType = document.getElementById('uaRuleType').value.trim();
        const matchType = document.getElementById('uaRuleMatchType').value;
        const isActive = document.getElementById('uaRuleActive')?.value || '1';
        
        if (!rawText) {
            showToast('请填写 UA 关键词', 'warning');
            return;
        }
        
        const lines = rawText.split('\n').map(l => l.trim()).filter(l => l !== '');
        if (id) {
            const firstLine = lines[0];
            const parts = firstLine.includes('|') ? firstLine.split('|').map(s => s.trim()) : [firstLine, spiderType, matchType];
            apiPost('update_ua_rule', {
                id: parseInt(id),
                ua_keyword: parts[0],
                spider_type: parts[1] || spiderType,
                match_type: parts[2] || matchType,
                is_active: parseInt(isActive)
            })
            .then(data => {
                showToast(data.message || '规则已更新', 'success');
                closeModal('uaRuleModal');
                loadUaRules();
            })
            .catch(err => showToast(err.message, 'error'));
        } else if (lines.length > 1) {
            const rules = lines.map(line => {
                const parts = line.includes('|') ? line.split('|').map(s => s.trim()) : [line, spiderType, matchType];
                return { ua_keyword: parts[0], spider_type: parts[1] || spiderType, match_type: parts[2] || matchType };
            });
            apiPost('batch_add_ua_rules', { rules })
                .then(data => {
                    showToast(data.message || '批量添加完成', 'success');
                    closeModal('uaRuleModal');
                    document.getElementById('uaRulePattern').value = '';
                    loadUaRules();
                })
                .catch(err => showToast(err.message, 'error'));
        } else {
            const parts = rawText.includes('|') ? rawText.split('|').map(s => s.trim()) : [rawText, spiderType, matchType];
            apiPost('add_ua_rule', {
                ua_keyword: parts[0],
                spider_type: parts[1] || spiderType,
                match_type: parts[2] || matchType
            })
            .then(data => {
                showToast(data.message || '规则已添加', 'success');
                closeModal('uaRuleModal');
                document.getElementById('uaRulePattern').value = '';
                loadUaRules();
            })
            .catch(err => showToast(err.message, 'error'));
        }
    };
    
    window.toggleUaRule = function(id, active) {
        apiPost('toggle_ua_rule', { id, active })
            .then(data => {
                showToast(data.message, 'success');
                loadUaRules();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.deleteUaRule = function(id) {
        apiPost('delete_ua_rule', { id })
            .then(data => {
                showToast(data.message, 'success');
                loadUaRules();
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.loadUaRules = loadUaRules;
    
    window.toggleAllUaRules = function(checkbox) {
        document.querySelectorAll('.ua-rule-checkbox').forEach(cb => { cb.checked = checkbox.checked; });
        updateUaBatchDeleteBtn();
    };
    
    window.updateUaBatchDeleteBtn = function() {
        const checked = document.querySelectorAll('.ua-rule-checkbox:checked').length;
        const btn = document.getElementById('batchDeleteUaBtn');
        if (btn) btn.style.display = checked > 0 ? '' : 'none';
    };
    
    window.batchDeleteUaRules = function() {
        const checked = document.querySelectorAll('.ua-rule-checkbox:checked');
        if (checked.length === 0) { showToast('请先选择要删除的规则', 'warning'); return; }
        if (!confirm(`确定要删除选中的 ${checked.length} 条 UA 规则吗？此操作不可撤销。`)) return;
        
        const ids = Array.from(checked).map(cb => parseInt(cb.value));
        apiPost('batch_delete_ua_rules', { ids })
            .then(data => { showToast(data.message, 'success'); loadUaRules(); })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.resetUaRules = function() {
        if (!confirm('确定要导入系统默认的 User-Agent 规则吗？已存在的规则将自动跳过。')) return;
        apiPost('reset_ua_rules')
            .then(data => { showToast(data.message, 'success'); loadUaRules(); })
            .catch(err => showToast(err.message, 'error'));
    };
    
    // ============================================
    // 已确认蜘蛛
    // ============================================
    function loadConfirmedSpiders() {
        const tableBody = document.querySelector('#confirmedSpidersTable tbody');
        if (!tableBody) { console.warn('[ConfirmedSpiders] table body not found'); return; }
        
        tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">加载中...</td></tr>';
        
        const search = document.getElementById('confirmedSearch')?.value?.trim() || '';
        const filterType = document.getElementById('confirmedTypeFilter')?.value || '';
        const filterConf = document.getElementById('confirmedConfFilter')?.value || '';
        
        apiGet('get_confirmed_spiders', { limit: 200, search, filter_type: filterType, filter_conf: filterConf })
            .then(data => {
                console.log('[ConfirmedSpiders] response:', data);
                if (!data.spiders || data.spiders.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">暂无已确认的蜘蛛 IP</td></tr>';
                    return;
                }
                
                tableBody.innerHTML = data.spiders.map(s => `
                    <tr>
                        <td><input type="checkbox" class="confirmed-check" value="${escHtml(s.ip_address)}" onchange="updateBatchImportBtn()"></td>
                        <td><code>${escHtml(s.ip_address)}</code></td>
                        <td>${escHtml(s.spider_type || '-')}</td>
                        <td><small>${escHtml(s.match_method || '-')}</small></td>
                        <td>${renderConfidence(s.confidence)}</td>
                        <td style="font-size:0.82rem">${s.last_seen || '-'}</td>
                        <td><span class="badge badge-muted">${s.seen_count || 1}</span></td>
                        <td><button class="btn btn-sm btn-primary" onclick="importConfirmedSpider('${escHtml(s.ip_address)}', this)" style="font-size:0.75rem;white-space:nowrap">📥 导入</button></td>
                    </tr>
                `).join('');
                
                document.getElementById('batchImportConfirmedBtn').style.display = 'none';
                document.getElementById('batchDeleteConfirmedBtn').style.display = 'none';
                document.getElementById('selectAllConfirmed').checked = false;
            })
            .catch(err => {
                console.error('[ConfirmedSpiders] load error:', err);
                tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-error">加载失败: ${escHtml(err.message)}</td></tr>`;
            });
    }
    
    function renderConfidence(conf) {
        if (!conf) return '-';
        const map = { verified: '✅ 已验证', high: '🟢 高', medium: '🟡 中', low: '🔴 低' };
        return map[conf] || conf;
    }
    
    window.importConfirmedSpider = function(ip, btn) {
        if (btn) { btn.disabled = true; btn.textContent = '导入中...'; }
        
        apiPost('import_confirmed_spider', { ip_address: ip })
            .then(data => {
                showToast('✅ ' + data.message, 'success');
                if (btn) { btn.textContent = '✅ 已导入'; btn.classList.add('btn-success'); }
                loadSpiderRanges();
            })
            .catch(err => {
                showToast('❌ ' + err.message, 'error');
                if (btn) { btn.disabled = false; btn.textContent = '📥 导入'; }
            });
    };
    
    window.importAllConfirmed = function() {
        if (!confirm('确定将所有已确认蜘蛛 IP 导入到蜘蛛IP管理？')) return;
        
        showToast('正在导入...', 'info');
        apiPost('import_confirmed_spiders')
            .then(data => {
                showToast('✅ ' + data.message, 'success');
                loadConfirmedSpiders();
                loadSpiderRanges();
            })
            .catch(err => showToast('❌ ' + err.message, 'error'));
    };
    
    window.batchImportConfirmed = function() {
        const checked = document.querySelectorAll('.confirmed-check:checked');
        if (checked.length === 0) { showToast('请先选中要导入的 IP', 'warning'); return; }
        
        const ips = Array.from(checked).map(cb => cb.value);
        if (!confirm(`确定将选中的 ${ips.length} 个 IP 导入到蜘蛛IP管理？`)) return;
        
        showToast('正在导入...', 'info');
        apiPost('import_confirmed_spiders', { ip_list: ips })
            .then(data => {
                showToast('✅ ' + data.message, 'success');
                loadConfirmedSpiders();
                loadSpiderRanges();
            })
            .catch(err => showToast('❌ ' + err.message, 'error'));
    };
    
    window.toggleAllConfirmed = function(checkbox) {
        document.querySelectorAll('.confirmed-check').forEach(cb => { cb.checked = checkbox.checked; });
        updateBatchImportBtn();
    };
    
    window.updateBatchImportBtn = function() {
        const checked = document.querySelectorAll('.confirmed-check:checked').length;
        document.getElementById('batchImportConfirmedBtn').style.display = checked > 0 ? '' : 'none';
        document.getElementById('batchDeleteConfirmedBtn').style.display = checked > 0 ? '' : 'none';
    };
    
    window.batchDeleteConfirmed = function() {
        const checked = document.querySelectorAll('.confirmed-check:checked');
        if (checked.length === 0) { showToast('请先选中要删除的 IP', 'warning'); return; }
        
        const ips = Array.from(checked).map(cb => cb.value);
        if (!confirm(`确定删除选中的 ${ips.length} 个已确认蜘蛛 IP？此操作不可撤销。`)) return;
        
        showToast('正在删除...', 'info');
        apiPost('batch_delete_confirmed_spiders', { ip_list: ips })
            .then(data => {
                showToast('✅ ' + data.message, 'success');
                loadConfirmedSpiders();
            })
            .catch(err => showToast('❌ ' + err.message, 'error'));
    };
    
    window.loadConfirmedSpiders = loadConfirmedSpiders;
    
    // ============================================
    // 更新操作
    // ============================================
    window.triggerUpdate = function(type) {
        const names = {
            'google_spiders': 'Google 蜘蛛 IP 段',
            'geoip_db': 'GeoIP 数据库',
        };
        
        if (!confirm(`确定要更新「${names[type] || type}」吗？`)) return;
        
        showToast('正在更新...', 'info');
        
        apiPost('trigger_update', { update_type: type })
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + (data.message || '更新完成'), 'success');
                } else {
                    showToast('⚠️ ' + (data.message || '更新可能存在问题'), 'warning');
                }
                // 刷新仪表盘数据
                setTimeout(() => location.reload(), 1500);
            })
            .catch(err => showToast('❌ 更新失败: ' + err.message, 'error'));
    };
    
    // ============================================
    // 从已确认蜘蛛 IP 导入到 IP 段库
    // ============================================
    window.importConfirmedSpiders = function() {
        if (!confirm('确定要从「已确认的百度蜘蛛 IP 列表」中导入所有 IP 到蜘蛛 IP 段库吗？\n\n已存在的 IP 段将自动跳过。')) return;
        
        showToast('正在导入...', 'info');
        
        apiPost('import_confirmed_spiders')
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                } else {
                    showToast('⚠️ ' + (data.message || data.error || '导入失败'), 'warning');
                }
                // 刷新页面以更新蜘蛛 IP 段列表
                setTimeout(() => location.reload(), 2000);
            })
            .catch(err => showToast('❌ 导入失败: ' + err.message, 'error'));
    };
    
    // ============================================
    // 数据清理
    // ============================================
    window.cleanOldData = function() {
        if (!confirm('确定清理过期数据（超过保留天数的日志）？')) return;
        
        apiPost('clean_old_data')
            .then(data => {
                showToast(data.success ? '清理完成' : '清理失败', data.success ? 'success' : 'error');
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.clearUpdateRecords = function() {
        if (!confirm('确定要清除所有更新历史日志吗？此操作不可撤销。')) return;
        
        apiPost('clear_update_records')
            .then(data => {
                showToast(data.message || '已清除', 'success');
                setTimeout(() => location.reload(), 800);
            })
            .catch(err => showToast(err.message, 'error'));
    };
    
    window.showMaxmindKeyInput = function() {
        document.getElementById('maxmindKeyDisplay').style.display = 'none';
        document.getElementById('maxmindKeyInput').style.display = '';
        const input = document.getElementById('set_maxmind_key');
        // 保留当前值，用户可直接编辑；如需清空请手动删除
        input.focus();
        input.select();
    };
    
    // ============================================
    // API 图表
    // ============================================
    function loadApiChart() {
        const container = document.getElementById('apiChart');
        if (!container) return;
        
        container.innerHTML = '<div class="chart-loading">加载中...</div>';
        
        apiGet('get_api_stats', { days: 30 })
            .then(data => {
                if (!data.stats || Object.keys(data.stats).length === 0) {
                    container.innerHTML = '<div class="chart-loading">暂无数据</div>';
                    return;
                }
                
                const values = Object.values(data.stats);
                const maxVal = Math.max(...values, 1);
                
                const bars = Object.entries(data.stats).map(([date, count]) => {
                    const height = Math.max((count / maxVal) * 160, 2);
                    const shortDate = date.slice(5); // MM-DD
                    return `<div class="chart-bar" style="height:${height}px;" title="${date}: ${count} 次调用">${count > 0 ? `<span style="font-size:8px;position:absolute;bottom:-16px;left:50%;transform:translateX(-50%);color:var(--admin-text-muted)">${shortDate}</span>` : ''}</div>`;
                }).join('');
                
                container.innerHTML = `<div class="chart-bars">${bars}</div>`;
            })
            .catch(() => {
                container.innerHTML = '<div class="chart-loading">加载失败</div>';
            });
    }
    
    // ============================================
    // 修改密码
    // ============================================
    window.changePassword = function(e) {
        e.preventDefault();
        
        const oldPwd = document.getElementById('oldPassword').value;
        const newPwd = document.getElementById('newPassword').value;
        const confirmPwd = document.getElementById('confirmPassword').value;
        const statusEl = document.getElementById('pwdStatus');
        
        if (!oldPwd || !newPwd || !confirmPwd) {
            if (statusEl) { statusEl.textContent = '请填写所有字段'; statusEl.style.color = 'var(--admin-error)'; }
            return;
        }
        
        if (newPwd !== confirmPwd) {
            if (statusEl) { statusEl.textContent = '两次密码不一致'; statusEl.style.color = 'var(--admin-error)'; }
            return;
        }
        
        if (newPwd.length < 6) {
            if (statusEl) { statusEl.textContent = '密码至少6位'; statusEl.style.color = 'var(--admin-error)'; }
            return;
        }
        
        apiPost('change_password', { old_password: oldPwd, new_password: newPwd })
            .then(data => {
                if (statusEl) {
                    statusEl.textContent = '✅ ' + data.message;
                    statusEl.style.color = 'var(--admin-success)';
                }
                showToast(data.message, 'success');
                document.getElementById('changePwdForm').reset();
            })
            .catch(err => {
                if (statusEl) {
                    statusEl.textContent = '❌ ' + err.message;
                    statusEl.style.color = 'var(--admin-error)';
                }
                showToast(err.message, 'error');
            });
    };
    
    // ============================================
    // 分页渲染
    // ============================================
    function renderPagination(containerId, totalPages, currentPage, callback) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '';
        html += `<button ${currentPage <= 1 ? 'disabled' : ''} onclick="${callback.name}(${currentPage - 1})">« 上一页</button>`;
        
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                html += `<button class="${i === currentPage ? 'active' : ''}" onclick="${callback.name}(${i})">${i}</button>`;
            } else if (i === currentPage - 3 || i === currentPage + 3) {
                html += '<button disabled>...</button>';
            }
        }
        
        html += `<button ${currentPage >= totalPages ? 'disabled' : ''} onclick="${callback.name}(${currentPage + 1})">下一页 »</button>`;
        container.innerHTML = html;
    }
    
    // ============================================
    // API 请求辅助函数
    // ============================================
    function apiGet(action, params = {}) {
        const url = new URL(window.ADMIN_AJAX_URL, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
        
        return fetch(url.toString(), {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
        .then(handleResponse);
    }
    
    function apiPost(action, data = {}) {
        const url = new URL(window.ADMIN_AJAX_URL, window.location.href);
        url.searchParams.set('action', action);
        
        // 统一使用 JSON body 发送 POST 请求
        const payload = {
            action: action,
            csrf_token: window.ADMIN_CSRF || '',
            ...data
        };
        
        return fetch(url.toString(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.ADMIN_CSRF || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        })
        .then(handleResponse);
    }
    
    function handleResponse(response) {
        if (!response.ok) {
            return response.json().then(data => {
                throw new Error(data.error || data.message || `HTTP ${response.status}`);
            }).catch(err => {
                if (err.message && !err.message.startsWith('HTTP')) throw err;
                throw new Error(`请求失败 (${response.status})`);
            });
        }
        return response.json().then(data => {
            if (data.error) throw new Error(data.error);
            return data;
        });
    }
    
    // ============================================
    // Toast 通知
    // ============================================
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // ============================================
    // 辅助函数
    // ============================================
    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    /**
     * Nginx 配置弹窗 — 显示需要添加到 nginx 配置的 location 块
     */
    function showNginxConfigModal(cfg) {
        // 移除已有弹窗
        const existing = document.getElementById('nginxConfigModal');
        if (existing) existing.remove();
        
        const configText = cfg.config_content || cfg.message || '';
        const confFile = cfg.conf_file || 'nginx-admin-route.conf';
        
        const overlay = document.createElement('div');
        overlay.id = 'nginxConfigModal';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
        
        overlay.innerHTML = `
            <div style="background:var(--admin-card-bg, #1e293b);border:1px solid var(--admin-border, #334155);border-radius:12px;max-width:700px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.5)">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px 0">
                    <h3 style="margin:0;font-size:1.1rem;color:var(--admin-text, #e2e8f0)">🖥️ Nginx 配置更新指引</h3>
                    <button onclick="this.closest('#nginxConfigModal').remove()" style="background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1">&times;</button>
                </div>
                <div style="padding:16px 24px">
                    <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:12px">
                        ⚠️ Nginx 不支持 <code>.htaccess</code>。系统已自动创建物理目录，<strong style="color:#10b981">自定义路径可直接访问</strong>。<br>
                        以下为可选配置：用于封锁原 <code>/admin/</code> 路径（添加后原路径返回 404）。
                    </p>
                    <div style="position:relative">
                        <pre style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;font-size:0.8rem;overflow-x:auto;line-height:1.5;margin:0;border:1px solid #334155">${escHtml(configText)}</pre>
                        <button onclick="navigator.clipboard.writeText(this.parentElement.querySelector('pre').textContent).then(()=>window.showToast&&window.showToast('已复制到剪贴板','success'))" 
                            style="position:absolute;top:8px;right:8px;background:rgba(255,255,255,0.1);border:1px solid #475569;color:#e2e8f0;border-radius:6px;padding:4px 10px;font-size:0.75rem;cursor:pointer">📋 复制</button>
                    </div>
                    <div style="margin-top:12px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:12px;font-size:0.82rem;color:#fbbf24">
                        ⚡ 添加配置后请执行: <code style="color:#e2e8f0;background:rgba(0,0,0,0.3);padding:2px 6px;border-radius:4px">nginx -t && nginx -s reload</code>
                    </div>
                    <p style="color:#64748b;font-size:0.78rem;margin-top:10px">
                        添加配置后原 <code>/admin/</code> 将返回 404，仅可通过新路径访问后台。<br>
                        💡 如忘记路径，查看项目根目录的 <code>.admin_path</code> 文件。
                    </p>
                </div>
                <div style="padding:0 24px 16px;text-align:right">
                    <button onclick="this.closest('#nginxConfigModal').remove()" style="background:var(--admin-primary,#6366f1);color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:0.85rem;cursor:pointer">我知道了</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        // 点击遮罩关闭
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.remove();
        });
        
        // 同时显示简要 toast
        showToast('⚠️ Nginx 配置已生成，请查看弹窗指引', 'warning');
    }
    
    function formatIPRange(ipRange) {
        if (!ipRange) return '';
        // 去除单 IP 的 /32 (IPv4) 和 /128 (IPv6) 后缀，使显示更直观
        return ipRange.replace(/\/32$/, '').replace(/\/128$/, '');
    }
    
    // 暴露到全局
    window.showToast = showToast;
    window.renderDailyChart = renderDailyChart;
    
})();
