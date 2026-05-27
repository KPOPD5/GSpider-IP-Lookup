/**
 * Google 蜘蛛 IP 段查询页面 - 前端脚本
 */
(function() {
    'use strict';

    var toastTimer;

    /**
     * 显示 Toast 提示
     */
    function showToast(message) {
        var el = document.getElementById('toast');
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function() {
            el.classList.remove('show');
        }, 1800);
    }

    /**
     * 复制文本到剪贴板（不弹 Toast）
     */
    function copyIP(ip) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(ip).catch(function() {
                fallbackCopy(ip);
            });
        } else {
            fallbackCopy(ip);
            return Promise.resolve();
        }
    }

    /**
     * 回退复制方案（兼容旧浏览器）
     */
    function fallbackCopy(text) {
        var t = document.createElement('textarea');
        t.value = text;
        t.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(t);
        t.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            showToast('复制失败');
        }
        document.body.removeChild(t);
    }

    /**
     * 复制当前列表（仅复制当前筛选/搜索后的可见 IP 段）
     */
    function copyAll() {
        var els = document.querySelectorAll('.ip-addr');
        if (!els.length) {
            showToast('无数据');
            return;
        }
        var ips = [];
        els.forEach(function(el) { ips.push(el.textContent.trim()); });
        copyIP(ips.join('\n')).then(function() {
            showToast('✓ 已复制 ' + ips.length + ' 条 IP 段');
        });
    }

    /**
     * 复制单个 IP 并提示
     */
    function copySingleIP(ip) {
        copyIP(ip).then(function() {
            showToast('✓ 已复制');
        });
    }

    /**
     * 清除搜索
     */
    function clearSearch() {
        document.querySelector('input[name="search"]').value = '';
        document.getElementById('filterForm').submit();
    }

    // 暴露到全局作用域（供 onclick 调用）
    window.copySingleIP = copySingleIP;
    window.copyAll = copyAll;
    window.clearSearch = clearSearch;
    window.copyAll = copyAll;
    window.clearSearch = clearSearch;

})();
