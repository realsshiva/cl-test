// cakebuilder.js: step flow, live SVG cake preview and the little game effects
// for cakebuilder.php. The form inputs are the single source of truth; the preview
// and summary are redrawn from them after every change.
(() => {
    'use strict';

    const form = document.getElementById('cake-form');
    if (!form) return;

    const data = JSON.parse(document.getElementById('cb-data').textContent);
    const recipes = new Map(data.recipes.map((r) => [r.id, r]));
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const els = {
        svg: $('#cb-cake'),
        stage: $('#cb-stage'),
        fx: $('#cb-fx'),
        caption: $('#cb-caption'),
        sections: $$('.cb-step'),
        dots: $$('.cb-step-dot'),
        bar: $('#cb-progress-bar'),
        back: $('#cb-back'),
        next: $('#cb-next'),
        submit: $('#cb-submit'),
        sizes: $('#cb-sizes'),
        finishes: $('#cb-finishes'),
        notReady: $('#cb-not-ready'),
        colorNames: $('#cb-color-names'),
        toppingCount: $('#cb-topping-count'),
        message: $('#cb-message'),
        messageCount: $('#cb-message-count'),
        summary: $('#cb-summary'),
        qty: $('#cb-qty'),
        toppingStep: $('.cb-step[data-step="4"]'),
    };

    const LAST_STEP = els.sections.length - 1;
    const DEFAULT_BASE = '#FFF4DC';
    const DEFAULT_ACCENT = '#E8738A';
    const MESSAGE_IDEAS = ['Happy Birthday!', 'Congrats!', 'Love you', 'Cheers!', 'Best Day Ever', 'Sweet 16'];
    const CAPTIONS = [
        'Pick a shape to start baking!',
        'Now choose how big it should be.',
        'Pick a finish for the outside.',
        'Time for some color!',
        'Add a few toppings, or keep it simple.',
        'Say something sweet.',
        'Looking delicious!',
    ];

    let current = 0;
    let submitting = false;
    const visited = new Set([0]);
    const completed = new Set();
    let previous = { geometry: '', toppings: new Set() };

    // ---------- Reading the form ----------
    const checkedValue = (name) => {
        const input = form.querySelector(`input[name="${name}"]:checked`);
        return input ? input.value : '';
    };
    const findInput = (name, value) => $$(`input[name="${name}"]`, form).find((i) => i.value === value);

    function readState() {
        const recipe = recipes.get(checkedValue('rec-Id')) || null;
        const size = recipe ? recipe.sizes.find((s) => s.label === checkedValue('size')) || null : null;
        const finish = size ? size.finishes.find((f) => f.id === checkedValue('recvar-Id')) || null : null;
        return {
            recipe,
            size,
            finish,
            base: checkedValue('base-color'),
            accent: checkedValue('accent-color'),
            toppings: $$('.cb-topping:checked', form).map((i) => i.value),
            message: els.message.value.trim(),
            qty: Number(els.qty.value),
        };
    }

    function stepDone(step, s) {
        switch (step) {
            case 0: return !!s.recipe;
            case 1: return !!s.size;
            case 2: return !!s.finish;
            case 3: return !!(s.base && s.accent);
            case 6: return Number.isInteger(s.qty) && s.qty >= 1 && s.qty <= Number(els.qty.max);
            default: return true;
        }
    }

    // The furthest step the user may jump to: the first one that still needs a choice.
    function reachableStep(s) {
        for (let i = 0; i < LAST_STEP; i++) if (!stepDone(i, s)) return i;
        return LAST_STEP;
    }

    // ---------- Size and finish options (depend on the chosen recipe) ----------
    function renderSizes(recipe, keepLabel) {
        els.sizes.replaceChildren();
        if (!recipe) return;
        recipe.sizes.forEach((size, i) => {
            const input = Object.assign(document.createElement('input'), {
                className: 'btn-check', type: 'radio', name: 'size', id: `size-${i}`, value: size.label,
            });
            input.checked = size.label === keepLabel || recipe.sizes.length === 1;
            const label = document.createElement('label');
            label.className = 'cb-chip';
            label.htmlFor = input.id;
            label.innerHTML = '<i class="bi bi-rulers"></i>';
            label.append(size.label);
            els.sizes.append(input, label);
        });
    }

    const FINISH_INFO = [
        ['butter', 'bi-cup-hot', 'Creamy and piped'],
        ['semi', 'bi-circle-half', 'Part fondant, part buttercream'],
        ['all', 'bi-gift', 'Fondant cake and decorations'],
        ['fondant', 'bi-circle-fill', 'Smooth fondant all over'],
        ['icing', 'bi-star', 'Icing with a 2D design'],
    ];

    function renderFinishes(size, keepName, keepId) {
        els.finishes.replaceChildren();
        if (!size) return;
        size.finishes.forEach((finish, i) => {
            const lower = finish.name.toLowerCase();
            const [, icon, blurb] = FINISH_INFO.find(([key]) => lower.includes(key)) || ['', 'bi-brush', 'Classic finish'];
            const input = Object.assign(document.createElement('input'), {
                className: 'btn-check', type: 'radio', name: 'recvar-Id', id: `finish-${i}`, value: finish.id,
            });
            input.checked = finish.id === keepId || finish.name === keepName || size.finishes.length === 1;
            const label = document.createElement('label');
            label.className = 'cb-option';
            label.htmlFor = input.id;
            const iconEl = Object.assign(document.createElement('i'), { className: `bi ${icon} cb-finish-icon` });
            const name = Object.assign(document.createElement('span'), { className: 'cb-option-name', textContent: finish.name });
            const meta = Object.assign(document.createElement('span'), { className: 'cb-option-meta', textContent: blurb });
            const ready = document.createElement('span');
            ready.className = `cb-ready ${finish.ready ? 'text-success-emphasis' : 'text-warning-emphasis'}`;
            ready.innerHTML = finish.ready ? '<i class="bi bi-check-circle me-1"></i>Recipe ready' : '<i class="bi bi-exclamation-circle me-1"></i>Ingredients needed';
            label.append(iconEl, name, meta, ready);
            const cell = document.createElement('div');
            cell.append(input, label);
            els.finishes.append(cell);
        });
    }

    // Name of the finish currently picked, so it can stay selected when the size or shape changes.
    function currentFinishName() {
        const input = findInput('recvar-Id', checkedValue('recvar-Id'));
        const name = input && input.labels[0] ? input.labels[0].querySelector('.cb-option-name') : null;
        return name ? name.textContent : '';
    }

    function onRecipeChange() {
        const finishName = currentFinishName();
        renderSizes(readState().recipe, checkedValue('size'));
        renderFinishes(readState().size, finishName, '');
    }

    function onSizeChange() {
        const finishName = currentFinishName();
        renderFinishes(readState().size, finishName, '');
    }

    // ---------- Colors ----------
    function hexToRgb(hex) {
        const n = parseInt(hex.slice(1), 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function mix(hex, target, amount) {
        const a = hexToRgb(hex);
        const b = hexToRgb(target);
        return '#' + a.map((v, i) => Math.round(v + (b[i] - v) * amount).toString(16).padStart(2, '0')).join('');
    }

    function luminance(hex) {
        const [r, g, b] = hexToRgb(hex).map((v) => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }

    function contrast(a, b) {
        const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
        return (hi + 0.05) / (lo + 0.05);
    }

    function applyColors(el, base, accent) {
        base = base || DEFAULT_BASE;
        accent = accent || DEFAULT_ACCENT;
        const baseLight = mix(base, '#FFFFFF', 0.35);
        const accentDark = mix(accent, '#000000', 0.2);
        // Message ink: the accent if it reads well on the cake, otherwise a dark or light fallback.
        let ink = accentDark;
        if (contrast(ink, baseLight) < 2.2) ink = luminance(baseLight) > 0.4 ? mix(base, '#000000', 0.65) : '#FFFFFF';
        el.style.setProperty('--cb-base', base);
        el.style.setProperty('--cb-base-light', baseLight);
        el.style.setProperty('--cb-base-dark', mix(base, '#000000', 0.14));
        el.style.setProperty('--cb-accent', accent);
        el.style.setProperty('--cb-accent-dark', accentDark);
        el.style.setProperty('--cb-ink', ink);
    }

    // ---------- Drawing the cake ----------
    // The cake body is drawn here; toppings, piping and trims come from assets/cake-sprite.svg
    // (inlined in the page) and are placed with <use href="#cb-...">.
    const esc = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const r1 = (n) => Math.round(n * 10) / 10;
    const SPRINKLES = ['#FF6B8B', '#FFD166', '#06D6A0', '#4CC9F0', '#B388EB', '#FFFFFF'];

    function seeded(seed) {
        let a = seed >>> 0;
        return () => {
            a = (a + 0x6D2B79F5) | 0;
            let t = Math.imul(a ^ (a >>> 15), 1 | a);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    // Places a sprite asset. (x, y) is the asset's anchor point.
    const use = (id, x, y, scale = 1, rotate = 0) =>
        `<use href="#${id}" transform="translate(${r1(x)} ${r1(y)})${rotate ? ` rotate(${r1(rotate)})` : ''}${scale !== 1 ? ` scale(${r1(scale * 100) / 100})` : ''}"/>`;

    // Turns a size label such as "6x4", "3x3/4x3" or "12x9x2.5" into pixel tiers, bottom tier first.
    function tierSizes(shape, tiers, sizeLabel) {
        const defaults = { round: [7, 4], mini: [3.5, 3], bento: [4, 2.2], square: [8, 4], rectangle: [12, 2.5] };
        let dims = String(sizeLabel || '').split('/')
            .map((part) => part.split(/x/i).map(parseFloat).filter((n) => n > 0))
            .filter((nums) => nums.length >= 2)
            .map((nums) => ({ d: nums[0], h: nums.length >= 3 ? nums[2] : nums[1], depth: nums.length >= 3 ? nums[1] / nums[0] : 1 }));
        if (!dims.length) {
            const [d, h] = defaults[shape] || defaults.round;
            dims = [{ d, h, depth: shape === 'rectangle' ? 0.75 : 1 }];
        }
        dims.sort((a, b) => b.d - a.d);
        while (dims.length < tiers) {
            const top = dims[dims.length - 1];
            dims.push({ d: top.d * 0.72, h: top.h * 0.9, depth: top.depth });
        }
        let px = dims.map((t) => ({ w: Math.min(300, 90 + t.d * 18), h: 26 + t.h * 15, depth: t.depth }));
        const total = px.reduce((sum, t) => sum + t.h, 0);
        if (total > 200) px = px.map((t) => ({ ...t, h: (t.h * 200) / total }));
        for (let i = 1; i < px.length; i++) if (px[i].w > px[i - 1].w * 0.85) px[i].w = px[i - 1].w * 0.78;
        return px;
    }

    // Round tiers are cylinders. Square and rectangle tiers are boxes seen from the front-right:
    // a front face (x0..x1), a right face going back by (dx, dy), and a top face.
    function layoutTiers(shape, sizes, plateY) {
        const box = shape === 'square' || shape === 'rectangle';
        const tiers = [];
        let yb = shape === 'bento' ? plateY - 22 : plateY + (box ? 5 : 0);
        sizes.forEach((s) => {
            const t = { box, w: s.w, h: s.h, yb, yt: yb - s.h };
            if (box) {
                t.dx = s.w * 0.2 * Math.min(1, s.depth);
                t.dy = t.dx * 0.85;
                t.front = s.w - t.dx;
                t.x0 = 200 - s.w / 2;
                t.x1 = t.x0 + t.front;
                t.cx = t.x0 + t.front / 2;
                t.rx = t.front / 2;
                t.ry = 0;
                t.topY = t.yt - t.dy / 2;
            } else {
                t.dx = 0;
                t.dy = 0;
                t.front = s.w;
                t.x0 = 200 - s.w / 2;
                t.x1 = 200 + s.w / 2;
                t.cx = 200;
                t.rx = s.w / 2;
                t.ry = Math.max(7, s.w * 0.13);
                t.topY = t.yt;
            }
            t.scale = Math.max(0.7, Math.min(1.25, t.front / 190));
            tiers.push(t);
            yb = box ? t.yt - t.dy * 0.45 : t.yt;
        });
        return tiers;
    }

    // Evenly spaced points along a tier's visible top or bottom edge, left to right
    // (for boxes: along the front edge, then back along the right edge).
    function edgePoints(t, where, spacing) {
        const y = where === 'top' ? t.yt : t.yb;
        const pts = [];
        if (t.box) {
            const nf = Math.max(4, Math.round(t.front / spacing));
            for (let i = 0; i <= nf; i++) pts.push({ x: t.x0 + 2 + ((t.front - 4) * i) / nf, y });
            const ns = Math.max(1, Math.round(Math.hypot(t.dx, t.dy) / spacing));
            for (let i = 1; i <= ns; i++) pts.push({ x: t.x1 + (t.dx * i) / ns, y: y - (t.dy * i) / ns });
            return pts;
        }
        const arc = Math.PI * Math.sqrt((t.rx * t.rx + t.ry * t.ry) / 2);
        const n = Math.max(6, Math.round(arc / spacing));
        for (let i = 0; i <= n; i++) {
            const angle = Math.PI - (Math.PI * i) / n;
            pts.push({ x: t.cx + t.rx * Math.cos(angle), y: y + t.ry * Math.sin(angle) });
        }
        return pts;
    }

    // ----- Scenery -----
    function stand(width, y) {
        const rx = width / 2 + 30;
        return `<ellipse cx="200" cy="${y + 39}" rx="54" ry="8" fill="url(#cbg-metal)"/>
            <ellipse cx="200" cy="${y + 37}" rx="50" ry="5.5" fill="#F3F5F9"/>
            <path d="M184,${y + 8} C190,${y + 20} 191,${y + 27} 175,${y + 37} H225 C209,${y + 27} 210,${y + 20} 216,${y + 8} Z" fill="url(#cbg-metal)"/>
            <ellipse cx="200" cy="${y + 6}" rx="${r1(rx)}" ry="14" fill="#B9C1CE"/>
            <ellipse cx="200" cy="${y}" rx="${r1(rx)}" ry="14" fill="url(#cbg-plate)"/>
            <path d="M${r1(200 - rx + 6)},${y + 3} A${r1(rx - 6)},12 0 0 0 ${r1(200 + rx - 6)},${y + 3}" stroke="#fff" stroke-width="1.6" fill="none" opacity=".8"/>`;
    }

    function bentoBack(width, y) {
        const w = width + 70;
        const x = 200 - w / 2;
        return `<path d="M${r1(x)},${y - 2} L${r1(x + 12)},${y - 155} H${r1(x + w - 12)} L${r1(x + w)},${y - 2} Z" fill="url(#cbg-kraft)"/>
            <path d="M${r1(x + 12)},${y - 155} H${r1(x + w - 12)}" stroke="#CBAA74" stroke-width="4" stroke-linecap="round"/>
            <path d="M${r1(x + 9)},${y - 120} H${r1(x + w - 9)}" stroke="#D9BD8C" stroke-width="1.4" stroke-dasharray="3 4"/>
            <path d="M${r1(x + 2)},${y - 30} H${r1(x + w - 2)} L${r1(x + w)},${y - 2} H${r1(x)} Z" fill="#000" opacity=".06"/>`;
    }

    function bentoFront(width, y) {
        const w = width + 70;
        const x = 200 - w / 2;
        return `<path d="M${r1(x - 5)},${y - 5} H${r1(x + w + 5)} L${r1(x + w - 6)},${y + 27} H${r1(x + 6)} Z" fill="url(#cbg-kraft-front)"/>
            <path d="M${r1(x - 3)},${y - 3} H${r1(x + w + 3)}" stroke="#F6E6C6" stroke-width="2.2" stroke-linecap="round"/>
            <circle cx="200" cy="${y + 12}" r="9.5" fill="#FFFFFF" stroke="#EBD9B6"/>
            ${use('cb-heart2d', 200, y + 12.5, 0.55)}`;
    }

    // ----- Cake body -----
    function tierBody(t, uid) {
        if (t.box) {
            const right = `${r1(t.x1)},${r1(t.yt)} ${r1(t.x1 + t.dx)},${r1(t.yt - t.dy)} ${r1(t.x1 + t.dx)},${r1(t.yb - t.dy)} ${r1(t.x1)},${r1(t.yb)}`;
            const top = `${r1(t.x0)},${r1(t.yt)} ${r1(t.x1)},${r1(t.yt)} ${r1(t.x1 + t.dx)},${r1(t.yt - t.dy)} ${r1(t.x0 + t.dx)},${r1(t.yt - t.dy)}`;
            return `<polygon points="${right}" fill="url(#${uid}-right)"/>
                <polygon points="${right}" fill="url(#${uid}-ao)"/>
                <rect x="${r1(t.x0)}" y="${r1(t.yt)}" width="${r1(t.front)}" height="${r1(t.h)}" fill="url(#${uid}-front)"/>
                <rect x="${r1(t.x0)}" y="${r1(t.yt)}" width="${r1(t.front)}" height="${r1(t.h)}" fill="url(#${uid}-ao)"/>
                <polygon points="${top}" fill="url(#${uid}-top)"/>
                <path d="M${r1(t.x0 + 1)},${r1(t.yt + 0.8)} H${r1(t.x1)} L${r1(t.x1 + t.dx - 1)},${r1(t.yt - t.dy + 0.8)}" stroke="#fff" stroke-opacity=".55" stroke-width="1.6" fill="none" stroke-linejoin="round"/>
                <path d="M${r1(t.x1)},${r1(t.yt)} V${r1(t.yb)}" stroke="#000" stroke-opacity=".08" stroke-width="1"/>`;
        }
        const side = `M${r1(t.x0)},${r1(t.yt)} V${r1(t.yb)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x1)},${r1(t.yb)} V${r1(t.yt)} Z`;
        return `<path d="${side}" fill="url(#${uid}-side)"/>
            <path d="${side}" fill="url(#${uid}-ao)"/>
            <ellipse cx="${t.cx}" cy="${r1(t.yt)}" rx="${r1(t.rx)}" ry="${r1(t.ry)}" fill="url(#${uid}-top)"/>
            <path d="M${r1(t.x0 + 1.5)},${r1(t.yt + 0.6)} A${r1(t.rx - 1.5)},${r1(t.ry - 0.6)} 0 0 0 ${r1(t.x1 - 1.5)},${r1(t.yt + 0.6)}" stroke="#fff" stroke-opacity=".6" stroke-width="2" fill="none"/>`;
    }

    // Soft shadow where a tier rests on the plate or on the tier below.
    function contactShadow(t) {
        if (t.box) {
            return `<ellipse cx="${r1(t.cx + t.dx / 2)}" cy="${r1(t.yb - t.dy / 2)}" rx="${r1(t.w / 2 + 8)}" ry="${r1(t.dy / 2 + 9)}" fill="url(#cbg-contact)"/>`;
        }
        return `<ellipse cx="${t.cx}" cy="${r1(t.yb + 1)}" rx="${r1(t.rx + 9)}" ry="${r1(t.ry + 6)}" fill="url(#cbg-contact)"/>`;
    }

    // ----- Finishes -----
    function shellBorder(t, where, size) {
        const pts = edgePoints(t, where, 9 * size);
        let out = '';
        for (let i = 0; i < pts.length - 1; i++) {
            const a = pts[i];
            const b = pts[i + 1];
            const angle = (Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI;
            const along = (Math.hypot(b.x - a.x, b.y - a.y) / 9) * 0.95;
            // Shells on a receding (diagonal) edge are drawn flatter, as seen at an angle.
            const across = Math.abs(angle) > 20 ? size * 0.55 : along;
            out += `<use href="#cb-shell" transform="translate(${r1(a.x)} ${r1(a.y - (where === 'bottom' ? 3.5 * size : 0))}) rotate(${r1(angle)}) scale(${r1(along * 100) / 100} ${r1(across * 100) / 100})"/>`;
        }
        return out;
    }

    const row = (t, where, id, spacing, scale, lift = 0) =>
        edgePoints(t, where, spacing).map((p) => use(id, p.x, p.y - lift, scale)).join('');

    // Points along the far edges of the top (drawn first, so the front border overlaps them).
    function backEdgePoints(t, spacing) {
        const pts = [];
        if (t.box) {
            const nl = Math.max(1, Math.round(Math.hypot(t.dx, t.dy) / spacing));
            for (let i = 0; i < nl; i++) pts.push({ x: t.x0 + (t.dx * i) / nl, y: t.yt - (t.dy * i) / nl });
            const nb = Math.max(4, Math.round(t.front / spacing));
            for (let i = 0; i <= nb; i++) pts.push({ x: t.x0 + t.dx + (t.front * i) / nb, y: t.yt - t.dy });
            return pts;
        }
        const arc = Math.PI * Math.sqrt((t.rx * t.rx + t.ry * t.ry) / 2);
        const n = Math.max(6, Math.round(arc / spacing));
        for (let i = 1; i < n; i++) {
            const angle = Math.PI + (Math.PI * i) / n;
            pts.push({ x: t.cx + t.rx * Math.cos(angle), y: t.yt + t.ry * Math.sin(angle) });
        }
        return pts;
    }

    // A border all the way around the top: far side first, then the near side.
    const topBorder = (t, id, spacing, scale) =>
        backEdgePoints(t, spacing).map((p) => use(id, p.x, p.y, scale * 0.92)).join('') + row(t, 'top', id, spacing, scale);

    function ribbon(t) {
        const hgt = 9 * t.scale;
        if (t.box) {
            return `<polygon points="${r1(t.x1)},${r1(t.yb - hgt)} ${r1(t.x1 + t.dx)},${r1(t.yb - hgt - t.dy)} ${r1(t.x1 + t.dx)},${r1(t.yb - t.dy)} ${r1(t.x1)},${r1(t.yb)}" style="fill:var(--cb-accent-dark)"/>
                <rect x="${r1(t.x0)}" y="${r1(t.yb - hgt)}" width="${r1(t.front)}" height="${r1(hgt)}" style="fill:var(--cb-accent)"/>
                <path d="M${r1(t.x0)},${r1(t.yb - hgt + 0.8)} H${r1(t.x1)}" stroke="#fff" stroke-opacity=".4" stroke-width="1.2"/>`;
        }
        return `<path d="M${r1(t.x0)},${r1(t.yb - hgt)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x1)},${r1(t.yb - hgt)} V${r1(t.yb)} A${r1(t.rx)},${r1(t.ry)} 0 0 1 ${r1(t.x0)},${r1(t.yb)} Z" style="fill:var(--cb-accent)"/>
            <path d="M${r1(t.x0)},${r1(t.yb - hgt)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x1)},${r1(t.yb - hgt)} V${r1(t.yb)} A${r1(t.rx)},${r1(t.ry)} 0 0 1 ${r1(t.x0)},${r1(t.yb)} Z" fill="url(#cbg-shade-v)" opacity=".7"/>
            <path d="M${r1(t.x0)},${r1(t.yb - hgt + 0.8)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x1)},${r1(t.yb - hgt + 0.8)}" stroke="#fff" stroke-opacity=".4" stroke-width="1.2" fill="none"/>`;
    }

    // Glossy vertical highlight on smooth fondant.
    function sheen(t) {
        const x = t.x0 + t.front * 0.15;
        const top = t.box ? t.yt + 4 : t.yt + t.ry * 0.9;
        const bottom = t.box ? t.yb - 4 : t.yb + t.ry * 0.65;
        return `<rect x="${r1(x)}" y="${r1(top)}" width="${r1(t.front * 0.12)}" height="${r1(bottom - top)}" rx="6" fill="url(#cbg-shine-h)"/>`;
    }

    // Spatula strokes on buttercream sides and a swirl on top.
    function buttercreamTexture(t, rand) {
        let out = '';
        for (let k = 1; k <= 3; k++) {
            const level = t.yt + (t.h * k) / 4;
            const steps = 26;
            const phase = rand() * 6;
            let d = '';
            for (let i = 0; i <= steps; i++) {
                const f = i / steps;
                let x;
                let y;
                if (t.box) {
                    x = t.x0 + 2 + (t.front - 4) * f;
                    y = level;
                } else {
                    const angle = Math.PI - Math.PI * f;
                    x = t.cx + (t.rx - 1) * Math.cos(angle);
                    y = level + t.ry * Math.sin(angle);
                }
                y += Math.sin(f * Math.PI * 7 + phase) * 1.3;
                d += `${i ? 'L' : 'M'}${r1(x)},${r1(y)} `;
            }
            out += `<path d="${d}" style="stroke:var(--cb-base-dark)" stroke-opacity=".3" stroke-width="1.6" fill="none" stroke-linecap="round"/>`;
            out += `<path d="${d}" stroke="#fff" stroke-opacity=".35" stroke-width="1" fill="none" transform="translate(0 1.6)"/>`;
        }
        if (!t.box) {
            out += `<ellipse cx="${t.cx}" cy="${r1(t.yt)}" rx="${r1(t.rx * 0.55)}" ry="${r1(t.ry * 0.5)}" style="stroke:var(--cb-base-dark)" stroke-opacity=".16" stroke-width="1.4" fill="none"/>
                <ellipse cx="${t.cx}" cy="${r1(t.yt)}" rx="${r1(t.rx * 0.28)}" ry="${r1(t.ry * 0.26)}" style="stroke:var(--cb-base-dark)" stroke-opacity=".14" stroke-width="1.2" fill="none"/>`;
        }
        return out;
    }

    // Decorations that depend on the finish. "under" goes below a chocolate drip, "over" on top of it.
    function finishDecor(t, finish, rand) {
        const f = (finish || '').toLowerCase();
        const s = t.scale;
        let under = '';
        let over = '';
        let trim = 9 * s; // height of the bottom trim, so pearls can sit above it
        if (f.includes('butter')) {
            under += buttercreamTexture(t, rand) + shellBorder(t, 'bottom', s);
            over += topBorder(t, 'cb-rosette', 8.5 * s, s * 0.85);
        } else if (f.includes('semi')) {
            under += sheen(t) + ribbon(t);
            over += topBorder(t, 'cb-bead', 7 * s, s * 0.8);
        } else if (f.includes('fondant')) {
            under += sheen(t) + ribbon(t);
            if (f.includes('all')) under += use('cb-bow', t.cx, t.box ? t.yb - 5 * s : t.yb + t.ry - 5 * s, s);
        } else if (f.includes('icing') || f.includes('2d')) {
            const y = t.box ? t.yt + t.h * 0.5 : t.yt + t.ry * 0.75 + t.h * 0.5;
            const size = Math.min(1.1, t.h / 60) * s;
            under += use('cb-star2d', t.x0 + t.front * 0.17, y, size, -12) + use('cb-heart2d', t.x1 - t.front * 0.17, y, size, 10);
            over += topBorder(t, 'cb-bead', 6.5 * s, s * 0.7);
            trim = 0;
        } else {
            under += row(t, 'bottom', 'cb-bead', 7.5 * s, s, 3 * s);
        }
        return { under, over, trim };
    }

    // ----- Toppings drawn on the cake -----
    function drip(t, rand) {
        const pts = edgePoints(t, 'top', 11 * t.scale);
        const lip = 3.5;
        let d = `M${r1(pts[0].x)},${r1(pts[0].y + lip)}`;
        let gloss = '';
        for (let i = 0; i < pts.length - 1; i++) {
            const a = pts[i];
            const b = pts[i + 1];
            const mx = (a.x + b.x) / 2 + (rand() - 0.5) * 3;
            const my = (a.y + b.y) / 2 + lip;
            if (rand() < 0.82) {
                const seg = Math.hypot(b.x - a.x, b.y - a.y);
                const w = Math.min(6, seg * 0.3) * (0.75 + rand() * 0.5);
                const len = 5 + rand() * Math.min(t.h * 0.45, 34);
                d += ` L${r1(mx - w)},${r1(my)} L${r1(mx - w * 0.8)},${r1(my + len)}`
                    + ` C${r1(mx - w * 1.35)},${r1(my + len + w * 1.9)} ${r1(mx + w * 1.35)},${r1(my + len + w * 1.9)} ${r1(mx + w * 0.8)},${r1(my + len)}`
                    + ` L${r1(mx + w)},${r1(my)}`;
                gloss += `<ellipse cx="${r1(mx - w * 0.3)}" cy="${r1(my + len + w * 0.3)}" rx="${r1(w * 0.28)}" ry="${r1(w * 0.5)}" fill="#fff" opacity=".35"/>`;
            }
            d += ` L${r1(b.x)},${r1(b.y + lip)}`;
        }
        d += t.box
            ? ` L${r1(t.x1 + t.dx)},${r1(t.yt - t.dy)} L${r1(t.x0 + t.dx)},${r1(t.yt - t.dy)} L${r1(t.x0)},${r1(t.yt)} Z`
            : ` L${r1(t.x1)},${r1(t.yt)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x0)},${r1(t.yt)} Z`;
        const edge = pts.map((p, i) => `${i ? 'L' : 'M'}${r1(p.x)},${r1(p.y + 1.5)}`).join(' ');
        return `<path d="${d}" fill="url(#cbg-choc)"/>
            <path d="${edge}" stroke="#fff" stroke-opacity=".28" stroke-width="1.6" fill="none" stroke-linecap="round"/>${gloss}`;
    }

    function sidePoint(t, rand) {
        const x = t.x0 + 8 + rand() * (t.front - 16);
        if (t.box) return { x, y: t.yt + 6 + rand() * (t.h - 12) };
        const curve = t.ry * Math.sqrt(Math.max(0, 1 - ((x - t.cx) / t.rx) ** 2));
        const top = t.yt + curve + 5;
        const bottom = t.yb + curve - 7;
        return { x, y: top + rand() * Math.max(2, bottom - top) };
    }

    function topPoint(t, rand) {
        if (t.box) {
            const u = 0.08 + rand() * 0.84;
            const v = 0.12 + rand() * 0.76;
            return { x: t.x0 + u * t.front + v * t.dx, y: t.yt - v * t.dy };
        }
        const a = rand() * Math.PI * 2;
        const r = Math.sqrt(rand()) * 0.82;
        return { x: t.cx + Math.cos(a) * r * t.rx, y: t.yt + Math.sin(a) * r * t.ry };
    }

    function sprinkles(t, rand, onTop) {
        const bit = (p, i) => (i % 4 === 3
            ? `<circle cx="${r1(p.x)}" cy="${r1(p.y)}" r="1.5" fill="${SPRINKLES[i % SPRINKLES.length]}"/>`
            : `<rect x="${r1(p.x - 3.2)}" y="${r1(p.y - 1.1)}" width="6.4" height="2.2" rx="1.1" fill="${SPRINKLES[i % SPRINKLES.length]}" transform="rotate(${Math.round(rand() * 180)} ${r1(p.x)} ${r1(p.y)})"/>`);
        let out = '';
        const count = 12 + Math.round(t.front / 12);
        for (let i = 0; i < count; i++) out += bit(sidePoint(t, rand), i);
        if (onTop) for (let i = 0; i < count; i++) out += bit(topPoint(t, rand), i + 2);
        return out;
    }

    function goldLeaf(t, rand) {
        let out = '';
        const cx = t.x0 + t.front * 0.2;
        const cy = (t.box ? t.yt : t.yt + t.ry) + t.h * 0.2;
        for (let i = 0; i < 9; i++) {
            const x = cx + (rand() - 0.5) * t.front * 0.26;
            const y = cy + (rand() - 0.3) * t.h * 0.3;
            out += use(`cb-gold-${1 + (i % 3)}`, x, y, (0.45 + rand() * 0.45) * t.scale, rand() * 360);
        }
        if (!t.box) for (let i = 0; i < 3; i++) {
            const p = topPoint(t, rand);
            out += use(`cb-gold-${1 + (i % 3)}`, p.x, p.y, 0.5 * t.scale, rand() * 360);
        }
        return out;
    }

    function sideFlowers(t) {
        const s = t.scale;
        const x = t.x1 - t.front * 0.2;
        const y = (t.box ? t.yt : t.yt + t.ry) + t.h * 0.3;
        return use('cb-leaf', x + 3 * s, y + 4 * s, s * 1.1, 20) + use('cb-leaf', x - 4 * s, y + 6 * s, s, 150)
            + use('cb-blossom', x, y, s * 1.25) + use('cb-blossom', x + 12 * s, y + 11 * s, s) + use('cb-blossom', x - 7 * s, y + 17 * s, s * 0.8);
    }

    function message(t, text) {
        const room = t.front * 0.8;
        // Shrink the font to fit first; squeeze the letters only if it's still too long.
        const fontSize = Math.max(10, Math.min(24, t.h * 0.32, room / (text.length * 0.5)));
        const y = (t.box ? t.yt : t.yt + t.ry) + t.h * 0.5 - 3;
        const fit = text.length * fontSize * 0.5 > room ? ` textLength="${r1(room)}" lengthAdjust="spacingAndGlyphs"` : '';
        const attrs = `x="${r1(t.cx)}" text-anchor="middle" dominant-baseline="middle" font-size="${r1(fontSize)}" font-family="'Segoe Script','Brush Script MT','Lucida Handwriting',cursive" font-weight="700"${fit}`;
        return `<text ${attrs} y="${r1(y + 1.3)}" fill="#000" opacity=".18">${esc(text)}</text>
            <text ${attrs} y="${r1(y)}" style="fill:var(--cb-ink);paint-order:stroke;stroke:var(--cb-base-light);stroke-width:3px;stroke-linejoin:round">${esc(text)}</text>`;
    }

    // The drawn (vector) cake. Used when the Blender images aren't available.
    function drawVectorCake(svg, cfg) {
        delete svg.dataset.renderKey;
        delete svg.dataset.waitingFor;
        const uid = svg.dataset.uid || (svg.dataset.uid = 'c' + Math.random().toString(36).slice(2, 8));
        const plateY = 292;
        const sizes = tierSizes(cfg.shape || 'round', cfg.tiers || 1, cfg.size);
        const width = sizes[0].w;
        const pieces = [];

        // Gradients that follow this cake's colors (the sprite holds the fixed-color ones).
        pieces.push(`<defs>
            <linearGradient id="${uid}-side" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-base-dark)"/>
                <stop offset=".16" style="stop-color:var(--cb-base)"/>
                <stop offset=".36" style="stop-color:var(--cb-base-light)"/>
                <stop offset=".64" style="stop-color:var(--cb-base)"/>
                <stop offset="1" style="stop-color:var(--cb-base-dark)"/>
            </linearGradient>
            <linearGradient id="${uid}-front" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-base)"/>
                <stop offset=".3" style="stop-color:var(--cb-base-light)"/>
                <stop offset="1" style="stop-color:var(--cb-base)"/>
            </linearGradient>
            <linearGradient id="${uid}-right" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-base-dark)"/>
                <stop offset="1" style="stop-color:var(--cb-base)"/>
            </linearGradient>
            <radialGradient id="${uid}-top" cx=".45" cy=".42" r=".62">
                <stop offset="0" style="stop-color:var(--cb-base-light)"/>
                <stop offset=".7" style="stop-color:var(--cb-base-light)"/>
                <stop offset="1" style="stop-color:var(--cb-base)"/>
            </radialGradient>
            <linearGradient id="${uid}-ao" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" stop-color="#fff" stop-opacity=".12"/>
                <stop offset=".25" stop-color="#fff" stop-opacity="0"/>
                <stop offset=".72" stop-color="#000" stop-opacity="0"/>
                <stop offset="1" stop-color="#000" stop-opacity=".16"/>
            </linearGradient>
            <radialGradient id="${uid}-floor"><stop offset="0" stop-color="#000" stop-opacity=".2"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
        </defs>`);
        pieces.push(`<ellipse cx="200" cy="341" rx="${r1(width / 2 + 55)}" ry="11" fill="url(#${uid}-floor)"/>`);
        pieces.push(cfg.shape === 'bento' ? bentoBack(width, plateY) : stand(width, plateY));

        // Nothing chosen yet: a dashed "ghost" cake.
        if (!cfg.shape) {
            pieces.push(`<g fill="none" stroke-width="2.5" stroke-dasharray="7 7" style="stroke:var(--bs-secondary-color)" opacity=".55">
                <path d="M105,180 V280 A95,22 0 0 0 295,280 V180"/><ellipse cx="200" cy="180" rx="95" ry="22"/></g>
                <text x="200" y="245" text-anchor="middle" font-size="42" font-weight="700" style="fill:var(--bs-secondary-color)" opacity=".45">?</text>`);
            svg.innerHTML = pieces.join('');
            return;
        }

        const tiers = layoutTiers(cfg.shape, sizes, plateY);
        const toppings = new Set(cfg.toppings || []);
        const popOn = (key) => cfg.popAll || (cfg.popSet && cfg.popSet.has(key));
        const item = (key, inner) => inner
            ? `<g class="cb-top-item${popOn(key) ? ' cb-pop' : ''}" data-t="${key}"${popOn(key) && cfg.popAll ? ' style="animation-delay:.5s"' : ''}>${inner}</g>`
            : '';
        const rand = seeded(Math.round(width * 7 + tiers.length * 131));

        tiers.forEach((t, i) => {
            const isTop = i === tiers.length - 1;
            const decor = finishDecor(t, cfg.finish, rand);
            const anim = cfg.animateTiers ? ` cb-drop" style="animation-delay:${i * 0.14}s` : '';
            let g = (cfg.shape === 'bento' ? '' : contactShadow(t)) + tierBody(t, uid) + decor.under;
            if (isTop && toppings.has('chocolate_drip')) g += item('chocolate_drip', drip(t, seeded(i + 7)));
            g += decor.over;
            if (toppings.has('pearls')) g += item('pearls', row(t, 'bottom', 'cb-pearl', 7.6, 1, decor.trim + 5));
            if (toppings.has('sprinkles')) g += item('sprinkles', sprinkles(t, rand, isTop && !toppings.has('chocolate_drip')));
            if (isTop && toppings.has('gold_leaf')) g += item('gold_leaf', goldLeaf(t, rand));
            if (isTop && toppings.has('flowers')) g += item('flowers', sideFlowers(t));
            if (isTop && cfg.message) g += item('message', message(t, cfg.message));
            pieces.push(`<g class="cb-tier${anim}">${g}</g>`);
        });

        if (cfg.shape === 'bento') pieces.push(bentoFront(width, plateY));

        // Pieces standing on the top surface: candles in the middle, the rest split left and right,
        // drawn back to front so nearer pieces overlap farther ones.
        const top = tiers[tiers.length - 1];
        const others = [];
        const candles = [];
        for (const key of ['strawberries', 'cherries', 'macarons', 'flowers', 'candles']) {
            if (!toppings.has(key)) continue;
            if (key === 'candles') candles.push(key, key, key);
            else if (key === 'flowers') others.push(key);
            else others.push(key, key);
        }
        const half = Math.ceil(others.length / 2);
        const lineUp = [...others.slice(0, half), ...candles, ...others.slice(half)];
        if (lineUp.length) {
            const scale = Math.max(0.7, Math.min(1.2, top.front / 180));
            const spanX = (top.box ? top.front / 2 : top.rx) * (lineUp.length > 4 ? 0.74 : 0.6);
            const spanY = (top.box ? top.dy / 2 : top.ry) * 0.42;
            const centerX = top.box ? top.cx + top.dx / 2 : top.cx;
            const counts = {};
            lineUp.map((key, i) => ({
                key,
                n: (counts[key] = (counts[key] || 0) + 1),
                x: lineUp.length === 1 ? centerX : centerX - spanX + (2 * spanX * i) / (lineUp.length - 1),
                y: top.topY + (lineUp.length > 2 ? (i % 2 ? 1 : -1) * spanY : 0),
            })).sort((a, b) => a.y - b.y).forEach((p) => {
                const asset = {
                    strawberries: p.n % 2 ? 'cb-strawberry' : 'cb-strawberry-half',
                    cherries: 'cb-cherry',
                    macarons: 'cb-macaron',
                    flowers: 'cb-rose',
                    candles: 'cb-candle',
                }[p.key];
                const size = p.key === 'flowers' ? scale * 1.2 : scale;
                const tilt = p.key === 'macarons' ? (p.n % 2 ? -8 : 6) : p.key === 'cherries' ? (p.n % 2 ? -6 : 8) : 0;
                const shadow = `<ellipse cx="${r1(p.x)}" cy="${r1(p.y)}" rx="${r1(9 * scale)}" ry="${r1(2.6 * scale)}" fill="url(#cbg-contact)"/>`;
                pieces.push(item(p.key, shadow + use(asset, p.x, p.y, size, tilt)));
            });
        }

        svg.innerHTML = pieces.join('');
    }

    // ---------- Rendered cake (Blender images) ----------
    // assets/cake-renders holds one see-through image per cake part, made by
    // blender_cake_kit/render_builder_assets.py. Parts that take the customer's colours were
    // rendered white and are tinted here with an SVG multiply filter; the message is live text.
    const RENDERS = data.renders;
    const TOP_ITEMS = ['flowers', 'macarons', 'candles', 'strawberries', 'cherries']; // back to front
    const loadedImages = new Set();
    const loadingImages = new Map();

    function finishKey(name) {
        const f = (name || '').toLowerCase();
        if (f.includes('butter')) return 'buttercream';
        if (f.includes('semi')) return 'semi';
        if (f.includes('fondant')) return f.includes('all') ? 'allfondant' : 'fondant';
        if (f.includes('icing') || f.includes('2d')) return 'icing';
        return 'plain';
    }

    // Size label parts with at least two numbers, e.g. "6x4/4x3" -> [[6, 4], [4, 3]].
    const sizeParts = (label) => String(label || '').split('/')
        .map((part) => (part.match(/\d+(?:\.\d+)?/g) || []).map(Number).filter((n) => n > 0))
        .filter((nums) => nums.length >= 2);

    function rigFor(shape, tiers, sizeLabel) {
        if (!RENDERS || !shape) return null;
        const n = Math.min(2, Math.max(tiers || 1, sizeParts(sizeLabel).length));
        const rigs = RENDERS.rigs;
        const exact = rigs[`${shape}-${n}@${sizeLabel}`];
        if (exact) return { rig: exact, key: `${shape}-${n}@${sizeLabel}`, exact: true };
        for (const key of [`${shape}-${n}`, `${shape}-1`]) if (rigs[key]) return { rig: rigs[key], key, exact: false };
        return null;
    }

    // The layers to stack, back to front: [name, tint, group, topping].
    function renderedLayers(rig, cfg) {
        const toppings = new Set(cfg.toppings || []);
        const drip = toppings.has('chocolate_drip');
        const finish = finishKey(cfg.finish);
        const body = { buttercream: 'buttercream', semi: 'fondant', fondant: 'fondant', allfondant: 'fondant' }[finish] || 'smooth';
        const list = [];
        const add = (name, tint, group, topping = '') => { if (rig.layers[name]) list.push([name, tint, group, topping]); };
        add(cfg.shape === 'bento' ? 'box-back' : 'stand', '', 'back');
        add(`body-${body}`, 'base', 'cake');
        if (drip) add('drip', '', 'cake', 'chocolate_drip');
        add(`finish-${finish}${drip ? '-drip' : ''}`, 'accent', 'cake');
        if (toppings.has('pearls')) add(finish === 'icing' ? 'pearls-low' : 'pearls', '', 'cake', 'pearls');
        if (toppings.has('sprinkles')) add(drip ? 'sprinkles-drip' : 'sprinkles', '', 'cake', 'sprinkles');
        if (toppings.has('gold_leaf')) add('gold', '', 'cake', 'gold_leaf');
        if (toppings.has('flowers')) {
            add('flowers-side', 'accent', 'cake', 'flowers');
            add('flowers-side-fixed', '', 'cake', 'flowers');
        }
        TOP_ITEMS.filter((key) => toppings.has(key)).forEach((key) => {
            if (key === 'flowers') {
                add('flowers-top', 'accent', 'top', key);
                add('flowers-top-fixed', '', 'top', key);
            } else if (key === 'macarons' || key === 'candles') {
                add(key, 'accent', 'top', key);
                add(`${key}-fixed`, '', 'top', key);
            } else {
                add(key, '', 'top', key);
            }
        });
        if (cfg.shape === 'bento') {
            add('box-front', '', 'front');
            add('box-front-accent', 'accent', 'front'); // the heart sticker
        }
        return list;
    }

    // Bigger sizes draw a little bigger (the images were rendered at the shape's default size).
    function renderedScale(found, sizeLabel) {
        if (found.exact) return 1;
        const parts = sizeParts(sizeLabel);
        if (!parts.length) return 1;
        const d = Math.max(...parts.map((p) => p[0]));
        const k = (90 + 18 * d) / (90 + 18 * found.rig.default_d);
        return Math.max(0.82, Math.min(1.15, k));
    }

    function renderedMessage(rig, text, drip, uid) {
        if (!text) return '';
        const spot = rig.message[drip ? 'drip' : 'plain'];
        const d = 'M' + spot.path.map(([x, y]) => `${x},${y}`).join(' L');
        // Script letters are about 0.6 em wide; shrink first, then squeeze so the text never runs off the path.
        const CHAR = 0.6;
        const fontSize = Math.max(7, Math.min(spot.height, spot.room / (text.length * CHAR)));
        const fit = text.length * fontSize * CHAR > spot.room * 0.9 ? ` textLength="${r1(spot.room)}" lengthAdjust="spacingAndGlyphs"` : '';
        const font = `font-size="${r1(fontSize)}" font-family="'Segoe Script','Brush Script MT','Lucida Handwriting',cursive" font-weight="700" dominant-baseline="middle"`;
        const line = (extra) => `<text ${font} ${extra}><textPath href="#${uid}-msgpath" startOffset="50%" text-anchor="middle"${fit}>${esc(text)}</textPath></text>`;
        const html = `<path id="${uid}-msgpath" d="${d}" fill="none"/>`
            + line('transform="translate(0 1.3)" fill="#000" opacity=".18"')
            + line('style="fill:var(--cb-ink);paint-order:stroke;stroke:var(--cb-base-light);stroke-width:3px;stroke-linejoin:round"');
        if (!spot.squash) return html;
        // Written flat on the top of the cake (bento): squash it to match the camera angle.
        const [x0, y0] = spot.path[0];
        const [x1, y1] = spot.path[spot.path.length - 1];
        const cx = r1((x0 + x1) / 2);
        const cy = r1((y0 + y1) / 2);
        return `<g transform="translate(${cx} ${cy}) scale(1 ${spot.squash}) translate(${-cx} ${-cy})">${html}</g>`;
    }

    function loadImage(url) {
        if (!loadingImages.has(url)) {
            loadingImages.set(url, new Promise((resolve) => {
                const img = new Image();
                const done = () => {
                    loadedImages.add(url);
                    resolve();
                };
                img.onload = done;
                img.onerror = done; // a missing layer just doesn't show; it never blocks the cake
                img.src = url;
            }));
        }
        return loadingImages.get(url);
    }

    function drawRenderedCake(svg, cfg, found) {
        const uid = svg.dataset.uid || (svg.dataset.uid = 'c' + Math.random().toString(36).slice(2, 8));
        const { rig } = found;
        const layers = renderedLayers(rig, cfg);
        const drip = (cfg.toppings || []).includes('chocolate_drip');
        const scale = renderedScale(found, cfg.size);
        const url = (name) => `${RENDERS.base}${rig.layers[name].file}?v=${RENDERS.version}`;
        const key = JSON.stringify([found.key, layers.map((l) => l[0]), scale]);
        const animating = cfg.animateTiers || cfg.popAll || (cfg.popSet && cfg.popSet.size);

        // Same layers as last time (e.g. only the colours or the message changed): colours update
        // through CSS variables on their own, so just rewrite the message.
        if (svg.dataset.renderKey === key && !animating) {
            const msg = svg.querySelector('.cb-msg');
            if (msg) msg.innerHTML = renderedMessage(rig, cfg.message, drip, uid);
            return;
        }

        // Wait until every image is ready so the cake never shows up half-built.
        const missing = layers.map((l) => url(l[0])).filter((u) => !loadedImages.has(u));
        if (missing.length) {
            svg.dataset.waitingFor = key;
            const slow = new Promise((resolve) => setTimeout(resolve, 5000));
            Promise.race([Promise.all(missing.map(loadImage)), slow]).then(() => {
                if (svg.dataset.waitingFor !== key) return;
                missing.forEach((u) => loadedImages.add(u));
                drawRenderedCake(svg, cfg, found);
            });
            return;
        }
        delete svg.dataset.waitingFor;
        svg.dataset.renderKey = key;

        const popOn = (topping) => topping && (cfg.popAll || (cfg.popSet && cfg.popSet.has(topping)));
        const image = ([name, tint]) => {
            const [x, y, w, h] = rig.layers[name].box;
            return `<image href="${url(name)}" x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="none"${tint ? ` filter="url(#${uid}-tint-${tint})"` : ''}/>`;
        };
        // Toppings are grouped per topping so a newly added one can pop in.
        const groupHtml = (group) => {
            let html = '';
            let open = null;
            layers.filter((l) => l[2] === group).forEach((l) => {
                const topping = l[3];
                if (topping !== open) {
                    if (open !== null) html += '</g>';
                    html += topping
                        ? `<g class="cb-top-item${popOn(topping) ? ' cb-pop' : ''}" data-t="${topping}"${popOn(topping) && cfg.popAll ? ' style="animation-delay:.5s"' : ''}>`
                        : '<g>';
                    open = topping;
                }
                html += image(l);
            });
            return open !== null ? html + '</g>' : html;
        };
        const tint = (name, color) => `<filter id="${uid}-tint-${name}" x="0" y="0" width="1" height="1" color-interpolation-filters="sRGB">
                <feFlood style="flood-color:var(${color})" result="c"/>
                <feComposite in="c" in2="SourceGraphic" operator="arithmetic" k1="1" k2="0" k3="0" k4="0"/>
            </filter>`;
        const [ax, ay] = rig.anchor;
        const lift = drip ? rig.drip_lift : 0;
        svg.innerHTML = `<defs>${tint('base', '--cb-base')}${tint('accent', '--cb-accent')}</defs>
            <g transform="translate(${ax} ${ay}) scale(${scale}) translate(${-ax} ${-ay})">
                ${groupHtml('back')}
                <g class="cb-tier${cfg.animateTiers ? ' cb-drop' : ''}">
                    ${groupHtml('cake')}
                    <g class="cb-msg">${renderedMessage(rig, cfg.message, drip, uid)}</g>
                    <g transform="translate(0 ${-lift})">${groupHtml('top')}</g>
                </g>
                ${groupHtml('front')}
            </g>`;

        // Shape cards: zoom in on the cake.
        if (svg.classList.contains('cb-thumb')) {
            const boxes = layers.filter((l) => l[2] !== 'top').map((l) => rig.layers[l[0]].box);
            const top = Math.min(...boxes.map((b) => b[1])) - 6;
            const bottom = Math.max(...boxes.map((b) => b[1] + b[3])) + 4;
            const h = bottom - top;
            const w = (h * 38) / 25;
            svg.setAttribute('viewBox', `${r1(200 - w / 2)} ${r1(top)} ${r1(w)} ${r1(h)}`);
        }
    }

    function drawCake(svg, cfg) {
        const found = cfg.shape ? rigFor(cfg.shape, cfg.tiers, cfg.size) : null;
        if (found) drawRenderedCake(svg, cfg, found);
        else drawVectorCake(svg, cfg);
    }

    function drawPreview(s) {
        applyColors(els.svg, s.base, s.accent);
        const geometry = s.recipe ? `${s.recipe.id}|${s.size ? s.size.label : ''}` : '';
        const animateTiers = !reduceMotion && geometry !== '' && geometry !== previous.geometry;
        const popSet = new Set(reduceMotion ? [] : s.toppings.filter((t) => !previous.toppings.has(t)));
        drawCake(els.svg, {
            shape: s.recipe ? s.recipe.shape : '',
            tiers: s.recipe ? s.recipe.tiers : 1,
            size: s.size ? s.size.label : '',
            finish: s.finish ? s.finish.name : '',
            toppings: s.toppings,
            message: s.message,
            animateTiers,
            popAll: animateTiers && s.toppings.length > 0,
            popSet,
        });
        previous = { geometry, toppings: new Set(s.toppings) };
    }

    // ---------- Effects ----------
    function sparkle() {
        if (reduceMotion) return;
        for (let i = 0; i < 12; i++) {
            const spark = document.createElement('span');
            spark.className = 'cb-sparkle';
            spark.textContent = '✦';
            const angle = (i / 12) * Math.PI * 2;
            const dist = 70 + Math.random() * 70;
            spark.style.left = '50%';
            spark.style.top = '38%';
            spark.style.setProperty('--dx', `${Math.round(Math.cos(angle) * dist)}px`);
            spark.style.setProperty('--dy', `${Math.round(Math.sin(angle) * dist)}px`);
            spark.style.color = SPRINKLES[i % 5];
            els.fx.append(spark);
            setTimeout(() => spark.remove(), 900);
        }
    }

    function shake() {
        if (reduceMotion) return;
        els.stage.classList.remove('is-shaking');
        void els.stage.offsetWidth;
        els.stage.classList.add('is-shaking');
    }

    function confetti() {
        const colors = ['#E0607E', '#F3A35B', '#FFD166', '#06D6A0', '#4CC9F0', '#B388EB'];
        for (let i = 0; i < 80; i++) {
            const bit = document.createElement('div');
            bit.className = 'cb-confetti';
            bit.style.left = `${Math.random() * 100}vw`;
            bit.style.background = colors[i % colors.length];
            bit.style.animationDelay = `${Math.random() * 0.25}s`;
            bit.style.setProperty('--dx', `${Math.round((Math.random() - 0.5) * 160)}px`);
            bit.style.setProperty('--rot', `${Math.round(360 + Math.random() * 720)}deg`);
            bit.style.setProperty('--dur', `${(0.9 + Math.random() * 0.6).toFixed(2)}s`);
            document.body.append(bit);
            setTimeout(() => bit.remove(), 2000);
        }
    }

    // ---------- Summary ----------
    function colorDot(hex) {
        const dot = document.createElement('span');
        dot.className = 'cb-dot';
        dot.style.background = hex;
        return dot;
    }

    const colorLabel = (hex, group) => (group === 'accent' ? data.accentColors : data.baseColors)[hex] || `Custom ${hex}`;

    function renderSummary(s) {
        const colors = document.createElement('span');
        if (s.base) colors.append(colorDot(s.base), colorLabel(s.base, 'base'));
        if (s.accent) colors.append(document.createTextNode('  '), colorDot(s.accent), `${colorLabel(s.accent, 'accent')} accents`);
        const rows = [
            ['Cake', s.recipe && s.recipe.name, 0],
            ['Size', s.size && s.size.label, 1],
            ['Finish', s.finish && s.finish.name, 2],
            ['Colors', colors, 3],
            ['Toppings', s.toppings.length ? s.toppings.map((t) => data.toppings[t]).join(', ') : 'None', 4],
            ['Message', s.message ? `“${s.message}”` : 'No message', 5],
        ];
        els.summary.replaceChildren(...rows.map(([label, value, step]) => {
            const wrap = document.createElement('div');
            const dt = Object.assign(document.createElement('dt'), { textContent: label });
            const dd = document.createElement('dd');
            if (value instanceof Node) dd.append(value);
            else dd.textContent = value || '—';
            const edit = Object.assign(document.createElement('dd'), { className: 'cb-edit' });
            const button = Object.assign(document.createElement('button'), {
                type: 'button', className: 'btn btn-link btn-sm p-0', textContent: 'Edit',
            });
            button.dataset.goto = String(step);
            button.setAttribute('aria-label', `Edit ${label.toLowerCase()}`);
            edit.append(button);
            wrap.append(dt, dd, edit);
            return wrap;
        }));
    }

    // ---------- Main update ----------
    function update() {
        const s = readState();
        const reach = reachableStep(s);

        els.dots.forEach((dot, i) => {
            dot.classList.toggle('is-current', i === current);
            dot.classList.toggle('is-done', i !== current && visited.has(i) && i < reach);
            dot.disabled = i > reach;
            if (i === current) dot.setAttribute('aria-current', 'step');
            else dot.removeAttribute('aria-current');
        });
        const percent = Math.round((current / LAST_STEP) * 100);
        els.bar.style.width = `${percent}%`;
        els.bar.parentElement.setAttribute('aria-valuenow', String(percent));

        const done = stepDone(current, s);
        els.back.disabled = current === 0;
        els.next.hidden = current === LAST_STEP;
        els.submit.hidden = current !== LAST_STEP;
        els.next.disabled = !done;
        els.next.classList.toggle('is-ready', done && current <= 3);
        if (done && current <= 3 && !completed.has(current)) {
            completed.add(current);
            sparkle();
        } else if (!done) {
            completed.delete(current);
        }

        // Toppings limit.
        const full = s.toppings.length >= data.maxToppings;
        $$('.cb-topping', form).forEach((input) => { input.disabled = full && !input.checked; });
        els.toppingCount.textContent = `${s.toppings.length}/${data.maxToppings} chosen`;
        els.toppingCount.classList.toggle('is-full', full);

        els.messageCount.textContent = `${els.message.value.length}/${els.message.maxLength}`;
        els.colorNames.textContent = s.base && s.accent
            ? `${colorLabel(s.base, 'base')} cake with ${colorLabel(s.accent, 'accent')} accents`
            : '';
        els.notReady.hidden = !(s.finish && !s.finish.ready);
        applyColors(els.toppingStep, s.base, s.accent);

        els.caption.textContent = s.recipe
            ? [s.recipe.name, s.size && s.size.label, s.finish && s.finish.name].filter(Boolean).join(' · ')
            : CAPTIONS[0];
        if (current === LAST_STEP) renderSummary(s);
        drawPreview(s);
    }

    function goTo(step, { focus = true } = {}) {
        const s = readState();
        step = Math.max(0, Math.min(step, reachableStep(s)));
        const changed = step !== current;
        current = step;
        visited.add(step);
        els.sections.forEach((section, i) => {
            section.hidden = i !== step;
            section.classList.toggle('is-entering', i === step && changed && !reduceMotion);
        });
        update();
        if (focus && changed) {
            const title = els.sections[step].querySelector('.cb-step-title');
            title.tabIndex = -1;
            title.focus({ preventScroll: true });
            const panel = form.querySelector('.cb-panel');
            if (panel.getBoundingClientRect().top < 0) panel.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        }
    }

    // ---------- Color wheel ----------
    // Hue is the angle (red at the top, clockwise), strength is the distance from the
    // center, and the slider sets brightness. A color picked here goes into the
    // "Your color" swatch of the group being edited (cake or accent).
    const wheel = {
        el: $('#cb-wheel'),
        handle: $('#cb-wheel-handle'),
        shade: $('#cb-wheel-shade'),
        brightness: $('#cb-brightness'),
        hex: $('#cb-hex'),
        hexDot: $('#cb-hex-dot'),
        target: 'base',
        h: 0,
        s: 0,
        v: 1,
        dragging: false,
    };
    let frame = 0;
    const scheduleUpdate = () => {
        if (!frame) frame = requestAnimationFrame(() => { frame = 0; update(); });
    };

    function hsvToHex(h, s, v) {
        const channel = (n) => {
            const k = (n + h / 60) % 6;
            return v - v * s * Math.max(0, Math.min(k, 4 - k, 1));
        };
        return '#' + [channel(5), channel(3), channel(1)].map((x) => Math.round(x * 255).toString(16).padStart(2, '0')).join('').toUpperCase();
    }

    function hexToHsv(hex) {
        const [r, g, b] = hexToRgb(hex).map((c) => c / 255);
        const max = Math.max(r, g, b);
        const d = max - Math.min(r, g, b);
        let h = 0;
        if (d) {
            if (max === r) h = ((g - b) / d) % 6;
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h = (h * 60 + 360) % 360;
        }
        return { h, s: max ? d / max : 0, v: max };
    }

    const customInput = (group) => $(`#${group}-custom`);

    function clearCustomColor(group) {
        const input = customInput(group);
        input.value = '';
        input.checked = false;
        input.disabled = true;
        input.labels[0].hidden = true;
    }

    // Checks the preset with this color, or puts it in the "Your color" swatch.
    function selectColor(group, hex) {
        const preset = $$(`input[name="${group}-color"]:not(.cb-custom)`, form).find((i) => i.value === hex);
        if (preset) {
            preset.checked = true;
            return;
        }
        const input = customInput(group);
        input.value = hex;
        input.disabled = false;
        input.checked = true;
        const label = input.labels[0];
        label.hidden = false;
        label.style.setProperty('--sw', hex);
        label.title = `Your color ${hex}`;
    }

    // Moves the handle, slider and code box to match wheel.h/s/v.
    function drawWheel(hex) {
        const rad = (wheel.h * Math.PI) / 180;
        wheel.handle.style.left = `${50 + Math.sin(rad) * wheel.s * 50}%`;
        wheel.handle.style.top = `${50 - Math.cos(rad) * wheel.s * 50}%`;
        wheel.handle.style.background = hex;
        wheel.handle.setAttribute('aria-valuenow', String(Math.round(wheel.h)));
        wheel.handle.setAttribute('aria-valuetext', `${hex}, hue ${Math.round(wheel.h)} degrees, strength ${Math.round(wheel.s * 100)}%`);
        wheel.shade.style.opacity = String(1 - wheel.v);
        wheel.brightness.value = String(Math.round(wheel.v * 100));
        wheel.brightness.style.setProperty('--track', `linear-gradient(90deg, #000, ${hsvToHex(wheel.h, wheel.s, 1)})`);
        if (document.activeElement !== wheel.hex) wheel.hex.value = hex;
        wheel.hexDot.style.background = hex;
    }

    // The wheel follows whatever color is selected for the group being edited.
    function syncWheel() {
        const hex = checkedValue(`${wheel.target}-color`) || (wheel.target === 'base' ? DEFAULT_BASE : DEFAULT_ACCENT);
        const hsv = hexToHsv(hex);
        if (hsv.s === 0 || hsv.v === 0) hsv.h = wheel.h; // grays have no hue; keep the last one
        Object.assign(wheel, hsv);
        drawWheel(hex);
        $('#cb-target-base-dot').style.background = checkedValue('base-color') || DEFAULT_BASE;
        $('#cb-target-accent-dot').style.background = checkedValue('accent-color') || DEFAULT_ACCENT;
    }

    // Uses the wheel's current h/s/v (or an exact code) as the color of the edited group.
    function pickFromWheel(exactHex) {
        const hex = exactHex || hsvToHex(wheel.h, wheel.s, wheel.v);
        selectColor(wheel.target, hex);
        drawWheel(hex);
        $(`#cb-target-${wheel.target}-dot`).style.background = hex;
        scheduleUpdate();
    }

    function setWheelTarget(group) {
        wheel.target = group;
        $(`#target-${group}`).checked = true;
        $$('.cb-swatches[data-group]').forEach((el) => { el.hidden = el.dataset.group !== group; });
        syncWheel();
    }

    function pointToColor(event) {
        const rect = wheel.el.getBoundingClientRect();
        const dx = event.clientX - (rect.left + rect.width / 2);
        const dy = event.clientY - (rect.top + rect.height / 2);
        wheel.s = Math.min(1, Math.hypot(dx, dy) / (rect.width / 2));
        wheel.h = ((Math.atan2(dx, -dy) * 180) / Math.PI + 360) % 360;
        pickFromWheel();
    }

    wheel.el.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        wheel.dragging = true;
        wheel.el.setPointerCapture(event.pointerId);
        els.stage.classList.add('is-picking');
        wheel.handle.focus({ preventScroll: true });
        pointToColor(event);
    });
    wheel.el.addEventListener('pointermove', (event) => { if (wheel.dragging) pointToColor(event); });
    const stopDrag = () => {
        wheel.dragging = false;
        els.stage.classList.remove('is-picking');
    };
    wheel.el.addEventListener('pointerup', stopDrag);
    wheel.el.addEventListener('pointercancel', stopDrag);

    wheel.handle.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 15 : 5;
        if (event.key === 'ArrowLeft') wheel.h = (wheel.h - step + 360) % 360;
        else if (event.key === 'ArrowRight') wheel.h = (wheel.h + step) % 360;
        else if (event.key === 'ArrowUp') wheel.s = Math.min(1, wheel.s + step / 100);
        else if (event.key === 'ArrowDown') wheel.s = Math.max(0, wheel.s - step / 100);
        else return;
        event.preventDefault();
        pickFromWheel();
    });

    wheel.brightness.addEventListener('input', () => {
        wheel.v = Number(wheel.brightness.value) / 100;
        els.stage.classList.add('is-picking');
        pickFromWheel();
    });
    wheel.brightness.addEventListener('change', () => els.stage.classList.remove('is-picking'));

    wheel.hex.addEventListener('input', () => {
        let value = wheel.hex.value.trim().toUpperCase();
        if (value && value[0] !== '#') value = '#' + value;
        const valid = /^#[0-9A-F]{6}$/.test(value);
        wheel.hex.classList.toggle('is-invalid', value.length >= 7 && !valid);
        if (!valid) return;
        const hsv = hexToHsv(value);
        if (hsv.s === 0 || hsv.v === 0) hsv.h = wheel.h;
        Object.assign(wheel, hsv);
        pickFromWheel(value);
    });
    wheel.hex.addEventListener('blur', () => {
        wheel.hex.classList.remove('is-invalid');
        syncWheel();
    });
    // Enter in the code box shouldn't move to the next step.
    wheel.hex.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });

    $$('input[name="cb-target"]').forEach((input) => input.addEventListener('change', () => setWheelTarget(input.value)));

    // ---------- Events ----------
    form.addEventListener('change', (event) => {
        const name = event.target.name;
        if (name === 'rec-Id') onRecipeChange();
        else if (name === 'size') onSizeChange();
        else if (name === `${wheel.target}-color`) syncWheel();
        update();
    });

    els.message.addEventListener('input', update);
    els.qty.addEventListener('input', update);

    $$('.cb-idea').forEach((button) => button.addEventListener('click', () => {
        els.message.value = button.textContent.trim();
        update();
    }));

    $$('[data-qty]').forEach((button) => button.addEventListener('click', () => {
        const value = (Number(els.qty.value) || 1) + Number(button.dataset.qty);
        els.qty.value = String(Math.max(1, Math.min(Number(els.qty.max), value)));
        update();
    }));

    document.addEventListener('click', (event) => {
        const target = event.target.closest('[data-goto]');
        if (target && !target.disabled) goTo(Number(target.dataset.goto));
    });

    els.back.addEventListener('click', () => goTo(current - 1));
    els.next.addEventListener('click', () => goTo(current + 1));

    $('#cb-surprise').addEventListener('click', () => {
        const pick = (list) => list[Math.floor(Math.random() * list.length)];
        const recipeInput = pick($$('input[name="rec-Id"]', form));
        recipeInput.checked = true;
        onRecipeChange();
        pick($$('input[name="size"]', form)).checked = true;
        onSizeChange();
        pick($$('input[name="recvar-Id"]', form)).checked = true;
        const baseInput = pick($$('input[name="base-color"]:not(.cb-custom)', form));
        baseInput.checked = true;
        pick($$('input[name="accent-color"]:not(.cb-custom)', form).filter((i) => i.value !== baseInput.value)).checked = true;
        syncWheel();
        const toppingInputs = $$('.cb-topping', form);
        toppingInputs.forEach((i) => { i.checked = false; i.disabled = false; });
        const count = 1 + Math.floor(Math.random() * 3);
        toppingInputs.sort(() => Math.random() - 0.5).slice(0, count).forEach((i) => { i.checked = true; });
        els.message.value = Math.random() < 0.7 ? pick(MESSAGE_IDEAS) : '';
        for (let i = 0; i <= LAST_STEP; i++) visited.add(i);
        shake();
        goTo(LAST_STEP);
    });

    $('#cb-reset').addEventListener('click', () => {
        if (readState().recipe && !window.confirm('Start over with a new cake? Your current design will be cleared.')) return;
        $$('input[type="radio"], input[type="checkbox"]', form).forEach((i) => { i.checked = false; i.disabled = false; });
        els.sizes.replaceChildren();
        els.finishes.replaceChildren();
        clearCustomColor('base');
        clearCustomColor('accent');
        findInput('base-color', DEFAULT_BASE).checked = true;
        findInput('accent-color', DEFAULT_ACCENT).checked = true;
        setWheelTarget('base');
        els.message.value = '';
        els.qty.value = '1';
        $('#cb-customer').value = '';
        $('#cb-pickup').value = '';
        $('#cb-notes').value = '';
        visited.clear();
        visited.add(0);
        completed.clear();
        current = -1;
        goTo(0, { focus: false });
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (submitting) return;
        // Enter in a text field moves to the next step instead of sending early.
        if (current !== LAST_STEP) {
            if (!els.next.disabled) goTo(current + 1);
            return;
        }
        const s = readState();
        const missing = [0, 1, 2, 3, 6].find((i) => !stepDone(i, s));
        if (missing !== undefined) {
            shake();
            goTo(missing);
            if (missing === 6) els.qty.reportValidity();
            return;
        }
        submitting = true;
        els.submit.disabled = true;
        els.submit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sending to the kitchen...';
        if (reduceMotion) {
            form.submit();
        } else {
            confetti();
            setTimeout(() => form.submit(), 900);
        }
    });

    // ---------- Start ----------
    // Shape cards show a small version of each cake.
    $$('.cb-thumb').forEach((thumb) => {
        const name = (thumb.parentElement.querySelector('.cb-option-name')?.textContent || '').toLowerCase();
        let base = '#F9C6D0';
        let accent = '#E8738A';
        if (name.includes('chocolate')) [base, accent] = ['#7B4B36', '#F9C6D0'];
        else if (name.includes('red velvet')) [base, accent] = ['#B23A48', '#FFFFFF'];
        applyColors(thumb, base, accent);
        drawCake(thumb, { shape: thumb.dataset.shape, tiers: Number(thumb.dataset.tiers) || 1, size: thumb.dataset.size, finish: 'Buttercream', toppings: [] });
    });

    const initial = data.initial;
    if (initial) {
        // Coming back from a failed save: restore every choice and open the review.
        const recipeInput = findInput('rec-Id', initial.recipe);
        if (recipeInput) {
            recipeInput.checked = true;
            renderSizes(recipes.get(initial.recipe), initial.size);
            renderFinishes(readState().size, '', initial.variant);
        }
        if (/^#[0-9A-F]{6}$/.test(initial.base || '')) selectColor('base', initial.base);
        if (/^#[0-9A-F]{6}$/.test(initial.accent || '')) selectColor('accent', initial.accent);
        syncWheel();
        (initial.toppings || []).forEach((key) => {
            const input = findInput('toppings[]', key);
            if (input) input.checked = true;
        });
        for (let i = 0; i <= LAST_STEP; i++) visited.add(i);
        for (let i = 0; i <= 3; i++) completed.add(i);
        goTo(LAST_STEP, { focus: false });
    } else {
        findInput('base-color', DEFAULT_BASE).checked = true;
        findInput('accent-color', DEFAULT_ACCENT).checked = true;
        syncWheel();
        completed.add(3);
        goTo(0, { focus: false });
    }
})();
