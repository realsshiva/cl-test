"""
Pre-renders the image layers used by the web Cake Builder (invtest/cakebuilder.php).

Every part of a cake is rendered on its own, on a see-through background, from one fixed camera:
the stand, the cake body, each finish's decorations and each topping. Parts that take the
customer's colours are rendered in white; the builder page tints them live with the chosen
base or accent colour and stacks the layers. The message is drawn by the page as text.

Run (takes a few minutes with a GPU):
    "D:\\SteamLibrary\\steamapps\\common\\Blender\\blender.exe" -b -P render_builder_assets.py
Options after "--":
    --out <folder>      where to write (default: ../invtest/assets/cake-renders)
    --samples N         Cycles samples (default 48)
    --rigs a,b          only these generic cake shapes, e.g. round-1,square-2 ("none" = skip them)
    --sizes a:b,...     exact-size versions, e.g. round-1:6x2  (used when that size is picked)
    --sizes-file F      the same, one per line (default: cake_sizes.txt next to this script,
                        made by list_cake_sizes.php from the recipes in the database)
    --only a,b          only these layers, e.g. flowers-top,flowers-top-fixed (keeps the others).
                        "--only none" renders nothing and just refreshes each shape's measurements.
    --pieces yes|only   also render the single topping pieces people drag around on the cake
                        ("only" = just those, no cake shapes)
"""
import fnmatch
import json
import math
import os
import shutil
import sys
import tempfile
import time

import bpy
import numpy as np
from mathutils import Matrix, Vector

HERE = os.path.dirname(os.path.abspath(__file__))
CAKE_NO_MAIN = True
exec(open(os.path.join(HERE, "cake_builder_blender.py"), encoding="utf-8").read())

VIEW_W, VIEW_H = 400, 360      # the builder's SVG viewBox
PX = 2                         # pixels per viewBox unit
BOTTOM_Y = 347                 # where the bottom of the stand lands
# Camera height in degrees (18 for everything else). Bento cakes are seen from higher up so the
# top, where their message goes, shows above the box wall.
ELEVATION = {"bento": 38}
# Square and rectangle cakes are turned a little so their right side shows (like the old drawing).
AZIMUTH = {"square": 24, "rectangle": 24}


def camera_axes(shape):
    """Elevation (radians) and the camera's right, up and toward-camera directions."""
    e = math.radians(ELEVATION.get(shape, 18))
    turn = Matrix.Rotation(math.radians(AZIMUTH.get(shape, 0)), 3, "Z")
    right = turn @ Vector((1, 0, 0))
    up = turn @ Vector((0, math.sin(e), math.cos(e)))
    toward = turn @ Vector((0, -math.cos(e), math.sin(e)))
    return e, right, up, toward

# key -> (shape, tiers, size label). An empty label uses the shape's default size.
RIGS = {
    "round-1": ("round", 1, ""),
    "round-2": ("round", 2, ""),
    "square-1": ("square", 1, ""),
    "square-2": ("square", 2, ""),
    "rectangle-1": ("rectangle", 1, ""),
    "rectangle-2": ("rectangle", 2, ""),
    "bento-1": ("bento", 1, ""),
    "mini-1": ("mini", 1, ""),
    "mini-2": ("mini", 2, ""),
}
FINISHES = [("buttercream", "Buttercream"), ("semi", "Semi-fondant"), ("fondant", "Fondant"),
            ("allfondant", "All Fondant"), ("icing", "Icing 2D")]
# One plain body per surface type. Before a finish is picked, the page shows the smooth body alone.
BODY_FOR = {"buttercream": "buttercream", "fondant": "fondant", "icing": "smooth"}
# Other buttercream top borders the customer can pick (the default, rosettes, is "finish-buttercream").
PIPING_STYLES = ["shell", "bead"]
# Single toppings the page lets people drag around: name -> (topping, which one, [(file suffix, pass)]).
PIECES = {
    "strawberry": ("strawberries", 1, [("", "all")]),
    "strawberry-half": ("strawberries", 2, [("", "all")]),
    "cherry": ("cherries", 1, [("", "all")]),
    "macaron": ("macarons", 1, [("", "accent"), ("-fixed", "fixed")]),
    "rose": ("flowers", 1, [("", "accent"), ("-fixed", "fixed")]),
    "candle": ("candles", 1, [("", "accent"), ("-fixed", "fixed")]),
}
# One set of pieces per camera angle: view -> the shape whose camera it uses.
PIECE_VIEWS = {"round": "round", "box": "square", "bento": "bento"}
ACCENT_MATS = {"Piping", "PipingDark", "Ribbon", "MacaronShell", "MacaronFeet"}
BASE = {"body", "drip", "stand", "box_back", "box_front"}


