const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

function element() {
    const attrs = new Map();
    return {
        children: [], events: {}, style: {}, textContent: '',
        classList: { toggle() {}, contains() { return false; } },
        setAttribute(name, value) { attrs.set(name, value); },
        getAttribute(name) { return attrs.get(name); },
        appendChild(child) { this.children.push(child); },
        insertBefore(child) { this.children.unshift(child); },
        addEventListener(name, handler) { this.events[name] = handler; },
        querySelector() { return null; }, querySelectorAll() { return []; },
    };
}

function fixture() {
    const notifications = [];
    const inserted = [];
    const events = {};
    const editor = {
        initialized: false,
        options: { set() {} },
        on(name, callback) { events[name] = callback; },
        notificationManager: { open(options) { notifications.push(options); } },
        windowManager: { close() {} },
        insertContent(html) { inserted.push(html); },
        undoManager: { add() {} },
    };
    const docEvents = {};
    const document = {
        readyState: 'complete',
        addEventListener(name, callback) { docEvents[name] = callback; },
        createElement: element,
    };
    const tinymce = {
        activeEditor: editor,
        get: () => [editor],
        PluginManager: { add(name, init) { init(editor); } },
    };
    const window = { tinymce, setInterval: (callback) => { window.tick = callback; }, clearInterval() {} };
    const context = vm.createContext({ document, window, tinymce });
    function load(name) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, `../resources/js/${name}.js`), 'utf8'), context, { filename: name });
    }
    load('rich-content-media-upload');
    return { window, document, docEvents, events, notifications, inserted, load, api: window.CmfRichContentMediaUpload };
}

for (const [failure, mixedAssets] of [[false, false], [true, false], [false, true], [true, true]]) {
    test(`video upload ${failure ? 'failure' : 'success'}${mixedAssets ? ' with a legacy uploader' : ''} handles notifications without an unhandled rejection`, async () => {
        const f = fixture();
        const nav = element();
        const body = element();
        const dialog = element();
        dialog.querySelector = (selector) => ({ '.tox-dialog__body-nav': nav, '.tox-dialog__body': body, '.cmf-video-tab-panel': body.children[0] }[selector] ?? null);
        f.document.querySelectorAll = () => [dialog];
        if (mixedAssets) delete f.api.notify;
        f.api.endpoints = () => ({ maxSize: 0 });
        f.api.upload = async () => {
            if (failure) throw new Error('上传失败测试');
            return { id: 12, url: '/video.mp4' };
        };
        f.load('tinymce-video');
        f.events.ExecCommand({ command: 'mceMedia' });
        f.window.tick();
        const [, pick, status, input] = body.children[0].children;
        input.files = [{ name: 'video.mp4' }];
        input.events.change();
        await new Promise(setImmediate);
        assert.equal(pick.disabled, false);
        assert.equal(f.notifications.length, mixedAssets ? 0 : 1);
        if (!mixedAssets) assert.equal(f.notifications[0].type, failure ? 'error' : 'success');
        if (failure) {
            assert.equal(status.textContent, '上传失败测试');
            assert.equal(f.inserted.length, 0);
        } else {
            assert.match(f.inserted[0], /data-cmf-media="12"/);
        }
    });
}

test('image dialog renders upload stages and percentage from the two-argument callback', async () => {
    const f = fixture();
    const dialog = element();
    const panel = element();
    dialog.querySelector = (selector) => ({ '.tox-dialog__title': { textContent: '插入图片' }, '.tox-dialog__body-content': panel }[selector] ?? null);
    const input = { tagName: 'INPUT', type: 'file', files: [{}], closest: (selector) => selector === '.tox-dialog' ? dialog : null };
    f.api.upload = async (editor, file, kind, progress) => {
        assert.equal(kind, 'image');
        assert.equal(panel.children[0].textContent, '正在上传…');
        progress('正在上传…', 0.42);
        assert.equal(panel.children[0].textContent, '正在上传… 42%');
        progress('正在登记…', 1);
        assert.equal(panel.children[0].textContent, '正在登记… 100%');
        return { id: 13, url: '/photo.png' };
    };
    f.load('rich-content-tinymce-bridge');
    f.docEvents.change({ target: input, preventDefault() {}, stopPropagation() {} });
    await new Promise(setImmediate);
    assert.match(f.inserted[0], /data-cmf-media="13"/);
});
