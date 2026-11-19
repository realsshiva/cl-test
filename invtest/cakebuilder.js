// cakebuilder.js: step flow, live cake preview and the little game effects for cakebuilder.php.
// The cake itself is drawn by cakedraw.js. The form inputs are the single source of truth, plus
// `layout` (where the toppings and the message sit), which is mirrored into the hidden "layout" input.
// The preview and summary are redrawn from them after every change.
(() => {
    'use strict';

    const form = document.getElementById('cake-form');
    if (!form) return;

    const data = JSON.parse(document.getElementById('cb-data').textContent);
    const CD = window.CakeDraw;
    CD.init(data.renders);
    const { hexToRgb, mix, SPRINKLES } = CD;
    const recipes = new Map(data.recipes.map((r) => [r.id, r]));
    // Every color the customer can set: base, base2, accent, trim, side, drip, board, ink.
    const GROUPS = Object.keys(data.colorGroups);
    const SIDES_STEP = 3;
    const COLOR_STEP = 4;
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
        colorNames: $('#cb-color-names'),
        toppingCount: $('#cb-topping-count'),
        message: $('#cb-message'),
        messageCount: $('#cb-message-count'),
        summary: $('#cb-summary'),
        qty: $('#cb-qty'),
        layout: $('#cb-layout'),
        piping: $('#cb-piping'),
        pieceBar: $('#cb-piece-bar'),
        pieceName: $('#cb-piece-name'),
        dragHint: $('#cb-drag-hint'),
        undo: $('#cb-undo'),
        redo: $('#cb-redo'),
    };

    const LAST_STEP = els.sections.length - 1;
    const DEFAULT_BASE = '#FFF4DC';
    const DEFAULT_ACCENT = '#E8738A';
    const MESSAGE_IDEAS = ['Happy Birthday!', 'Congrats!', 'Love you', 'Cheers!', 'Best Day Ever', 'Sweet 16'];
    const CAPTIONS = [
        'Pick a shape to start baking!',
        'Now choose how big it should be.',
        'Pick a finish for the outside.',
        'Dress up the sides.',
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

    const DEFAULT_DRIP = '#5A3423';
    const PIECE_NAMES = { strawberries: 'Strawberry', cherries: 'Cherry', macarons: 'Macaron', flowers: 'Rose', candles: 'Candle' };
    const SIZE_RANGE = [0.6, 1.6];
    const clamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));
    const r3 = (n) => Math.round(n * 1000) / 1000;

    // Where the movable toppings and the message sit. items: [{k: topping, u, v, s: size}] with (u, v) a
    // spot on the top of the cake, each -1 to 1; msg: how far the message was moved (-1 to 1) and its size;
    // photo: the picture printed on top ({id, u, v, s}) or null.
    const blankLayout = () => ({ items: [], msg: { dx: 0, dy: 0, size: 1 }, photo: null });
    let layout = blankLayout();
    let selected = null;   // what is selected on the cake: an index into layout.items, 'msg', 'photo' or null
    let lastInk = '#9B2C5C';

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
        const colors = Object.fromEntries(GROUPS.map((g) => [g, checkedValue(`${g}-color`)]));
        const tierCount = recipe ? CD.tierCount(recipe.tiers, size ? size.label : '') : 1;
        let style = checkedValue('cake-style') || 'solid';
        if (style === 'tiers' && tierCount < 2) style = 'solid';
        if (style !== 'solid' && !colors.base2) style = 'solid';
        return {
            recipe,
            size,
            finish,
            base: colors.base,
            accent: colors.accent,
            colors,              // as chosen: a hex code, 'auto' or '' (nothing picked)
            style,               // solid | ombre | tiers, already checked against this cake
            tierCount,
            piping: checkedValue('piping') || 'rosette',
            font: checkedValue('msg-font') || 'script',
            // The side design of each tier, bottom tier first ('none' = plain sides).
            sides: Array.from({ length: tierCount }, (_, i) => checkedValue(`side-t${i + 1}`) || 'none'),
            // A different finish for the upper tier (two-tier cakes), by name; '' = same as the rest.
            finish2: tierCount > 1 && finish && size.finishes.some((f) => f.name === checkedValue('finish2') && f.id !== finish.id)
                ? checkedValue('finish2') : '',
            toppings: $$('.cb-topping:checked', form).map((i) => i.value),
            message: els.message.value.trim(),
            qty: Number(els.qty.value),
        };
    }

    const isHex = (value) => /^#[0-9A-F]{6}$/.test(value || '');
    const isButtercream = (s) => !!s.finish && s.finish.name.toLowerCase().includes('butter');

    // The colors to draw with. "auto" choices are left out so the drawing works them out.
    function paint(s) {
        const c = s.colors;
        const pick = (g) => (isHex(c[g]) ? c[g] : '');
        const base = pick('base') || DEFAULT_BASE;
        const base2 = s.style !== 'solid' ? pick('base2') : '';
        return {
            base,
            accent: pick('accent') || DEFAULT_ACCENT,
            base2,
            trim: pick('trim'),
            side: pick('side'),
            board: pick('board'),
            drip: pick('drip'),
            ink: pick('ink'),
            // The message sits on the top tier: in the second color for two-tone tiers, mid-fade for ombre.
            inkOn: s.style === 'tiers' ? base2 : s.style === 'ombre' ? mix(base, base2, 0.5).toUpperCase() : '',
        };
    }

    // The color a group shows right now, also when it is set to follow the rest of the design.
    function shownColor(group, s) {
        const value = s.colors[group];
        if (isHex(value)) return value;
        switch (group) {
            case 'base': return DEFAULT_BASE;
            case 'base2': return s.colors.base || DEFAULT_BASE;
            case 'trim': return s.colors.accent || DEFAULT_ACCENT;
            case 'side': return isHex(s.colors.trim) ? s.colors.trim : s.colors.accent || DEFAULT_ACCENT;
            case 'drip': return DEFAULT_DRIP;
            case 'board': return '#FFFFFF';
            case 'ink': return lastInk.toUpperCase();
            default: return DEFAULT_ACCENT;
        }
    }

    // Which color groups make sense for this cake (the rest are hidden in the "Color to change" row).
    function groupUsed(group, s) {
        if (group === 'base2') return s.style !== 'solid';
        if (group === 'drip') return s.toppings.includes('chocolate_drip');
        if (group === 'board') return !(s.recipe && s.recipe.shape === 'bento');
        if (group === 'side') return hasSides(s);
        return true;   // the message color can be chosen before the message is written
    }

    const hasSides = (s) => s.sides.some((key) => key !== 'none');

    function stepDone(step, s) {
        switch (step) {
            case 0: return !!s.recipe;
            case 1: return !!s.size;
            case 2: return !!s.finish;
            case COLOR_STEP: return !!(s.base && s.accent);
            case LAST_STEP: return Number.isInteger(s.qty) && s.qty >= 1 && s.qty <= Number(els.qty.max);
            default: return true;   // the sides, toppings and message are optional
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
            label.append(iconEl, name, meta);
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

    // The "Upper tier finish" choices: shown for two-tier cakes whose size comes in more than one finish.
    // Returns true when the choices were rebuilt (the caller then reads the form again).
    const finish2Box = $('#cb-finish2');
    const finish2Options = $('#cb-finish2-options');
    function syncFinish2(s) {
        const others = s.tierCount > 1 && s.finish ? s.size.finishes.filter((f) => f.id !== s.finish.id).map((f) => f.name) : [];
        const signature = others.join('|');
        finish2Box.hidden = others.length === 0;
        if (finish2Options.dataset.signature === signature) return false;
        const keep = checkedValue('finish2');
        finish2Options.dataset.signature = signature;
        finish2Options.replaceChildren();
        if (!others.length) return true;
        [['', `Same (${s.finish.name})`], ...others.map((name) => [name, name])].forEach(([value, text], i) => {
            const input = Object.assign(document.createElement('input'), { className: 'btn-check', type: 'radio', name: 'finish2', id: `finish2-${i}`, value });
            input.checked = value === keep || (i === 0 && !others.includes(keep));
            const label = Object.assign(document.createElement('label'), { className: 'cb-chip', htmlFor: input.id, textContent: text });
            finish2Options.append(input, label);
        });
        return true;
    }

    // ---------- Toppings and message on the cake ----------
    // Keeps the movable pieces in step with the topping checkboxes: a newly ticked topping gets its
    // usual pieces, an unticked one loses them.
    function syncItems(toppings) {
        CD.PIECE_KEYS.forEach((key) => {
            const has = layout.items.some((item) => item.k === key);
            if (toppings.includes(key) && !has) {
                layout.items.push(...CD.defaultItems([key]));
            } else if (!toppings.includes(key) && has) {
                layout.items = layout.items.filter((item) => item.k !== key);
                if (typeof selected === 'number') selected = null;
            }
        });
        if (typeof selected === 'number' && !layout.items[selected]) selected = null;
    }

    const layoutJson = () => JSON.stringify({
        items: layout.items.map((item) => [item.k, r3(item.u), r3(item.v), r3(item.s)]),
        msg: [r3(layout.msg.dx), r3(layout.msg.dy), r3(layout.msg.size)],
        photo: layout.photo ? [layout.photo.id, r3(layout.photo.u), r3(layout.photo.v), r3(layout.photo.s)] : null,
    });

    // Reads a layout as sent to the server ({items: [[key, u, v, size]], msg: [dx, dy, size]}).
    function parseLayout(json) {
        const result = blankLayout();
        let raw = null;
        try { raw = JSON.parse(json || ''); } catch (e) { raw = null; }
        if (!raw || typeof raw !== 'object') return result;
        const num = (value, lo, hi, fallback) => (Number.isFinite(Number(value)) ? clamp(Number(value), lo, hi) : fallback);
        if (Array.isArray(raw.items)) {
            result.items = raw.items.filter((item) => Array.isArray(item) && CD.PIECE_KEYS.includes(item[0]))
                .map((item) => ({ k: item[0], u: num(item[1], -1, 1, 0), v: num(item[2], -1, 1, 0), s: num(item[3], ...SIZE_RANGE, 1) }));
        }
        if (Array.isArray(raw.photo) && /^[a-f0-9]{32}$/.test(raw.photo[0] || '')) {
            result.photo = { id: raw.photo[0], u: num(raw.photo[1], -1, 1, 0), v: num(raw.photo[2], -1, 1, 0), s: num(raw.photo[3], ...SIZE_RANGE, 1) };
        }
        if (Array.isArray(raw.msg)) {
            result.msg = { dx: num(raw.msg[0], -1, 1, 0), dy: num(raw.msg[1], -1, 1, 0), size: num(raw.msg[2], ...SIZE_RANGE, 1) };
        }
        return result;
    }

    // Bento cakes come in a box; the customer can hide it to see the cake on its own (preview only).
    const boxToggle = $('#cb-box-toggle');
    let showBox = true;
    boxToggle.addEventListener('click', () => {
        showBox = !showBox;
        boxToggle.setAttribute('aria-pressed', String(showBox));
        boxToggle.title = showBox ? 'Hide the box' : 'Show the box';
        drawPreview(readState());
    });

    function drawPreview(s) {
        const colors = paint(s);
        lastInk = CD.applyColors(els.svg, colors);
        CD.applyColors(form, colors);   // the option cards (topping chips, style chips) follow the cake's colors too
        const geometry = s.recipe ? `${s.recipe.id}|${s.size ? s.size.label : ''}` : '';
        const animateTiers = !reduceMotion && geometry !== '' && geometry !== previous.geometry;
        const popSet = new Set(reduceMotion ? [] : s.toppings.filter((t) => !previous.toppings.has(t)));
        const hadFocus = els.svg.contains(document.activeElement);
        const isBento = !!(s.recipe && s.recipe.shape === 'bento');
        boxToggle.hidden = !isBento;
        CD.drawCake(els.svg, {
            hideBox: isBento && !showBox,
            shape: s.recipe ? s.recipe.shape : '',
            tiers: s.recipe ? s.recipe.tiers : 1,
            size: s.size ? s.size.label : '',
            finish: s.finish ? s.finish.name : '',
            toppings: s.toppings,
            message: s.message,
            style: s.style,
            piping: s.piping,
            finish2: s.finish2,
            sides: s.sides,
            photo: layout.photo ? { src: `${data.photoUrl}${layout.photo.id}.jpg`, u: layout.photo.u, v: layout.photo.v, s: layout.photo.s } : null,
            dripTint: isHex(s.colors.drip),
            font: s.font,
            msg: layout.msg,
            items: layout.items,
            selected,
            interactive: true,
            animateTiers,
            popAll: animateTiers && s.toppings.length > 0,
            popSet,
        });
        // Redrawing replaces the pieces; keep keyboard focus on the selected one.
        if (hadFocus && typeof selected === 'number') {
            const piece = els.svg.querySelector(`.cb-piece[data-i="${selected}"]`);
            if (piece) piece.focus({ preventScroll: true });
        }
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

    // Preset name, or a description of a custom color. Same rules as cakeColorName() in cake_options.php,
    // so the builder and the production queue name a color the same way.
    const ALL_COLORS = Object.assign({}, ...GROUPS.slice().reverse().map((g) => data.colorGroups[g].colors));
    function colorLabel(hex, group) {
        const all = { ...ALL_COLORS, ...data.colorGroups[group].colors };
        return all[hex] || describeColor(hex, all);
    }

    // Name of a group's current choice, e.g. "Rose", "Match accent" or "Custom light green".
    const choiceLabel = (group, s) => (isHex(s.colors[group]) ? colorLabel(s.colors[group], group) : data.colorGroups[group].auto || '');

    function describeColor(hex, palette) {
        const [r, g, b] = hexToRgb(hex);
        let nearest = '';
        let best = Infinity;
        Object.entries(palette).forEach(([presetHex, presetName]) => {
            const [pr, pg, pb] = hexToRgb(presetHex);
            const mean = (r + pr) / 2;
            const distance = Math.sqrt((2 + mean / 256) * (r - pr) ** 2 + 4 * (g - pg) ** 2 + (2 + (255 - mean) / 256) * (b - pb) ** 2);
            if (distance < best) {
                best = distance;
                nearest = presetName;
            }
        });
        if (best <= 40) return `Custom, near ${nearest}`;

        const [rf, gf, bf] = [r / 255, g / 255, b / 255];
        const max = Math.max(rf, gf, bf);
        const min = Math.min(rf, gf, bf);
        const d = max - min;
        const l = (max + min) / 2;
        const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
        if (s < 0.15) {
            if (l >= 0.92) return 'Custom white';
            if (l >= 0.7) return 'Custom light gray';
            if (l >= 0.35) return 'Custom gray';
            if (l >= 0.15) return 'Custom dark gray';
            return 'Custom black';
        }
        let h;
        if (max === rf) h = (((gf - bf) / d) + 6) % 6;
        else if (max === gf) h = (bf - rf) / d + 2;
        else h = (rf - gf) / d + 4;
        h *= 60;

        let hue;
        if (h < 15 || h >= 345) hue = l >= 0.7 ? 'pink' : 'red';
        else if (h < 45) hue = l < 0.4 ? 'brown' : 'orange';
        else if (h < 70) hue = l < 0.35 ? 'olive' : 'yellow';
        else if (h < 165) hue = 'green';
        else if (h < 195) hue = 'teal';
        else if (h < 255) hue = 'blue';
        else if (h < 290) hue = 'purple';
        else hue = 'pink';

        let shade = '';
        if (hue !== 'brown' && hue !== 'olive') {
            if (l >= 0.8) shade = 'pale ';
            else if (l >= 0.62) shade = 'light ';
            else if (l < 0.3) shade = 'dark ';
        }
        return `Custom ${shade}${hue}`;
    }

    // "Stripes", "Stripes (both tiers)" or "Bottom tier: Stripes · Top tier: Plain".
    function sidesText(s) {
        if (!hasSides(s)) return 'Plain';
        const names = s.sides.map((key) => data.sideDesigns[key] || 'Plain');
        if (names.length < 2) return names[0];
        if (names[0] === names[1]) return `${names[0]} (both tiers)`;
        return `Bottom tier: ${names[0]} · Top tier: ${names[1]}`;
    }

    // ---------- Sides step ----------
    // Each design's card shows a small cake of the chosen shape and finish with that pattern. They are drawn
    // only while the step is open, and again when the shape or finish changes (colors follow on their own).
    const sideThumbs = $$('.cb-side-thumb');
    let sideThumbKey = '';
    function syncSides(s) {
        const twoTiers = s.tierCount > 1;
        $('#cb-side-t2').hidden = !twoTiers;
        $('#cb-side-t1-label').textContent = twoTiers ? 'Bottom tier' : 'All around the cake';
        $$('[data-side-copy]').forEach((button) => { button.hidden = !twoTiers; });
        $('#cb-sides-hint').textContent = twoTiers
            ? 'Pick a pattern for each tier: the same on both, or a different one on top.'
            : 'Pick a pattern for the sides of the cake, or keep them plain.';
        $('#cb-side-color').hidden = !hasSides(s);
        $('#cb-side-dot').style.background = shownColor('side', s);
        $('#cb-side-name').textContent = isHex(s.colors.side)
            ? `${choiceLabel('side', s)} side design` : 'Side design in the piping & trim color';
        if (current !== SIDES_STEP) return;
        const shape = s.recipe ? s.recipe.shape : 'round';
        const finish = s.finish ? s.finish.name : 'Fondant';
        const key = `${shape}|${finish}`;
        if (key === sideThumbKey) return;
        sideThumbKey = key;
        sideThumbs.forEach((svg) => CD.drawCake(svg, {
            shape, tiers: 1, size: '', finish, toppings: [], hideBox: true, sides: [svg.dataset.side],
        }));
    }

    // "Use on both tiers": copies one tier's design to the other.
    $$('[data-side-copy]').forEach((button) => button.addEventListener('click', () => {
        const from = button.dataset.sideCopy;
        const input = findInput(`side-t${from === '1' ? 2 : 1}`, checkedValue(`side-t${from}`));
        if (input) input.checked = true;
        update();
    }));

    function renderSummary(s) {
        const colors = document.createElement('span');
        const addColor = (hex, text) => colors.append(document.createTextNode(colors.childNodes.length ? '  ' : ''), colorDot(hex), text);
        if (s.base) addColor(s.base, colorLabel(s.base, 'base'));
        if (s.style !== 'solid') addColor(s.colors.base2, `${colorLabel(s.colors.base2, 'base2')} (${s.style === 'ombre' ? 'ombre, fading in from the top' : 'upper tier'})`);
        if (s.accent) addColor(s.accent, `${colorLabel(s.accent, 'accent')} accents`);
        // Only the extra colors that were actually changed.
        const extras = [['trim', 'piping & trim'], ['side', 'side design'], ['drip', 'drip'], ['board', 'stand'], ['ink', 'message']];
        extras.filter(([g]) => groupUsed(g, s) && isHex(s.colors[g]) && (g !== 'ink' || s.message))
            .forEach(([g, what]) => addColor(s.colors[g], `${colorLabel(s.colors[g], g)} ${what}`));

        const pieceCounts = {};
        layout.items.forEach((item) => { pieceCounts[item.k] = (pieceCounts[item.k] || 0) + 1; });
        const toppingText = s.toppings.map((t) => (pieceCounts[t] ? `${data.toppings[t]} ×${pieceCounts[t]}` : data.toppings[t])).join(', ');
        const toppingList = [toppingText, layout.photo ? 'Photo on top' : ''].filter(Boolean).join(', ');
        const finishName = s.finish && (s.finish2 ? `${s.finish.name} (upper tier: ${s.finish2})` : s.finish.name);
        const finishText = s.finish && (isButtercream(s) && s.piping !== 'rosette'
            ? `${finishName} · ${data.pipingStyles[s.piping].toLowerCase()} border` : finishName);
        const rows = [
            ['Cake', s.recipe && s.recipe.name, 0],
            ['Size', s.size && s.size.label, 1],
            ['Finish', finishText, 2],
            ['Sides', sidesText(s), SIDES_STEP],
            ['Colors', colors, COLOR_STEP],
            ['Toppings', toppingList || 'None', COLOR_STEP + 1],
            ['Message', s.message ? `“${s.message}” · ${data.fonts[s.font]}` : 'No message', COLOR_STEP + 2],
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
        let s = readState();
        // An ombre or two-tone cake needs a second color: start with one that differs from the cake color.
        if ((checkedValue('cake-style') || 'solid') !== 'solid' && !s.colors.base2) {
            selectColor('base2', s.colors.base === '#F9C6D0' ? '#D9C8F0' : '#F9C6D0');
            s = readState();
        }
        syncItems(s.toppings);
        if (selected === 'msg' && !s.message) selected = null;
        if (selected === 'photo' && !layout.photo) selected = null;
        if (syncFinish2(s)) s = readState();
        els.layout.value = layoutJson();
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
        els.next.classList.toggle('is-ready', done && current <= COLOR_STEP);
        if (done && current <= COLOR_STEP && !completed.has(current)) {
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
        if (s.base && s.accent) {
            const cake = s.style === 'ombre' ? `${colorLabel(s.base, 'base')} to ${colorLabel(s.colors.base2, 'base2')} ombre cake`
                : s.style === 'tiers' ? `${colorLabel(s.base, 'base')} and ${colorLabel(s.colors.base2, 'base2')} tiers`
                    : `${colorLabel(s.base, 'base')} cake`;
            els.colorNames.textContent = `${cake} with ${colorLabel(s.accent, 'accent')} accents`;
        } else {
            els.colorNames.textContent = '';
        }

        // Options that depend on the cake: piping for buttercream, two-tone tiers for two-tier cakes.
        // The border can be chosen when any tier is buttercream.
        els.piping.hidden = !(isButtercream(s) || /butter/i.test(s.finish2));
        const tiersInput = $('#style-tiers');
        tiersInput.disabled = s.tierCount < 2;
        tiersInput.labels[0].hidden = s.tierCount < 2;
        if (tiersInput.checked && tiersInput.disabled) $('#style-solid').checked = true;

        els.caption.textContent = s.recipe
            ? [s.recipe.name, s.size && s.size.label, s.finish && s.finish.name].filter(Boolean).join(' · ')
            : CAPTIONS[0];
        drawPreview(s);   // also works out the automatic message color, which the color dots below show

        // "Color to change" row: hide the colors this cake doesn't have, and show each one's current shade.
        GROUPS.forEach((group) => {
            const used = groupUsed(group, s);
            const input = $(`#target-${group}`);
            input.disabled = !used;
            input.labels[0].hidden = !used;
            $(`#cb-target-${group}-dot`).style.background = shownColor(group, s);
        });
        if (!groupUsed(wheel.target, s)) setWheelTarget('base');
        syncSides(s);
        $('#cb-drip-color').hidden = !s.toppings.includes('chocolate_drip');
        $('#cb-drip-dot').style.background = shownColor('drip', s);
        $('#cb-drip-name').textContent = `${choiceLabel('drip', s)} drip`;
        $('#cb-ink-dot').style.background = shownColor('ink', s);
        $('#cb-ink-name').textContent = `${choiceLabel('ink', s)} color`;

        // Selection toolbar under the cake.
        const piece = typeof selected === 'number' ? layout.items[selected] : null;
        els.pieceBar.hidden = selected === null;
        if (selected !== null) {
            els.pieceName.textContent = piece ? PIECE_NAMES[piece.k] : selected === 'photo' ? 'Photo' : 'Message';
            const count = piece ? layout.items.filter((item) => item.k === piece.k).length : 0;
            $('#cb-piece-copy').hidden = !piece;
            $('#cb-piece-copy').disabled = count >= data.maxPiecesEach;
            $('#cb-piece-remove').hidden = !piece && selected !== 'photo';
            $('#cb-piece-center').hidden = !!piece;
            const size = piece ? piece.s : selected === 'photo' ? layout.photo.s : layout.msg.size;
            $('[data-piece="smaller"]', els.pieceBar).disabled = size <= SIZE_RANGE[0] + 0.001;
            $('[data-piece="bigger"]', els.pieceBar).disabled = size >= SIZE_RANGE[1] - 0.001;
        }
        els.dragHint.hidden = selected !== null || !s.recipe || !(layout.items.length || s.message || layout.photo);

        // Photo on top.
        photoStatus.textContent = photoMessage || (layout.photo ? 'Your photo is on the cake. Drag it to move it, or tap it to resize.' : PHOTO_HELP);
        $('#cb-photo-add-label').textContent = layout.photo ? 'Change photo' : 'Add a photo';
        $('#cb-photo-remove').hidden = !layout.photo;

        if (current === LAST_STEP) renderSummary(s);
        queueHistory();
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
        // For a group set to "automatic", the wheel starts from the color it currently shows as.
        const hex = shownColor(wheel.target, readState());
        const hsv = hexToHsv(hex);
        if (hsv.s === 0 || hsv.v === 0) hsv.h = wheel.h; // grays have no hue; keep the last one
        Object.assign(wheel, hsv);
        drawWheel(hex);
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

    // Emoji can't be stored (the database connection is 3-byte utf8) or piped onto a cake, so they are
    // taken out as they're typed, with a note, instead of vanishing when the cake is saved.
    const EMOJI = /[\u{10000}-\u{10FFFF}‍️]/gu;
    const emojiTimers = new Map();
    function removeEmoji(input) {
        const value = input.value;
        const clean = value.replace(EMOJI, '');
        if (clean === value) return;
        const caret = input.selectionStart == null ? clean.length : value.slice(0, input.selectionStart).replace(EMOJI, '').length;
        input.value = clean;
        try { input.setSelectionRange(caret, caret); } catch (e) { /* not a text field */ }
        const hint = document.getElementById(`${input.id}-emoji`);
        if (!hint) return;
        hint.hidden = false;
        clearTimeout(emojiTimers.get(input));
        emojiTimers.set(input, setTimeout(() => { hint.hidden = true; }, 5000));
    }

    els.message.addEventListener('input', () => { removeEmoji(els.message); update(); });
    ['#cb-customer', '#cb-notes'].forEach((sel) => {
        const input = $(sel);
        input.addEventListener('input', () => removeEmoji(input));
    });
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

    // Buttons outside the Colors step that jump straight to one color (drip, message).
    $$('[data-color-target]').forEach((button) => button.addEventListener('click', () => {
        goTo(COLOR_STEP);
        setWheelTarget(button.dataset.colorTarget);
    }));

    // ---------- Moving toppings and the message on the cake ----------
    // Pointer position in the cake's own coordinates (its viewBox).
    function svgPoint(event) {
        const matrix = els.svg.getScreenCTM();
        if (!matrix) return { x: 0, y: 0 };
        const p = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
        return { x: p.x, y: p.y };
    }

    function select(what) {
        if (selected === what) return;
        selected = what;
        update();
    }

    // ---------- Zooming in on the cake ----------
    // The cake is drawn on a 400 x 360 canvas; zooming shows a smaller part of it. Dragging the cake
    // itself (not a topping) moves the view around while zoomed in.
    const VIEW = { w: 400, h: 360, max: 3 };
    const view = { k: 1, x: VIEW.w / 2, y: VIEW.h / 2 };
    function applyView() {
        view.k = clamp(view.k, 1, VIEW.max);
        const w = VIEW.w / view.k;
        const h = VIEW.h / view.k;
        view.x = clamp(view.x, w / 2, VIEW.w - w / 2);
        view.y = clamp(view.y, h / 2, VIEW.h - h / 2);
        els.svg.setAttribute('viewBox', `${r3(view.x - w / 2)} ${r3(view.y - h / 2)} ${r3(w)} ${r3(h)}`);
        els.stage.classList.toggle('is-zoomed', view.k > 1);
        $('#cb-zoom-in').disabled = view.k >= VIEW.max;
        $('#cb-zoom-out').disabled = view.k <= 1;
        $('#cb-zoom-reset').disabled = view.k <= 1;
    }
    // Zooms by a factor, keeping the given point of the cake (or the middle of the view) in place.
    function zoomBy(factor, at) {
        const before = view.k;
        view.k = clamp(view.k * factor, 1, VIEW.max);
        if (at) {
            view.x = at.x + ((view.x - at.x) * before) / view.k;
            view.y = at.y + ((view.y - at.y) * before) / view.k;
        }
        applyView();
    }
    $('#cb-zoom-in').addEventListener('click', () => zoomBy(1.5));
    $('#cb-zoom-out').addEventListener('click', () => zoomBy(1 / 1.5));
    $('#cb-zoom-reset').addEventListener('click', () => { view.k = 1; applyView(); });
    // Ctrl + mouse wheel, or a pinch on a touchpad (browsers report that as Ctrl + wheel).
    els.stage.addEventListener('wheel', (event) => {
        if (!event.ctrlKey) return;
        event.preventDefault();
        zoomBy(event.deltaY < 0 ? 1.2 : 1 / 1.2, svgPoint(event));
    }, { passive: false });

    let drag = null;
    els.svg.addEventListener('pointerdown', (event) => {
        if (event.button > 0) return;
        const pieceEl = event.target.closest('.cb-piece');
        const msgEl = pieceEl ? null : event.target.closest('.cb-msg');
        const photoEl = pieceEl || msgEl ? null : event.target.closest('.cb-photo');
        if (!pieceEl && !msgEl && !photoEl) {
            // The cake itself: clears the selection, and moves the view when zoomed in.
            if (view.k > 1) {
                drag = { kind: 'pan', px: event.clientX, py: event.clientY, x: view.x, y: view.y, moved: false };
                els.stage.classList.add('is-panning');
                els.svg.setPointerCapture(event.pointerId);
            } else {
                select(null);
            }
            return;
        }
        event.preventDefault();
        const start = svgPoint(event);
        const spot = CD.pointToSpot(els.svg, start.x, start.y);
        if (pieceEl) {
            const index = Number(pieceEl.dataset.i);
            const item = layout.items[index];
            if (!item || !spot) return;
            // Keep the piece under the finger where it was grabbed, not jumping to its foot.
            drag = { kind: 'piece', index, item, el: pieceEl, start, du: item.u - spot[0], dv: item.v - spot[1], moved: false };
            pieceEl.classList.add('is-dragging');
        } else if (photoEl) {
            if (!layout.photo || !spot) return;
            drag = { kind: 'photo', start, du: layout.photo.u - spot[0], dv: layout.photo.v - spot[1], moved: false };
        } else {
            drag = { kind: 'msg', start, dx: layout.msg.dx, dy: layout.msg.dy, moved: false };
        }
        els.stage.classList.add('is-dragging');
        els.svg.setPointerCapture(event.pointerId);
    });

    els.svg.addEventListener('pointermove', (event) => {
        if (!drag) return;
        if (drag.kind === 'pan') {
            const rect = els.svg.getBoundingClientRect();
            const unitsPerPixel = VIEW.w / view.k / rect.width;
            if (Math.hypot(event.clientX - drag.px, event.clientY - drag.py) > 3) drag.moved = true;
            view.x = drag.x - (event.clientX - drag.px) * unitsPerPixel;
            view.y = drag.y - (event.clientY - drag.py) * unitsPerPixel;
            applyView();
            return;
        }
        const p = svgPoint(event);
        if (!drag.moved && Math.hypot(p.x - drag.start.x, p.y - drag.start.y) < 3) return;   // a tap, not a drag
        drag.moved = true;
        const drawn = CD.info(els.svg);
        if (!drawn) return;
        if (drag.kind === 'piece') {
            const spot = CD.pointToSpot(els.svg, p.x, p.y);
            if (!spot) return;
            [drag.item.u, drag.item.v] = CD.clampSpot(drawn.top, spot[0] + drag.du, spot[1] + drag.dv);
            CD.movePiece(els.svg, drag.el, drag.item.u, drag.item.v);
        } else if (drag.kind === 'photo') {
            const spot = CD.pointToSpot(els.svg, p.x, p.y);
            if (!spot || !layout.photo) return;
            placePhoto(spot[0] + drag.du, spot[1] + drag.dv);
            drawPreview(readState());
        } else if (drawn.msg) {
            layout.msg.dx = clamp(drag.dx + (p.x - drag.start.x) / drawn.scale / drawn.msg.x, -1, 1);
            layout.msg.dy = clamp(drag.dy + (p.y - drag.start.y) / drawn.scale / drawn.msg.y, -1, 1);
            drawPreview(readState());
        }
    });

    function endDrag(event) {
        if (!drag) return;
        const finished = drag;
        drag = null;
        els.stage.classList.remove('is-dragging', 'is-panning');
        if (els.svg.hasPointerCapture(event.pointerId)) els.svg.releasePointerCapture(event.pointerId);
        if (finished.kind === 'pan') {
            if (!finished.moved) select(null);
            return;
        }
        if (finished.kind === 'piece') finished.el.classList.remove('is-dragging');
        // A tap selects (or unselects); a drag just moves, and selects what was moved.
        const what = finished.kind === 'piece' ? finished.index : finished.kind;
        if (finished.moved) selected = what;
        else selected = selected === what ? null : what;
        update();
    }
    els.svg.addEventListener('pointerup', endDrag);
    els.svg.addEventListener('pointercancel', endDrag);
    // Touch screens: stop the page from scrolling while something on the cake is being dragged.
    els.svg.addEventListener('touchstart', (event) => {
        if (view.k > 1 || event.target.closest('.cb-piece, .cb-msg, .cb-photo')) event.preventDefault();
    }, { passive: false });

    // Keyboard: Tab to a piece, arrows to move it, + and - to resize, Delete to remove, Esc to finish.
    els.svg.addEventListener('focusin', (event) => {
        const pieceEl = event.target.closest('.cb-piece');
        if (pieceEl && !drag) select(Number(pieceEl.dataset.i));
    });
    els.svg.addEventListener('keydown', (event) => {
        const item = typeof selected === 'number' ? layout.items[selected] : null;
        if (!item) return;
        const step = event.shiftKey ? 0.12 : 0.04;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        if (moves[event.key]) {
            const drawn = CD.info(els.svg);
            if (!drawn || !drawn.top) return;
            [item.u, item.v] = CD.clampSpot(drawn.top, item.u + moves[event.key][0], item.v + moves[event.key][1]);
        } else if (event.key === '+' || event.key === '=') pieceAction('bigger');
        else if (event.key === '-') pieceAction('smaller');
        else if (event.key === 'Delete' || event.key === 'Backspace') pieceAction('remove');
        else if (event.key === 'Escape') selected = null;
        else return;
        event.preventDefault();
        update();
    });

    // ---------- Photo on top (edible print) ----------
    // Moves the photo, keeping all of it on the cake.
    function placePhoto(u, v) {
        const drawn = CD.info(els.svg);
        if (!layout.photo || !drawn || !drawn.top) return;
        const s = readState();
        [layout.photo.u, layout.photo.v] = CD.clampPhoto(drawn.top, { size: s.size ? s.size.label : '', photo: layout.photo }, u, v);
    }

    // The browser crops the picture to a centered square and shrinks it before sending, so uploads stay
    // small and the server only ever receives a plain JPEG.
    function squareJpeg(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(url);
                const side = Math.min(img.naturalWidth, img.naturalHeight);
                if (side < 16) { reject(new Error('small')); return; }
                const size = Math.min(1024, side);
                const canvas = Object.assign(document.createElement('canvas'), { width: size, height: size });
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';   // behind see-through pictures
                context.fillRect(0, 0, size, size);
                context.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, size, size);
                canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('encode'))), 'image/jpeg', 0.88);
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('read')); };
            img.src = url;
        });
    }

    const photoStatus = $('#cb-photo-status');
    const PHOTO_HELP = photoStatus.textContent;
    let photoMessage = '';   // an upload problem or progress note, shown instead of the usual help text
    $('#cb-photo-add').addEventListener('click', () => $('#cb-photo-file').click());
    $('#cb-photo-file').addEventListener('change', async (event) => {
        const file = event.target.files[0];
        event.target.value = '';   // so picking the same file again still counts as a change
        if (!file) return;
        const button = $('#cb-photo-add');
        button.disabled = true;
        photoMessage = 'Adding your photo…';
        update();
        try {
            const body = new FormData();
            body.append('photo', await squareJpeg(file), 'photo.jpg');
            const response = await fetch(data.photoUpload, { method: 'POST', body });
            const reply = await response.json();
            if (!response.ok || !reply.id) throw new Error(reply.error || 'upload');
            layout.photo = { id: reply.id, u: 0, v: 0, s: 1 };
            selected = 'photo';
            photoMessage = '';
        } catch (error) {
            photoMessage = error.message === 'read' || error.message === 'small' || error.message === 'encode'
                ? 'That file could not be used as a picture. Please try another one.'
                : 'The photo could not be added. Please check your connection and try again.';
        }
        button.disabled = false;
        update();
    });
    $('#cb-photo-remove').addEventListener('click', () => {
        layout.photo = null;
        if (selected === 'photo') selected = null;
        photoMessage = '';
        update();
    });

    // The toolbar under the cake, for whatever is selected: a piece, the message or the photo.
    function pieceAction(action) {
        const item = typeof selected === 'number' ? layout.items[selected] : null;
        const target = item || (selected === 'msg' ? layout.msg : selected === 'photo' ? layout.photo : null);
        if (!target) return;
        const sizeKey = selected === 'msg' ? 'size' : 's';
        if (action === 'bigger') target[sizeKey] = clamp(target[sizeKey] + 0.15, ...SIZE_RANGE);
        else if (action === 'smaller') target[sizeKey] = clamp(target[sizeKey] - 0.15, ...SIZE_RANGE);
        else if (action === 'center' && selected === 'msg') Object.assign(layout.msg, { dx: 0, dy: 0 });
        else if (action === 'center' && selected === 'photo') Object.assign(layout.photo, { u: 0, v: 0 });
        else if (action === 'done') selected = null;
        else if (action === 'remove' && selected === 'photo') {
            layout.photo = null;
            selected = null;
        } else if (action === 'copy' && item) {
            if (layout.items.filter((other) => other.k === item.k).length >= data.maxPiecesEach) return;
            const drawn = CD.info(els.svg);
            const [u, v] = drawn && drawn.top ? CD.clampSpot(drawn.top, item.u + (item.u > 0.4 ? -0.3 : 0.3), item.v - 0.12) : [0, 0];
            layout.items.push({ ...item, u, v });
            selected = layout.items.length - 1;
        } else if (action === 'remove' && item) {
            layout.items.splice(selected, 1);
            selected = null;
            // Removing the last piece of a topping also unticks that topping.
            if (!layout.items.some((other) => other.k === item.k)) {
                const input = findInput('toppings[]', item.k);
                if (input) input.checked = false;
            }
        }
        // A bigger photo may no longer fit where it was.
        if (layout.photo && selected === 'photo') placePhoto(layout.photo.u, layout.photo.v);
    }
    els.pieceBar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-piece]');
        if (!button || button.disabled) return;
        pieceAction(button.dataset.piece);
        update();
    });

    // ---------- Whole designs: undo, redo, start over, surprise ----------
    const blankDesign = () => ({
        recipe: '', size: '', variant: '',
        colors: { base: DEFAULT_BASE, base2: '', accent: DEFAULT_ACCENT, trim: 'auto', side: 'auto', drip: 'auto', board: 'auto', ink: 'auto' },
        style: 'solid', piping: 'rosette', font: 'script', finish2: '', sides: ['none', 'none'], toppings: [], message: '', layout: blankLayout(),
    });

    // Everything that makes up the cake, as plain data.
    function currentDesign() {
        return {
            recipe: checkedValue('rec-Id'),
            size: checkedValue('size'),
            variant: checkedValue('recvar-Id'),
            colors: Object.fromEntries(GROUPS.map((g) => [g, checkedValue(`${g}-color`)])),
            style: checkedValue('cake-style') || 'solid',
            piping: checkedValue('piping') || 'rosette',
            font: checkedValue('msg-font') || 'script',
            finish2: checkedValue('finish2'),
            sides: [checkedValue('side-t1') || 'none', checkedValue('side-t2') || 'none'],
            toppings: $$('.cb-topping:checked', form).map((i) => i.value),
            message: els.message.value,
            layout: JSON.parse(JSON.stringify(layout)),
        };
    }

    // Puts a design back into the form (the opposite of currentDesign).
    function applyDesign(design) {
        const d = { ...blankDesign(), ...design };
        const check = (name, value, fallback) => {
            const input = findInput(name, value) || findInput(name, fallback);
            if (input) input.checked = true;
        };
        $$('input[name="rec-Id"], .cb-topping', form).forEach((i) => { i.checked = false; i.disabled = false; });
        els.sizes.replaceChildren();
        els.finishes.replaceChildren();
        if (d.recipe && recipes.has(d.recipe)) {
            findInput('rec-Id', d.recipe).checked = true;
            renderSizes(recipes.get(d.recipe), d.size);
            renderFinishes(readState().size, '', d.variant);
        }
        GROUPS.forEach((group) => {
            clearCustomColor(group);
            $$(`input[name="${group}-color"]`, form).forEach((i) => { i.checked = false; });
            const value = (d.colors || {})[group] || blankDesign().colors[group];
            if (isHex(value) || value === 'auto') selectColor(group, value);
        });
        check('cake-style', d.style, 'solid');
        check('piping', d.piping, 'rosette');
        check('msg-font', d.font, 'script');
        const sides = Array.isArray(d.sides) ? d.sides : [];
        check('side-t1', sides[0] || 'none', 'none');
        check('side-t2', sides[1] || sides[0] || 'none', 'none');
        finish2Options.dataset.signature = '?';   // rebuild the upper tier choices for this cake
        syncFinish2(readState());
        check('finish2', d.finish2 || '', '');
        (d.toppings || []).forEach((key) => {
            const input = findInput('toppings[]', key);
            if (input) input.checked = true;
        });
        els.message.value = d.message || '';
        layout = { ...blankLayout(), ...JSON.parse(JSON.stringify(d.layout || {})) };
        selected = null;
        syncWheel();
    }

    // Undo history: a snapshot is saved shortly after each change, so a drag of the color wheel
    // or a typed word counts as one step.
    const history = { stack: [], index: -1, timer: 0, paused: false };
    function refreshHistoryButtons() {
        els.undo.disabled = history.index <= 0;
        els.redo.disabled = history.index >= history.stack.length - 1;
    }
    function commitHistory() {
        clearTimeout(history.timer);
        history.timer = 0;
        const snapshot = JSON.stringify(currentDesign());
        if (snapshot === history.stack[history.index]) return;
        history.stack.splice(history.index + 1);
        history.stack.push(snapshot);
        if (history.stack.length > 80) history.stack.shift();
        history.index = history.stack.length - 1;
        refreshHistoryButtons();
    }
    function queueHistory() {
        if (history.paused) return;
        clearTimeout(history.timer);
        history.timer = setTimeout(commitHistory, 350);
    }
    function stepHistory(by) {
        if (history.timer) commitHistory();   // include a change that hasn't been saved yet
        const index = history.index + by;
        if (index < 0 || index >= history.stack.length) return;
        history.index = index;
        history.paused = true;
        applyDesign(JSON.parse(history.stack[index]));
        // Stay on the current step unless it can no longer be reached (e.g. the shape was undone).
        goTo(Math.min(current, reachableStep(readState())), { focus: false });
        history.paused = false;
        refreshHistoryButtons();
    }
    els.undo.addEventListener('click', () => stepHistory(-1));
    els.redo.addEventListener('click', () => stepHistory(1));
    document.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
        if (event.target.closest('input[type="text"], input[type="number"], input[type="date"], textarea')) return;   // keep the browser's own undo while typing
        const key = event.key.toLowerCase();
        if (key === 'z' && !event.shiftKey) stepHistory(-1);
        else if (key === 'y' || (key === 'z' && event.shiftKey)) stepHistory(1);
        else return;
        event.preventDefault();
    });

    $('#cb-surprise').addEventListener('click', () => {
        const pick = (list) => list[Math.floor(Math.random() * list.length)];
        const chance = (p) => Math.random() < p;
        // Pick the shape first, so every shape is equally likely however many sizes it has.
        const recipe = pick(data.recipes);
        const size = pick(recipe.sizes);
        const shades = (group) => Object.keys(data.colorGroups[group].colors);
        const base = pick(shades('base'));
        const twoTiers = CD.tierCount(recipe.tiers, size.label) > 1;
        const style = twoTiers && chance(0.5) ? 'tiers' : chance(0.3) ? 'ombre' : 'solid';
        const patterns = Object.keys(data.sideDesigns).filter((key) => key !== 'none');
        const side = chance(0.6) ? pick(patterns) : 'none';
        applyDesign({
            recipe: recipe.id,
            size: size.label,
            variant: pick(size.finishes).id,
            colors: {
                base,
                base2: style === 'solid' ? '' : pick(shades('base2').filter((c) => c !== base)),
                accent: pick(shades('accent').filter((c) => c !== base)),
                trim: 'auto',
                side: side !== 'none' && chance(0.3) ? pick(shades('side')) : 'auto',
                drip: chance(0.3) ? pick(shades('drip')) : 'auto',
                board: chance(0.25) ? pick(shades('board')) : 'auto',
                ink: 'auto',
            },
            style,
            piping: pick(Object.keys(data.pipingStyles)),
            font: pick(Object.keys(data.fonts)),
            finish2: twoTiers && chance(0.4) ? pick(size.finishes).name : '',
            sides: [side, twoTiers && chance(0.4) ? pick(['none', ...patterns]) : side],
            toppings: Object.keys(data.toppings).sort(() => Math.random() - 0.5).slice(0, 1 + Math.floor(Math.random() * 3)),
            message: chance(0.7) ? pick(MESSAGE_IDEAS) : '',
        });
        for (let i = 0; i <= LAST_STEP; i++) visited.add(i);
        shake();
        goTo(LAST_STEP);
    });

    $('#cb-reset').addEventListener('click', () => {
        if (readState().recipe && !window.confirm('Start over with a new cake? Your current design will be cleared.')) return;
        applyDesign(blankDesign());
        setWheelTarget('base');
        els.qty.value = '1';
        $('#cb-customer').value = '';
        $('#cb-pickup').value = '';
        $('#cb-notes').value = '';
        visited.clear();
        visited.add(0);
        completed.clear();
        completed.add(SIDES_STEP);
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
        const missing = [0, 1, 2, COLOR_STEP, LAST_STEP].find((i) => !stepDone(i, s));
        if (missing !== undefined) {
            shake();
            goTo(missing);
            if (missing === LAST_STEP) els.qty.reportValidity();
            return;
        }
        submitting = true;
        els.layout.value = layoutJson();
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
    // Shape cards show a small version of each cake. (The side design cards are drawn by syncSides, in the
    // cake's own colors.)
    $$('.cb-shape .cb-thumb').forEach((thumb) => {
        const name = (thumb.parentElement.querySelector('.cb-option-name')?.textContent || '').toLowerCase();
        let base = '#F9C6D0';
        let accent = '#E8738A';
        if (name.includes('chocolate')) [base, accent] = ['#7B4B36', '#F9C6D0'];
        else if (name.includes('red velvet')) [base, accent] = ['#B23A48', '#FFFFFF'];
        CD.applyColors(thumb, { base, accent });
        CD.drawCake(thumb, { shape: thumb.dataset.shape, tiers: Number(thumb.dataset.tiers) || 1, size: thumb.dataset.size, finish: 'Buttercream', toppings: [] });
    });

    const initial = data.initial;
    if (initial) {
        // Coming back from a failed save: restore every choice and open the review.
        const sent = (value) => String(value || '').toUpperCase();
        const orAuto = (value) => (isHex(sent(value)) ? sent(value) : 'auto');
        applyDesign({
            recipe: initial.recipe,
            size: initial.size,
            variant: initial.variant,
            colors: {
                base: isHex(sent(initial.base)) ? sent(initial.base) : DEFAULT_BASE,
                base2: isHex(sent(initial.base2)) ? sent(initial.base2) : '',
                accent: isHex(sent(initial.accent)) ? sent(initial.accent) : DEFAULT_ACCENT,
                trim: orAuto(initial.trim),
                side: orAuto(initial.side),
                drip: orAuto(initial.drip),
                board: orAuto(initial.board),
                ink: orAuto(initial.ink),
            },
            style: initial.style,
            piping: initial.piping,
            font: initial.font,
            finish2: initial.finish2,
            sides: initial.sides,
            toppings: initial.toppings || [],
            message: els.message.value,
            layout: parseLayout(initial.layout),
        });
        for (let i = 0; i <= LAST_STEP; i++) visited.add(i);
        for (let i = 0; i <= COLOR_STEP; i++) completed.add(i);
        goTo(LAST_STEP, { focus: false });
    } else {
        applyDesign(blankDesign());
        completed.add(SIDES_STEP);   // optional steps that start out done: no sparkle just for opening them
        completed.add(COLOR_STEP);
        goTo(0, { focus: false });
    }
    commitHistory();   // the starting point for Undo
})();