# ---------------------------------------------------------------------------
def layer_of(ob):
    name = ob.name[len(PREFIX):] if ob.name.startswith(PREFIX) else ob.name
    name = name.split(".")[0]
    if name == "Stand":
        return "stand"
    if name in ("BentoFront", "BentoSticker"):
        return "box_front"
    if name.startswith("Bento"):
        return "box_back"
    if name.startswith("Tier"):
        return "body"
    if name.startswith("Finish") or name.startswith("Ribbon"):
        return "finish"
    if name in ("DripTop", "Drips"):
        return "drip"
    if name == "SideFlowers":
        return "flowers-side"
    if name.startswith("Top_"):
        return "top-" + name[4:]
    if name == "FlameLight":
        return "flame"
    return {"Pearls": "pearls", "Sprinkles": "sprinkles", "GoldLeaf": "gold", "Backdrop": "backdrop"}.get(name, "other")


def _new_material(name):
    m = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    if bpy.app.version < (5, 0, 0):
        m.use_nodes = True
    m.node_tree.nodes.clear()
    return m


def holdout_material():
    m = _new_material("HO_Holdout")
    N, L = m.node_tree.nodes, m.node_tree.links
    out = N.new("ShaderNodeOutputMaterial")
    L.new(N.new("ShaderNodeHoldout").outputs[0], out.inputs["Surface"])
    return m


def candle_material(stripes_visible):
    """Candle wax and stripes split into two passes with a holdout mask."""
    m = _new_material("HO_Candle" + ("Stripes" if stripes_visible else "Wax"))
    N, L = m.node_tree.nodes, m.node_tree.links
    out = N.new("ShaderNodeOutputMaterial")
    tc = N.new("ShaderNodeTexCoord")
    wave = N.new("ShaderNodeTexWave")
    wave.wave_type = "BANDS"
    wave.bands_direction = "DIAGONAL"
    wave.inputs["Scale"].default_value = 55.0
    L.new(tc.outputs["Object"], wave.inputs["Vector"])
    ramp = N.new("ShaderNodeValToRGB")
    ramp.color_ramp.interpolation = "CONSTANT"
    ramp.color_ramp.elements[0].color = (0, 0, 0, 1)
    ramp.color_ramp.elements[1].position = 0.6
    ramp.color_ramp.elements[1].color = (1, 1, 1, 1)
    L.new(wave.outputs["Fac"], ramp.inputs["Fac"])
    wax = N.new("ShaderNodeBsdfPrincipled")
    wax.inputs["Base Color"].default_value = (1, 1, 1, 1)
    wax.inputs["Roughness"].default_value = 0.35
    hold = N.new("ShaderNodeHoldout")
    mixer = N.new("ShaderNodeMixShader")
    L.new(ramp.outputs["Color"], mixer.inputs["Fac"])
    first, second = (hold, wax) if stripes_visible else (wax, hold)
    L.new(first.outputs[0], mixer.inputs[1])
    L.new(second.outputs[0], mixer.inputs[2])
    L.new(mixer.outputs[0], out.inputs["Surface"])
    return m


def white_material():
    """Glossy white, for parts the page tints with a colour of the customer's choice (the drip)."""
    m = _new_material("HO_White")
    N, L = m.node_tree.nodes, m.node_tree.links
    out = N.new("ShaderNodeOutputMaterial")
    bsdf = N.new("ShaderNodeBsdfPrincipled")
    bsdf.inputs["Base Color"].default_value = (1, 1, 1, 1)
    bsdf.inputs["Roughness"].default_value = 0.22
    L.new(bsdf.outputs[0], out.inputs["Surface"])
    return m


