/**
 * TinyMCE 插件：抓取微信公众号文章正文。
 *
 * - 工具栏按钮「公众号文章」：弹窗粘贴链接 → 调后台接口抓取（图片已落 cmf-media）
 *   → 把正文插入光标处；
 * - 粘贴增强：从公众号页面直接复制时，只取 #js_content / .rich_media_content
 *   正文容器，并把懒加载的 data-src 还原成 src，避免把整页外壳粘进编辑器。
 *
 * 接口：POST /cmf-rich-content/wechat-article（需登录 + CSRF）。
 */
(function () {
    "use strict";

    var ENDPOINT = "/cmf-rich-content/wechat-article";
    var BUTTON = "wxarticle";
    var PLUGIN = "wechatarticle";
    var PAGE_CHROME_SELECTOR = "#activity-name, #meta_content, #js_name, #publish_time, #js_pc_qr_code, #js_view_source, #js_toobar3, #js_bottom_ad_area, .rich_media_title, .rich_media_meta_list, .rich_media_tool";

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function requestArticle(url) {
        return fetch(ENDPOINT, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": csrfToken(),
            },
            body: JSON.stringify({ url: url }),
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.message || "抓取失败（HTTP " + response.status + "）");
                    }

                    return data;
                });
        });
    }

    function openDialog(editor) {
        editor.windowManager.open({
            title: "抓取公众号文章",
            size: "medium",
            body: {
                type: "panel",
                items: [
                    {
                        type: "htmlpanel",
                        html:
                            '<p style="margin:0 0 8px;color:#6b7280;font-size:13px;line-height:1.6">' +
                            "粘贴公众号图文链接（https://mp.weixin.qq.com/s/…）。抓取后正文插入光标处，" +
                            "图片自动存入媒体库。" +
                            "</p>",
                    },
                    {
                        type: "input",
                        name: "url",
                        label: "文章链接",
                        placeholder: "https://mp.weixin.qq.com/s/…",
                    },
                ],
            },
            buttons: [
                { type: "cancel", text: "取消" },
                { type: "submit", text: "抓取并插入", primary: true },
            ],
            onSubmit: function (api) {
                var url = String(api.getData().url || "").trim();

                if (!url) {
                    editor.notificationManager.open({
                        text: "请先粘贴文章链接",
                        type: "warning",
                        timeout: 3000,
                    });

                    return;
                }

                api.close();

                var notice = editor.notificationManager.open({
                    text: "正在抓取公众号文章…",
                    type: "info",
                    timeout: 0,
                });

                requestArticle(url)
                    .then(function (data) {
                        notice.close();

                        if (!data.html) {
                            throw new Error("没有抓到正文");
                        }

                        editor.insertContent(data.html);
                        editor.undoManager.add();

                        var summary = "已插入公众号正文（图片 " + (data.imported || 0) + " 张已存入媒体库）";

                        if (data.failed) {
                            summary += "，" + data.failed + " 张未能转存，保留原外链";
                        }

                        editor.notificationManager.open({ text: summary, type: "success", timeout: 5000 });
                    })
                    .catch(function (error) {
                        notice.close();
                        editor.notificationManager.open({
                            text: error && error.message ? error.message : "抓取失败",
                            type: "error",
                            timeout: 7000,
                        });
                    });
            },
        });
    }

    /**
     * 非公众号片段返回 null；已识别页面但没有可靠正文返回空串，禁止回退整页粘贴。
     */
    function extractFromClipboard(html) {
        var recognized = false;

        try {
            var doc = new DOMParser().parseFromString(html, "text/html");
            var candidates = doc.querySelectorAll("#js_content");

            if (!candidates.length) {
                candidates = doc.querySelectorAll(".rich_media_content");
            }

            if (!candidates.length) {
                return doc.querySelector(PAGE_CHROME_SELECTOR + ", .rich_media_area_primary") ? "" : null;
            }

            recognized = true;

            if (candidates.length !== 1) {
                return "";
            }

            var container = candidates[0];

            if (/^(HTML|BODY)$/.test(container.tagName) || container.matches(PAGE_CHROME_SELECTOR) || container.querySelector(PAGE_CHROME_SELECTOR)) {
                return "";
            }

            Array.prototype.forEach.call(container.querySelectorAll("img"), function (image) {
                var source = image.getAttribute("data-src") || image.getAttribute("data-original");

                if (source) {
                    image.setAttribute("src", source);
                    image.removeAttribute("data-src");
                    image.removeAttribute("data-original");
                }

                image.removeAttribute("class");
                // 保留图片的静态排版；保存时由服务端 CSS 白名单再次过滤。
                image.setAttribute("style", "max-width: 100%; vertical-align: middle; " + (image.getAttribute("style") || ""));
            });

            Array.prototype.forEach.call(container.querySelectorAll("script, style"), function (node) {
                node.parentNode.removeChild(node);
            });

            Array.prototype.forEach.call(container.querySelectorAll("a"), function (link) {
                link.setAttribute("style", "color: #576b95; text-decoration: none; " + (link.getAttribute("style") || ""));
            });

            // 注释不算正文；不能按 textContent 判空，否则会误删图片和空装饰节点。
            var walker = doc.createTreeWalker(container, 128);
            var comments = [];
            var comment;

            while ((comment = walker.nextNode())) {
                comments.push(comment);
            }

            comments.forEach(function (node) {
                node.parentNode.removeChild(node);
            });

            var body = container.innerHTML.trim();

            // innerHTML 只有纯文字也是有效正文，不能因此把原来的整页交还默认粘贴。
            return !body ? "" :
                '<section data-type="wechat-article" style="font-size: 17px; line-height: 1.6; color: rgba(0, 0, 0, 0.9); text-align: justify; overflow-wrap: break-word">' + body + '</section>';
        } catch (error) {
            // 已认出公众号容器后处理失败，也不能把整页交还默认粘贴。
            return recognized ? "" : null;
        }
    }

    tinymce.PluginManager.add(PLUGIN, function (editor) {
        editor.on("PreInit", function () {
            // TinyMCE 默认删空 span，公众号常用有尺寸/背景的空节点绘制装饰。
            // 只关闭该标签的空内容删除；属性过滤不变，无属性空 span 仍由原规则清理。
            var span = editor.schema.getElementRule("span");

            if (span) {
                span.removeEmpty = false;
            }
        });

        // 双对话气泡区分公众号抓取与视频插入，随工具栏主题使用 currentColor。
        editor.ui.registry.addIcon("wechat-article", '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 8.6C14.4 5.4 11.7 3 8.4 3 4.9 3 2 5.6 2 8.8c0 1.8.9 3.4 2.4 4.5L4 16l2.9-1.5 1.5.2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 14.5c0-3-2.8-5.4-6.2-5.4s-6.2 2.4-6.2 5.4 2.8 5.4 6.2 5.4l1.4-.1L20 21l-.4-2.5c1.5-1 2.4-2.4 2.4-4Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><g fill="currentColor"><circle cx="6.2" cy="7.6" r=".9"/><circle cx="10.5" cy="7.6" r=".9"/><circle cx="13.7" cy="13.4" r=".9"/><circle cx="18" cy="13.4" r=".9"/></g></svg>');
        editor.ui.registry.addButton(BUTTON, {
            icon: "wechat-article",
            tooltip: "插入公众号文章",
            onAction: function () {
                openDialog(editor);
            },
        });

        editor.on("paste", function (event) {
            var clipboard = event.clipboardData || (window.clipboardData && { getData: window.clipboardData.getData });

            if (!clipboard || typeof clipboard.getData !== "function") {
                return;
            }

            var html = clipboard.getData("text/html");

            if (!html || !/(?:js_content|rich_media_content|rich_media_area_primary|rich_media_title|rich_media_meta_list|rich_media_tool|activity-name|meta_content|js_name|publish_time|js_pc_qr_code|js_view_source|js_toobar3|js_bottom_ad_area)/.test(html)) {
                return;
            }

            var body = extractFromClipboard(html);

            if (body === null) {
                return;
            }

            event.preventDefault();

            if (body === "") {
                editor.notificationManager.open({
                    text: "未识别到可靠的公众号正文，已阻止整页粘贴，请使用工具栏按文章链接抓取",
                    type: "warning",
                    timeout: 6000,
                });

                return;
            }

            editor.insertContent(body);
            editor.undoManager.add();
            editor.notificationManager.open({ text: "已按公众号正文粘贴（去掉页面外壳）", type: "success", timeout: 3000 });
        });
    });
})();
