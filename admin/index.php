<?php
/**
 * 后台管理 - 主控制台 (SPA 单页应用)
 * 
 * 2026 现代方案：
 * - 侧边栏导航 + 多 Tab 内容区
 * - 原生 JS 实现 Tab 切换，无框架依赖
 * - 支持深色/浅色主题切换
 * - 所有数据通过 AJAX 动态加载
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../AdminAuth.php';
require_once __DIR__ . '/../AdminSettings.php';

// 确保数据库已初始化
if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/../init_db.php';
}

$auth = new AdminAuth();
$auth->requireLogin();

$settings = new AdminSettings();
$dashboard = $settings->getDashboardData();
$dailyStats = $settings->getDailyStats(7);
$adminTheme = $settings->get('admin_theme', 'auto');

// 获取用户名
$username = $_SESSION['admin_username'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?php echo $adminTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - 蜘蛛识别查询系统</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="../assets/css/admin.css?v=2.0.0">
    <style>
        /* 更新卡片信息行布局 */
        .update-card {
            display: flex;
            flex-direction: column;
            gap: 10px;
            text-align: left;
            min-width: 260px;
            flex: 1 1 260px;
            max-width: 380px;
        }
        .update-card h4 {
            margin: 0 0 6px 0;
        }
        .update-card button {
            margin-top: auto;
            width: 100%;
            justify-content: center;
        }
        /* 更新管理 Tab 描述文字与卡片间距 */
        #tab-updates .dash-card > .text-muted {
            margin-bottom: 20px;
        }
        .card-badge {
            display: inline-block;
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 10px;
            white-space: nowrap;
        }
        .card-badge-free {
            color: var(--admin-text-dim, #94a3b8);
            background: rgba(16,185,129,0.1);
        }
        .card-badge-warn {
            color: var(--admin-warning, #f59e0b);
            background: rgba(245,158,11,0.1);
        }
        .update-card .info-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 3px 0;
            font-size: 0.82rem;
        }
        .update-card .info-label {
            flex-shrink: 0;
            min-width: 76px;
            color: var(--admin-text-dim, #94a3b8);
            font-size: 0.78rem;
        }
        .update-card .info-value {
            color: var(--admin-text, #e2e8f0);
            font-weight: 500;
            word-break: break-word;
        }
        .update-card .info-note {
            margin-top: 6px;
            padding: 5px 10px;
            font-size: 0.75rem;
            color: var(--admin-warning, #f59e0b);
            background: rgba(245,158,11,0.08);
            border-radius: 6px;
            border-left: 3px solid var(--admin-warning, #f59e0b);
            line-height: 1.5;
        }
        .update-card-header {
            padding-bottom: 8px;
            border-bottom: 1px solid var(--admin-border, rgba(255,255,255,0.06));
        }
        .update-card-info {
            flex: 1;
        }
    </style>
</head>
<body>
    <!-- 侧边栏 -->
    <aside class="admin-sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">🕷️</div>
            <div class="brand-text">
                <span class="brand-title">蜘蛛识别系统</span>
                <span class="brand-sub">管理控制台</span>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <a href="#dashboard" class="nav-item active" data-tab="dashboard">
                <span class="nav-icon">📊</span>
                <span class="nav-label">仪表盘</span>
            </a>
            <a href="#settings" class="nav-item" data-tab="settings">
                <span class="nav-icon">⚙️</span>
                <span class="nav-label">系统设置</span>
            </a>
            <a href="#rules" class="nav-item" data-tab="rules">
                <span class="nav-icon">🧬</span>
                <span class="nav-label">识别规则</span>
            </a>
            <a href="#updates" class="nav-item" data-tab="updates">
                <span class="nav-icon">🔄</span>
                <span class="nav-label">更新管理</span>
            </a>
            <a href="#spiders" class="nav-item" data-tab="spiders">
                <span class="nav-icon">🕸️</span>
                <span class="nav-label">蜘蛛IP管理</span>
            </a>
            <a href="#confirmed" class="nav-item" data-tab="confirmed">
                <span class="nav-icon">✅</span>
                <span class="nav-label">已确认蜘蛛</span>
            </a>
            <a href="#logs" class="nav-item" data-tab="logs">
                <span class="nav-icon">📋</span>
                <span class="nav-label">查询日志</span>
            </a>
            <a href="#api" class="nav-item" data-tab="api">
                <span class="nav-icon">🔌</span>
                <span class="nav-label">API 管理</span>
            </a>
            <a href="#account" class="nav-item" data-tab="account">
                <span class="nav-icon">👤</span>
                <span class="nav-label">账户安全</span>
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <div class="theme-toggle" id="themeToggle" title="切换主题">
                <span class="theme-icon-light">☀️</span>
                <span class="theme-icon-dark">🌙</span>
            </div>
            <a href="../index.php" class="footer-link" target="_blank" title="打开前台">🏠</a>
            <a href="logout.php" class="footer-link footer-logout" title="退出登录">🚪</a>
        </div>
    </aside>
    
    <!-- 移动端汉堡菜单 -->
    <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="菜单">☰</button>
    
    <!-- 主内容区 -->
    <main class="admin-main">
        <!-- 顶部栏 -->
        <header class="admin-topbar">
            <div class="topbar-left">
                <h1 id="pageTitle">📊 仪表盘</h1>
            </div>
            <div class="topbar-right">
                <span class="topbar-time" id="topbarTime">--</span>
                <div class="topbar-user">
                    <span class="user-avatar">👤</span>
                    <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                </div>
            </div>
        </header>
        
        <!-- Tab 内容区 -->
        <div class="admin-content">
            <!-- ========== 仪表盘 Tab ========== -->
            <section class="tab-content active" id="tab-dashboard">
                <!-- 统计卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(99,102,241,0.15);color:#6366f1;">📡</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($dashboard['total_ranges']); ?></span>
                            <span class="stat-label">活跃IP段</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(16,185,129,0.15);color:#10b981;">🔍</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($dashboard['total_queries']); ?></span>
                            <span class="stat-label">总查询次数</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;">🕷️</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($dashboard['spider_queries']); ?></span>
                            <span class="stat-label">蜘蛛查询</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(139,92,246,0.15);color:#8b5cf6;">✅</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($dashboard['confirmed_spiders']); ?></span>
                            <span class="stat-label">已确认蜘蛛</span>
                        </div>
                    </div>
                </div>
                
                <!-- 今日统计 + 系统状态 -->
                <div class="dash-grid-2col">
                    <div class="dash-card">
                        <h3>📈 近 7 天查询趋势</h3>
                        <canvas id="dailyChart" width="400" height="200" style="width:100%;max-height:220px;margin:8px 0"></canvas>
                        <script>window.__dailyChartData = <?php echo json_encode($dailyStats, JSON_UNESCAPED_UNICODE); ?>;</script>
                        <div class="chart-legend" style="display:flex;gap:16px;justify-content:center;font-size:0.78rem">
                            <span style="display:flex;align-items:center;gap:4px">
                                <span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--admin-primary,#6366f1)"></span> 总查询
                            </span>
                            <span style="display:flex;align-items:center;gap:4px">
                                <span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--admin-warning,#f59e0b)"></span> 蜘蛛查询
                            </span>
                        </div>
                    </div>
                    <div class="dash-card">
                        <h3>🖥️ 系统状态</h3>
                        <div class="dash-stat-row">
                            <span>🗺️ GeoLite2 数据库</span>
                            <strong class="<?php echo $dashboard['geoip_exists'] ? 'text-success' : 'text-error'; ?>">
                                <?php echo $dashboard['geoip_exists'] ? "{$dashboard['geoip_size_mb']}MB" : '未安装'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🌍 DB-IP Lite 数据库</span>
                            <strong class="<?php echo $dashboard['dbip_exists'] ? 'text-success' : 'text-error'; ?>">
                                <?php echo $dashboard['dbip_exists'] ? "{$dashboard['dbip_size_mb']}MB" : '未安装'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>📍 IP2Location 数据库</span>
                            <strong class="<?php echo $dashboard['ip2l_exists'] ? 'text-success' : 'text-error'; ?>">
                                <?php echo $dashboard['ip2l_exists'] ? "{$dashboard['ip2l_size_mb']}MB" : '未安装'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🕷️ Google 蜘蛛缓存</span>
                            <strong class="<?php echo $dashboard['google_cache_exists'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['google_cache_exists'] ? number_format($dashboard['google_cache_count']) . ' 条' : '未缓存'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🧬 rDNS 规则数</span>
                            <strong><?php echo number_format($dashboard['rdns_rule_count']); ?></strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🕴️ UA 规则数</span>
                            <strong><?php echo number_format($dashboard['ua_rule_count']); ?></strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>💾 SQLite 数据库</span>
                            <strong><?php echo $dashboard['db_size_kb']; ?> KB</strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🐘 PHP 版本</span>
                            <strong><?php echo htmlspecialchars($dashboard['php_version']); ?></strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🔄 自动更新</span>
                            <strong class="<?php echo $dashboard['auto_update_enabled'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['auto_update_enabled'] ? '已启用' : '已禁用'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🕷️ 蜘蛛检测</span>
                            <strong class="<?php echo $dashboard['enable_spider_check'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['enable_spider_check'] ? '已启用' : '已禁用'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🔍 反向DNS验证</span>
                            <strong class="<?php echo $dashboard['enable_rdns_check'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['enable_rdns_check'] ? '已启用' : '已禁用'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🌐 地理位置查询</span>
                            <strong class="<?php echo $dashboard['enable_geolocation'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['enable_geolocation'] ? '已启用' : '已禁用'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>🔌 API 接口</span>
                            <strong class="<?php echo $dashboard['enable_api'] ? 'text-success' : 'text-muted'; ?>">
                                <?php echo $dashboard['enable_api'] ? '已启用' : '已禁用'; ?>
                            </strong>
                        </div>
                        <div class="dash-stat-row">
                            <span>📅 最后数据更新</span>
                            <strong><?php echo htmlspecialchars($dashboard['last_update']); ?></strong>
                        </div>
                    </div>
                </div>
                
                <!-- 快捷操作 -->
                <div class="dash-card">
                    <h3>⚡ 快捷操作</h3>
                    <div class="quick-actions">
                        <button class="quick-btn" onclick="triggerUpdate('google_spiders')">🔄 更新Google蜘蛛IP</button>
                        <button class="quick-btn" onclick="triggerUpdate('geoip_db')">🗺️ 更新GeoIP数据库</button>
                        <button class="quick-btn" onclick="importConfirmedSpiders()">📥 从已确认蜘蛛导入IP段</button>
                        <button class="quick-btn quick-btn-danger" onclick="cleanOldData()">🧹 清理过期数据</button>
                    </div>
                </div>
            </section>
            
            <!-- ========== 系统设置 Tab ========== -->
            <section class="tab-content" id="tab-settings">
                <div class="dash-card">
                    <form id="settingsForm" class="settings-form">
                        <div class="settings-section">
                            <h4>🌐 基本设置</h4>
                            <div class="form-row-2col">
                                <div class="form-group">
                                    <label for="set_site_name">网站名称</label>
                                    <input type="text" id="set_site_name" data-key="site_name" value="<?php echo htmlspecialchars($settings->get('site_name', '')); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="set_site_description">网站描述</label>
                                    <input type="text" id="set_site_description" data-key="site_description" value="<?php echo htmlspecialchars($settings->get('site_description', '')); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="set_admin_path">🔒 后台管理访问路径</label>
                                <input type="text" id="set_admin_path" data-key="admin_path" value="<?php echo htmlspecialchars($settings->get('admin_path', 'admin')); ?>" placeholder="admin" pattern="[a-zA-Z0-9_\-]+" required>
                                <span class="form-hint">
                                    ⚠️ 修改后系统自动创建物理目录，可通过 <code>/新路径/</code> 直接访问后台。<br>
                                    仅允许字母、数字、下划线和连字符。Apache 自动封锁 <code>/admin/</code>；Nginx 请按弹窗指引封锁原路径。<br>
                                    <strong>保存后请使用新地址重新登录。</strong><br>
                                    💡 如忘记路径，可查看项目根目录下的 <code>.admin_path</code> 文件。
                                </span>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>🗺️ MaxMind GeoIP 配置</h4>
                            <div class="form-group">
                                <label for="set_maxmind_key">MaxMind License Key</label>
                                <?php $currentKey = $settings->get('maxmind_license_key', ''); ?>
                                <?php if (!empty($currentKey)): ?>
                                <div id="maxmindKeyDisplay" style="display:flex;align-items:center;gap:8px">
                                    <code class="key-badge">••••••••••••<?php echo htmlspecialchars(substr($currentKey, -4)); ?></code>
                                    <button type="button" class="btn btn-sm" onclick="showMaxmindKeyInput()" style="white-space:nowrap">✏️ 更改</button>
                                </div>
                                <div id="maxmindKeyInput" style="display:none">
                                    <input type="text" id="set_maxmind_key" data-key="maxmind_license_key" value="<?php echo htmlspecialchars($currentKey); ?>" placeholder="请输入新的 MaxMind License Key" class="key-input">
                                </div>
                                <?php else: ?>
                                <input type="text" id="set_maxmind_key" data-key="maxmind_license_key" value="" placeholder="请输入您的 MaxMind License Key（留空则无法更新 GeoIP 数据库）" style="font-family:monospace;font-size:0.85rem">
                                <?php endif; ?>
                                <span class="form-hint">
                                    免费申请地址：<a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener noreferrer">https://www.maxmind.com/en/geolite2/signup</a>
                                    &nbsp;·&nbsp;注册后在 Account → Manage License Keys 中获取
                                </span>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>📍 IP2Location 配置</h4>
                            <div class="form-group">
                                <label for="set_ip2location_token">IP2Location Token</label>
                                <?php 
                                $ip2lToken = $settings->get('ip2location_token', '');
                                if ($ip2lToken):
                                    $masked = substr($ip2lToken, 0, 4) . str_repeat('•', max(0, strlen($ip2lToken) - 8)) . substr($ip2lToken, -4);
                                ?>
                                <div id="ip2lTokenDisplay" style="display:flex;align-items:center;gap:8px">
                                    <code class="key-badge"><?php echo htmlspecialchars($masked); ?></code>
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('ip2lTokenDisplay').style.display='none';document.getElementById('ip2lTokenInput').style.display='block'" style="white-space:nowrap">✏️ 更改</button>
                                </div>
                                <div id="ip2lTokenInput" style="display:none">
                                    <input type="text" id="set_ip2location_token" data-key="ip2location_token" value="<?php echo htmlspecialchars($ip2lToken); ?>" placeholder="请输入您的 IP2Location Token" class="key-input">
                                </div>
                                <?php else: ?>
                                <input type="text" id="set_ip2location_token" data-key="ip2location_token" value="" placeholder="请输入您的 IP2Location Token（用于下载 IP2Location LITE 数据库）" style="font-family:monospace;font-size:0.85rem">
                                <?php endif; ?>
                                <span class="form-hint">
                                    免费注册地址：<a href="https://lite.ip2location.com/" target="_blank" rel="noopener noreferrer">https://lite.ip2location.com/</a>
                                    &nbsp;·&nbsp;注册后在下载页面获取 Token
                                </span>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>☁️ IP2Location.io API 配置</h4>
                            <div class="form-group">
                                <label for="set_ip2location_io_key">IP2Location.io API Key</label>
                                <?php 
                                $ip2ioKey = $settings->get('ip2location_io_key', '');
                                if ($ip2ioKey):
                                    $masked = substr($ip2ioKey, 0, 4) . str_repeat('•', max(0, strlen($ip2ioKey) - 8)) . substr($ip2ioKey, -4);
                                ?>
                                <div id="ip2ioKeyDisplay" style="display:flex;align-items:center;gap:8px">
                                    <code class="key-badge"><?php echo htmlspecialchars($masked); ?></code>
                                    <button type="button" class="btn btn-sm" onclick="document.getElementById('ip2ioKeyDisplay').style.display='none';document.getElementById('ip2ioKeyInput').style.display='block'" style="white-space:nowrap">✏️ 更改</button>
                                </div>
                                <div id="ip2ioKeyInput" style="display:none">
                                    <input type="text" id="set_ip2location_io_key" data-key="ip2location_io_key" value="<?php echo htmlspecialchars($ip2ioKey); ?>" placeholder="请输入您的 IP2Location.io API Key" class="key-input">
                                </div>
                                <?php else: ?>
                                <input type="text" id="set_ip2location_io_key" data-key="ip2location_io_key" value="" placeholder="请输入您的 IP2Location.io API Key（用于在线 IP 信息查询）" style="font-family:monospace;font-size:0.85rem">
                                <?php endif; ?>
                                <span class="form-hint">
                                    免费注册地址：<a href="https://www.ip2location.io/" target="_blank" rel="noopener noreferrer">https://www.ip2location.io/</a>
                                    &nbsp;·&nbsp;注册后在 Dashboard 获取 API Key
                                </span>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>🗄️ IP 数据库开关</h4>
                            <p class="text-muted" style="font-size:0.8rem;margin-bottom:14px">控制各 IP 地理位置数据库的使用优先级，禁用后对应数据库不参与查询。</p>
                            <div class="db-switches">
                                <label class="db-switch-item">
                                    <input type="checkbox" data-key="db_geolite2_enabled" data-type="bool" <?php echo $settings->get('db_geolite2_enabled', true) ? 'checked' : ''; ?>>
                                    <span class="db-switch-info">
                                        <strong>🗺️ GeoLite2-City</strong>
                                        <span class="db-desc">MaxMind 免费库，首选数据源</span>
                                    </span>
                                </label>
                                <label class="db-switch-item">
                                    <input type="checkbox" data-key="db_dbip_enabled" data-type="bool" <?php echo $settings->get('db_dbip_enabled', true) ? 'checked' : ''; ?>>
                                    <span class="db-switch-info">
                                        <strong>🌍 DB-IP Lite</strong>
                                        <span class="db-desc">免费城市库，第2回退</span>
                                    </span>
                                </label>
                                <label class="db-switch-item">
                                    <input type="checkbox" data-key="db_ip2location_enabled" data-type="bool" <?php echo $settings->get('db_ip2location_enabled', false) ? 'checked' : ''; ?>>
                                    <span class="db-switch-info">
                                        <strong>📍 IP2Location LITE</strong>
                                        <span class="db-desc">免费本地库，第3回退</span>
                                    </span>
                                </label>
                                <label class="db-switch-item">
                                    <input type="checkbox" data-key="db_ip2location_io_enabled" data-type="bool" <?php echo $settings->get('db_ip2location_io_enabled', false) ? 'checked' : ''; ?>>
                                    <span class="db-switch-info">
                                        <strong>☁️ IP2Location.io API</strong>
                                        <span class="db-desc">在线查询，第1回退（需配 API Key）</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>🕸 百度蜘蛛默认 IP 段</h4>
                            <p class="text-muted" style="font-size:0.8rem;margin-bottom:12px">系统初始化时自动导入的百度蜘蛛 IP 段（CIDR 格式）。修改后需在「🕸️ 蜘蛛IP管理」中手动同步。</p>
                            <div class="form-group">
                                <textarea id="set_baidu_ranges" data-key="baidu_default_ranges" data-type="text" rows="10" placeholder="每行一个 CIDR，例如：&#10;116.179.32.0/24&#10;180.76.0.0/16" style="font-family:monospace;font-size:0.82rem"><?php
                                    $ranges = $settings->get('baidu_default_ranges', '');
                                    if (empty($ranges)) {
                                        echo htmlspecialchars(implode("\n", getBaiduDefaultRanges()));
                                    } else {
                                        echo htmlspecialchars($ranges);
                                    }
                                ?></textarea>
                                <span class="form-hint">每行一个 CIDR 格式 IP 段。保存后请在下方「验证IP段」确认所有 IP 均为可信百度蜘蛛。</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" onclick="validateBaiduRanges()" style="margin-top:8px">🔍 验证 IP 段可信度</button>
                            <span id="baiduRangesValidateResult" style="margin-left:12px;font-size:0.82rem"></span>
                        </div>
                        
                        <div class="settings-section">
                            <h4>🔄 更新设置</h4>
                            <div class="form-row-2col">
                                <div class="form-group">
                                    <label for="set_google_interval">Google蜘蛛更新间隔（秒）</label>
                                    <input type="number" id="set_google_interval" data-key="google_spider_update_interval" data-type="int" value="<?php echo (int)$settings->get('google_spider_update_interval', 604800); ?>">
                                    <span class="form-hint">默认604800秒 = 7天</span>
                                </div>
                                <div class="form-group">
                                    <label for="set_geoip_interval">GeoLite2 更新间隔（秒）</label>
                                    <input type="number" id="set_geoip_interval" data-key="geoip_update_interval" data-type="int" value="<?php echo (int)$settings->get('geoip_update_interval', 2592000); ?>">
                                    <span class="form-hint">默认2592000秒 = 30天</span>
                                </div>
                                <div class="form-group">
                                    <label for="set_dbip_interval">DB-IP Lite 更新间隔（秒）</label>
                                    <input type="number" id="set_dbip_interval" data-key="dbip_update_interval" data-type="int" value="<?php echo (int)$settings->get('dbip_update_interval', 2592000); ?>">
                                    <span class="form-hint">默认2592000秒 = 30天</span>
                                </div>
                                <div class="form-group">
                                    <label for="set_ip2l_interval">IP2Location LITE 更新间隔（秒）</label>
                                    <input type="number" id="set_ip2l_interval" data-key="ip2location_update_interval" data-type="int" value="<?php echo (int)$settings->get('ip2location_update_interval', 2592000); ?>">
                                    <span class="form-hint">默认2592000秒 = 30天（需先配置 Token）</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="auto_update_enabled" data-type="bool" <?php echo $settings->get('auto_update_enabled', true) ? 'checked' : ''; ?>>
                                    <span>启用自动更新</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>🔧 功能开关</h4>
                            <div class="toggle-grid">
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="enable_api" data-type="bool" <?php echo $settings->get('enable_api', true) ? 'checked' : ''; ?>>
                                    <span>启用API接口</span>
                                </label>
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="enable_geolocation" data-type="bool" <?php echo $settings->get('enable_geolocation', true) ? 'checked' : ''; ?>>
                                    <span>启用IP地理位置查询</span>
                                </label>
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="enable_rdns_check" data-type="bool" <?php echo $settings->get('enable_rdns_check', true) ? 'checked' : ''; ?>>
                                    <span>启用反向DNS验证</span>
                                </label>
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="enable_spider_check" data-type="bool" <?php echo $settings->get('enable_spider_check', true) ? 'checked' : ''; ?>>
                                    <span>启用蜘蛛检测</span>
                                </label>
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="maintenance_mode" data-type="bool" <?php echo $settings->get('maintenance_mode', false) ? 'checked' : ''; ?>>
                                    <span>维护模式（仅管理员可访问）</span>
                                </label>
                                <label class="toggle-label">
                                    <input type="checkbox" data-key="auto_import_confirmed" data-type="bool" <?php echo $settings->get('auto_import_confirmed', false) ? 'checked' : ''; ?>>
                                    <span>自动导入已确认蜘蛛到IP管理</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <h4>📊 高级设置</h4>
                            <div class="form-row-2col">
                                <div class="form-group">
                                    <label for="set_log_retention">日志保留天数</label>
                                    <input type="number" id="set_log_retention" data-key="query_log_retention_days" data-type="int" value="<?php echo (int)$settings->get('query_log_retention_days', 90); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="set_max_query">每分钟最大查询数</label>
                                    <input type="number" id="set_max_query" data-key="max_query_per_minute" data-type="int" value="<?php echo (int)$settings->get('max_query_per_minute', 30); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="set_theme">后台主题</label>
                                <select id="set_theme" data-key="admin_theme">
                                    <option value="auto" <?php echo $settings->get('admin_theme') === 'auto' ? 'selected' : ''; ?>>跟随系统</option>
                                    <option value="light" <?php echo $settings->get('admin_theme') === 'light' ? 'selected' : ''; ?>>浅色模式</option>
                                    <option value="dark" <?php echo $settings->get('admin_theme') === 'dark' ? 'selected' : ''; ?>>深色模式</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg">💾 保存所有设置</button>
                        <span class="save-status" id="settingsSaveStatus"></span>
                    </form>
                </div>
            </section>
            
            <!-- ========== 识别规则 Tab ========== -->
            <section class="tab-content" id="tab-rules">
                <!-- 反向DNS 自定义识别规则 -->
                <div class="dash-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                        <div>
                            <h3 style="margin:0">🔍 反向DNS 自定义识别规则</h3>
                            <p class="text-muted" style="font-size:0.8rem;margin:4px 0 0 0">
                                通过反向DNS主机名关键词匹配识别蜘蛛类型。支持<strong>包含匹配</strong>、<strong>正则表达式</strong>和<strong>精确匹配</strong>三种模式。<br>
                                规则按排序号从小到大依次匹配，匹配到第一条即停止。内置规则已预置 Google、Baidu、Bing、Yandex 等常见蜘蛛。
                            </p>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button class="btn btn-sm btn-danger" onclick="batchDeleteRdnsRules()" id="batchDeleteRdnsBtn" style="display:none">🗑️ 批量删除</button>
                            <button class="btn btn-sm btn-ghost" onclick="resetRdnsRules()" title="恢复系统内置默认规则">📥 导入默认规则</button>
                            <button class="btn btn-sm btn-primary" onclick="showAddRdnsRuleModal()">➕ 添加规则</button>
                        </div>
                    </div>
                    
                    <div class="table-wrap" id="rdnsRulesTable">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="selectAllRdnsRules" onchange="toggleAllRdnsRules(this)" title="全选/取消"></th>
                                    <th>排序</th>
                                    <th>主机名关键词</th>
                                    <th>蜘蛛类型</th>
                                    <th>匹配模式</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- User-Agent 自定义识别规则 -->
                <div class="dash-card" style="margin-top:24px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                        <div>
                            <h3 style="margin:0">🕴️ User-Agent 自定义识别规则</h3>
                            <p class="text-muted" style="font-size:0.8rem;margin:4px 0 0 0">
                                通过 User-Agent 字符串中的关键词匹配识别蜘蛛类型。支持<strong>包含匹配</strong>（不区分大小写）和<strong>正则表达式</strong>两种模式。<br>
                                规则按排序号从小到大依次匹配，匹配到第一条即停止。系统内置预设了百度、Google 等常见蜘蛛的 UA 关键词。
                            </p>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button class="btn btn-sm btn-danger" onclick="batchDeleteUaRules()" id="batchDeleteUaBtn" style="display:none">🗑️ 批量删除</button>
                            <button class="btn btn-sm btn-ghost" onclick="resetUaRules()" title="恢复系统内置默认规则">📥 导入默认规则</button>
                            <button class="btn btn-sm btn-primary" onclick="showAddUaRuleModal()">➕ 添加规则</button>
                        </div>
                    </div>
                    
                    <div class="table-wrap" id="uaRulesTable">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="selectAllUaRules" onchange="toggleAllUaRules(this)" title="全选/取消"></th>
                                    <th>排序</th>
                                    <th>UA 关键词</th>
                                    <th>蜘蛛类型</th>
                                    <th>匹配模式</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- rDNS 规则编辑弹窗 -->
                <div class="modal-overlay" id="rdnsRuleModal" style="display:none;">
                    <div class="modal">
                        <div class="modal-header">
                            <h3 id="rdnsRuleModalTitle">添加规则</h3>
                            <button class="modal-close" onclick="closeModal('rdnsRuleModal')">✕</button>
                        </div>
                        <div class="modal-body">
                            <form id="rdnsRuleForm" onsubmit="saveRdnsRule(event)">
                                <input type="hidden" id="rdnsRuleId" value="">
                                <div class="form-group">
                                    <label for="rdnsRulePattern">主机名关键词 *</label>
                                    <textarea id="rdnsRulePattern" placeholder="每行一个规则，格式：关键词|蜘蛛类型|匹配模式&#10;例如：&#10;googlebot.com|Googlebot|contains&#10;crawl\\.baidu\\.com|Baiduspider|regex&#10;r2---sn-xxxx.googlevideo.com|Googlebot|contains" rows="5" required></textarea>
                                    <span class="form-hint">
                                        <strong>批量添加</strong>：每行一个规则，用 <code>|</code> 分隔：<code>关键词|蜘蛛类型|匹配模式</code><br>
                                        匹配模式可选：<code>contains</code>（默认）/ <code>regex</code> / <code>exact</code><br>
                                        <strong>单条添加</strong>：仅输入一行，蜘蛛类型从下方选择，匹配模式从下方选择
                                    </span>
                                </div>
                                <div class="form-group">
                                    <label for="rdnsRuleType">蜘蛛类型 *</label>
                                    <input type="text" id="rdnsRuleType" placeholder="例如: Googlebot、CustomCrawler" required>
                                </div>
                                <div class="form-group">
                                    <label for="rdnsRuleMatchType">匹配模式</label>
                                    <select id="rdnsRuleMatchType">
                                        <option value="contains">包含匹配（默认，推荐）</option>
                                        <option value="regex">正则表达式</option>
                                        <option value="exact">精确匹配（完整主机名）</option>
                                    </select>
                                </div>
                                <div class="form-group" id="rdnsRuleActiveGroup" style="display:none">
                                    <label for="rdnsRuleActive">状态</label>
                                    <select id="rdnsRuleActive">
                                        <option value="1">启用</option>
                                        <option value="0">禁用</option>
                                    </select>
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="btn btn-ghost" onclick="closeModal('rdnsRuleModal')">取消</button>
                                    <button type="submit" class="btn btn-primary">保存</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- UA 规则编辑弹窗 -->
                <div class="modal-overlay" id="uaRuleModal" style="display:none;">
                    <div class="modal">
                        <div class="modal-header">
                            <h3 id="uaRuleModalTitle">添加规则</h3>
                            <button class="modal-close" onclick="closeModal('uaRuleModal')">✕</button>
                        </div>
                        <div class="modal-body">
                            <form id="uaRuleForm" onsubmit="saveUaRule(event)">
                                <input type="hidden" id="uaRuleId" value="">
                                <div class="form-group">
                                    <label for="uaRulePattern">UA 关键词 *</label>
                                    <textarea id="uaRulePattern" placeholder="每行一个规则，格式：关键词|蜘蛛类型|匹配模式&#10;例如：&#10;Bingbot|Bingbot|contains&#10;YandexBot|YandexBot|contains&#10;PetalBot|PetalBot|regex" rows="5" required></textarea>
                                    <span class="form-hint">
                                        <strong>批量添加</strong>：每行一个规则，用 <code>|</code> 分隔：<code>关键词|蜘蛛类型|匹配模式</code><br>
                                        匹配模式可选：<code>contains</code>（默认）/ <code>regex</code><br>
                                        <strong>单条添加</strong>：仅输入一行，蜘蛛类型从下方选择，匹配模式从下方选择
                                    </span>
                                </div>
                                <div class="form-group">
                                    <label for="uaRuleType">蜘蛛类型 *</label>
                                    <input type="text" id="uaRuleType" placeholder="例如: Baiduspider、CustomBot" required>
                                </div>
                                <div class="form-group">
                                    <label for="uaRuleMatchType">匹配模式</label>
                                    <select id="uaRuleMatchType">
                                        <option value="contains">包含匹配（默认，推荐）</option>
                                        <option value="regex">正则表达式</option>
                                    </select>
                                </div>
                                <div class="form-group" id="uaRuleActiveGroup" style="display:none">
                                    <label for="uaRuleActive">状态</label>
                                    <select id="uaRuleActive">
                                        <option value="1">启用</option>
                                        <option value="0">禁用</option>
                                    </select>
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="btn btn-ghost" onclick="closeModal('uaRuleModal')">取消</button>
                                    <button type="submit" class="btn btn-primary">保存</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ========== 更新管理 Tab ========== -->
            <section class="tab-content" id="tab-updates">
                <div class="dash-card">
                    <p class="text-muted">手动触发各种数据更新任务，查看更新历史记录。</p>
                    
                    <div class="update-actions" style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
                        <?php
                        // 计算各数据源最后更新时间与文件大小
                        $googleCacheFile = __DIR__ . '/../db/google_spider_cache.json';
                        $googleLastUpdate = file_exists($googleCacheFile) ? date('Y-m-d', filemtime($googleCacheFile)) : null;
                        $googleCacheSize = file_exists($googleCacheFile) ? round(filesize($googleCacheFile)/1024, 1) : 0;
                        $geoLastUpdate = file_exists(GEOIP_DB_PATH) ? date('Y-m-d', filemtime(GEOIP_DB_PATH)) : null;
                        $geoSize = file_exists(GEOIP_DB_PATH) ? round(filesize(GEOIP_DB_PATH)/1048576, 1) : 0;
                        $dbipLastUpdate = file_exists(DBIP_DB_PATH) ? date('Y-m-d', filemtime(DBIP_DB_PATH)) : null;
                        $dbipSize = file_exists(DBIP_DB_PATH) ? round(filesize(DBIP_DB_PATH)/1048576, 1) : 0;
                        $ip2lPath = __DIR__ . '/../geoip/IP2Location-LITE-DB11.BIN';
                        $ip2lLastUpdate = file_exists($ip2lPath) ? date('Y-m-d', filemtime($ip2lPath)) : null;
                        $ip2lSize = file_exists($ip2lPath) ? round(filesize($ip2lPath)/1048576, 1) : 0;
                        ?>
                        <!-- Google 蜘蛛 -->
                        <div class="update-card">
                            <div class="update-card-header">
                                <h4>🕷️ Google 蜘蛛 IP 段</h4>
                                <span class="card-badge card-badge-free">🆓 免费 · 无需密钥</span>
                            </div>
                            <div class="update-card-info">
                                <div class="info-row">
                                    <span class="info-label">数据来源</span>
                                    <span class="info-value">Google 官方爬虫 JSON</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📦 缓存大小</span>
                                    <span class="info-value"><?php echo $googleCacheSize > 0 ? "{$googleCacheSize} KB" : '<em style="color:var(--admin-error)">暂无缓存</em>'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📅 更新日期</span>
                                    <span class="info-value"><?php echo $googleLastUpdate ?: '<em style="color:var(--admin-error)">尚未更新</em>'; ?></span>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="triggerUpdate('google_spiders')">立即更新</button>
                        </div>
                        <!-- GeoLite2 -->
                        <div class="update-card">
                            <div class="update-card-header">
                                <h4>🗺️ GeoLite2-City</h4>
                                <span class="card-badge card-badge-warn">🔑 需配置 License Key</span>
                            </div>
                            <div class="update-card-info">
                                <div class="info-row">
                                    <span class="info-label">数据来源</span>
                                    <span class="info-value">MaxMind GeoLite2 免费库</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📦 文件大小</span>
                                    <span class="info-value"><?php echo $geoSize > 0 ? "{$geoSize} MB" : '<em style="color:var(--admin-error)">未安装</em>'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📅 更新日期</span>
                                    <span class="info-value"><?php echo $geoLastUpdate ?: '<em style="color:var(--admin-error)">尚未更新</em>'; ?></span>
                                </div>
                                <div class="info-note">⚠️ 请在「系统设置」中填写 <strong>MaxMind License Key</strong> 后生效</div>
                            </div>
                            <button class="btn btn-primary" onclick="triggerUpdate('geoip_db')">立即更新</button>
                        </div>
                        <!-- DB-IP Lite -->
                        <div class="update-card">
                            <div class="update-card-header">
                                <h4>🌍 DB-IP Lite</h4>
                                <span class="card-badge card-badge-free">🆓 免费 · 无需密钥</span>
                            </div>
                            <div class="update-card-info">
                                <div class="info-row">
                                    <span class="info-label">数据来源</span>
                                    <span class="info-value">DB-IP 免费城市数据库</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📦 文件大小</span>
                                    <span class="info-value"><?php echo $dbipSize > 0 ? "{$dbipSize} MB" : '<em style="color:var(--admin-error)">未安装</em>'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📅 更新日期</span>
                                    <span class="info-value"><?php echo $dbipLastUpdate ?: '<em style="color:var(--admin-error)">尚未更新</em>'; ?></span>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="triggerUpdate('dbip_lite')">立即更新</button>
                        </div>
                        <!-- IP2Location -->
                        <div class="update-card">
                            <div class="update-card-header">
                                <h4>📍 IP2Location LITE</h4>
                                <span class="card-badge card-badge-warn">🔑 需配置 Token</span>
                            </div>
                            <div class="update-card-info">
                                <div class="info-row">
                                    <span class="info-label">数据来源</span>
                                    <span class="info-value">IP2Location LITE DB11</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📦 文件大小</span>
                                    <span class="info-value"><?php echo $ip2lSize > 0 ? "{$ip2lSize} MB" : '<em style="color:var(--admin-error)">未安装</em>'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">📅 更新日期</span>
                                    <span class="info-value"><?php echo $ip2lLastUpdate ?: '<em style="color:var(--admin-error)">尚未更新</em>'; ?></span>
                                </div>
                                <div class="info-note">⚠️ 请在「系统设置」中填写 <strong>IP2Location Token</strong> 后生效</div>
                            </div>
                            <button class="btn btn-primary" onclick="triggerUpdate('ip2location_lite')">立即更新</button>
                        </div>
                    </div>
                    
                    <h4 style="margin-top:24px;display:flex;align-items:center;gap:12px">
                        <span>🔗 Google 数据源管理</span>
                        <button class="btn btn-sm btn-primary" onclick="showAddSourceModal()" style="font-size:0.75rem">➕ 添加数据源</button>
                    </h4>
                    <p class="text-muted" style="font-size:0.8rem;margin-bottom:8px">管理 Google 蜘蛛 IP 段的 JSON 数据源端点，启用/禁用后点击上方"立即更新"生效。</p>
                    <div class="table-wrap" id="googleSourcesTable">
                        <table>
                            <thead>
                                <tr><th>标识</th><th>名称</th><th>端点 URL</th><th>状态</th><th>操作</th></tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <h4 style="margin-top:24px;display:flex;align-items:center;gap:12px">
                        <span>📜 更新历史日志</span>
                        <button class="btn btn-sm btn-danger" onclick="clearUpdateRecords()" style="font-size:0.75rem">🗑️ 清除日志</button>
                    </h4>
                    <div class="table-wrap" id="updateHistoryTable">
                        <table>
                            <thead>
                                <tr><th>类型</th><th>状态</th><th>详情</th><th>记录数</th><th>时间</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($settings->getUpdateRecords(20) as $r): 
                                    $detail = json_decode($r['message'] ?? '', true);
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['update_type']); ?></td>
                                    <td><span class="badge badge-<?php echo $r['status'] === 'success' ? 'success' : 'error'; ?>"><?php echo $r['status']; ?></span></td>
                                    <td>
                                        <?php if (is_array($detail)): ?>
                                            <?php if (isset($detail['total'])): // Google蜘蛛更新 ?>
                                                <span>总计 <strong><?php echo number_format($detail['total']); ?></strong> 条</span>
                                                <?php if (($detail['imported'] ?? 0) > 0): ?><span class="text-success"> · 新增 <strong><?php echo number_format($detail['imported']); ?></strong></span><?php endif; ?>
                                                <?php if (($detail['reactivated'] ?? 0) > 0): ?><span class="text-warning"> · 重新激活 <strong><?php echo number_format($detail['reactivated']); ?></strong></span><?php endif; ?>
                                                <?php if (!empty($detail['sources'])): ?>
                                                    <br><small class="text-muted">
                                                    <?php foreach ($detail['sources'] as $src => $cnt): ?>
                                                        <?php echo htmlspecialchars($src); ?>: <?php echo number_format($cnt); ?><?php echo next($detail['sources']) ? ' · ' : ''; ?>
                                                    <?php endforeach; ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php elseif (isset($detail['size_mb'])): // GeoIP更新 ?>
                                                <span>文件大小 <strong><?php echo $detail['size_mb']; ?> MB</strong></span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($r['message']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($r['message'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $r['records_count']; ?></td>
                                    <td><?php echo $r['created_at']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($settings->getUpdateRecords(1))): ?>
                                <tr><td colspan="5" class="text-center text-muted">暂无更新记录</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 数据源编辑弹窗 -->
                    <div class="modal-overlay" id="sourceModal" style="display:none;">
                        <div class="modal">
                            <div class="modal-header">
                                <h3 id="sourceModalTitle">添加数据源</h3>
                                <button class="modal-close" onclick="closeModal('sourceModal')">✕</button>
                            </div>
                            <div class="modal-body">
                                <form id="sourceForm" onsubmit="saveSource(event)">
                                    <input type="hidden" id="sourceId" value="">
                                    <div class="form-group">
                                        <label for="sourceKey">数据源标识 *</label>
                                        <input type="text" id="sourceKey" placeholder="例如: custom-crawlers" required pattern="[a-z0-9_\-]+">
                                        <small class="text-muted">仅允许小写字母、数字、连字符和下划线</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="sourceName">显示名称 *</label>
                                        <input type="text" id="sourceName" placeholder="例如: Custom Crawlers" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="sourceUrl">JSON 端点 URL *</label>
                                        <input type="url" id="sourceUrl" placeholder="https://example.com/ipranges/crawlers.json" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="sourceActive">状态</label>
                                        <select id="sourceActive">
                                            <option value="1">启用</option>
                                            <option value="0">禁用</option>
                                        </select>
                                    </div>
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-ghost" onclick="closeModal('sourceModal')">取消</button>
                                        <button type="submit" class="btn btn-primary">保存</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ========== API 管理 Tab ========== -->
            <section class="tab-content" id="tab-api">
                <div class="dash-card">
                    <p class="text-muted">查看系统公开 API 端点文档与近 30 天调用统计。</p>
                    
                    <div class="settings-section">
                        <h4>🌐 公开 API 端点</h4>
                        <table>
                            <thead>
                                <tr><th>端点</th><th>方法</th><th>说明</th><th>示例</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>/api.php?action=check</code></td>
                                    <td>GET</td>
                                    <td>检查IP是否为蜘蛛</td>
                                    <td><code>?action=check&ip=1.2.3.4</code></td>
                                </tr>
                                <tr>
                                    <td><code>/api.php?action=geo</code></td>
                                    <td>GET</td>
                                    <td>查询IP地理位置</td>
                                    <td><code>?action=geo&ip=1.2.3.4</code></td>
                                </tr>
                                <tr>
                                    <td><code>/api.php?action=full</code></td>
                                    <td>GET</td>
                                    <td>完整查询（蜘蛛+位置）</td>
                                    <td><code>?action=full&ip=1.2.3.4</code></td>
                                </tr>
                                <tr>
                                    <td><code>/api.php?action=stats</code></td>
                                    <td>GET</td>
                                    <td>获取统计信息</td>
                                    <td><code>?action=stats</code></td>
                                </tr>
                                <tr>
                                    <td><code>/api.php?action=ranges</code></td>
                                    <td>GET</td>
                                    <td>获取所有IP段</td>
                                    <td><code>?action=ranges</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="settings-section">
                        <h4>📖 调用示例</h4>
                        <?php
                        $apiBaseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                            . '://' . getSafeHost() . '/api.php';
                        ?>
                        <p class="text-muted" style="font-size:0.82rem;margin-bottom:12px">
                            API 基础地址：<code><?php echo $apiBaseUrl; ?></code>&nbsp;&nbsp;所有接口支持 GET/POST，返回 JSON。
                        </p>
                        
                        <div class="code-examples" style="display:flex;gap:16px;flex-wrap:wrap">
                            <!-- curl 示例 -->
                            <div class="code-block" style="flex:1 1 280px;min-width:280px">
                                <h5 style="margin:0 0 8px 0;font-size:0.85rem">🔧 cURL</h5>
                                <pre style="background:rgba(0,0,0,0.2);padding:12px;border-radius:6px;font-size:0.78rem;overflow-x:auto;margin:0;line-height:1.6"><code># 完整查询（蜘蛛+地理位置）
curl "<?php echo $apiBaseUrl; ?>?action=full&ip=66.249.66.1"

# 仅检查蜘蛛
curl "<?php echo $apiBaseUrl; ?>?action=check&ip=66.249.66.1&ua=Googlebot"

# 获取统计信息
curl "<?php echo $apiBaseUrl; ?>?action=stats"</code></pre>
                            </div>
                            
                            <!-- JavaScript 示例 -->
                            <div class="code-block" style="flex:1 1 280px;min-width:280px">
                                <h5 style="margin:0 0 8px 0;font-size:0.85rem">📜 JavaScript (Fetch)</h5>
                                <pre style="background:rgba(0,0,0,0.2);padding:12px;border-radius:6px;font-size:0.78rem;overflow-x:auto;margin:0;line-height:1.6"><code>fetch('<?php echo $apiBaseUrl; ?>?action=full&ip=66.249.66.1')
  .then(r => r.json())
  .then(data => {
    console.log('蜘蛛:', data.spider);
    console.log('位置:', data.geo);
  });</code></pre>
                            </div>
                            
                            <!-- PHP 示例 -->
                            <div class="code-block" style="flex:1 1 280px;min-width:280px">
                                <h5 style="margin:0 0 8px 0;font-size:0.85rem">🐘 PHP</h5>
                                <pre style="background:rgba(0,0,0,0.2);padding:12px;border-radius:6px;font-size:0.78rem;overflow-x:auto;margin:0;line-height:1.6"><code>$url = '<?php echo $apiBaseUrl; ?>'
     . '?action=full&ip=66.249.66.1';
$data = json_decode(
  file_get_contents($url), true
);
echo $data['spider']['spider_type'];
echo $data['geo']['country'];</code></pre>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-section">
                        <h4>📊 API 调用统计（近30天）</h4>
                        <div class="api-chart" id="apiChart">
                            <div class="chart-loading">加载中...</div>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ========== 蜘蛛IP管理 Tab ========== -->
            <section class="tab-content" id="tab-spiders">
                <div class="dash-card">
                    <p class="text-muted" style="margin-bottom:16px">管理搜索引擎蜘蛛的 IP 段数据库，支持手动添加、批量导入、启用/禁用和删除操作。IP 段匹配是蜘蛛识别的<strong>第一重验证</strong>手段。</p>
                    <div class="card-header-row">
                        <span></span>
                        <div style="display:flex;gap:8px">
                            <button class="btn btn-sm btn-danger" onclick="batchDeleteRanges()" id="batchDeleteBtn" style="display:none">🗑️ 批量删除</button>
                            <button class="btn btn-sm" onclick="loadSpiderRanges()">🔄 刷新</button>
                            <button class="btn btn-sm btn-primary" onclick="showAddRangeModal()">➕ 添加IP段</button>
                        </div>
                    </div>
                    
                    <!-- 搜索和过滤栏 -->
                    <div class="spider-ranges-filters">
                        <span class="filter-label">🔍 搜索 IP 段</span>
                        <div class="filter-inputs">
                            <input type="text" id="spiderSearch" placeholder="输入 IP 或 IP 段，如 116.179.32.1..." 
                                   onkeydown="if(event.key==='Enter')loadSpiderRanges(1)">
                            <select id="spiderTypeFilter" onchange="loadSpiderRanges(1)">
                                <option value="">全部类型</option>
                                <?php
                                $typeDb = new SQLite3(SQLITE_DB_PATH);
                                $typeResult = $typeDb->query("SELECT DISTINCT spider_type FROM spider_ranges WHERE spider_type IS NOT NULL AND spider_type != '' ORDER BY spider_type ASC");
                                $seenTypes = [];
                                while ($tRow = $typeResult->fetchArray(SQLITE3_ASSOC)) {
                                    $t = $tRow['spider_type'];
                                    if (isset($seenTypes[$t])) continue;
                                    $seenTypes[$t] = true;
                                    echo '<option value="' . htmlspecialchars($t) . '">' . htmlspecialchars($t) . '</option>';
                                }
                                $typeDb->close();
                                ?>
                            </select>
                            <select id="spiderSourceFilter" onchange="loadSpiderRanges(1)">
                                <option value="">全部来源</option>
                                <?php
                                $srcDb = new SQLite3(SQLITE_DB_PATH);
                                $srcResult = $srcDb->query("SELECT DISTINCT source FROM spider_ranges WHERE source IS NOT NULL AND source != '' ORDER BY source ASC");
                                while ($sRow = $srcResult->fetchArray(SQLITE3_ASSOC)) {
                                    $s = $sRow['source'];
                                    $labels = ['google_official'=>'Google官方','builtin'=>'内置','auto_import'=>'自动导入','confirmed_import'=>'确认导入'];
                                    $label = $labels[$s] ?? $s;
                                    echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($label) . '</option>';
                                }
                                $srcDb->close();
                                ?>
                            </select>
                        <button class="btn btn-sm" onclick="loadSpiderRanges(1)" style="white-space:nowrap">🔍 搜索</button>
                        </div>
                    </div>
                    
                    <div class="table-wrap" id="spiderRangesTable">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="selectAllRanges" onchange="toggleAllRanges(this)" title="全选/取消"></th>
                                    <th>IP 段 (CIDR)</th><th>蜘蛛类型</th><th>来源</th><th>可信度</th><th>状态</th><th>创建时间</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 分页 -->
                    <div class="pagination" id="spiderRangesPagination"></div>
                </div>
                
                <!-- 添加/编辑IP段弹窗 -->
                <div class="modal-overlay" id="rangeModal" style="display:none;">
                    <div class="modal">
                        <div class="modal-header">
                            <h3 id="rangeModalTitle">添加 IP 段</h3>
                            <button class="modal-close" onclick="closeModal('rangeModal')">✕</button>
                        </div>
                        <div class="modal-body">
                            <form id="rangeForm" onsubmit="saveRange(event)">
                                <input type="hidden" id="rangeId" value="">
                                <div class="form-group">
                                    <label for="rangeCidr">CIDR 表示法 / IP 地址 *</label>
                                    <textarea id="rangeCidr" placeholder="每行一个 CIDR 或 IP，例如：&#10;116.179.32.0/24&#10;66.249.66.169&#10;192.168.1.1/32" rows="5" required></textarea>
                                    <span class="form-hint">每行一个 CIDR 段或单个 IP，支持批量添加</span>
                                </div>
                                <div class="form-group">
                                    <label for="rangeType">蜘蛛类型</label>
                                    <select id="rangeType" onchange="document.getElementById('rangeTypeOther').style.display=this.value==='Other'?'block':'none'">
                                        <?php
                                        // 动态加载数据库中已有的蜘蛛类型
                                        try {
                                            $modalDb = new SQLite3(SQLITE_DB_PATH);
                                            $modalResult = $modalDb->query("SELECT DISTINCT spider_type FROM spider_ranges WHERE spider_type IS NOT NULL AND spider_type != '' ORDER BY spider_type ASC");
                                            $modalSeen = [];
                                            while ($mRow = $modalResult->fetchArray(SQLITE3_ASSOC)) {
                                                $mt = $mRow['spider_type'];
                                                if (isset($modalSeen[$mt])) continue;
                                                $modalSeen[$mt] = true;
                                                echo '<option value="' . htmlspecialchars($mt) . '">' . htmlspecialchars($mt) . '</option>';
                                            }
                                            $modalDb->close();
                                        } catch (Exception $e) {}
                                        ?>
                                        <option value="Other">其他（手动输入新类型）</option>
                                    </select>
                                    <input type="text" id="rangeTypeOther" placeholder="请输入自定义蜘蛛类型，如 SemrushBot" style="display:none;margin-top:8px" oninput="document.getElementById('rangeType').value='Other'">
                                </div>
                                <div class="form-group">
                                    <label for="rangeSource">来源</label>
                                    <input type="text" id="rangeSource" value="manual" placeholder="manual / google / baidu">
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="btn btn-ghost" onclick="closeModal('rangeModal')">取消</button>
                                    <button type="submit" class="btn btn-primary">保存</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ========== 已确认蜘蛛 Tab ========== -->
            <section class="tab-content" id="tab-confirmed">
                <div class="dash-card">
                    <p class="text-muted" style="margin-bottom:20px">
                        系统自动识别并记录的已确认蜘蛛 IP。当某个 IP 再次查询不再被认定为蜘蛛时，会自动从列表中移除。<br>
                        可手动导入到 <a href="#spiders" onclick="document.querySelector('[data-tab=spiders]').click()" style="color:var(--admin-primary)">🕸️ 蜘蛛IP管理</a> 中用于后续匹配。
                    </p>
                    
                    <!-- 搜索和过滤栏 -->
                    <div class="spider-ranges-filters" style="margin-top:0">
                        <span class="filter-label">🔍 搜索已确认蜘蛛</span>
                        <div class="filter-inputs">
                            <input type="text" id="confirmedSearch" placeholder="输入 IP 地址，如 66.249..." 
                                   onkeydown="if(event.key==='Enter')loadConfirmedSpiders()">
                            <select id="confirmedTypeFilter" onchange="loadConfirmedSpiders()">
                                <option value="">全部类型</option>
                                <?php
                                try {
                                    $ctDb = new SQLite3(SQLITE_DB_PATH);
                                    $ctResult = $ctDb->query("SELECT DISTINCT spider_type FROM confirmed_spiders WHERE spider_type IS NOT NULL AND spider_type != '' ORDER BY spider_type ASC");
                                    while ($ctRow = $ctResult->fetchArray(SQLITE3_ASSOC)) {
                                        $ct = $ctRow['spider_type'];
                                        echo '<option value="' . htmlspecialchars($ct) . '">' . htmlspecialchars($ct) . '</option>';
                                    }
                                    $ctDb->close();
                                } catch (Exception $e) {}
                                ?>
                            </select>
                            <select id="confirmedConfFilter" onchange="loadConfirmedSpiders()">
                                <option value="">全部可信度</option>
                                <option value="verified">已验证</option>
                                <option value="high">高</option>
                                <option value="medium">中</option>
                                <option value="low">低</option>
                            </select>
                            <button class="btn btn-sm" onclick="loadConfirmedSpiders()" style="white-space:nowrap">🔍 搜索</button>
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
                        <span style="flex:1"></span>
                        <button class="btn btn-sm btn-danger" onclick="batchDeleteConfirmed()" id="batchDeleteConfirmedBtn" style="display:none">🗑️ 批量删除选中</button>
                        <button class="btn btn-sm btn-danger" onclick="batchImportConfirmed()" id="batchImportConfirmedBtn" style="display:none">📥 批量导入选中</button>
                        <button class="btn btn-sm" onclick="loadConfirmedSpiders()">🔄 刷新</button>
                        <button class="btn btn-sm btn-primary" onclick="importAllConfirmed()">📥 导入全部到IP管理</button>
                    </div>
                    <div class="table-wrap" id="confirmedSpidersTable">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="selectAllConfirmed" onchange="toggleAllConfirmed(this)" title="全选/取消"></th>
                                    <th>IP 地址</th><th>蜘蛛类型</th><th>匹配方式</th><th>可信度</th>
                                    <th>最近发现</th><th>发现次数</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="8" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            
            <!-- ========== 查询日志 Tab ========== -->
            <section class="tab-content" id="tab-logs">
                <div class="dash-card">
                    <p class="text-muted" style="margin-bottom:16px">按 IP 聚合展示所有查询记录，区分蜘蛛与普通访客。支持按类型筛选，可一键清空过期日志数据。</p>
                    <div class="card-header-row">
                        <span></span>
                        <div class="log-filters">
                            <select id="logFilter" onchange="loadLogs(1)">
                                <option value="">全部记录</option>
                                <option value="spider">仅蜘蛛</option>
                                <option value="visitor">仅访客</option>
                            </select>
                            <button class="btn btn-sm" onclick="loadLogs(1)">🔄 刷新</button>
                            <button class="btn btn-sm btn-danger" onclick="clearAllLogs()">🗑️ 清空日志</button>
                        </div>
                    </div>
                    <div class="table-wrap" id="logsTable">
                        <table>
                            <thead>
                                <tr><th>IP 地址</th><th>查询次数</th><th>类型</th><th>蜘蛛类型</th><th>首次查询</th><th>最近查询</th></tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="logsPagination"></div>
                </div>
            </section>
            
            <!-- ========== 账户安全 Tab ========== -->
            <section class="tab-content" id="tab-account">
                <div class="dash-card">
                    <div class="settings-section">
                        <h4>🔒 修改密码</h4>
                        <form id="changePwdForm" onsubmit="changePassword(event)">
                            <div class="form-group">
                                <label for="oldPassword">原密码</label>
                                <input type="password" id="oldPassword" required>
                            </div>
                            <div class="form-group">
                                <label for="newPassword">新密码</label>
                                <input type="password" id="newPassword" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label for="confirmPassword">确认新密码</label>
                                <input type="password" id="confirmPassword" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary">🔒 修改密码</button>
                            <span class="save-status" id="pwdStatus"></span>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </main>
    
    <!-- Toast 通知 -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- CSRF Token -->
    <script>
        window.ADMIN_CSRF = '<?php echo $auth->getCsrfToken(); ?>';
        window.ADMIN_AJAX_URL = 'ajax.php';
    </script>
    <script src="../assets/js/admin.js?v=2.1.4"></script>
    <script>
    (function() {
        var canvas = document.getElementById('dailyChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        var w = rect.width, h = rect.height;
        var data = window.__dailyChartData;
        if (!data) return;
        var labels = data.labels, total = data.total, spider = data.spider;
        var maxVal = Math.max.apply(null, total.concat(spider).concat([1]));
        var pad = { top: 20, right: 16, bottom: 28, left: 36 };
        var pw = w - pad.left - pad.right, ph = h - pad.top - pad.bottom;
        ctx.clearRect(0, 0, w, h);
        ctx.strokeStyle = 'rgba(255,255,255,0.06)'; ctx.lineWidth = 1;
        for (var i = 0; i <= 4; i++) {
            var y = pad.top + (ph / 4) * i;
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(w - pad.right, y); ctx.stroke();
        }
        ctx.fillStyle = '#94a3b8'; ctx.font = '10px system-ui'; ctx.textAlign = 'right';
        for (var i = 0; i <= 4; i++) {
            var val = Math.round(maxVal * (4 - i) / 4);
            ctx.fillText(val, pad.left - 6, pad.top + (ph / 4) * i + 4);
        }
        function draw(d, color) {
            ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.setLineDash([]);
            ctx.beginPath();
            for (var i = 0; i < d.length; i++) {
                var x = pad.left + (pw / (d.length - 1 || 1)) * i;
                var y = pad.top + ph - (d[i] / maxVal) * ph;
                i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            }
            ctx.stroke();
            for (var i = 0; i < d.length; i++) {
                var x = pad.left + (pw / (d.length - 1 || 1)) * i;
                var y = pad.top + ph - (d[i] / maxVal) * ph;
                ctx.fillStyle = color; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI*2); ctx.fill();
                ctx.fillStyle = '#e2e8f0'; ctx.font = '9px system-ui'; ctx.textAlign = 'center';
                ctx.fillText(d[i], x, y - 8);
            }
        }
        draw(total, '#6366f1');
        draw(spider, '#f59e0b');
        ctx.fillStyle = '#94a3b8'; ctx.font = '10px system-ui'; ctx.textAlign = 'center';
        for (var i = 0; i < labels.length; i++) {
            ctx.fillText(labels[i], pad.left + (pw / (labels.length - 1 || 1)) * i, h - 6);
        }
    })();
    </script>
</body>
</html>