def mat_class(mat):
    name = mat.name[len(PREFIX):].split(".")[0] if mat and mat.name.startswith(PREFIX) else ""
    if name == "Candle":
        return "candle"
    return "accent" if name in ACCENT_MATS else "fixed"


class Passes:
    def __init__(self):
        self.hold = holdout_material()
        self.stripes = candle_material(True)
        self.wax = candle_material(False)
        self.white = white_material()
        self.saved = {}

    def remember(self, objects):
        self.saved = {ob.name: list(ob.data.materials) for ob in objects if ob.type == "MESH"}

    def apply(self, ob, mode):
        """mode: all | accent | fixed | white"""
        if ob.type != "MESH":
            return
        mats = self.saved.get(ob.name, [])
        for i, m in enumerate(mats):
            cls = mat_class(m)
            new = m
            if mode == "white":
                new = self.white
            elif mode == "accent":
                new = self.stripes if cls == "candle" else (m if cls == "accent" else self.hold)
            elif mode == "fixed":
                new = self.wax if cls == "candle" else (self.hold if cls == "accent" else m)
            ob.data.materials[i] = new


# ---------------------------------------------------------------------------
def order_for(shape, tiers, size, finish, toppings, samples, **extra):
    return {
        **extra,
        "recipe_name": "", "shape": shape, "tiers": tiers, "size": size, "finish": finish,
        "base_color": "#FFFFFF", "accent_color": "#FFFFFF",   # white = tinted later in the browser
        "toppings": toppings, "message": "", "_no_limit": True, "_fixed_slots": True,
        "render": {"output": "", "stand": "white", "stand_height": 1.6, "hide_other_objects": True,
                   "engine": "CYCLES", "samples": samples, "resolution": [VIEW_W * PX, VIEW_H * PX],
                   "exposure": EXPOSURE},
    }


def screen_points(objects, right_vec, up_vec):
    """All mesh vertices of these objects, as (x, height-on-screen) pairs."""
    pts = []
    dg = bpy.context.evaluated_depsgraph_get()
    for ob in objects:
        if ob.type != "MESH":
            continue
        me = ob.evaluated_get(dg).to_mesh()
        co = np.empty(len(me.vertices) * 3, dtype=np.float32)
        me.vertices.foreach_get("co", co)
        ob.evaluated_get(dg).to_mesh_clear()
        co = co.reshape(-1, 3)
        mw = np.array(ob.matrix_world)
        co = co @ mw[:3, :3].T + mw[:3, 3]
        pts.append(np.stack([co @ np.array(right_vec), co @ np.array(up_vec)], axis=1))
    return np.concatenate(pts) if pts else np.zeros((0, 2))


class Frame:
    """Maps world points to viewBox units and sets up a matching orthographic camera."""

    def __init__(self, objects, cake_width, shape):
        self.elev, self.right, self.up, self.toward = camera_axes(shape)
        pts = screen_points(objects, self.right, self.up)
        minx, maxx = float(pts[:, 0].min()), float(pts[:, 0].max())
        miny, maxy = float(pts[:, 1].min()), float(pts[:, 1].max())
        target_px = min(300.0, 90 + 18 * cake_width / IN)
        self.unit = max(cake_width / target_px, (maxx - minx) / (VIEW_W - 24), (maxy - miny) / (BOTTOM_Y - 14))
        self.cx = (minx + maxx) / 2
        self.miny = miny

    def project(self, p):
        p = Vector(p)
        return (round(VIEW_W / 2 + (p.dot(self.right) - self.cx) / self.unit, 2),
                round(BOTTOM_Y - (p.dot(self.up) - self.miny) / self.unit, 2))

    def apply(self, cam):
        cam.data.type = "ORTHO"
        cam.data.ortho_scale = VIEW_W * self.unit
        cam.data.shift_x = cam.data.shift_y = 0
        mid = self.miny + (BOTTOM_Y - VIEW_H / 2) * self.unit
        target = self.right * self.cx + self.up * mid
        cam.location = target + self.toward * 3.0
        cam.rotation_euler = (-self.toward).to_track_quat("-Z", "Y").to_euler()
        cam.data.clip_start = 0.01
        cam.data.clip_end = 10.0


