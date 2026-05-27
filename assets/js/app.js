/**
 * 百度蜘蛛识别查询系统 - 前端脚本
 */

(function() {
    'use strict';

    // DOM 加载完成后初始化
    document.addEventListener('DOMContentLoaded', function() {
        initAutoComplete();
        initQuickActions();
        initCopyButtons();
    });

    /**
     * IP 输入框自动补全提示
     */
    function initAutoComplete() {
        var ipInput = document.getElementById('query_ip');
        if (!ipInput) return;

        // 常用测试 IP 列表
        var commonIPs = [
            { ip: '116.179.32.1', label: '百度蜘蛛 IP (116.179.32.0/24)' },
            { ip: '180.76.15.1', label: '百度蜘蛛 IP (180.76.0.0/16)' },
            { ip: '220.181.108.1', label: '百度蜘蛛 IP (220.181.0.0/18)' },
            { ip: '123.125.68.1', label: '百度蜘蛛 IP (123.125.0.0/16)' },
            { ip: '8.8.8.8', label: 'Google DNS' },
            { ip: '1.1.1.1', label: 'Cloudflare DNS' },
            { ip: '114.114.114.114', label: '国内 DNS' },
        ];

        // 创建 datalist
        var datalist = document.createElement('datalist');
        datalist.id = 'ip-suggestions';
        
        commonIPs.forEach(function(item) {
            var option = document.createElement('option');
            option.value = item.ip;
            option.label = item.label;
            datalist.appendChild(option);
        });

        document.body.appendChild(datalist);
        ipInput.setAttribute('list', 'ip-suggestions');
    }

    /**
     * 快捷操作：点击预设 IP 快速查询
     */
    function initQuickActions() {
        // 可以在这里添加快捷查询按钮等
    }

    /**
     * 一键复制按钮
     */
    function initCopyButtons() {
        var codes = document.querySelectorAll('.api-url');
        codes.forEach(function(code) {
            code.style.cursor = 'pointer';
            code.title = '点击复制';
            
            code.addEventListener('click', function() {
                var text = this.textContent;
                copyToClipboard(text);
                
                // 视觉反馈
                var originalBg = this.style.background;
                this.style.background = '#d1fae5';
                setTimeout(function(el) {
                    el.style.background = originalBg;
                }, 1000, this);
            });
        });
    }

    /**
     * 复制到剪贴板
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('已复制到剪贴板 ✓');
            }).catch(function() {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    /**
     * 回退复制方案
     */
    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showToast('已复制到剪贴板 ✓');
        } catch (e) {
            showToast('复制失败，请手动复制');
        }
        document.body.removeChild(textarea);
    }

    /**
     * Toast 提示
     */
    function showToast(message) {
        var toast = document.createElement('div');
        toast.className = 'toast-message';
        toast.textContent = message;
        toast.style.cssText = [
            'position: fixed',
            'bottom: 24px',
            'left: 50%',
            'transform: translateX(-50%)',
            'background: #1f2937',
            'color: #fff',
            'padding: '10px 20px',
            'border-radius: 8px',
            'font-size: 0.9rem',
            'z-index: 9999',
            'animation: fadeInUp 0.3s ease',
            'box-shadow: 0 4px 12px rgba(0,0,0,0.3)',
        ].join(';');

        document.body.appendChild(toast);

        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 2000);
    }

    // 添加动画样式
    var style = document.createElement('style');
    style.textContent = '@keyframes fadeInUp { from { opacity:0; transform:translate(-50%, 10px); } to { opacity:1; transform:translate(-50%, 0); } }';
    document.head.appendChild(style);

})();
