/**
 * 顶层 SVG 卡片的编辑器选框。选框属于临时 UI（data-mce-bogus=all），
 * 正文只保存 SVG 自身的尺寸，不包裹卡片、不重建动画节点。
 */
(() => {
    'use strict';

    const bind = (editor, flush) => {
        if (editor.__cmfSvgSelectionBound) return;
        editor.__cmfSvgSelectionBound = true;

        const init = () => {
            const doc = editor.getDoc();
            const win = doc.defaultView;
            const body = editor.getBody();
            let selected = null;
            let box = null;
            let drag = null;
            let observer = null;
            const listeners = [];
            const listen = (target, event, fn, options) => {
                target.addEventListener(event, fn, options);
                listeners.push(() => target.removeEventListener(event, fn, options));
            };
            const editable = () => !editor.mode.isReadOnly();
            const hide = () => {
                drag = null;
                selected = null;
                observer?.disconnect();
                box?.remove();
                box = null;
            };
            const draw = (rect) => {
                if (!box) return;
                Object.assign(box.style, {
                    left: `${rect.left}px`, top: `${rect.top}px`,
                    width: `${rect.width}px`, height: `${rect.height}px`,
                });
            };
            const refresh = () => {
                if (!selected?.isConnected || !editable()) {
                    hide();
                    return;
                }
                if (!drag) draw(selected.getBoundingClientRect());
            };
            const resize = (event) => {
                if (!drag || !selected) return;
                event.preventDefault();
                const dx = (event.screenX - drag.x) * drag.horizontal;
                const dy = (event.screenY - drag.y) * drag.vertical;
                const delta = Math.abs(dx) >= Math.abs(dy * drag.ratio) ? dx : dy * drag.ratio;
                drag.width = Math.round(Math.min(10000, Math.max(24, drag.rect.width + delta)));
                drag.height = Math.max(1, Math.round(drag.width / drag.ratio));
                draw({ ...drag.rect, width: drag.width, height: drag.height });
                box.title = `${drag.width} × ${drag.height}`;
            };
            const finish = () => {
                if (!drag || !selected) return;
                const target = selected;
                const { width, height, rect } = drag;
                drag = null;
                if (!target.isConnected || !editable()) {
                    hide();
                    return;
                }
                // 一次拖动作为一个撤销步骤；保留 viewBox 和子树，动画时间轴不重置。
                editor.undoManager.transact(() => {
                    // 没有 viewBox 的普通 SVG 也要缩放内部图形，而不是只缩小视口造成裁切。
                    if (!target.hasAttribute('viewBox')) {
                        target.setAttribute('viewBox', `0 0 ${rect.width} ${rect.height}`);
                    }
                    target.setAttribute('width', String(width));
                    target.setAttribute('height', String(height));
                    editor.dom.setStyles(target, { width: `${width}px`, height: 'auto', 'max-width': '100%' });
                });
                editor.dispatch('ObjectResized', { target, width, height, origin: 'cmf-svg' });
                editor.nodeChanged();
                flush(editor);
                refresh();
            };
            const show = (svg) => {
                hide();
                selected = svg;
                box = doc.createElement('div');
                box.className = 'cmf-svg-selection';
                box.setAttribute('data-mce-bogus', 'all');
                box.setAttribute('contenteditable', 'false');
                box.setAttribute('aria-label', 'SVG 卡片尺寸调整');
                Object.assign(box.style, {
                    position: 'fixed', border: '1px solid #4099ff', boxSizing: 'border-box',
                    pointerEvents: 'none', zIndex: '2147483646',
                });
                for (const corner of ['nw', 'ne', 'sw', 'se']) {
                    const handle = doc.createElement('span');
                    handle.className = `cmf-svg-resize-handle cmf-svg-resize-${corner}`;
                    handle.setAttribute('title', '拖动调整 SVG 大小（保持比例）');
                    Object.assign(handle.style, {
                        position: 'absolute', width: '10px', height: '10px', background: '#4099ff',
                        border: '1px solid white', boxSizing: 'border-box', pointerEvents: 'auto',
                        cursor: `${corner}-resize`,
                        [corner.includes('n') ? 'top' : 'bottom']: '-5px',
                        [corner.includes('w') ? 'left' : 'right']: '-5px',
                    });
                    handle.addEventListener('mousedown', (event) => {
                        if (event.button !== 0 || !editable()) return;
                        event.preventDefault();
                        event.stopPropagation();
                        const rect = selected.getBoundingClientRect();
                        if (rect.width <= 0 || rect.height <= 0) return;
                        drag = {
                            rect: { left: rect.left, top: rect.top, width: rect.width, height: rect.height },
                            x: event.screenX, y: event.screenY, ratio: rect.width / rect.height,
                            horizontal: corner.includes('w') ? -1 : 1,
                            vertical: corner.includes('n') ? -1 : 1,
                            width: Math.round(rect.width), height: Math.round(rect.height),
                        };
                    });
                    box.appendChild(handle);
                }
                body.appendChild(box);
                observer = new win.ResizeObserver(refresh);
                observer.observe(svg);
                refresh();
            };
            listen(doc, 'mousedown', (event) => {
                if (box?.contains(event.target)) return;
                if (event.button !== 0 || !editable()) {
                    hide();
                    return;
                }
                let svg = event.target?.closest?.('svg');
                if (!svg || !body.contains(svg)) {
                    hide();
                    return;
                }
                // 点击内部 image/g 或嵌套 SVG 时，始终选择整张顶层卡片。
                while (svg.parentElement?.closest('svg')) svg = svg.parentElement.closest('svg');
                event.preventDefault();
                editor.focus();
                editor.selection.select(svg);
                editor.nodeChanged();
                show(svg);
            });
            listen(doc, 'mousemove', resize);
            listen(doc, 'mouseup', finish);
            if (doc !== document) {
                listen(document, 'mousemove', resize);
                listen(document, 'mouseup', finish);
            }
            listen(win, 'scroll', refresh, true);
            listen(win, 'resize', refresh);
            listen(window, 'blur', hide);
            editor.on('keydown', (event) => { if (event.key === 'Escape') hide(); });
            editor.on('blur SetContent SwitchMode', hide);
            editor.on('undo redo NodeChange ResizeEditor', refresh);
            editor.on('remove', () => {
                hide();
                listeners.forEach((remove) => remove());
            });
        };

        if (editor.initialized) init();
        else editor.on('init', init);
    };

    window.CmfRichContentSvgSelection = { bind };
})();