FILM = 16 / 255   # shadow-catcher haze below this alpha is dropped; real contact shadows are much darker
SHADOW = 0.6      # topping shadows are softened, so several toppings together don't darken the cake
EXPOSURE = -0.6   # white parts must keep their shading (not clip to flat white) so the tint looks 3D


def clean_shadow_film(px):
    """Removes the faint grey haze the shadow catcher leaves over the whole cake, keeping real shadows."""
    a = px[:, :, 3]
    shadow = (px[:, :, :3].max(axis=2) < 30 / 255) & (a < 0.8)
    a[shadow] = np.clip((a[shadow] - FILM) / (1 - FILM), 0, 1) * SHADOW


def save_cropped(png, out_path):
    """Crops a render to its visible pixels and saves it as WebP. Returns the box in viewBox units."""
    img = bpy.data.images.load(png, check_existing=False)
    w, h = img.size
    px = np.empty(w * h * 4, dtype=np.float32)
    img.pixels.foreach_get(px)
    bpy.data.images.remove(img)
    px = px.reshape(h, w, 4)              # rows go bottom to top
    clean_shadow_film(px)
    ys, xs = np.nonzero(px[:, :, 3] > 1.5 / 255)
    if len(xs) == 0:
        return None
    x0, x1 = max(0, xs.min() - 2), min(w, xs.max() + 3)
    y0, y1 = max(0, ys.min() - 2), min(h, ys.max() + 3)
    crop = np.ascontiguousarray(px[y0:y1, x0:x1])
    out = bpy.data.images.new("crop", x1 - x0, y1 - y0, alpha=True)
    out.pixels.foreach_set(crop.ravel())
    out.filepath_raw = out_path
    out.file_format = "WEBP"
    try:
        out.save(quality=90)
    except TypeError:
        out.save()
    bpy.data.images.remove(out)
    top = h - y1
    return [round(x0 / PX, 2), round(top / PX, 2), round((x1 - x0) / PX, 2), round((y1 - y0) / PX, 2)]


def render_job(scene, objects, passes, show, mode, base_mode, png):
    for ob in objects:
        key = layer_of(ob)
        if ob.type == "LIGHT":
            if key == "flame":
                ob.hide_render = "top-candles" not in show
            continue
        if ob.type != "MESH":
            continue
        ob.is_holdout = False
        ob.is_shadow_catcher = False
        passes.apply(ob, "all")
        # "body-top" = every tier above the bottom one (so the page can colour the tiers separately).
        upper_tier = key == "body" and not ob.name.split(".")[0].endswith("Tier1")
        # "finish-t1" / "finish-t2" = the decorations of the bottom tier / of the tiers above it.
        tier_finish = ("finish-t1" if ob.name.split(".")[0].endswith("1") else "finish-t2") if key == "finish" else ""
        if key in show or (upper_tier and "body-top" in show) or tier_finish in show:
            ob.hide_render = False
            passes.apply(ob, mode)
        elif key in BASE and base_mode:
            ob.hide_render = False
            if base_mode == "holdout":
                ob.is_holdout = True
            else:
                ob.is_shadow_catcher = True
        else:
            ob.hide_render = True
    scene.render.filepath = png
    bpy.ops.render.render(write_still=True)


def message_spots(frame, top, lift, on_top=False):
    """Where the page should write the message on the top tier, with and without a chocolate drip."""
    spots = {}
    if on_top:
        # Flat on the top surface, a little toward the front. The page squashes the text to match.
        for key, extra in (("plain", 0.0), ("drip", 0.1 * IN)):
            z = top.zt + extra
            half = (top.w / 2 if top.box else top.r) * 0.72
            y = -(top.d / 2 if top.box else top.r) * 0.12
            pts = [(-half, y, z), (half, y, z)]
            spots[key] = {"path": [frame.project(p) for p in pts],
                          "height": round(0.75 * IN * top.scale / frame.unit, 2),
                          "room": round(2 * half / frame.unit * 0.92, 2),
                          "squash": round(math.sin(frame.elev), 3)}
        return spots
    for key, k, size_k in (("plain", 0.42, 0.32), ("drip", 0.3, 0.26)):
        z = top.zb + top.h * k
        if top.box:
            pts = [(-top.w / 2 * 0.9, -top.d / 2, z), (top.w / 2 * 0.9, -top.d / 2, z)]
        else:
            r = top.r + 0.002
            pts = [(r * math.cos(math.radians(a)), r * math.sin(math.radians(a)), z) for a in range(-160, -19, 10)]
        h_px = top.h / frame.unit
        spots[key] = {"path": [frame.project(p) for p in pts], "height": round(h_px * size_k, 2),
                      "room": round(top.front / frame.unit * 0.8, 2)}
    return spots


