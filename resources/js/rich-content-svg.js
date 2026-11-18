/**
 * TinyMCE 的 SVG 清洗会删除 <animate>，但保留 data-*。
 * 用经过验证的声明恢复 opacity 动画，保留 DOMPurify，不开放任意 SMIL 属性。
 * 前台与编辑器共用本实现，声明随正文往返保存。
 */
(() => {
    'use strict';

    const ATTRIBUTE = 'data-cmf-svg-opacity';
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const allowed = new Set(['attributeName', 'values', 'dur', 'begin', 'repeatCount', 'fill', 'keyTimes', 'keySplines', 'calcMode', 'restart']);
    const number = /^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;
    const unit = (value) => number.test(value) && Number(value) >= 0 && Number(value) <= 1;
    const time = (value, positive) => {
        if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?(?:ms|s)?$/.test(value)) return false;
        const seconds = Number.parseFloat(value) / (value.endsWith('ms') ? 1000 : 1);
        return Number.isFinite(seconds) && (!positive || (seconds > 0 && seconds <= 3600));
    };
    const validate = (raw) => {
        if (!raw || typeof raw !== 'object' || Array.isArray(raw) || Object.keys(raw).some((key) => !allowed.has(key))) return null;
        if (Object.values(raw).some((value) => typeof value !== 'string')) return null;
        if (raw.attributeName !== 'opacity' || !time(raw.dur || '', true)) return null;
        const values = (raw.values || '').split(';').map((value) => value.trim());
        if (values.length < 1 || values.length > 32 || !values.every(unit)) return null;
        if (raw.begin !== undefined && !time(raw.begin, false)) return null;
        if (raw.repeatCount !== undefined && raw.repeatCount !== 'indefinite' && (!number.test(raw.repeatCount) || Number(raw.repeatCount) <= 0 || Number(raw.repeatCount) > 1000000)) return null;
        if (raw.fill !== undefined && !['remove', 'freeze'].includes(raw.fill)) return null;
        if (raw.restart !== undefined && !['always', 'whenNotActive', 'never'].includes(raw.restart)) return null;
        if (raw.calcMode !== undefined && !['linear', 'discrete', 'spline'].includes(raw.calcMode)) return null;
        if (raw.keyTimes !== undefined) {
            const times = raw.keyTimes.split(';').map((value) => value.trim());
            if (times.length !== values.length || !times.every(unit) || Number(times[0]) !== 0 || (raw.calcMode !== 'discrete' && Number(times[times.length - 1]) !== 1) || times.some((value, index) => index > 0 && Number(value) < Number(times[index - 1]))) return null;
        }
        if (raw.keySplines !== undefined) {
            const splines = raw.keySplines.split(';').map((value) => value.trim().split(/[\s,]+/));
            if (splines.length !== values.length - 1 || splines.some((spline) => spline.length !== 4 || !spline.every(unit))) return null;
        }
        if (raw.calcMode === 'spline' && (!raw.keySplines || !raw.keyTimes)) return null;
        return raw;
    };

    const hydrate = (root) => {
        if (!root?.querySelectorAll) return;
        root.querySelectorAll(`svg g[${ATTRIBUTE}]`).forEach((group) => {
            if (group.namespaceURI !== SVG_NS) return;
            let attributes;
            try {
                const encoded = group.getAttribute(ATTRIBUTE);
                if (encoded.length > 16384) return;
                attributes = validate(JSON.parse(encoded));
            } catch (error) { return; }
            if (!attributes) return;
            // 已有合法动画继续播放，重复初始化不会重置时间轴。
            if (Array.from(group.children).some((child) => child.localName === 'animate' && child.getAttribute('attributeName') === 'opacity')) return;
            const animation = group.ownerDocument.createElementNS(SVG_NS, 'animate');
            Object.entries(attributes).forEach(([key, value]) => animation.setAttribute(key, value));
            group.appendChild(animation);
            // 动态插入时以当前 SVG 时间为起点，避免 begin=0s 在编辑器里已过期。
            if (typeof animation.beginElement === 'function') animation.beginElement();
        });
    };

    if (window.CmfRichContentSvg) return;
    window.CmfRichContentSvg = { hydrate };

    const hydrateBodies = () => document.querySelectorAll('.cmf-rich-content-body').forEach(hydrate);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrateBodies, { once: true });
    } else {
        hydrateBodies();
    }
    document.addEventListener('livewire:navigated', hydrateBodies);
})();
