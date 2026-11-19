// cakedraw.js: draws a custom cake into an <svg>. Shared by the Cake Builder (cakebuilder.js)
// and the production queue (prodqueue.php), so both show the same cake.
// Needs assets/cake-sprite.svg inlined in the page. Call CakeDraw.init(renders) once, then
// CakeDraw.applyColors(svg, colors) and CakeDraw.drawCake(svg, cfg).
window.CakeDraw = (() => {
    'use strict';

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

    const DEFAULT_BASE = '#FFF4DC';
    const DEFAULT_ACCENT = '#E8738A';
    const DEFAULT_DRIP = '#5A3423';

    // Sets the cake's colors on an element as CSS variables (the drawing reads them).
    // colors: { base, accent } plus optional base2 (second cake color), trim (piping and ribbon),
    // board (stand), drip, ink (message) and inkOn (the cake color behind the message).
    // Left-out colors follow the others: trim = accent, base2 = base, board = white, ink = automatic.
    function applyColors(el, colors) {
        const base = colors.base || DEFAULT_BASE;
        const accent = colors.accent || DEFAULT_ACCENT;
        const base2 = colors.base2 || base;
        const trim = colors.trim || accent;
        const accentDark = mix(accent, '#000000', 0.2);
        const behind = colors.inkOn || base;
        const halo = mix(behind, '#FFFFFF', 0.35);
        // Message ink: the accent if it reads well on the cake, otherwise a dark or light fallback.
        let ink = colors.ink;
        if (!ink) {
            ink = accentDark;
            if (contrast(ink, halo) < 2.2) ink = luminance(halo) > 0.4 ? mix(behind, '#000000', 0.65) : '#FFFFFF';
        }
        const set = (name, value) => el.style.setProperty(name, value);
        set('--cb-base', base);
        set('--cb-base-light', mix(base, '#FFFFFF', 0.35));
        set('--cb-base-dark', mix(base, '#000000', 0.14));
        set('--cb-base2', base2);
        set('--cb-base2-light', mix(base2, '#FFFFFF', 0.35));
        set('--cb-base2-dark', mix(base2, '#000000', 0.14));
        set('--cb-accent', accent);
        set('--cb-accent-dark', accentDark);
        set('--cb-trim', trim);
        set('--cb-trim-dark', mix(trim, '#000000', 0.2));
        set('--cb-board', colors.board || '#FFFFFF');
        set('--cb-drip', colors.drip || DEFAULT_DRIP);
        set('--cb-ink', ink);
        set('--cb-ink-halo', halo);
        return ink;
    }

    // ---------- Things both kinds of drawing share ----------
    // Message fonts. "char" is the average letter width in em, used to fit the text.
    const FONTS = {
        script: { family: "'Segoe Script','Brush Script MT','Lucida Handwriting',cursive", weight: 700, char: 0.6 },
        pacifico: { family: "'Pacifico','Segoe Script',cursive", weight: 400, char: 0.5 },
        dancing: { family: "'Dancing Script','Segoe Script',cursive", weight: 700, char: 0.44 },
        playfair: { family: "'Playfair Display',Georgia,serif", weight: 700, char: 0.54 },
        fredoka: { family: "'Fredoka','Trebuchet MS',sans-serif", weight: 600, char: 0.56 },
    };
    const fontOf = (cfg) => FONTS[cfg.font] || FONTS.script;
    const msgOf = (cfg) => ({ dx: 0, dy: 0, size: 1, ...(cfg.msg || {}) });

    // Toppings that stand on top of the cake as separate pieces the customer can move.
    // A spot is (u, v), each from -1 to 1 on the top of the cake: u = left to right, v = front to back.
    const PIECE_KEYS = ['strawberries', 'cherries', 'macarons', 'flowers', 'candles'];
    const DEFAULT_SPOTS = {
        flowers: [[0, 0.62]],
        macarons: [[-0.46, 0.41], [0.46, 0.41]],
        strawberries: [[-0.6, -0.17], [0.6, -0.17]],
        cherries: [[-0.29, -0.55], [0.29, -0.55]],
        candles: [[-0.16, -0.08], [0.16, -0.08], [0, 0.16]],
    };
    const defaultItems = (toppings) => PIECE_KEYS.filter((k) => toppings.includes(k))
        .flatMap((k) => DEFAULT_SPOTS[k].map(([u, v]) => ({ k, u, v, s: 1 })));

    // The pieces to draw, back to front, each with its index in cfg.items and its number within its kind.
    function orderedItems(cfg) {
        const items = cfg.items || defaultItems(cfg.toppings || []);
        const counts = {};
        return items.map((item, i) => ({ ...item, i, n: (counts[item.k] = (counts[item.k] || 0) + 1) }))
            .sort((a, b) => b.v - a.v);
    }

    // Keeps a spot on the top of the cake (a little in from the edge).
    function clampSpot(top, u, v, limit = 0.86) {
        const LIMIT = Math.max(0, limit);
        if (top.box) return [Math.max(-LIMIT, Math.min(LIMIT, u)), Math.max(-LIMIT, Math.min(LIMIT, v))];
        const r = Math.hypot(u, v);
        return r > LIMIT ? [(u * LIMIT) / r, (v * LIMIT) / r] : [u, v];
    }

    // ----- Photo printed on top of the cake (edible print) -----
    // cfg.photo = { src, u, v, s }: the picture, its spot on the top and its size (1 = half the cake's width).
    // The picture lies flat on the cake: it is drawn in "top of the cake" units and a matrix lays it down.
    const PHOTO_HALF = 0.5;
    // Width divided by depth of the top, so a picture on a rectangle cake isn't stretched.
    function topAspect(cfg) {
        const part = sizeParts(cfg.size).slice().sort((a, b) => a[0] - b[0])[0];   // the top (smallest) tier
        return part && part.length >= 3 && part[1] > 0 ? part[0] / part[1] : 1;
    }

    // Half width and half depth of the picture in top units.
    function photoHalf(top, cfg) {
        const aspect = top.box ? topAspect(cfg) : 1;
        const rx = Math.min(PHOTO_HALF * ((cfg.photo && cfg.photo.s) || 1), 0.9, 0.9 / aspect);
        return [rx, rx * aspect];
    }

    // Keeps the whole picture on the cake.
    function clampPhoto(top, cfg, u, v) {
        const [rx, ry] = photoHalf(top, cfg);
        if (top.box) return [Math.max(rx - 0.95, Math.min(0.95 - rx, u)), Math.max(ry - 0.95, Math.min(0.95 - ry, v))];
        return clampSpot(top, u, v, 0.95 - rx);
    }

    function photoHtml(d, cfg, uid) {
        const p = cfg.photo;
        if (!p || !p.src || !d.top) return '';
        const { c, ex, ey, box } = d.top;
        const [rx, ry] = photoHalf(d.top, cfg);
        const [u, v] = clampPhoto(d.top, cfg, p.u || 0, p.v || 0);
        const n = (value) => Math.round(value * 1000) / 1000;
        const x = n(u);
        const y = n(-v);   // the matrix below flips v, so the picture's top edge is at the back of the cake
        const outline = (extra) => (box
            ? `<rect x="${n(x - rx)}" y="${n(y - ry)}" width="${n(rx * 2)}" height="${n(ry * 2)}" rx="${n(rx * 0.1)}" ${extra}/>`
            : `<circle cx="${x}" cy="${y}" r="${n(rx)}" ${extra}/>`);
        const selected = cfg.selected === 'photo';
        return `<g transform="matrix(${ex[0]} ${ex[1]} ${-ey[0]} ${-ey[1]} ${c[0]} ${r1(c[1] - d.lift)})">
                <clipPath id="${uid}-photo">${outline('')}</clipPath>
                <image href="${esc(p.src)}" x="${n(x - rx)}" y="${n(y - ry)}" width="${n(rx * 2)}" height="${n(ry * 2)}" preserveAspectRatio="xMidYMid slice" clip-path="url(#${uid}-photo)" opacity=".96"${cfg.interactive ? ' style="pointer-events:all"' : ''}/>
                ${outline(`fill="none" stroke="${selected ? 'var(--cb-rose, #e0607e)' : '#fff'}" stroke-opacity="${selected ? 1 : 0.75}" stroke-width="${selected ? 0.04 : 0.02}"${selected ? ' stroke-dasharray=".08 .06"' : ''}`)}
            </g>`;
    }

    // What the last drawing of this svg looked like, for dragging: where the top of the cake is,
    // and how far the message can move. Coordinates are in the svg's viewBox.
    const drawn = new WeakMap();
    const info = (svg) => drawn.get(svg) || null;

    // viewBox point -> spot on the top of the cake (not clamped).
    function pointToSpot(svg, x, y) {
        const d = info(svg);
        if (!d || !d.top) return null;
        const { c, ex, ey } = d.top;
        const px = (x - d.ax) / d.scale + d.ax - (d.tx || 0) - c[0];
        const py = (y - d.ay) / d.scale + d.ay - (d.ty || 0) - (c[1] - d.lift);
        const det = ex[0] * ey[1] - ex[1] * ey[0];
        return [(px * ey[1] - py * ey[0]) / det, (py * ex[0] - px * ex[1]) / det];
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
    const FIXED_STAND_WIDTH = 270;   // the drawn stand fits the widest cake (300) with a little overhang
    function stand(width, y) {
        const rx = width / 2 + 30;
        return `<ellipse cx="200" cy="${y + 39}" rx="54" ry="8" fill="url(#cbg-metal)"/>
            <ellipse cx="200" cy="${y + 37}" rx="50" ry="5.5" fill="#F3F5F9"/>
            <path d="M184,${y + 8} C190,${y + 20} 191,${y + 27} 175,${y + 37} H225 C209,${y + 27} 210,${y + 20} 216,${y + 8} Z" fill="url(#cbg-metal)"/>
            <ellipse cx="200" cy="${y + 6}" rx="${r1(rx)}" ry="14" fill="#B9C1CE"/>
            <ellipse cx="200" cy="${y}" rx="${r1(rx)}" ry="14" fill="url(#cbg-plate)"/>
            <g style="fill:var(--cb-board);mix-blend-mode:multiply">
                <path d="M184,${y + 8} C190,${y + 20} 191,${y + 27} 175,${y + 37} H225 C209,${y + 27} 210,${y + 20} 216,${y + 8} Z"/>
                <ellipse cx="200" cy="${y + 38}" rx="54" ry="8"/>
                <ellipse cx="200" cy="${y + 6}" rx="${r1(rx)}" ry="14"/>
                <ellipse cx="200" cy="${y}" rx="${r1(rx)}" ry="14"/>
            </g>
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
    // uid = this svg's gradient prefix; pass uid + '2' for a tier in the second cake color.
    // ombre = id of a gradient that fades the second color in from the top (or '' for none).
    function tierBody(t, uid, ombre = '') {
        const fade = (shape) => (ombre ? shape.replace('FILL', `url(#${ombre})`) : '');
        if (t.box) {
            const right = `${r1(t.x1)},${r1(t.yt)} ${r1(t.x1 + t.dx)},${r1(t.yt - t.dy)} ${r1(t.x1 + t.dx)},${r1(t.yb - t.dy)} ${r1(t.x1)},${r1(t.yb)}`;
            const top = `${r1(t.x0)},${r1(t.yt)} ${r1(t.x1)},${r1(t.yt)} ${r1(t.x1 + t.dx)},${r1(t.yt - t.dy)} ${r1(t.x0 + t.dx)},${r1(t.yt - t.dy)}`;
            const front = `<rect x="${r1(t.x0)}" y="${r1(t.yt)}" width="${r1(t.front)}" height="${r1(t.h)}" fill="FILL"/>`;
            return `<polygon points="${right}" fill="url(#${uid}-right)"/>
                ${fade(`<polygon points="${right}" fill="FILL"/>`)}
                <polygon points="${right}" fill="url(#${uid}-ao)"/>
                <rect x="${r1(t.x0)}" y="${r1(t.yt)}" width="${r1(t.front)}" height="${r1(t.h)}" fill="url(#${uid}-front)"/>
                ${fade(front)}
                <rect x="${r1(t.x0)}" y="${r1(t.yt)}" width="${r1(t.front)}" height="${r1(t.h)}" fill="url(#${uid}-ao)"/>
                <polygon points="${top}" fill="url(#${uid}-top)"/>
                ${fade(`<polygon points="${top}" fill="FILL"/>`)}
                <path d="M${r1(t.x0 + 1)},${r1(t.yt + 0.8)} H${r1(t.x1)} L${r1(t.x1 + t.dx - 1)},${r1(t.yt - t.dy + 0.8)}" stroke="#fff" stroke-opacity=".55" stroke-width="1.6" fill="none" stroke-linejoin="round"/>
                <path d="M${r1(t.x1)},${r1(t.yt)} V${r1(t.yb)}" stroke="#000" stroke-opacity=".08" stroke-width="1"/>`;
        }
        const side = `M${r1(t.x0)},${r1(t.yt)} V${r1(t.yb)} A${r1(t.rx)},${r1(t.ry)} 0 0 0 ${r1(t.x1)},${r1(t.yb)} V${r1(t.yt)} Z`;
        return `<path d="${side}" fill="url(#${uid}-side)"/>
            ${fade(`<path d="${side}" fill="FILL"/>`)}
            <path d="${side}" fill="url(#${uid}-ao)"/>
            <ellipse cx="${t.cx}" cy="${r1(t.yt)}" rx="${r1(t.rx)}" ry="${r1(t.ry)}" fill="url(#${uid}-top)"/>
            ${fade(`<ellipse cx="${t.cx}" cy="${r1(t.yt)}" rx="${r1(t.rx)}" ry="${r1(t.ry)}" fill="FILL"/>`)}
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
    function finishDecor(t, finish, rand, piping) {
        const f = (finish || '').toLowerCase();
        const s = t.scale;
        let under = '';
        let over = '';
        let trim = 9 * s; // height of the bottom trim, so pearls can sit above it
        if (f.includes('butter')) {
            under += buttercreamTexture(t, rand) + shellBorder(t, 'bottom', s);
            if (piping === 'shell') over += shellBorder(t, 'top', s);
            else if (piping === 'bead') over += topBorder(t, 'cb-bead', 7.5 * s, s);
            else over += topBorder(t, 'cb-rosette', 8.5 * s, s * 0.85);
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
    function drip(t, rand, tinted) {
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
        const body = tinted
            ? `<path d="${d}" style="fill:var(--cb-drip)"/><path d="${d}" fill="url(#cbg-shade-v)" opacity=".6"/>`
            : `<path d="${d}" fill="url(#cbg-choc)"/>`;
        return `${body}
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

    // The message on the drawn cake. Returns the text and how far it may be dragged (in viewBox units).
    function message(t, cfg) {
        const text = cfg.message;
        const font = fontOf(cfg);
        const at = msgOf(cfg);
        const room = t.front * 0.8;
        const wide = font.char * 0.85;
        // Shrink the font to fit first; squeeze the letters only if it's still too long.
        const fontSize = Math.max(8, Math.min(24, t.h * 0.32, room / (text.length * wide)) * at.size);
        const reach = { x: t.front * 0.2, y: t.h * 0.3 };
        const x = t.cx + at.dx * reach.x;
        const y = (t.box ? t.yt : t.yt + t.ry) + t.h * 0.5 - 3 + at.dy * reach.y;
        const fit = text.length * fontSize * wide > room ? ` textLength="${r1(room)}" lengthAdjust="spacingAndGlyphs"` : '';
        const attrs = `x="${r1(x)}" text-anchor="middle" dominant-baseline="middle" font-size="${r1(fontSize)}" font-family="${font.family}" font-weight="${font.weight}"${fit}`;
        return {
            reach,
            html: `<text ${attrs} y="${r1(y + 1.3)}" fill="#000" opacity=".18">${esc(text)}</text>
            <text ${attrs} y="${r1(y)}" style="fill:var(--cb-ink);paint-order:stroke;stroke:var(--cb-ink-halo);stroke-width:3px;stroke-linejoin:round">${esc(text)}</text>`,
        };
    }

    // One movable topping piece. Both kinds of drawing wrap their pieces in the same group, placed with
    // translate() + scale(), so dragging only has to rewrite the translate (see movePiece).
    function pieceGroup(p, x, y, k, cfg, inner, ring) {
        const pop = cfg.popAll || (cfg.popSet && cfg.popSet.has(p.k));
        const selected = cfg.selected === p.i;
        return `<g class="cb-piece${selected ? ' is-selected' : ''}" data-i="${p.i}" data-t="${p.k}" data-k="${k}" transform="translate(${r1(x)} ${r1(y)}) scale(${k})"${cfg.interactive ? ' tabindex="0" role="button"' : ''}>`
            + `<g class="cb-top-item${pop ? ' cb-pop' : ''}"${pop && cfg.popAll ? ' style="animation-delay:.5s"' : ''}>${selected ? ring : ''}${inner}</g></g>`;
    }

    const spotPoint = (d, u, v) => [d.top.c[0] + u * d.top.ex[0] + v * d.top.ey[0], d.top.c[1] - d.lift + u * d.top.ex[1] + v * d.top.ey[1]];

    // Moves a drawn piece to a new spot without redrawing the cake (used while dragging).
    function movePiece(svg, el, u, v) {
        const d = info(svg);
        if (!d || !d.top) return;
        const [x, y] = spotPoint(d, u, v);
        el.setAttribute('transform', `translate(${r1(x)} ${r1(y)}) scale(${el.dataset.k})`);
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
        // One set for the cake color and one (id + "2") for the second cake color.
        const grads = (id, v) => `
            <linearGradient id="${id}-side" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-${v}-dark)"/>
                <stop offset=".16" style="stop-color:var(--cb-${v})"/>
                <stop offset=".36" style="stop-color:var(--cb-${v}-light)"/>
                <stop offset=".64" style="stop-color:var(--cb-${v})"/>
                <stop offset="1" style="stop-color:var(--cb-${v}-dark)"/>
            </linearGradient>
            <linearGradient id="${id}-front" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-${v})"/>
                <stop offset=".3" style="stop-color:var(--cb-${v}-light)"/>
                <stop offset="1" style="stop-color:var(--cb-${v})"/>
            </linearGradient>
            <linearGradient id="${id}-right" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" style="stop-color:var(--cb-${v}-dark)"/>
                <stop offset="1" style="stop-color:var(--cb-${v})"/>
            </linearGradient>
            <radialGradient id="${id}-top" cx=".45" cy=".42" r=".62">
                <stop offset="0" style="stop-color:var(--cb-${v}-light)"/>
                <stop offset=".7" style="stop-color:var(--cb-${v}-light)"/>
                <stop offset="1" style="stop-color:var(--cb-${v})"/>
            </radialGradient>
            <linearGradient id="${id}-ao" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" stop-color="#fff" stop-opacity=".12"/>
                <stop offset=".25" stop-color="#fff" stop-opacity="0"/>
                <stop offset=".72" stop-color="#000" stop-opacity="0"/>
                <stop offset="1" stop-color="#000" stop-opacity=".16"/>
            </linearGradient>`;
        pieces.push(`<defs>${grads(uid, 'base')}${grads(uid + '2', 'base2')}
            <radialGradient id="${uid}-floor"><stop offset="0" stop-color="#000" stop-opacity=".2"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
        </defs>`);
        pieces.push(`<ellipse cx="200" cy="341" rx="${r1(width / 2 + 55)}" ry="11" fill="url(#${uid}-floor)"/>`);
        // The stand is always the same size, whatever the size of the cake on it.
        const boxed = cfg.shape === 'bento' && !cfg.hideBox;   // bento cakes sit in a box unless it is hidden
        if (boxed) pieces.push(bentoBack(width, plateY));
        else if (cfg.shape !== 'bento') pieces.push(stand(FIXED_STAND_WIDTH, plateY));

        // Nothing chosen yet: a dashed "ghost" cake.
        if (!cfg.shape) {
            pieces.push(`<g fill="none" stroke-width="2.5" stroke-dasharray="7 7" style="stroke:var(--bs-secondary-color)" opacity=".55">
                <path d="M105,180 V280 A95,22 0 0 0 295,280 V180"/><ellipse cx="200" cy="180" rx="95" ry="22"/></g>
                <text x="200" y="245" text-anchor="middle" font-size="42" font-weight="700" style="fill:var(--bs-secondary-color)" opacity=".45">?</text>`);
            svg.innerHTML = pieces.join('');
            drawn.delete(svg);
            return;
        }

        const tiers = layoutTiers(cfg.shape, sizes, plateY);
        const top = tiers[tiers.length - 1];
        const toppings = new Set(cfg.toppings || []);
        const popOn = (key) => cfg.popAll || (cfg.popSet && cfg.popSet.has(key));
        const item = (key, inner) => inner
            ? `<g class="cb-top-item${popOn(key) ? ' cb-pop' : ''}" data-t="${key}"${popOn(key) && cfg.popAll ? ' style="animation-delay:.5s"' : ''}>${inner}</g>`
            : '';
        const rand = seeded(Math.round(width * 7 + tiers.length * 131));
        // Piping, ribbons and bows take the trim color instead of the accent.
        const trimmed = (html) => (html ? `<g style="--cb-accent:var(--cb-trim);--cb-accent-dark:var(--cb-trim-dark)">${html}</g>` : '');

        // Ombre: the second color fades in from the top of the cake.
        const ombre = cfg.style === 'ombre' ? `${uid}-ombre` : '';
        if (ombre) {
            pieces.push(`<defs><linearGradient id="${ombre}" gradientUnits="userSpaceOnUse" x1="0" x2="0" y1="${r1(top.yt - 20)}" y2="${plateY}">
                <stop offset="0" style="stop-color:var(--cb-base2)"/>
                <stop offset=".25" style="stop-color:var(--cb-base2)"/>
                <stop offset=".85" style="stop-color:var(--cb-base2)" stop-opacity="0"/>
            </linearGradient></defs>`);
        }

        // Where the top of the cake is, for the photo and the movable pieces.
        const d = {
            top: top.box
                ? { c: [top.x0 + top.front / 2 + top.dx / 2, top.yt - top.dy / 2], ex: [top.front / 2, 0], ey: [top.dx / 2, -top.dy / 2], box: true }
                : { c: [top.cx, top.yt], ex: [top.rx, 0], ey: [0, -top.ry], box: false },
            lift: 0, ax: 0, ay: 0, scale: 1,
            msg: null,
        };
        let reach = null;
        tiers.forEach((t, i) => {
            const isTop = i === tiers.length - 1;
            const second = cfg.style === 'tiers' && i > 0;   // upper tiers in the second color
            const decor = finishDecor(t, i > 0 && cfg.finish2 ? cfg.finish2 : cfg.finish, rand, cfg.piping);
            let g = (boxed ? '' : contactShadow(t)) + tierBody(t, second ? uid + '2' : uid, ombre) + trimmed(decor.under);
            if (isTop && toppings.has('chocolate_drip')) g += item('chocolate_drip', drip(t, seeded(i + 7), cfg.dripTint));
            g += trimmed(decor.over);
            if (toppings.has('pearls')) g += item('pearls', row(t, 'bottom', 'cb-pearl', 7.6, 1, decor.trim + 5));
            if (toppings.has('sprinkles')) g += item('sprinkles', sprinkles(t, rand, isTop && !toppings.has('chocolate_drip')));
            if (isTop && toppings.has('gold_leaf')) g += item('gold_leaf', goldLeaf(t, rand));
            if (isTop && toppings.has('flowers')) g += item('flowers', sideFlowers(t));
            if (isTop) g += `<g class="cb-photo${cfg.selected === 'photo' ? ' is-selected' : ''}">${photoHtml(d, cfg, uid)}</g>`;
            if (isTop && cfg.message) {
                const m = message(t, cfg);
                reach = m.reach;
                g += `<g class="cb-msg${cfg.selected === 'msg' ? ' is-selected' : ''}">${item('message', m.html)}</g>`;
            }
            const styles = [];
            if (second) styles.push('--cb-base-dark:var(--cb-base2-dark)');
            if (cfg.animateTiers) styles.push(`animation-delay:${i * 0.14}s`);
            pieces.push(`<g class="cb-tier${cfg.animateTiers ? ' cb-drop' : ''}"${styles.length ? ` style="${styles.join(';')}"` : ''}>${g}</g>`);
        });

        if (boxed) pieces.push(bentoFront(width, plateY));

        // Pieces standing on the top surface, drawn back to front so nearer ones overlap farther ones.
        d.msg = reach;
        drawn.set(svg, d);
        const scale = Math.max(0.7, Math.min(1.2, top.front / 180));
        orderedItems(cfg).forEach((p) => {
            const asset = {
                strawberries: p.n % 2 ? 'cb-strawberry' : 'cb-strawberry-half',
                cherries: 'cb-cherry',
                macarons: 'cb-macaron',
                flowers: 'cb-rose',
                candles: 'cb-candle',
            }[p.k];
            if (!asset) return;
            const [x, y] = spotPoint(d, p.u, p.v);
            const k = r1(scale * (p.k === 'flowers' ? 1.2 : 1) * (p.s || 1) * 100) / 100;
            const tilt = p.k === 'macarons' ? (p.n % 2 ? -8 : 6) : p.k === 'cherries' ? (p.n % 2 ? -6 : 8) : 0;
            const shadow = '<ellipse cx="0" cy="0" rx="9" ry="2.6" fill="url(#cbg-contact)"/>';
            const tall = p.k === 'candles' ? 62 : 36;
            // See-through area to grab the piece by (only where pieces can be moved).
            const grab = cfg.interactive ? `<rect class="cb-grab" fill="transparent" x="-15" y="${-tall}" width="30" height="${tall + 6}" rx="8"/>` : '';
            pieces.push(pieceGroup(p, x, y, k, cfg, shadow + use(asset, 0, 0, 1, tilt) + grab,
                '<ellipse class="cb-sel-ring" cx="0" cy="-1" rx="15" ry="6"/>'));
        });

        svg.innerHTML = pieces.join('');
    }

    // ---------- Rendered cake (Blender images) ----------
    // assets/cake-renders holds one see-through image per cake part, made by
    // blender_cake_kit/render_builder_assets.py. Parts that take the customer's colours were
    // rendered white and are tinted here with an SVG multiply filter; the message is live text.
    let RENDERS = null;
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

    // The image layers to stack, back to front: [name, tint, group, topping].
    function renderedLayers(rig, cfg, usePieces) {
        const toppings = new Set(cfg.toppings || []);
        const drip = toppings.has('chocolate_drip');
        const finish = finishKey(cfg.finish);
        const bodyOf = (f) => ({ buttercream: 'buttercream', semi: 'fondant', fondant: 'fondant', allfondant: 'fondant' }[f] || 'smooth');
        const body = bodyOf(finish);
        const list = [];
        const has = (name) => !!rig.layers[name];
        const add = (name, tint, group, topping = '') => { if (has(name)) list.push([name, tint, group, topping]); };
        // A finish's decorations: `part` is '' for the whole cake, '-t1' for the bottom tier only or
        // '-t2' for the upper tier only. Buttercream can have another top border (piping style).
        const finishLayer = (f, part) => {
            const styled = `finish-${f}-${cfg.piping}${part}`;
            return f === 'buttercream' && cfg.piping && has(styled) ? styled : `finish-${f}${part}`;
        };
        const tail = drip ? '-drip' : '';
        // Two-tier cakes can have a different finish on the upper tier (when those images exist).
        const finish2 = cfg.finish2 ? finishKey(cfg.finish2) : finish;
        const split = finish2 !== finish && has(finishLayer(finish, '-t1')) && has(finishLayer(finish2, `-t2${tail}`));
        const body2 = split ? bodyOf(finish2) : body;

        if (cfg.shape !== 'bento') add('stand', 'board', 'back');
        else if (!cfg.hideBox) add('box-back', '', 'back');
        add(`body-${body}`, 'base', 'cake');
        // The upper tier again on top, when it has its own color (two-tone tiers) or its own surface.
        if (cfg.style === 'tiers' || body2 !== body) add(`body-${body2}-top`, cfg.style === 'tiers' ? 'base2' : 'base', 'cake');
        if (drip) {
            if (cfg.dripTint && has('drip-tint')) add('drip-tint', 'drip', 'cake', 'chocolate_drip');
            else add('drip', '', 'cake', 'chocolate_drip');
        }
        if (split) {
            add(finishLayer(finish, '-t1'), 'trim', 'cake');
            add(finishLayer(finish2, `-t2${tail}`), 'trim', 'cake');
        } else {
            add(finishLayer(finish, tail), 'trim', 'cake');
        }
        if (toppings.has('pearls')) add(finish === 'icing' ? 'pearls-low' : 'pearls', '', 'cake', 'pearls');
        if (toppings.has('sprinkles')) add(drip ? 'sprinkles-drip' : 'sprinkles', '', 'cake', 'sprinkles');
        if (toppings.has('gold_leaf')) add('gold', '', 'cake', 'gold_leaf');
        if (toppings.has('flowers')) {
            add('flowers-side', 'accent', 'cake', 'flowers');
            add('flowers-side-fixed', '', 'cake', 'flowers');
        }
        // Without the single-piece images, the toppings on top come as one image each, in fixed spots.
        if (!usePieces) {
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
        }
        if (cfg.shape === 'bento' && !cfg.hideBox) {
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

    // The message as live text along the front of the cake. Returns the text and how far it can
    // be dragged sideways and up or down (viewBox units, before the cake's own scale).
    function renderedMessage(rig, cfg, drip, uid) {
        const text = cfg.message;
        if (!text) return { html: '', reach: null };
        const spot = rig.message[drip ? 'drip' : 'plain'];
        const font = fontOf(cfg);
        const at = msgOf(cfg);
        const d = 'M' + spot.path.map(([x, y]) => `${x},${y}`).join(' L');
        let pathLen = 0;
        for (let i = 1; i < spot.path.length; i++) pathLen += Math.hypot(spot.path[i][0] - spot.path[i - 1][0], spot.path[i][1] - spot.path[i - 1][1]);
        // Shrink first, then squeeze, so the text never runs off the path.
        const fontSize = Math.max(6, Math.min(spot.height, spot.room / (text.length * font.char)) * at.size);
        const natural = text.length * fontSize * font.char;
        const fit = natural > spot.room * 0.9 ? ` textLength="${r1(spot.room)}" lengthAdjust="spacingAndGlyphs"` : '';
        // Sideways: slide along the path as far as the text still fits on it. Up and down: move the whole line.
        const slack = Math.max(0, (pathLen - Math.min(natural, spot.room)) / 2);
        const reach = { x: Math.max(1, slack), y: spot.height * 0.85 * (spot.squash || 1) };
        const start = r1(pathLen / 2 + at.dx * slack);
        const shift = r1(at.dy * spot.height * 0.85);
        const attrs = `font-size="${r1(fontSize)}" font-family="${font.family}" font-weight="${font.weight}" dominant-baseline="middle"`;
        const line = (extra) => `<text ${attrs} ${extra}><textPath href="#${uid}-msgpath" startOffset="${start}" text-anchor="middle"${fit}>${esc(text)}</textPath></text>`;
        let html = `<g transform="translate(0 ${shift})"><path id="${uid}-msgpath" d="${d}" fill="none"/>`
            + line('transform="translate(0 1.3)" fill="#000" opacity=".18"')
            + line('style="fill:var(--cb-ink);paint-order:stroke;stroke:var(--cb-ink-halo);stroke-width:3px;stroke-linejoin:round"')
            + '</g>';
        if (spot.squash) {
            // Written flat on the top of the cake (bento): squash it to match the camera angle.
            const [x0, y0] = spot.path[0];
            const [x1, y1] = spot.path[spot.path.length - 1];
            const cx = r1((x0 + x1) / 2);
            const cy = r1((y0 + y1) / 2);
            html = `<g transform="translate(${cx} ${cy}) scale(1 ${spot.squash}) translate(${-cx} ${-cy})">${html}</g>`;
        }
        return { html, reach };
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

    // The single-piece images for this cake's camera angle, or null if they haven't been rendered.
    function piecesFor(rig) {
        if (!RENDERS.pieces || !rig.top) return null;
        const view = rig.shape === 'bento' ? 'bento' : rig.top.box ? 'box' : 'round';
        return RENDERS.pieces[view] || null;
    }

    // Which images make up one piece: [name, tint]. Strawberries alternate whole and halved.
    function pieceImages(p) {
        switch (p.k) {
            case 'strawberries': return [[p.n % 2 ? 'strawberry' : 'strawberry-half', '']];
            case 'cherries': return [['cherry', '']];
            case 'macarons': return [['macaron', 'accent'], ['macaron-fixed', '']];
            case 'flowers': return [['rose', 'accent'], ['rose-fixed', '']];
            case 'candles': return [['candle', 'accent'], ['candle-fixed', '']];
            default: return [];
        }
    }

    // Where a piece can be grabbed: [half width, height] around its foot, in the piece images' own units.
    const PIECE_GRAB = { strawberries: [19, 42], cherries: [15, 40], macarons: [24, 48], flowers: [30, 40], candles: [11, 80] };

    function renderedPieces(rig, cfg, uid, d) {
        const set = piecesFor(rig);
        if (!set) return '';
        // Pieces were rendered on a 7-inch cake; match this cake's size.
        const base = (set.unit / rig.top.unit) * (rig.top.s / set.s);
        return orderedItems(cfg).map((p) => {
            const [x, y] = spotPoint(d, p.u, p.v);
            const k = r1(base * (p.s || 1) * 1000) / 1000;
            // Macarons lean outwards, so the ones on the left are mirrored.
            const flip = p.k === 'macarons' && p.u < 0 ? ' transform="scale(-1 1)"' : '';
            const images = pieceImages(p).filter(([name]) => set.pieces[name]).map(([name, tint]) => {
                const [bx, by, bw, bh] = set.pieces[name].box;
                return `<image href="${RENDERS.base}${set.pieces[name].file}?v=${RENDERS.version}" x="${bx}" y="${by}" width="${bw}" height="${bh}" preserveAspectRatio="none"${tint ? ` filter="url(#${uid}-tint-${tint})"` : ''}/>`;
            }).join('');
            const [gw, gh] = PIECE_GRAB[p.k] || [18, 40];
            const grab = cfg.interactive ? `<rect class="cb-grab" fill="transparent" x="${-gw}" y="${-gh}" width="${gw * 2}" height="${gh + 8}" rx="8"/>` : '';
            const ring = `<ellipse class="cb-sel-ring" cx="0" cy="-1" rx="${gw + 6}" ry="${r1((gw + 6) * 0.36)}"/>`;
            return pieceGroup(p, x, y, k, cfg, `<g${flip}>${images}</g>${grab}`, ring);
        }).join('');
    }

    function pieceUrls(rig, cfg) {
        const set = piecesFor(rig);
        if (!set) return [];
        return orderedItems(cfg).flatMap((p) => pieceImages(p)).filter(([name]) => set.pieces[name])
            .map(([name]) => `${RENDERS.base}${set.pieces[name].file}?v=${RENDERS.version}`);
    }

    // One stand per cake shape: the widest stand rendered for that shape is shown under every size,
    // so the stand stays the same while the cake on it changes. Returns that stand's image and how far
    // to move this cake so it sits on it, or null (bento boxes, or stand images not rendered yet).
    const standByShape = new Map();
    function fixedStand(rig) {
        if (rig.shape === 'bento' || !rig.base) return null;
        if (!standByShape.has(rig.shape)) {
            let widest = null;
            Object.values(RENDERS.rigs).forEach((other) => {
                const image = other.layers['stand-full'];
                if (other.shape !== rig.shape || !other.base || !image) return;
                if (!widest || image.box[2] > widest.layers['stand-full'].box[2]) widest = other;
            });
            standByShape.set(rig.shape, widest);
        }
        const ref = standByShape.get(rig.shape);
        if (!ref) return null;
        return { image: ref.layers['stand-full'], tx: r1(ref.base[0] - rig.base[0]), ty: r1(ref.base[1] - rig.base[1]) };
    }

    function drawRenderedCake(svg, cfg, found) {
        const uid = svg.dataset.uid || (svg.dataset.uid = 'c' + Math.random().toString(36).slice(2, 8));
        const { rig } = found;
        const usePieces = !!piecesFor(rig);
        const layers = renderedLayers(rig, cfg, usePieces);
        const drip = (cfg.toppings || []).includes('chocolate_drip');
        const scale = renderedScale(found, cfg.size);
        const url = (name) => `${RENDERS.base}${rig.layers[name].file}?v=${RENDERS.version}`;
        const ombre = cfg.style === 'ombre';
        // Shape cards keep each cake's own stand; the big preview uses the fixed one.
        const fixed = svg.classList.contains('cb-thumb') ? null : fixedStand(rig);
        const standUrl = fixed ? `${RENDERS.base}${fixed.image.file}?v=${RENDERS.version}` : '';
        const key = JSON.stringify([found.key, layers.map((l) => l[0]), scale, ombre, standUrl]);
        const animating = cfg.animateTiers || cfg.popAll;
        const [ax, ay] = rig.anchor;
        const lift = drip ? rig.drip_lift : 0;
        const d = { top: rig.top || null, lift, ax, ay, scale, msg: null, tx: fixed ? fixed.tx : 0, ty: fixed ? fixed.ty : 0 };
        const message = renderedMessage(rig, cfg, drip, uid);
        d.msg = message.reach;
        const msgClass = `cb-msg${cfg.selected === 'msg' ? ' is-selected' : ''}`;
        const photoClass = `cb-photo${cfg.selected === 'photo' ? ' is-selected' : ''}`;

        // Same layers as last time (only colours, the message or the pieces changed): colours update
        // through CSS variables on their own, so just rewrite the message and the pieces.
        if (svg.dataset.renderKey === key && !animating) {
            drawn.set(svg, d);
            const photo = svg.querySelector('.cb-photo');
            if (photo) {
                photo.setAttribute('class', photoClass);
                photo.innerHTML = photoHtml(d, cfg, uid);
            }
            const msg = svg.querySelector('.cb-msg');
            if (msg) {
                msg.setAttribute('class', msgClass);
                msg.innerHTML = message.html;
            }
            const holder = svg.querySelector('.cb-pieces');
            if (holder && usePieces) {
                const missing = pieceUrls(rig, cfg).filter((u) => !loadedImages.has(u));
                const fill = () => { holder.innerHTML = renderedPieces(rig, cfg, uid, d); };
                if (!missing.length) fill();
                else {
                    // New kinds of piece: wait for their images, unless a newer drawing has replaced this one.
                    const stamp = (svg.dataset.pieceStamp = String(Math.random()));
                    Promise.all(missing.map(loadImage)).then(() => { if (svg.dataset.pieceStamp === stamp) fill(); });
                }
            }
            return;
        }

        // Wait until every image is ready so the cake never shows up half-built.
        const wanted = layers.map((l) => url(l[0])).concat(usePieces ? pieceUrls(rig, cfg) : [], fixed ? [standUrl] : []);
        const missing = wanted.filter((u) => !loadedImages.has(u));
        if (missing.length) {
            svg.dataset.waitingFor = key;
            svg._cbWaiting = cfg;
            const slow = new Promise((resolve) => setTimeout(resolve, 5000));
            Promise.race([Promise.all(missing.map(loadImage)), slow]).then(() => {
                if (svg.dataset.waitingFor !== key) return;
                missing.forEach((u) => loadedImages.add(u));
                drawRenderedCake(svg, svg._cbWaiting, found);
            });
            return;
        }
        delete svg.dataset.waitingFor;
        delete svg.dataset.pieceStamp;
        svg.dataset.renderKey = key;
        drawn.set(svg, d);

        const popOn = (topping) => topping && (cfg.popAll || (cfg.popSet && cfg.popSet.has(topping)));
        const image = ([name, tint]) => {
            const [x, y, w, h] = rig.layers[name].box;
            const img = (extra) => `<image href="${url(name)}" x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="none"${extra}/>`;
            const tinted = img(tint ? ` filter="url(#${uid}-tint-${tint})"` : '');
            // Ombre: the same body again in the second color, faded in from the top.
            if (ombre && tint === 'base') return tinted + img(` filter="url(#${uid}-tint-base2)" mask="url(#${uid}-ombre)"`);
            return tinted;
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
        // The ombre fade runs over the cake body, top to bottom.
        const bodyLayer = layers.find((l) => l[1] === 'base');
        const [, bodyY, , bodyH] = bodyLayer ? rig.layers[bodyLayer[0]].box : [0, 0, 0, 1];
        const ombreMask = ombre ? `<linearGradient id="${uid}-ombre-g" gradientUnits="userSpaceOnUse" x1="0" x2="0" y1="${bodyY}" y2="${bodyY + bodyH}">
                <stop offset=".12" stop-color="#fff"/><stop offset=".88" stop-color="#000"/>
            </linearGradient>
            <mask id="${uid}-ombre" maskUnits="userSpaceOnUse" x="0" y="0" width="400" height="360"><rect width="400" height="360" fill="url(#${uid}-ombre-g)"/></mask>` : '';
        // The fixed stand, and a soft shadow where the cake rests on it (the stand image has none of its own).
        let standHtml = '';
        let contactShadow = '';
        if (fixed) {
            const [sx, sy, sw, sh] = fixed.image.box;
            standHtml = `<g><image href="${standUrl}" x="${sx}" y="${sy}" width="${sw}" height="${sh}" preserveAspectRatio="none" filter="url(#${uid}-tint-board)"/></g>`;
            const bodyBox = bodyLayer ? rig.layers[bodyLayer[0]].box : null;
            if (bodyBox) {
                const rx = bodyBox[2] / 2 + 7;
                contactShadow = `<ellipse cx="${r1(bodyBox[0] + bodyBox[2] / 2)}" cy="${r1(rig.base[1] + 1)}" rx="${r1(rx)}" ry="${r1(rx * (rig.top && rig.top.box ? 0.22 : 0.33))}" fill="url(#${uid}-rest)"/>`;
            }
        }
        svg.innerHTML = `<defs>${fixed ? `<radialGradient id="${uid}-rest"><stop offset=".55" stop-color="#2a1a14" stop-opacity=".34"/><stop offset="1" stop-color="#2a1a14" stop-opacity="0"/></radialGradient>` : ''}${tint('base', '--cb-base')}${tint('base2', '--cb-base2')}${tint('accent', '--cb-accent')}${tint('trim', '--cb-trim')}${tint('board', '--cb-board')}${tint('drip', '--cb-drip')}${ombreMask}</defs>
            <g transform="translate(${ax} ${ay}) scale(${scale}) translate(${-ax} ${-ay})">
                ${fixed ? standHtml : groupHtml('back')}
                <g transform="translate(${d.tx} ${d.ty})">
                ${fixed ? contactShadow : ''}
                <g class="cb-tier${cfg.animateTiers ? ' cb-drop' : ''}">
                    ${groupHtml('cake')}
                    <g class="${photoClass}">${photoHtml(d, cfg, uid)}</g>
                    <g class="${msgClass}">${message.html}</g>
                    <g transform="translate(0 ${-lift})">${groupHtml('top')}</g>
                    <g class="cb-pieces">${usePieces ? renderedPieces(rig, cfg, uid, d) : ''}</g>
                </g>
                ${groupHtml('front')}
                </g>
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

    return {
        init(renders) { RENDERS = renders || null; standByShape.clear(); },
        applyColors, drawCake, hexToRgb, mix, luminance, contrast, SPRINKLES,
        // For moving things around on a drawn cake (cakebuilder.js):
        info, pointToSpot, clampSpot, clampPhoto, movePiece, defaultItems, PIECE_KEYS, DEFAULT_SPOTS,
        // How many tiers this cake is drawn with ("6x4/4x3" means two).
        tierCount: (tiers, sizeLabel) => Math.min(2, Math.max(tiers || 1, sizeParts(sizeLabel).length)),
    };
})();