def top_surface(frame, top):
    """The top of the cake in viewBox units, so the page can place toppings on it.
    A spot (u, v), each from -1 to 1 (u = left to right, v = front to back), is drawn at c + u*ex + v*ey."""
    half_w = top.w / 2 if top.box else top.r
    half_d = top.d / 2 if top.box else top.r
    c = frame.project((0, 0, top.zt))
    ex = frame.project((half_w, 0, top.zt))
    ey = frame.project((0, half_d, top.zt))
    return {"c": list(c), "ex": [round(ex[0] - c[0], 2), round(ex[1] - c[1], 2)],
            "ey": [round(ey[0] - c[0], 2), round(ey[1] - c[1], 2)], "box": bool(top.box),
            "s": round(max(0.7, min(1.2, (top.front / IN) / 7.0)), 3), "unit": frame.unit}


def render_pieces(out_dir, samples, log):
    """Renders each topping as a single piece per camera angle. Returns the manifest's "pieces" section."""
    scene = bpy.context.scene
    tmp = tempfile.mkdtemp(prefix="cakepieces_")
    result = {}
    passes = None
    for view, shape in PIECE_VIEWS.items():
        os.makedirs(os.path.join(out_dir, "pieces", view), exist_ok=True)
        info = {"pieces": {}}
        for name, (topping, which, jobs) in PIECES.items():
            built = build_cake(order_for(shape, 1, "", "Buttercream", [topping], samples, _single_piece=(topping, which)))
            coll = built["collection"]
            objs = [o for o in coll.objects if layer_of(o) not in ("backdrop", "flame") and o.type == "MESH"]
            frame = Frame(objs, max(built["tiers"][0].w, built["tiers"][0].d), shape)
            top = built["tiers"][-1]
            scene.render.film_transparent = True
            scene.render.use_persistent_data = True
            scene.render.image_settings.file_format = "PNG"
            scene.render.image_settings.color_mode = "RGBA"
            scene.render.image_settings.color_depth = "8"
            frame.apply(built["camera"])
            objects = list(coll.objects)
            if passes is None:
                passes = Passes()
            passes.remember(objects)
            ax, ay = frame.project((0, 0, top.zt))   # where the piece stands
            info["unit"] = frame.unit
            info["s"] = round(max(0.7, min(1.2, (top.front / IN) / 7.0)), 3)
            for suffix, mode in jobs:
                t0 = time.time()
                png = os.path.join(tmp, name + suffix + ".png")
                render_job(scene, objects, passes, {"top-" + topping}, mode, "catcher", png)
                box = save_cropped(png, os.path.join(out_dir, "pieces", view, name + suffix + ".webp"))
                if box:
                    info["pieces"][name + suffix] = {
                        "file": "pieces/%s/%s%s.webp" % (view, name, suffix),
                        "box": [round(box[0] - ax, 2), round(box[1] - ay, 2), box[2], box[3]]}
                log("  %-22s %5.1fs %s" % (view + "/" + name + suffix, time.time() - t0, "" if box else "(empty)"))
                if PROGRESS:
                    PROGRESS.step("Topping pieces")
        result[view] = info
    shutil.rmtree(tmp, ignore_errors=True)
    return result


def save_rig_blend(rig_key, shape, tiers, size, blend_dir, samples, log):
    """Saves the shape as a .blend (buttercream with every topping, same camera as the images). No rendering."""
    everything = ["gold_leaf", "flowers", "macarons", "candles", "strawberries", "cherries", "pearls", "sprinkles"]
    built = build_cake(order_for(shape, tiers, size, "Buttercream", everything, samples))
    objs = [o for o in built["collection"].objects if layer_of(o) not in ("backdrop", "flame") and o.type == "MESH"]
    Frame(objs, max(built["tiers"][0].w, built["tiers"][0].d), shape).apply(built["camera"])
    bpy.context.scene.render.film_transparent = True
    path = os.path.join(blend_dir, rig_key + ".blend")
    bpy.ops.wm.save_as_mainfile(filepath=path, copy=True, compress=True)
    log("  saved %s (%.1f MB)" % (path, os.path.getsize(path) / 1048576))


