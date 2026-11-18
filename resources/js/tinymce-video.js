/**
 * TinyMCE 插件：插入视频。
 *
 * 在「插入/编辑媒体」对话框里只保留两个页签：
 * - 上传（默认）：选本地文件 → 走 cmf-media 浏览器直传 → 插入 <video controls data-cmf-media="…">；
 * - 嵌入：媒体插件自带的 iframe 面板，粘白名单站点的播放器代码。
 * 「一般」「高级」两个页签在打开对话框时隐藏，地址嗅探（页面地址 → 官方播放器）已下线。
 *
 * 上传走 cmf-media 的浏览器直传协议：算内容指纹 → check（同内容秒传）→ sign（签直传凭证）
 * → 浏览器直传云存储 → callback 建档；local 驱动由 sign 直接给出应用服务器的 upload 端点。
 * 只有云存储桶没配 CORS（浏览器直传 status 0）时才退回 POST /cmf-rich-content/video 由服务器中转。
 */
(function () {
    "use strict";

    // 云存储未配 CORS 时的中转兜底（大文件受 PHP upload_max_filesize 约束）
    var PLUGIN = "cmfvideo";
    var DIALOG_MARK = "__cmfVideoTabReady";
    var TAB_MARK = "cmf-video-tab";
    var PANEL_MARK = "cmf-video-tab-panel";
    var NAV_ACTIVE = "tox-dialog__body-nav-item--active";

    /**
     * 上传管线（指纹 → check → sign → 直传 → callback）在 rich-content-media-upload.js 里与图片共用。
     */
    function uploadVideo(editor, file, onProgress) {
        if (!window.CmfRichContentMediaUpload) {
            return Promise.reject(new Error("上传脚本未加载，请刷新页面重试"));
        }

        return window.CmfRichContentMediaUpload.upload(editor, file, "video", onProgress);
    }

    /**
     * 视频标签拼装与图片上传共用同一份实现（rich-content-media-upload.js），
     * 这里只做取用，避免两处各写一份转义逻辑。
     */
    function videoHtml(src, id, width, height) {
        var api = window.CmfRichContentMediaUpload;

        if (!api || typeof api.videoHtml !== "function") {
            throw new Error("上传脚本未加载，请刷新页面重试");
        }

        return api.videoHtml(src, id, width, height);
    }

    /** 原生页签（一般/内嵌/高级），用来挪走高亮，避免同时出现两条下划线。 */
    function nativeTabs(dialog) {
        var nav = dialog.querySelector(".tox-dialog__body-nav");

        if (!nav) {
            return [];
        }

        return Array.prototype.slice.call(nav.querySelectorAll(".tox-dialog__body-nav-item")).filter(function (el) {
            return !el.classList.contains(TAB_MARK);
        });
    }

    /**
     * 媒体插件自带的页签里只留「嵌入」：一般（地址 + 尺寸）与高级（边框/间距/对齐等）
     * 对本站编辑没有意义，隐藏掉但保留 DOM——插件内部仍按自己的页签状态渲染面板。
     */
    var HIDDEN_NATIVE_TAB = /^(一般|高级|general|advanced)$/i;

    function hideNativeTabs(dialog) {
        nativeTabs(dialog).forEach(function (el) {
            var label = (el.textContent || "").trim();

            if (HIDDEN_NATIVE_TAB.test(label)) {
                el.style.display = "none";

                return;
            }

            // 只剩这一个原生页签，按站内叫法统一成「嵌入」（插件内部按序号识别，改文案不影响行为）。
            if (label === "内嵌") {
                el.textContent = "嵌入";
            }
        });
    }

    /** 只让一个页签带高亮：先摘掉所有原生页签的 active，再给指定项加上。 */
    function highlight(dialog, target) {
        nativeTabs(dialog).forEach(function (el) {
            var on = el === target;

            el.classList.toggle(NAV_ACTIVE, on);
            el.setAttribute("aria-selected", on ? "true" : "false");
        });
    }

    /** 切到「上传」页签：藏起媒体插件自己的面板，显示上传面板，并临时禁用「保存」。 */
    function showUploadTab(dialog, active) {
        var nav = dialog.querySelector(".tox-dialog__body-nav");
        var nativePanel = dialog.querySelector(".tox-dialog__body-content:not(." + PANEL_MARK + ")");
        var panel = dialog.querySelector("." + PANEL_MARK);
        var item = nav ? nav.querySelector("." + TAB_MARK) : null;

        if (item) {
            item.setAttribute("aria-selected", active ? "true" : "false");
            item.classList.toggle(NAV_ACTIVE, active);
        }

        // 上传页签激活时把原生页签的高亮全部摘掉，否则「一般」和「上传」会同时带下划线。
        if (active) {
            highlight(dialog, null);
        }

        if (panel) {
            panel.style.display = active ? "flex" : "none";
        }

        if (nativePanel) {
            nativePanel.style.display = active ? "none" : "";
        }

        // 上传页签里没有地址，点「保存」只会报「请输入有效地址」，这里先禁用；
        // 切回一般/内嵌时恢复，不影响插件自己的提交。
        var buttons = dialog.querySelectorAll(".tox-dialog__footer .tox-button");
        var save = buttons.length ? buttons[buttons.length - 1] : null;

        if (save) {
            save.disabled = !!active;
            save.classList.toggle("tox-button--disabled", !!active);
        }
    }

    /**
     * 在「插入/编辑媒体」对话框里加一个「上传」页签。
     *
     * 媒体对话框是插件自己用 windowManager.open 建的 tabpanel，没有可插 tab 的扩展点，
     * 所以这里在 mceMedia 之后等对话框出现，往它的左侧导航补一项「上传」，并在右侧补一块
     * 与原生面板同级的面板；点原生页签时再切回去（原生面板由插件自己重绘，不用管）。
     */
    function decorateDialog(editor, dialog) {
        if (!dialog || dialog.getAttribute(DIALOG_MARK)) {
            return false;
        }

        var nav = dialog.querySelector(".tox-dialog__body-nav");
        var body = dialog.querySelector(".tox-dialog__body");

        if (!nav || !body) {
            return false;
        }

        dialog.setAttribute(DIALOG_MARK, "1");

        var item = document.createElement("div");
        item.className = "tox-dialog__body-nav-item tox-tab " + TAB_MARK;
        item.setAttribute("role", "tab");
        item.setAttribute("aria-selected", "false");
        item.tabIndex = -1;
        item.textContent = "上传";
        // 放在页签栏最前：「上传」是默认页签，排在「嵌入」前面更符合操作顺序。
        nav.insertBefore(item, nav.firstChild);

        var panel = document.createElement("div");
        panel.className = "tox-dialog__body-content " + PANEL_MARK;
        panel.setAttribute("role", "tabpanel");
        panel.style.display = "none";
        // 与原生面板同级并排：显式占满剩余宽度，避免被挤成窄条或压住左侧页签栏。
        panel.style.flex = "1 1 auto";
        panel.style.minWidth = "0";
        panel.style.boxSizing = "border-box";
        panel.style.flexDirection = "column";
        panel.style.gap = "12px";
        panel.style.padding = "16px";

        var hint = document.createElement("p");
        hint.style.margin = "0";
        hint.style.fontSize = "13px";
        hint.style.lineHeight = "1.6";
        hint.style.opacity = "0.8";
        var limit = window.CmfRichContentMediaUpload.endpoints(editor, "video").maxSize;
        hint.textContent =
            "支持 mp4 / webm / ogg，单个文件不超过 " +
            (limit > 0 ? Math.round(limit / 1048576) + "MB" : "站点配置上限") +
            "。上传后视频存进媒体库并自动插入正文。";

        var pick = document.createElement("button");
        pick.type = "button";
        pick.className = "tox-button";
        pick.textContent = "选择本地视频";

        var status = document.createElement("p");
        status.style.margin = "0";
        status.style.fontSize = "12px";
        status.style.opacity = "0.7";
        status.textContent = "";

        var input = document.createElement("input");
        input.type = "file";
        input.accept = "video/*";
        input.style.display = "none";

        panel.appendChild(hint);
        panel.appendChild(pick);
        panel.appendChild(status);
        panel.appendChild(input);
        body.appendChild(panel);

        pick.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            input.click();
        });

        input.addEventListener("change", function () {
            var file = input.files && input.files[0];

            if (!file) {
                return;
            }

            pick.disabled = true;
            status.textContent = "上传中：" + file.name;

            uploadVideo(editor, file, function (label, ratio) {
                status.textContent = label + " " + Math.round(ratio * 100) + "%";
            })
                .then(function (media) {
                    pick.disabled = false;

                    editor.windowManager.close();
                    editor.insertContent(videoHtml(media.url, media.id, 0, 0));
                    editor.undoManager.add();
                    window.CmfRichContentMediaUpload?.notify(editor, "视频已插入", "success");
                })
                .catch(function (error) {
                    pick.disabled = false;
                    status.textContent = error.message || "上传失败";
                    window.CmfRichContentMediaUpload?.notify(editor, error.message || "上传失败", "error");
                });
        });

        item.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            showUploadTab(dialog, true);
        });

        // 点原生页签（一般/内嵌/高级）时把上传面板收起来，交回插件自己的面板。
        nav.addEventListener("click", function (event) {
            var target = event.target;

            if (!target || !target.closest) {
                return;
            }

            var clicked = target.closest(".tox-tab");

            if (clicked && !clicked.classList.contains(TAB_MARK)) {
                hideNativeTabs(dialog);
                showUploadTab(dialog, false);

                // 插件自己的切换逻辑先跑（它会按内部状态挪高亮），这里在下一帧统一收口成
                // 「只有点中的那一项带下划线」，避免双下划线或一条都没有。
                window.setTimeout(function () {
                    highlight(dialog, clicked);
                }, 0);
            }
        });

        // 只留「嵌入」，并且默认停在「上传」：对话框一打开就是选文件，不用先切页签。
        hideNativeTabs(dialog);
        showUploadTab(dialog, true);

        return true;
    }

    function watchDialog(editor) {
        var tries = 0;

        var timer = window.setInterval(function () {
            tries += 1;

            var dialogs = document.querySelectorAll(".tox-dialog");

            for (var i = 0; i < dialogs.length; i += 1) {
                if (decorateDialog(editor, dialogs[i])) {
                    window.clearInterval(timer);

                    return;
                }
            }

            if (tries > 40) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    tinymce.PluginManager.add(PLUGIN, function (editor) {
        // mceMedia 打开的就是「插入/编辑媒体」对话框（工具栏 media 按钮与快捷键都走它）。
        editor.on("ExecCommand", function (event) {
            if (event.command === "mceMedia") {
                watchDialog(editor);
            }
        });

        return {
            getMetadata: function () {
                return { name: "插入视频", url: "https://quansitech.com" };
            },
        };
    });
})();
