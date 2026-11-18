/**
 * TinyMCE 与 Livewire 表单状态同步的兜底桥接。
 *
 * 背景：上游 filament-tinyeditor 只在 `blur change keyup` 时把编辑器内容写回 Livewire 状态
 * （见 vendor/amidesfahani/filament-tinyeditor/resources/views/tiny-editor.blade.php 的 setup 回调）。
 * 图片是通过 `images_upload_handler` 异步插入的，TinyMCE 对这种程序化插入不一定会触发 `change`；
 * 用户「插完图直接点保存」时，队列里的状态可能还是插图前的 HTML，于是正文里的图片丢失。
 *
 * 这里不复制上游视图、也不打 patch，只在捕获阶段于保存按钮 mousedown/click 与表单 submit 之前
 * 主动 flush 一次 `editor.getContent()`，并订阅更多 TinyMCE 事件保持同步。
 */
(() => {
    'use strict';

    const findStatePath = (root) => {
        const alpine = window.Alpine;

        if (!root || !alpine || typeof alpine.$data !== 'function') {
            return null;
        }

        try {
            const data = alpine.$data(root);
            const statePath = data?.statePath;

            return typeof statePath === 'string' && statePath !== '' ? statePath : null;
        } catch (error) {
            return null;
        }
    };

    const livewireProxyOf = (root) => {
        const livewire = window.Livewire;
        const wireRoot = root?.closest?.('[wire\\:id]');

        if (!wireRoot || !livewire || typeof livewire.find !== 'function') {
            return null;
        }

        try {
            return livewire.find(wireRoot.getAttribute('wire:id'));
        } catch (error) {
            return null;
        }
    };

    const flush = (editor) => {
        const element = editor?.getElement?.();
        const root = element?.closest?.('[x-data]');
        const statePath = findStatePath(root);
        const wire = livewireProxyOf(root);

        if (!statePath || typeof wire?.$set !== 'function') {
            return;
        }

        try {
            // 第三个参数 false：只更新前端状态，不额外发请求；随后的 wire:click / submit 请求会带上它。
            wire.$set(statePath, editor.getContent(), false);
        } catch (error) {
            // 静默：同步失败不应阻断保存流程。
        }
    };

    const flushAll = () => {
        const tinymce = window.tinymce;

        if (!tinymce || typeof tinymce.get !== 'function') {
            return;
        }

        for (const editor of tinymce.get()) {
            if (editor && !editor.isHidden?.()) {
                flush(editor);
            }
        }
    };

    const hostOf = (src) => {
        if (!src) {
            return '';
        }

        try {
            return new URL(src, window.location.href).hostname.toLowerCase().replace(/\.$/, '');
        } catch (error) {
            return '';
        }
    };

    const hostMatches = (host, patterns) => {
        const bare = host.replace(/^www\./, '');

        return patterns.some((pattern) => {
            const value = String(pattern || '').toLowerCase().trim().replace(/\.$/, '');

            if (value === '') {
                return false;
            }

            const target = value.replace(/^www\./, '');

            if (target === bare) {
                return true;
            }

            if (!target.includes('*')) {
                return false;
            }

            const regex = new RegExp('^' + target.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');

            return regex.test(bare);
        });
    };

    const DEFAULT_EMBED_SIZE = { width: 640, height: 360 };

    // TinyMCE 的 media 插件会把嵌入内容包成 mce-preview-object 占位，并把宽高丢进 data-mce-p-*
    // 或直接丢掉（掉成 300×150）。占位框本身要保留 —— 编辑时靠它选中、拖拽调整大小，
    // 这里只把宽高补回内层 iframe，并摘掉白名单域名的沙箱，让编辑器内就能预览播放。
    const applyEmbedSize = (editor, patterns) => {
        const body = editor?.getBody?.();

        if (!body || patterns.length === 0) {
            return;
        }

        body.querySelectorAll('.mce-preview-object[data-mce-object="iframe"]').forEach((wrapper) => {
            const frame = wrapper.querySelector('iframe');

            if (!frame) {
                return;
            }

            const src = frame.getAttribute('src') || wrapper.getAttribute('data-mce-p-src') || '';
            const host = hostOf(src);

            if (!host || !hostMatches(host, patterns)) {
                return;
            }

            const width = String(wrapper.getAttribute('data-mce-p-width') || frame.getAttribute('width') || '');
            const height = String(wrapper.getAttribute('data-mce-p-height') || frame.getAttribute('height') || '');

            frame.setAttribute('width', /^\d{1,5}$/.test(width) ? width : String(DEFAULT_EMBED_SIZE.width));
            frame.setAttribute('height', /^\d{1,5}$/.test(height) ? height : String(DEFAULT_EMBED_SIZE.height));

            if (src !== '' && frame.getAttribute('src') !== src) {
                frame.setAttribute('src', src);
            }

            frame.removeAttribute('sandbox');
            reloadWithoutSandbox(frame);
        });
    };

    // sandbox 是在文档开始加载那一刻生效的：TinyMCE 先加沙箱、我们再摘掉，已经被拦下的脚本
    // 不会因为属性消失而重新执行，播放器就永远停在白屏。这里让每个白名单 iframe 以「无沙箱」
    // 状态重新加载一次（每个元素只做一次，避免每次输入都重载）。
    const reloadWithoutSandbox = (frame) => {
        if (frame.__cmfEmbedReloaded) {
            return;
        }

        const src = frame.getAttribute('src');

        if (!src || src === 'about:blank') {
            return;
        }

        frame.__cmfEmbedReloaded = true;
        frame.setAttribute('src', 'about:blank');

        window.setTimeout(() => {
            frame.setAttribute('src', src);
        }, 0);
    };

    // TinyMCE 的 sandbox_iframes 会在解析内容时给 iframe 补 sandbox=""，白名单命中的播放器
    // 因此被浏览器拦住脚本（控制台报 "frame is sandboxed"）。这里在编辑器内直接摘掉沙箱，
    // 只对配置白名单里的域名生效；保存时服务端 HtmlSanitizer 另有同样的域名校验并剥掉 sandbox。
    const releaseSandbox = (editor) => {
        const patterns = editor?.options?.get?.('sandbox_iframes_exclusions') || [];
        const body = editor?.getBody?.();

        if (!body || !Array.isArray(patterns) || patterns.length === 0) {
            return;
        }

        body.querySelectorAll('iframe[sandbox]').forEach((frame) => {
            const host = hostOf(frame.getAttribute('src'));

            if (!host || !hostMatches(host, patterns)) {
                return;
            }

            frame.removeAttribute('sandbox');
            reloadWithoutSandbox(frame);
        });
    };

    const normalizeEmbeds = (editor) => {
        const patterns = editor?.options?.get?.('sandbox_iframes_exclusions') || [];

        if (!Array.isArray(patterns) || patterns.length === 0) {
            return;
        }

        applyEmbedSize(editor, patterns);
        releaseSandbox(editor);
    };

    // 图片上传走 cmf-media 浏览器直传（与视频共用同一套协议）：TinyMCE 拿回 media.url 插图，
    // 我们再给插入的 <img> 补 data-cmf-media——服务端 MediaReferences 只认这个属性，
    // 少了它引用计不进去，24 小时后会被 cmf-media:prune-orphans 物理删除。
    const sameSrc = (left, right) => {
        if (!left || !right) {
            return false;
        }

        try {
            const a = new URL(left, window.location.href);
            const b = new URL(right, window.location.href);

            return a.origin === b.origin && a.pathname === b.pathname;
        } catch (error) {
            return left === right;
        }
    };

    /**
     * 把待落标的图片记在编辑器上：TinyMCE 要等对话框点「保存」才把 <img> 插进正文，
     * 上传回调那一刻正文里还没有这张图，所以只能等它出现再补属性。
     */
    const registerMediaId = (editor, media) => {
        if (!media?.id || !media?.url) {
            return;
        }

        editor.__cmfPendingMedia = editor.__cmfPendingMedia || [];
        editor.__cmfPendingMedia.push({ id: media.id, url: media.url });

        stampPendingMediaIds(editor);
    };

    const stampPendingMediaIds = (editor) => {
        const pending = editor?.__cmfPendingMedia;
        const body = editor?.getBody?.();

        if (!Array.isArray(pending) || pending.length === 0 || !body) {
            return false;
        }

        let stamped = false;

        editor.__cmfPendingMedia = pending.filter((entry) => {
            let matched = false;

            body.querySelectorAll('img:not([data-cmf-media])').forEach((image) => {
                if (sameSrc(image.getAttribute('src'), entry.url)) {
                    image.setAttribute('data-cmf-media', String(entry.id));
                    matched = true;
                    stamped = true;
                }
            });

            return !matched;
        });

        if (stamped) {
            flush(editor);
        }

        return stamped;
    };

    /** 图片对话框是否正开着（TinyMCE 自带的「插入/编辑图片」）。 */
    const imageDialogOpen = () => {
        const dialogs = Array.from(document.querySelectorAll('.tox-dialog'));

        return dialogs.some((dialog) => {
            const title = (dialog.querySelector('.tox-dialog__title')?.textContent || '').trim();

            return /图片|image/i.test(title);
        });
    };

    const escapeAttribute = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    /**
     * 上传页签里选完图就直接落进正文。
     *
     * TinyMCE 原生流程是「上传完成 → 切回一般页签 → 用户点保存」，多两步且容易以为没成功。
     * 这里等插件把地址回填完（微任务跑完）再关对话框、自己插入 <img>，尺寸取服务端返回的原始宽高。
     */
    const insertUploadedImage = (editor, media) => {
        if (!media?.url) {
            return;
        }

        const size = [
            Number(media.width) > 0 ? ` width="${Number(media.width)}"` : '',
            Number(media.height) > 0 ? ` height="${Number(media.height)}"` : '',
        ].join('');

        editor.insertContent(
            `<img src="${escapeAttribute(media.url)}" alt=""${size} data-cmf-media="${Number(media.id)}">`,
        );
        editor.undoManager?.add?.();
        flush(editor);
    };

    /**
     * 图片对话框「上传」页签的 change 事件在 document 捕获阶段截胡。
     *
     * TinyMCE 自己的处理是「传完 → 切回一般页签 → 等用户点保存」，切页签这一下会闪一下一般面板。
     * 在捕获阶段拦下事件（插件的监听挂在 input 上，属于目标阶段，捕获先跑），插件就不会介入，
     * 由我们直接上传、关对话框、插入 <img>，页签始终停在上传。
     */
    const imageDialogStatus = (dialog) => {
        let status = dialog.querySelector('.cmf-image-upload-status');

        if (!status) {
            status = document.createElement('p');
            status.className = 'cmf-image-upload-status';
            status.style.margin = '8px 0 0';
            status.style.fontSize = '12px';
            status.style.color = '#666';

            const panel = dialog.querySelector('.tox-dialog__body-content');

            if (!panel) {
                return null;
            }

            panel.appendChild(status);
        }

        return status;
    };

    const editorForDialog = () => {
        const tinymce = window.tinymce;

        if (!tinymce) {
            return null;
        }

        return tinymce.activeEditor
            || tinymce.editors[tinymce.editors.length - 1]
            || null;
    };

    const handleImageDialogFile = (event) => {
        const input = event.target;

        if (!input || input.tagName !== 'INPUT' || input.type !== 'file') {
            return;
        }

        const dialog = input.closest('.tox-dialog');

        if (!dialog || input.closest('.cmf-video-tab-panel')) {
            return;
        }

        const title = (dialog.querySelector('.tox-dialog__title')?.textContent || '').trim();

        if (!/图片|image/i.test(title)) {
            return;
        }

        const file = input.files && input.files[0];

        if (!file) {
            return;
        }

        const editor = editorForDialog();
        const uploader = window.CmfRichContentMediaUpload;

        if (!editor || !uploader) {
            return;
        }

        // 抢在插件之前处理，避免它切回「一般」页签造成闪动
        event.preventDefault();
        event.stopPropagation();

        const status = imageDialogStatus(dialog);
        const setStatus = (message) => {
            if (status) {
                status.textContent = message;
            }
        };

        setStatus('正在上传…');

        uploader.upload(editor, file, 'image', (progress) => {
            const percent = Number(progress?.percent);

            setStatus(Number.isFinite(percent) ? `正在上传… ${Math.round(percent)}%` : '正在上传…');
        }).then((media) => {
            try {
                editor.windowManager.close();
            } catch (error) {
                // 对话框可能已经关掉，忽略
            }

            insertUploadedImage(editor, media);
        }).catch((error) => {
            setStatus(`上传失败：${error?.message || '未知错误'}`);
            input.value = '';
        });
    };

    const installImageDialogUploadCapture = () => {
        if (window.__cmfImageUploadCaptureInstalled) {
            return;
        }

        window.__cmfImageUploadCaptureInstalled = true;
        document.addEventListener('change', handleImageDialogFile, true);
    };

    const installImageUploader = (editor) => {
        const uploader = window.CmfRichContentMediaUpload;

        if (!uploader || typeof editor?.options?.set !== 'function') {
            return;
        }

        editor.options.set('images_upload_handler', (blobInfo) => {
            // 拖拽/粘贴进来的图片没有对话框，交给 TinyMCE 自己插；对话框里上传则直接收尾
            const fromDialog = imageDialogOpen();

            return uploader.upload(editor, blobInfo.blob(), 'image', () => {}).then((media) => {
                registerMediaId(editor, media);

                if (fromDialog) {
                    window.setTimeout(() => {
                        if (imageDialogOpen()) {
                            try {
                                editor.windowManager.close();
                            } catch (error) {
                                // 对话框可能已被插件自己关掉，忽略
                            }
                        }

                        insertUploadedImage(editor, media);
                    }, 0);
                }

                return media.url;
            });
        });
    };

    const bindEditor = (editor) => {
        if (!editor || editor.__richContentBound) {
            return;
        }

        editor.__richContentBound = true;

        // AddEditor 早于 PreInit，此时写入的 images_upload_handler 会被 EditorUpload 采纳。
        installImageUploader(editor);
        installImageDialogUploadCapture();
        window.CmfRichContentSvgSelection?.bind(editor, flush);

        // 覆盖程序化插入（上传图片、粘贴、拖拽）与撤销/重做等不保证触发 change 的路径。
        editor.on('input change undo redo SetContent ObjectResized ExecCommand', () => {
            normalizeEmbeds(editor);
            stampPendingMediaIds(editor);
            flush(editor);
        });

        const observeSandbox = () => {
            const body = editor.getBody?.();

            if (!body || editor.__richContentSandboxObserver) {
                return;
            }

            // sandbox 属性可能在内容解析之后才由 TinyMCE 补上，用属性观察兜住这个时序。
            editor.__richContentSandboxObserver = new MutationObserver(() => {
                normalizeEmbeds(editor);
                // 图片是点对话框「保存」后才插进正文的，这里兜住那个时刻。
                stampPendingMediaIds(editor);
            });
            editor.__richContentSandboxObserver.observe(body, {
                attributes: true,
                attributeFilter: ['sandbox'],
                childList: true,
                subtree: true,
            });

            normalizeEmbeds(editor);
        };

        // DOMPurify 会移除 <animate>，声明保存在 data-* 中，内容装载后按安全白名单恢复。
        const hydrateSvg = () => window.CmfRichContentSvg?.hydrate(editor.getBody?.());
        editor.on('init SetContent', hydrateSvg);
        if (editor.initialized) {
            hydrateSvg();
        }

        editor.on('init SetContent PostProcess', observeSandbox);

        if (editor.initialized) {
            observeSandbox();
        }
    };

    const bindAll = () => {
        const tinymce = window.tinymce;

        if (!tinymce) {
            return;
        }

        if (typeof tinymce.get === 'function') {
            tinymce.get().forEach(bindEditor);
        }

        if (typeof tinymce.on === 'function' && !tinymce.__richContentAddEditorBound) {
            tinymce.__richContentAddEditorBound = true;
            tinymce.on('AddEditor', (event) => bindEditor(event.editor));
        }
    };

    const submitSelector = 'button[type="submit"], [type="submit"], .fi-btn, .fi-ac-btn, [wire\\:click]';

    const isSubmitTarget = (event) => Boolean(event.target?.closest?.(submitSelector));

    // 捕获阶段：先于 Livewire 的点击处理执行，确保状态在请求组装前到位。
    document.addEventListener('mousedown', (event) => {
        if (isSubmitTarget(event)) {
            flushAll();
        }
    }, true);

    document.addEventListener('click', (event) => {
        if (isSubmitTarget(event)) {
            flushAll();
        }
    }, true);

    document.addEventListener('submit', flushAll, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll, { once: true });
    } else {
        bindAll();
    }

    // tinymce 主脚本可能晚于本文件加载，短暂轮询补齐绑定。
    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts += 1;
        bindAll();

        if (attempts >= 60) {
            window.clearInterval(timer);
        }
    }, 500);

    document.addEventListener('livewire:navigated', bindAll);
    document.addEventListener('livewire:init', bindAll);
})();