class Progress:
    """Writes render_progress.json next to this script; render_status.php shows it as a progress bar."""

    def __init__(self, path):
        self.path = path
        self.rows = []
        self.started = time.time()
        self.current = ""
        self.finished = False

    def plan(self, name, expected):
        if expected:
            self.rows.append({"name": name, "done": 0, "expected": expected})

    def step(self, name, done=1):
        self.current = name
        for row in self.rows:
            if row["name"] == name:
                row["done"] = min(row["expected"], row["done"] + done)
        self.save()

    def save(self):
        data = {"started": self.started, "updated": time.time(), "current": self.current,
                "finished": self.finished, "rows": self.rows}
        try:
            with open(self.path + ".tmp", "w", encoding="utf-8") as fh:
                json.dump(data, fh)
            os.replace(self.path + ".tmp", self.path)
        except OSError:
            pass   # progress is only for show; never stop a render over it


PROGRESS = None
EVERYTHING = ["gold_leaf", "flowers", "macarons", "candles", "strawberries", "cherries"]


def layer_wanted(name, only):
    """Does this layer match --only? Names may use * as a wildcard, e.g. finish-*-t2*."""
    return any(fnmatch.fnmatchcase(name, pattern) for pattern in only)


def tier_finish_jobs(tiers, key, drip):
    """Two-tier cakes: each tier's decorations on their own, so the tiers can have different finishes.
    t1 = bottom tier (the same with or without a drip), t2 = the tier(s) above it."""
    if tiers < 2:
        return []
    jobs = [("finish-%s-t2%s" % (key, "-drip" if drip else ""), {"finish-t2"}, "accent", "catcher")]
    if not drip:
        jobs.append(("finish-%s-t1" % key, {"finish-t1"}, "accent", "catcher"))
    return jobs


def rig_builds(shape, tiers):
    """Every cake to build for one shape and the image layers to render from each:
    [(finish, toppings, [(layer, objects shown, pass, what the cake itself does), ...], extra order options)]."""
    everything = EVERYTHING
    builds = []
    for key, name in FINISHES:
        for drip in (False, True):
            jobs = [("finish-%s%s" % (key, "-drip" if drip else ""), {"finish"}, "accent", "catcher")]
            jobs += tier_finish_jobs(tiers, key, drip)
            if not drip and key in BODY_FOR:
                jobs.append(("body-" + BODY_FOR[key], {"body"}, "all", None))
                if tiers > 1:   # the upper tier on its own, for two-colour cakes
                    jobs.append(("body-%s-top" % BODY_FOR[key], {"body-top"}, "all", None))
            if not drip and key == "buttercream":
                if shape == "bento":
                    jobs += [("box-back", {"box_back"}, "all", "holdout"), ("box-front", {"box_front"}, "fixed", None),
                             ("box-front-accent", {"box_front"}, "accent", None)]
                else:
                    jobs.append(("stand", {"stand"}, "all", "holdout"))
                    # The whole stand, without the cut-out where the cake hides it. The page shows one
                    # stand per shape (the widest one) under every size, so the stand never changes size.
                    jobs.append(("stand-full", {"stand"}, "all", None))
            if drip and key == "buttercream":
                jobs.append(("drip", {"drip"}, "all", "catcher"))
                jobs.append(("drip-tint", {"drip"}, "white", "catcher"))   # white, coloured by the page
            builds.append((name, ["chocolate_drip"] if drip else [], jobs))
    for style in PIPING_STYLES:
        for drip in (False, True):
            builds.append(("Buttercream", ["chocolate_drip"] if drip else [],
                           [("finish-buttercream-%s%s" % (style, "-drip" if drip else ""), {"finish"}, "accent", "catcher")]
                           + tier_finish_jobs(tiers, "buttercream-" + style, drip),
                           {"piping": style}))
    builds += [
        ("Buttercream", ["pearls"], [("pearls", {"pearls"}, "all", "catcher")]),
        ("Icing 2D", ["pearls"], [("pearls-low", {"pearls"}, "all", "catcher")]),
        ("Buttercream", ["sprinkles"], [("sprinkles", {"sprinkles"}, "all", "catcher")]),
        ("Buttercream", ["sprinkles", "chocolate_drip"], [("sprinkles-drip", {"sprinkles"}, "all", "catcher")]),
        ("Buttercream", everything, [
            ("gold", {"gold"}, "all", "catcher"),
            ("flowers-side", {"flowers-side"}, "accent", "catcher"),
            ("flowers-side-fixed", {"flowers-side"}, "fixed", "catcher"),
            ("flowers-top", {"top-flowers"}, "accent", "catcher"),
            ("flowers-top-fixed", {"top-flowers"}, "fixed", "catcher"),
            ("macarons", {"top-macarons"}, "accent", "catcher"),
            ("macarons-fixed", {"top-macarons"}, "fixed", "catcher"),
            ("candles", {"top-candles"}, "accent", "catcher"),
            ("candles-fixed", {"top-candles"}, "fixed", "catcher"),
            ("strawberries", {"top-strawberries"}, "all", "catcher"),
            ("cherries", {"top-cherries"}, "all", "catcher"),
        ]),
    ]
    return builds


def render_rig(rig_key, shape, tiers, size, out_dir, samples, log, only=None, previous=None, progress_name=""):
    scene = bpy.context.scene
    tmp = tempfile.mkdtemp(prefix="cakeassets_")
    rig_dir = os.path.join(out_dir, rig_key)
    os.makedirs(rig_dir, exist_ok=True)
    everything = EVERYTHING
    builds = rig_builds(shape, tiers)

    # Frame the camera on the tallest version (all toppings), then keep it for every layer.
    built = build_cake(order_for(shape, tiers, size, "Buttercream", everything, samples))
    objs = [o for o in built["collection"].objects if layer_of(o) not in ("backdrop", "flame") and o.type == "MESH"]
    frame = Frame(objs, max(built["tiers"][0].w, built["tiers"][0].d), shape)
    top = built["tiers"][-1]
    lift_world = Vector((0, 0, 0.1 * IN))
    lift_px = round(frame.project((0, 0, top.zt))[1] - frame.project(Vector((0, 0, top.zt)) + lift_world)[1], 2)
    meta = {
        "shape": shape, "tiers": tiers, "size": size,
        "default_d": round(built["tiers"][0].w / IN, 3),
        "anchor": frame.project((0, 0, 0)),
        "drip_lift": lift_px,
        # Where the middle of the cake's bottom is (on top of the stand); the page lines this up with
        # the fixed stand.
        "base": frame.project((0, 0, built["stand_top"])),
        "top": top_surface(frame, top),
        "message": message_spots(frame, top, lift_px, on_top=shape == "bento"),
        "layers": dict((previous or {}).get("layers", {})) if only else {},
    }

    passes = None
    for finish, toppings, jobs, *more in builds:
        if only:
            jobs = [j for j in jobs if layer_wanted(j[0], only)]
            if not jobs:
                continue
        built = build_cake(order_for(shape, tiers, size, finish, toppings, samples, **(more[0] if more else {})))
        coll = built["collection"]
        scene.render.film_transparent = True
        scene.render.use_persistent_data = True
        scene.render.image_settings.file_format = "PNG"
        scene.render.image_settings.color_mode = "RGBA"
        scene.render.image_settings.color_depth = "8"
        frame.apply(built["camera"])
        objects = list(coll.objects)
        if passes is None:
            passes = Passes()
        passes.remember(objects)
        for name, show, mode, base_mode in jobs:
            t0 = time.time()
            png = os.path.join(tmp, name + ".png")
            render_job(scene, objects, passes, show, mode, base_mode, png)
            box = save_cropped(png, os.path.join(rig_dir, name + ".webp"))
            if box:
                meta["layers"][name] = {"file": "%s/%s.webp" % (rig_key, name), "box": box, "clean": True}
            else:
                meta["layers"].pop(name, None)
            log("  %-22s %5.1fs %s" % (name, time.time() - t0, "" if box else "(empty)"))
            if PROGRESS:
                PROGRESS.step(progress_name)
    shutil.rmtree(tmp, ignore_errors=True)
    return meta


def main_assets():
    argv = sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else []
    opts = {"out": os.path.normpath(os.path.join(HERE, "..", "invtest", "assets", "cake-renders")),
            "samples": 48, "rigs": "", "sizes": "", "sizes-file": os.path.join(HERE, "cake_sizes.txt"), "only": "",
            "pieces": ""}
    for i in range(0, len(argv) - 1, 2):
        opts[argv[i].lstrip("-")] = argv[i + 1]
    out_dir = opts["out"]
    os.makedirs(out_dir, exist_ok=True)
    manifest_path = os.path.join(out_dir, "manifest.json")
    manifest = {"view": [VIEW_W, VIEW_H], "rigs": {}}
    if os.path.exists(manifest_path):
        with open(manifest_path, encoding="utf-8") as fh:
            manifest = json.load(fh)

    rigs = dict(RIGS)
    if opts["rigs"]:
        wanted = set(opts["rigs"].split(","))
        rigs = {k: v for k, v in rigs.items() if k in wanted}
    sizes = [x.strip() for x in opts["sizes"].split(",")]
    if os.path.exists(opts["sizes-file"]):
        with open(opts["sizes-file"], encoding="utf-8") as fh:
            sizes += [line.strip() for line in fh]
    for item in filter(None, sizes):
        base, size = item.split(":", 1)
        shape, tiers, _ = RIGS[base]
        rigs["%s@%s" % (base, size)] = (shape, tiers, size)

    log_lines = []

    def log(msg):
        print(msg, flush=True)
        log_lines.append(msg)

    start = time.time()
    if opts.get("blend"):
        blend_dir = os.path.abspath(opts["blend"])
        os.makedirs(blend_dir, exist_ok=True)
        for key, (shape, tiers, size) in rigs.items():
            log("Building %s ..." % key)
            save_rig_blend(key.replace("@", "_at_").replace("/", "-"), shape, tiers, size, blend_dir,
                           int(opts["samples"]), log)
        log("Done in %.1f min. Blend files: %s" % ((time.time() - start) / 60, blend_dir))
        return
    def save_manifest():
        manifest["generated"] = time.strftime("%Y-%m-%d %H:%M:%S")
        with open(manifest_path, "w", encoding="utf-8") as fh:
            json.dump(manifest, fh, indent=1)

    global PROGRESS
    only = set(filter(None, opts["only"].split(",")))
    # Pieces are part of a full run; with --only they are skipped unless asked for.
    pieces = opts["pieces"] or ("no" if only else "yes")
    if pieces == "only":
        rigs = {}
    PROGRESS = Progress(os.path.join(HERE, "render_progress.json"))
    if pieces in ("yes", "only"):
        PROGRESS.plan("Topping pieces", len(PIECE_VIEWS) * sum(len(jobs) for _, _, jobs in PIECES.values()))
    label = lambda key: key.replace("@", " · ")
    for key, (shape, tiers, size) in rigs.items():
        names = [job[0] for build in rig_builds(shape, tiers) for job in build[2]]
        if shape != "bento":
            names = [n for n in names if not n.startswith("box-")]
        PROGRESS.plan(label(key), len([n for n in names if not only or layer_wanted(n, only)]))
    PROGRESS.save()

    if pieces in ("yes", "only"):
        log("Rendering single topping pieces ...")
        manifest["pieces"] = render_pieces(out_dir, int(opts["samples"]), log)
        save_manifest()
    for key, (shape, tiers, size) in rigs.items():
        log("Rendering %s ..." % key)
        manifest["rigs"][key] = render_rig(key.replace("@", "_at_").replace("/", "-"), shape, tiers, size,
                                           out_dir, int(opts["samples"]), log, only, manifest["rigs"].get(key),
                                           label(key))
        save_manifest()
    PROGRESS.finished = True
    PROGRESS.save()
    log("Done in %.1f min. Manifest: %s" % ((time.time() - start) / 60, manifest_path))


main_assets()
