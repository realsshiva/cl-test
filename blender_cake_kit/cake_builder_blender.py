"""
Cake Builder -> Blender renderer
================================
Builds a 3D model of a custom cake from the bakery system's Cake Builder
(invtest/cakebuilder.php) and renders it.

It understands the same choices the web builder offers:
  shape/tiers (from the recipe name), size label, finish, base + accent colour,
  up to 4 toppings, a message of up to 40 characters, and the order notes.

THREE WAYS TO RUN IT
--------------------
1) From the Claude Desktop app with the Blender MCP connector (execute_blender_code):
       ORDER_OVERRIDE = { ...order... }          # or ORDER_FILE = r"C:\\path\\order.json"
       exec(open(r"D:\\Xampp\\htdocs\\cl-test\\blender_cake_kit\\cake_builder_blender.py", encoding="utf-8").read())

2) Inside Blender: Scripting tab > open this file > edit DEFAULT_ORDER below > Run Script.

3) Headless from a terminal:
       blender -b -P cake_builder_blender.py -- order.json --render out.png --samples 64

Units: the order uses inches (like the size labels "6x4", "6x4/4x3", "12x9x2.5").
The model is built in metres (1 in = 0.0254 m). The cake's front faces -Y (towards the camera).
"""

import bpy
import bmesh
import json
import math
import os
import random
import re
import sys
from mathutils import Matrix, Vector

IN = 0.0254  # metres per inch
COLLECTION = "CakeRender"
PREFIX = "CK_"

# ---------------------------------------------------------------------------
# The order. Field names match the Cake Builder / `m3.1-custom_cake_design` table.
# ---------------------------------------------------------------------------
DEFAULT_ORDER = {
    "recipe_name": "Round 2-Tier Custom Cake",   # shape + tiers are worked out from this name
    "shape": None,            # optional override: round | square | rectangle | bento | mini
    "tiers": None,            # optional override: 1 or 2
    "size": "8x4/6x3",        # inches: diameter x height, "/" between tiers; rectangles: length x width x height
    "finish": "Buttercream",  # Buttercream | Semi-fondant | Fondant | All Fondant | Icing 2D | Plain
    "base_color": "#F9C6D0",  # frosting colour (any hex)
    "accent_color": "#D4A63A",  # piping, borders, ribbon, macarons, roses, candle stripes, message ink
    "toppings": ["chocolate_drip", "macarons", "flowers", "gold_leaf"],  # max 4, see TOPPINGS
    "message": "Happy Birthday, Mia!",  # max 40 characters
    "customer": "",
    "pickup_date": "",
    "notes": "",
    # Design extras from the web Cake Builder (all optional; left out = the classic look).
    # The production queue's "Blender order file" button writes these for a saved cake.
    "color_style": "solid",   # solid | ombre (base_color2 fades in from the top) | tiers (upper tier in base_color2)
    "base_color2": "",        # second frosting colour, for ombre and tiers
    "trim_color": "",         # piping, borders, ribbon and bow ("" = accent_color)
    "drip_color": "",         # "" = chocolate
    "stand_color": "",        # "" = the stand chosen in render.stand
    "message_color": "",      # "" = picked to read well on the cake
    "piping": "rosette",      # buttercream top border: rosette | shell | bead
    "finish_top": "",         # two-tier cakes: a different finish for the upper tier ("" = same)
    "font": "script",         # message lettering: script | pacifico | dancing | playfair | fredoka (see FONT_FILES)
    "layout": None,           # where things sit: {"items": [[topping, u, v, size], ...], "msg": [dx, dy, size]}
                              # (u, v) is a spot on the top of the cake, each -1..1: u = left to right, v = front to back
    "photo": None,            # picture printed on top: {"file": "C:/.../photo.jpg", "u": 0, "v": 0, "s": 1}
    "side_designs": [],       # pattern on the sides, per tier (bottom first): none | stripes | dots | quilted |
                              # swags | ruffles | zigzag. One entry is used for every tier.
    "side_color": "",         # colour of the side designs ("" = trim_color, or accent_color)
    "render": {
        "output": "",                 # e.g. "C:/Users/me/Desktop/cake.png" ("" = build only, no render)
        "views": ["hero"],            # hero | three_quarter | top | side   (each gets its own file)
        "engine": "CYCLES",           # CYCLES (best) | EEVEE (fast)
        "samples": 128,
        "resolution": [1600, 1600],
        "backdrop": "#EFE8E1",        # studio sweep colour
        "stand": "white",             # white | silver | gold | wood | none
        "message_placement": "front",  # front (like the web builder) | top
        "hide_other_objects": True,   # hide (not delete) everything else in the scene
        "save_blend": "",             # optional path to save a .blend file
        "color_view": "Standard",     # Standard (true-to-hex) | AgX (softer, photographic, paler colours)
        "color_look": "None",
        "exposure": 0.0,
    },
}

BASE_COLORS = {
    "#FFF4DC": "Vanilla Cream", "#FFFFFF": "Classic White", "#F9C6D0": "Blush Pink",
    "#FCD5B5": "Peach", "#FBE7A1": "Butter Yellow", "#C6EBD9": "Mint", "#C5E1F5": "Sky Blue",
    "#D9C8F0": "Lavender", "#B23A48": "Red Velvet", "#7B4B36": "Chocolate",
}
ACCENT_COLORS = {
    "#E8738A": "Rose", "#D4A63A": "Gold", "#4A2C21": "Dark Chocolate", "#9B2C5C": "Berry",
    "#2A9D8F": "Teal", "#9D7BD8": "Lilac", "#F08A5D": "Coral", "#FFFFFF": "Snow White",
}
TOPPINGS = ["sprinkles", "strawberries", "cherries", "macarons", "flowers",
            "pearls", "chocolate_drip", "gold_leaf", "candles"]
SPRINKLE_COLORS = ["#FF6B8B", "#FFD166", "#06D6A0", "#4CC9F0", "#B388EB", "#FFFFFF"]
MAX_TOPPINGS = 4
MESSAGE_MAX = 40


# ---------------------------------------------------------------------------
# Colour helpers (same maths as cakebuilder.js)
# ---------------------------------------------------------------------------
def hex_rgb(h):
    h = h.strip().lstrip("#")
    return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))


def mix(h, target, amount):
    a, b = hex_rgb(h), hex_rgb(target)
    return "#%02X%02X%02X" % tuple(round((x + (y - x) * amount) * 255) for x, y in zip(a, b))


def _lin(c):
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def luminance(h):
    r, g, b = (_lin(c) for c in hex_rgb(h))
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def contrast(a, b):
    la, lb = luminance(a), luminance(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


def rgba(h):
    return tuple(_lin(c) for c in hex_rgb(h)) + (1.0,)


def palette(base, accent):
    base_light = mix(base, "#FFFFFF", 0.35)
    accent_dark = mix(accent, "#000000", 0.2)
    ink = accent_dark
    if contrast(ink, base_light) < 2.2:
        ink = mix(base, "#000000", 0.65) if luminance(base_light) > 0.4 else "#FFFFFF"
    return {
        "base": base, "base_light": base_light, "base_dark": mix(base, "#000000", 0.14),
        "accent": accent, "accent_dark": accent_dark, "ink": ink,
    }


# ---------------------------------------------------------------------------
# Materials
# ---------------------------------------------------------------------------
def _set(node, name, value):
    if name in node.inputs:
        try:
            node.inputs[name].default_value = value
        except (TypeError, ValueError):
            pass


def material(name, color, rough=0.5, metal=0.0, coat=0.0, sss=0.0, sheen=0.0,
             spec=0.5, emission=None, emit=0.0, bump=None, bump_strength=0.2):
    m = bpy.data.materials.new(PREFIX + name)
    if bpy.app.version < (5, 0, 0):
        m.use_nodes = True
    nt = m.node_tree
    b = nt.nodes.get("Principled BSDF")
    _set(b, "Base Color", rgba(color))
    _set(b, "Roughness", rough)
    _set(b, "Metallic", metal)
    _set(b, "Specular IOR Level", spec)
    _set(b, "Coat Weight", coat)
    _set(b, "Coat Roughness", 0.08)
    _set(b, "Sheen Weight", sheen)
    _set(b, "Sheen Roughness", 0.4)
    if sss:
        _set(b, "Subsurface Weight", sss)
        _set(b, "Subsurface Radius", (1.0, 0.55, 0.35))
        _set(b, "Subsurface Scale", 0.004)
    if emission:
        _set(b, "Emission Color", rgba(emission))
        _set(b, "Emission Strength", emit)
    m.diffuse_color = rgba(color)
    if bump:
        _add_bump(m, bump, bump_strength)
    return m


def _add_bump(m, kind, strength):
    nt = m.node_tree
    N, L = nt.nodes, nt.links
    b = N.get("Principled BSDF")
    tc = N.new("ShaderNodeTexCoord")
    bump = N.new("ShaderNodeBump")
    _set(bump, "Strength", strength)
    _set(bump, "Distance", 0.002)
    noise = N.new("ShaderNodeTexNoise")
    L.new(tc.outputs["Object"], noise.inputs["Vector"])
    if kind == "buttercream":
        # Horizontal spatula ridges + fine grain.
        wave = N.new("ShaderNodeTexWave")
        wave.wave_type = "BANDS"
        wave.bands_direction = "Z"
        _set(wave, "Scale", 9.0)
        _set(wave, "Distortion", 4.0)
        _set(wave, "Detail", 3.0)
        L.new(tc.outputs["Object"], wave.inputs["Vector"])
        _set(noise, "Scale", 120.0)
        _set(noise, "Detail", 6.0)
        add = N.new("ShaderNodeMath")
        add.operation = "MULTIPLY_ADD"
        L.new(noise.outputs["Fac"], add.inputs[0])
        add.inputs[1].default_value = 0.6
        L.new(wave.outputs["Fac"], add.inputs[2])
        L.new(add.outputs[0], bump.inputs["Height"])
    elif kind == "seeds":
        vor = N.new("ShaderNodeTexVoronoi")
        _set(vor, "Scale", 420.0)
        L.new(tc.outputs["Object"], vor.inputs["Vector"])
        L.new(vor.outputs["Distance"], bump.inputs["Height"])
    else:  # fondant / kraft / macaron / generic grain
        _set(noise, "Scale", {"fondant": 40.0, "kraft": 400.0, "macaron": 300.0}.get(kind, 150.0))
        _set(noise, "Detail", 4.0)
        L.new(noise.outputs["Fac"], bump.inputs["Height"])
    L.new(bump.outputs["Normal"], b.inputs["Normal"])


def striped_material(name, base_hex, stripe_hex, scale=55.0):
    """Candle wax with diagonal (spiral-looking) stripes."""
    m = material(name, base_hex, rough=0.35, sss=0.3)
    nt = m.node_tree
    N, L = nt.nodes, nt.links
    tc = N.new("ShaderNodeTexCoord")
    wave = N.new("ShaderNodeTexWave")
    wave.wave_type = "BANDS"
    wave.bands_direction = "DIAGONAL"
    _set(wave, "Scale", scale)
    L.new(tc.outputs["Object"], wave.inputs["Vector"])
    ramp = N.new("ShaderNodeValToRGB")
    ramp.color_ramp.interpolation = "CONSTANT"
    ramp.color_ramp.elements[0].color = rgba(base_hex)
    ramp.color_ramp.elements[1].position = 0.6
    ramp.color_ramp.elements[1].color = rgba(stripe_hex)
    L.new(wave.outputs["Fac"], ramp.inputs["Fac"])
    L.new(ramp.outputs["Color"], N.get("Principled BSDF").inputs["Base Color"])
    return m


FROSTING = {
    "buttercream": dict(rough=0.6, sss=0.25, bump="buttercream", bump_strength=0.18),
    "semi": dict(rough=0.42, sss=0.15, sheen=0.2, bump="fondant", bump_strength=0.04),
    "fondant": dict(rough=0.38, sss=0.1, sheen=0.35, bump="fondant", bump_strength=0.03),
    "icing2d": dict(rough=0.45, sss=0.15, bump="fondant", bump_strength=0.05),
    "plain": dict(rough=0.5, sss=0.2, bump="fondant", bump_strength=0.06),
}


def is_hex(value):
    return bool(re.fullmatch(r"#[0-9A-Fa-f]{6}", value or ""))


def apply_ombre(mat, base_hex, top_hex, z_bottom, z_top):
    """Fades a frosting material from base_hex at the bottom of the cake to top_hex at the top."""
    N, L = mat.node_tree.nodes, mat.node_tree.links
    bsdf = N.get("Principled BSDF")
    geo = N.new("ShaderNodeNewGeometry")
    sep = N.new("ShaderNodeSeparateXYZ")
    L.new(geo.outputs["Position"], sep.inputs[0])
    rng = N.new("ShaderNodeMapRange")
    rng.inputs["From Min"].default_value = z_bottom
    rng.inputs["From Max"].default_value = z_top
    L.new(sep.outputs["Z"], rng.inputs["Value"])
    ramp = N.new("ShaderNodeValToRGB")
    ramp.color_ramp.elements[0].position = 0.12
    ramp.color_ramp.elements[0].color = rgba(base_hex)
    ramp.color_ramp.elements[1].position = 0.88
    ramp.color_ramp.elements[1].color = rgba(top_hex)
    L.new(rng.outputs[0], ramp.inputs["Fac"])
    L.new(ramp.outputs["Color"], bsdf.inputs["Base Color"])


def build_materials(order, pal, finish):
    acc = pal["accent"]
    frosting = FROSTING[finish]
    # Design extras: each falls back to the classic choice when it isn't given.
    trim = order.get("trim_color") if is_hex(order.get("trim_color")) else acc
    drip_hex = order.get("drip_color") if is_hex(order.get("drip_color")) else "#2A160D"
    base2 = order.get("base_color2") if is_hex(order.get("base_color2")) else ""
    two_tone = order.get("color_style") == "tiers" and base2
    ink = pal["ink"]
    if two_tone:   # the message sits on the upper tier, so it must read well on that colour
        ink = palette(base2, acc)["ink"]
    if is_hex(order.get("message_color")):
        ink = order["message_color"]
    finish_top = order.get("_finish_top") or finish
    stand = order["render"].get("stand", "white")
    stand_mat = {
        "white": dict(color="#F6F5F2", rough=0.18, coat=0.6),
        "silver": dict(color="#E4E7EC", rough=0.3, metal=1.0),
        "gold": dict(color="#E6C27A", rough=0.3, metal=1.0),
        "wood": dict(color="#A9744F", rough=0.55, bump="kraft", bump_strength=0.1),
    }.get(stand, dict(color="#F6F5F2", rough=0.18, coat=0.6))
    if is_hex(order.get("stand_color")):
        stand_mat = dict(stand_mat, color=order["stand_color"])
    M = {
        "frosting": material("Frosting", pal["base"], **frosting),
        "piping": material("Piping", trim, rough=0.5, sss=0.2),
        "side": material("SideDeco", order.get("side_color") if is_hex(order.get("side_color")) else trim,
                         rough=0.42, sss=0.15, sheen=0.2),
        "piping_dark": material("PipingDark", mix(trim, "#000000", 0.2), rough=0.45, sss=0.15),
        "ribbon": material("Ribbon", trim, rough=0.32, sheen=0.6, coat=0.2),
        "ink": material("MessageInk", ink, rough=0.35, coat=0.3),
        "chocolate": material("Ganache", drip_hex, rough=0.16, coat=0.45, spec=0.5),
        "gold": material("GoldLeaf", "#F2C14E", rough=0.22, metal=1.0, bump="fondant", bump_strength=0.4),
        "pearl": material("Pearl", "#F5F1EA", rough=0.15, metal=0.35, coat=0.8),
        "strawberry": material("Strawberry", "#C8102E", rough=0.3, coat=0.5, sss=0.2, bump="seeds", bump_strength=0.08),
        "berry_inside": material("StrawberryInside", "#F4A3A8", rough=0.4, sss=0.4),
        "leaf": material("Leaf", "#3E8E41", rough=0.45, sss=0.2),
        "cherry": material("Cherry", "#7A0A14", rough=0.08, coat=1.0, sss=0.1),
        "stem": material("Stem", "#5A6B2A", rough=0.6),
        "macaron": material("MacaronShell", acc, rough=0.55, bump="macaron", bump_strength=0.15),
        "macaron_feet": material("MacaronFeet", mix(acc, "#FFFFFF", 0.15), rough=0.85, bump="macaron", bump_strength=0.6),
        "cream": material("Cream", "#FFF6E6", rough=0.6, sss=0.3),
        "candle": striped_material("Candle", "#FFFFFF", acc),
        "wick": material("Wick", "#2B2B2B", rough=0.8),
        "flame": material("Flame", "#FFB347", rough=0.5, emission="#FFB347", emit=12.0),
        "blossom_center": material("BlossomCenter", "#F6C445", rough=0.5),
        "stand": material("Stand", **stand_mat),
        "backdrop": material("Backdrop", order["render"].get("backdrop", "#EFE8E1"), rough=0.9),
        "kraft": material("Kraft", "#C99E68", rough=0.85, bump="kraft", bump_strength=0.2),
        "sticker": material("Sticker", "#FFFFFF", rough=0.5),
    }
    M["sprinkles"] = [material("Sprinkle%d" % i, c, rough=0.35, coat=0.5) for i, c in enumerate(SPRINKLE_COLORS)]
    # The upper tier(s) get their own frosting when they have another colour or another finish.
    M["frosting_top"] = M["frosting"]
    if two_tone or finish_top != finish:
        M["frosting_top"] = material("FrostingTop", base2 if two_tone else pal["base"], **FROSTING[finish_top])
    return M


# ---------------------------------------------------------------------------
# Mesh templates (built in inches, placed with a matrix) and a mesh builder
# ---------------------------------------------------------------------------
def T_from_bm(bm):
    bm.verts.index_update()
    return {
        "v": [v.co.copy() for v in bm.verts],
        "f": [[v.index for v in f.verts] for f in bm.faces],
        "m": [f.material_index for f in bm.faces],
    }


def t_sphere(seg=16, ring=8):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=seg, v_segments=ring, radius=1.0)
    t = T_from_bm(bm)
    bm.free()
    return t


def t_cylinder(seg=24, r1=1.0, r2=1.0, depth=1.0):
    bm = bmesh.new()
    bmesh.ops.create_cone(bm, cap_ends=True, cap_tris=False, segments=seg,
                          radius1=r1, radius2=r2, depth=depth)
    t = T_from_bm(bm)
    bm.free()
    return t


def t_loft(profile, layers=14, points=7, inner=0.8, twist=0.0, axis="Z"):
    """A piping-bag shape: a star cross-section swept along an axis, radius from profile(t)."""
    bm = bmesh.new()
    rings = []
    n = points * 2
    for j in range(layers + 1):
        t = j / layers
        r = profile(t)
        ring = []
        for k in range(n):
            a = twist * t + k * math.pi / points
            rr = r * (1.0 if k % 2 == 0 else inner)
            u, v = rr * math.cos(a), rr * math.sin(a)
            ring.append(bm.verts.new((u, v, t) if axis == "Z" else (t, u, v)))
        rings.append(ring)
    for j in range(layers):
        for k in range(n):
            bm.faces.new((rings[j][k], rings[j][(k + 1) % n], rings[j + 1][(k + 1) % n], rings[j + 1][k]))
    if profile(0.0) > 1e-4:
        bm.faces.new(list(reversed(rings[0])))
    if profile(1.0) > 1e-4:
        bm.faces.new(rings[-1])
    bmesh.ops.remove_doubles(bm, verts=bm.verts[:], dist=1e-5)
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    t = T_from_bm(bm)
    bm.free()
    return t


def t_tube(path, ra, rb=None, segs=10, closed=False, side_hint=None, radius_fn=None, caps=True):
    """Tube along a polyline. ra = radius along the side direction, rb = along the other axis."""
    rb = ra if rb is None else rb
    path = [Vector(p) for p in path]
    n = len(path)
    bm = bmesh.new()
    rings = []
    for i, p in enumerate(path):
        if closed:
            tang = path[(i + 1) % n] - path[i - 1]
        else:
            tang = path[min(i + 1, n - 1)] - path[max(i - 1, 0)]
        tang.normalize()
        if side_hint is not None:
            sh = Vector(side_hint)
            side = sh - tang * sh.dot(tang)
        else:
            side = tang.cross(Vector((0, 0, 1)))
        if side.length < 1e-6:
            side = tang.cross(Vector((1, 0, 0)))
        side.normalize()
        nrm = side.cross(tang)
        s = radius_fn(i / max(1, n - 1)) if radius_fn else 1.0
        rings.append([bm.verts.new(p + side * math.cos(a) * ra * s + nrm * math.sin(a) * rb * s)
                      for a in (2 * math.pi * k / segs for k in range(segs))])
    last = n if closed else n - 1
    for i in range(last):
        r0, r1 = rings[i], rings[(i + 1) % n]
        for k in range(segs):
            bm.faces.new((r0[k], r0[(k + 1) % segs], r1[(k + 1) % segs], r1[k]))
    if caps and not closed:
        bm.faces.new(list(reversed(rings[0])))
        bm.faces.new(rings[-1])
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    t = T_from_bm(bm)
    bm.free()
    return t


def t_flat(points2d, thickness=0.05):
    """A flat shape in the XZ plane, facing -Y, with a little thickness."""
    bm = bmesh.new()
    front = [bm.verts.new((x, -thickness / 2, z)) for x, z in points2d]
    back = [bm.verts.new((x, thickness / 2, z)) for x, z in points2d]
    bm.faces.new(front)
    bm.faces.new(list(reversed(back)))
    n = len(points2d)
    for i in range(n):
        bm.faces.new((front[i], back[i], back[(i + 1) % n], front[(i + 1) % n]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    t = T_from_bm(bm)
    bm.free()
    return t


def t_star2d():
    pts = []
    for i in range(10):
        a = math.pi / 2 + i * math.pi / 5
        r = 1.0 if i % 2 == 0 else 0.42
        pts.append((r * math.cos(a), r * math.sin(a)))
    return t_flat(pts, 0.12)


def t_heart2d():
    pts = []
    for i in range(48):
        t = 2 * math.pi * i / 48
        x = 16 * math.sin(t) ** 3
        z = 13 * math.cos(t) - 5 * math.cos(2 * t) - 2 * math.cos(3 * t) - math.cos(4 * t)
        pts.append((x / 17, z / 17))
    return t_flat(list(reversed(pts)), 0.12)


def t_merge(*parts):
    """Combine (template, matrix, material slot) parts into one template."""
    out = {"v": [], "f": [], "m": []}
    for t, mat, slot in parts:
        base = len(out["v"])
        out["v"] += [mat @ v for v in t["v"]]
        out["f"] += [[i + base for i in f] for f in t["f"]]
        out["m"] += [slot] * len(t["f"])
    return out


def S(x, y=None, z=None):
    """Scale matrix."""
    y = x if y is None else y
    z = x if z is None else z
    return Matrix.Diagonal((x, y, z, 1.0))


def Tr(x, y, z):
    return Matrix.Translation((x, y, z))


def R(deg, axis):
    return Matrix.Rotation(math.radians(deg), 4, axis)


def t_strawberry(half=False):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=24, v_segments=14, radius=1.0)
    for v in bm.verts:
        z = v.co.z
        k = 0.3 + 0.7 * ((1 - z) / 2) ** 0.6   # wide at the bottom, pointed tip at the top
        v.co.x *= k
        v.co.y *= k
        v.co.z = z * 1.15 + 1.15
    if half:
        bmesh.ops.bisect_plane(bm, geom=bm.verts[:] + bm.edges[:] + bm.faces[:], dist=1e-5,
                               plane_co=(0, 0, 0), plane_no=(0, -1, 0), clear_outer=True)
        # Close the cut with one face (the pale inside of the berry).
        rim = [v for v in bm.verts if abs(v.co.y) < 1e-4]
        cx = sum(v.co.x for v in rim) / len(rim)
        cz = sum(v.co.z for v in rim) / len(rim)
        rim.sort(key=lambda v: math.atan2(v.co.z - cz, v.co.x - cx))
        centre = bm.verts.new((cx, 0, cz))
        for i in range(len(rim)):
            f = bm.faces.new((centre, rim[i], rim[(i + 1) % len(rim)]))
            f.material_index = 2
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    body = T_from_bm(bm)
    bm.free()
    leaves = []
    for i in range(6):
        a = i * 60 + (0 if not half else 30)
        if half and math.sin(math.radians(a)) < -0.2:
            continue
        leaf = t_flat([(0, 0), (0.25, 0.35), (0, 0.85), (-0.25, 0.35)], 0.04)
        leaves.append((leaf, R(a, "Z") @ Tr(0, 0, 0.12) @ R(-75, "X") @ S(0.9), 1))
    return t_merge((body, Matrix(), 0), *leaves) if leaves else body


def t_cherry():
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=20, v_segments=12, radius=1.0)
    for v in bm.verts:
        if v.co.z > 0.8:
            v.co.z -= (v.co.z - 0.8) * 1.2   # dimple at the stem
        v.co.z = v.co.z * 0.92 + 0.92
    body = T_from_bm(bm)
    bm.free()
    stem = t_tube([(0, 0, 1.55), (0.1, 0, 2.2), (0.35, 0, 2.8), (0.7, 0, 3.2)], 0.06, segs=6)
    return t_merge((body, Matrix(), 0), (stem, Matrix(), 1))


def _dome(height):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=28, v_segments=14, radius=1.0)
    bmesh.ops.bisect_plane(bm, geom=bm.verts[:] + bm.edges[:] + bm.faces[:], dist=1e-5,
                           plane_co=(0, 0, 0), plane_no=(0, 0, -1), clear_outer=True)
    bmesh.ops.holes_fill(bm, edges=[e for e in bm.edges if e.is_boundary], sides=0)
    for v in bm.verts:
        v.co.z *= height
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    t = T_from_bm(bm)
    bm.free()
    return t


def t_macaron():
    """Unit macaron, radius 1, standing on its flat bottom (z = 0)."""
    dome = _dome(0.5)
    bm = bmesh.new()
    bmesh.ops.create_cone(bm, cap_ends=True, cap_tris=False, segments=40, radius1=1.0, radius2=1.0, depth=0.16)
    rnd = random.Random(4)
    for v in bm.verts:
        r = math.hypot(v.co.x, v.co.y)
        if r > 0.5:
            k = 1.0 + rnd.uniform(-0.03, 0.05)
            v.co.x *= k
            v.co.y *= k
    feet = T_from_bm(bm)
    bm.free()
    filling = t_cylinder(32, 0.86, 0.86, 0.32)
    return t_merge(
        (dome, Tr(0, 0, 0.5) @ R(180, "X"), 0),
        (feet, Tr(0, 0, 0.58), 1),
        (filling, Tr(0, 0, 0.82), 2),
        (feet, Tr(0, 0, 1.06), 1),
        (dome, Tr(0, 0, 1.14), 0),
    )


def t_rose():
    """Piped buttercream rose (~2 units wide) sitting on z = 0: a ribbon wound in a spiral."""
    bm = bmesh.new()
    n, turns = 220, 4.2
    outer, inner = [], []
    for i in range(n + 1):
        f = i / n
        th = f * turns * 2 * math.pi
        petal = abs(math.sin(th * 1.25))           # each turn splits into petals
        wob = 1 + 0.16 * petal
        r_top = (0.05 + 0.85 * f ** 0.8) * wob
        z_top = (1.05 - 0.45 * f) + 0.12 * petal * f
        r_bot = 0.04 + 0.6 * f
        c, si = math.cos(th), math.sin(th)
        col_o, col_i = [], []
        for k, (rr, zz) in enumerate(((r_bot, 0.08), ((r_bot + r_top) / 2 + 0.05 * f, (0.08 + z_top) / 2), (r_top, z_top))):
            col_o.append(bm.verts.new((rr * c, rr * si, zz)))
            ri = max(0.0, rr - 0.045)
            col_i.append(bm.verts.new((ri * c, ri * si, zz - (0.03 if k == 2 else 0))))
        outer.append(col_o)
        inner.append(col_i)
    for i in range(n):
        for k in range(2):
            bm.faces.new((outer[i][k], outer[i + 1][k], outer[i + 1][k + 1], outer[i][k + 1]))
            bm.faces.new((inner[i][k + 1], inner[i + 1][k + 1], inner[i + 1][k], inner[i][k]))
        bm.faces.new((outer[i][2], outer[i + 1][2], inner[i + 1][2], inner[i][2]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    body = T_from_bm(bm)
    bm.free()
    leaf = t_flat([(0, 0), (0.35, 0.3), (0, 1.0), (-0.35, 0.3)], 0.05)
    return t_merge((body, Matrix(), 0), (t_sphere(16, 8), Tr(0, 0, 0.12) @ S(0.62, 0.62, 0.2), 0),
                   (leaf, R(-30, "Z") @ Tr(0, -0.75, 0.12) @ R(-80, "X") @ S(0.9), 1),
                   (leaf, R(150, "Z") @ Tr(0, -0.75, 0.12) @ R(-80, "X") @ S(0.8), 1))


def t_blossom():
    """Five-petal flower in the XZ plane facing -Y (for the side of the cake)."""
    sph = t_sphere(12, 6)
    parts = []
    for i in range(5):
        a = 90 + i * 72
        parts.append((sph, R(a, "Y") @ Tr(0.45, 0, 0) @ S(0.48, 0.12, 0.3), 0))
    parts.append((sph, Tr(0, -0.1, 0) @ S(0.2, 0.12, 0.2), 1))
    return t_merge(*parts)


def t_leaf():
    return t_flat([(0, 0), (0.3, 0.25), (0.45, 0.6), (0, 1.0), (-0.45, 0.6), (-0.3, 0.25)], 0.06)


def t_candle():
    """Candle in inches: 0.24 in wide, 2.5 in tall, flame on top. Slots: wax, wick, flame."""
    wax = t_cylinder(20, 0.12, 0.12, 2.5)
    wick = t_tube([(0, 0, 2.5), (0, 0, 2.62), (0.01, 0, 2.7)], 0.018, segs=6)
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=14, v_segments=10, radius=1.0)
    for v in bm.verts:
        if v.co.z > 0:
            k = (1 - v.co.z) ** 1.3
            v.co.x *= k
            v.co.y *= k
            v.co.z *= 2.2
    flame = T_from_bm(bm)
    bm.free()
    return t_merge((wax, Tr(0, 0, 1.25), 0), (wick, Matrix(), 1), (flame, Tr(0.01, 0, 2.8) @ S(0.075), 2))


def t_gold_flake(rnd):
    n = rnd.randint(5, 8)
    pts = []
    for i in range(n):
        a = 2 * math.pi * i / n + rnd.uniform(-0.25, 0.25)
        r = rnd.uniform(0.55, 1.0)
        pts.append((r * math.cos(a), r * math.sin(a)))
    return t_flat(pts, 0.03)


def t_bow():
    """Fondant bow facing -Y: two wings, two tails and a knot. Slots: ribbon, knot."""
    parts = []
    for side in (-1, 1):
        wing = [Vector((side * (0.6 - 0.6 * math.cos(a)), -0.14 * math.sin(a) - 0.02, 0))
                for a in (2 * math.pi * i / 32 for i in range(32))]
        parts.append((t_tube(wing, 0.26, 0.035, segs=12, closed=True, side_hint=(0, 0, 1),
                             radius_fn=None), R(-side * 14, "Y"), 0))
        tail = [(side * 0.05, -0.1, -0.05), (side * 0.22, -0.12, -0.3), (side * 0.36, -0.1, -0.6)]
        parts.append((t_tube(tail, 0.17, 0.03, segs=10, side_hint=(1, 0, 0)), Matrix(), 0))
    parts.append((t_sphere(14, 8), Tr(0, -0.12, 0) @ S(0.2, 0.15, 0.26), 1))
    return t_merge(*parts)


class Builder:
    """Collects many small parts into one mesh object (fast even with hundreds of sprinkles)."""

    def __init__(self, name):
        self.name = name
        self.bm = bmesh.new()
        self.mats = []

    def slot(self, mat):
        if mat not in self.mats:
            self.mats.append(mat)
        return self.mats.index(mat)

    def add(self, t, matrix, mats):
        if not isinstance(mats, (list, tuple)):
            mats = [mats]
        slots = [self.slot(m) for m in mats]
        verts = [self.bm.verts.new(matrix @ v) for v in t["v"]]
        for f, mi in zip(t["f"], t["m"]):
            try:
                face = self.bm.faces.new([verts[i] for i in f])
            except ValueError:
                continue
            face.material_index = slots[min(mi, len(slots) - 1)]
            face.smooth = True

    def finish(self, coll, smooth=True):
        if not self.bm.verts:
            self.bm.free()
            return None
        me = bpy.data.meshes.new(PREFIX + self.name)
        self.bm.normal_update()
        self.bm.to_mesh(me)
        self.bm.free()
        for m in self.mats:
            me.materials.append(m)
        if not smooth:
            for p in me.polygons:
                p.use_smooth = False
        ob = bpy.data.objects.new(PREFIX + self.name, me)
        coll.objects.link(ob)
        return ob


# ---------------------------------------------------------------------------
# Scene objects
# ---------------------------------------------------------------------------
def lathe(name, profile, coll, mat, segs=96):
    """Revolve a (radius, z) profile around Z. Radius 0 makes a single centre vertex."""
    bm = bmesh.new()
    rings = []
    for r, z in profile:
        if r <= 1e-9:
            rings.append([bm.verts.new((0, 0, z))])
        else:
            rings.append([bm.verts.new((r * math.cos(2 * math.pi * k / segs), r * math.sin(2 * math.pi * k / segs), z))
                          for k in range(segs)])
    for a, b in zip(rings, rings[1:]):
        if len(a) == 1 and len(b) == 1:
            continue
        if len(a) == 1:
            for k in range(segs):
                bm.faces.new((a[0], b[k], b[(k + 1) % segs]))
        elif len(b) == 1:
            for k in range(segs):
                bm.faces.new((a[k], a[(k + 1) % segs], b[0]))
        else:
            for k in range(segs):
                bm.faces.new((a[k], a[(k + 1) % segs], b[(k + 1) % segs], b[k]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    for f in bm.faces:
        f.smooth = True
    me = bpy.data.meshes.new(PREFIX + name)
    bm.to_mesh(me)
    bm.free()
    me.materials.append(mat)
    ob = bpy.data.objects.new(PREFIX + name, me)
    coll.objects.link(ob)
    return ob


def rounded_box(name, w, d, h, z0, bevel, coll, mat):
    bm = bmesh.new()
    bmesh.ops.create_cube(bm, size=1.0)
    for v in bm.verts:
        v.co.x *= w
        v.co.y *= d
        v.co.z = (v.co.z + 0.5) * h + z0
    if bevel > 0:
        kw = dict(geom=bm.edges[:], offset=bevel, segments=4, profile=0.5)
        try:
            bmesh.ops.bevel(bm, affect="EDGES", **kw)
        except TypeError:
            bmesh.ops.bevel(bm, vertex_only=False, **kw)
    for f in bm.faces:
        f.smooth = True
    me = bpy.data.meshes.new(PREFIX + name)
    bm.to_mesh(me)
    bm.free()
    me.materials.append(mat)
    ob = bpy.data.objects.new(PREFIX + name, me)
    coll.objects.link(ob)
    return ob


def arc_points(cx, cz, r, a0, a1, n):
    return [(cx + r * math.cos(math.radians(a0 + (a1 - a0) * i / n)),
             cz + r * math.sin(math.radians(a0 + (a1 - a0) * i / n))) for i in range(n + 1)]


class Tier:
    """One layer of cake. All lengths in metres."""

    def __init__(self, box, w, d, h, zb):
        self.box = box
        self.w, self.d, self.h = w, d, h       # round: w = d = diameter
        self.zb, self.zt = zb, zb + h
        self.r = w / 2
        self.front = w
        self.scale = max(0.7, min(1.2, (w / IN) / 7.0))
        self.obj = None
        self.top_lift = 0.0                    # raised by a chocolate drip cap

    # Points around the side, `offset` metres outside the surface.
    def ring(self, z, spacing, offset=0.0):
        pts = []
        if not self.box:
            r = self.r + offset
            n = max(8, round(2 * math.pi * r / spacing))
            for i in range(n):
                a = -math.pi / 2 + 2 * math.pi * i / n
                c, s = math.cos(a), math.sin(a)
                pts.append((Vector((r * c, r * s, z)), Vector((-s, c, 0)), Vector((c, s, 0))))
            return pts
        hw, hd = self.w / 2 + offset, self.d / 2 + offset
        sides = [  # start, end, outward normal
            (Vector((-hw, -hd, z)), Vector((hw, -hd, z)), Vector((0, -1, 0))),
            (Vector((hw, -hd, z)), Vector((hw, hd, z)), Vector((1, 0, 0))),
            (Vector((hw, hd, z)), Vector((-hw, hd, z)), Vector((0, 1, 0))),
            (Vector((-hw, hd, z)), Vector((-hw, -hd, z)), Vector((-1, 0, 0))),
        ]
        for a, b, n in sides:
            length = (b - a).length
            count = max(1, round(length / spacing))
            tan = (b - a).normalized()
            for i in range(count):
                pts.append((a.lerp(b, i / count), tan, n))
        return pts

    def top_ring(self, spacing, inset):
        """Points on the top surface, `inset` metres in from the edge."""
        z = self.zt + self.top_lift
        if not self.box:
            return self.ring(z, spacing, -inset)
        return self.ring(z, spacing, -inset)

    def side_random(self, rnd, zlo, zhi, offset):
        z = rnd.uniform(zlo, zhi)
        if not self.box:
            a = rnd.uniform(0, 2 * math.pi)
            c, s = math.cos(a), math.sin(a)
            return Vector(((self.r + offset) * c, (self.r + offset) * s, z)), Vector((c, s, 0))
        per = 2 * (self.w + self.d)
        u = rnd.uniform(0, per)
        hw, hd = self.w / 2 + offset, self.d / 2 + offset
        if u < self.w:
            return Vector((-hw + u, -hd, z)), Vector((0, -1, 0))
        u -= self.w
        if u < self.d:
            return Vector((hw, -hd + u, z)), Vector((1, 0, 0))
        u -= self.d
        if u < self.w:
            return Vector((hw - u, hd, z)), Vector((0, 1, 0))
        u -= self.w
        return Vector((-hw, hd - u, z)), Vector((-1, 0, 0))

    def side_front(self, xfrac, z, offset):
        """Point on the front side at x = xfrac * half-width."""
        if not self.box:
            x = max(-0.97, min(0.97, xfrac))
            a = math.atan2(-math.sqrt(1 - x * x), x)
            c, s = math.cos(a), math.sin(a)
            return Vector(((self.r + offset) * c, (self.r + offset) * s, z)), Vector((c, s, 0))
        return Vector((xfrac * self.w / 2, -self.d / 2 - offset, z)), Vector((0, -1, 0))

    def perimeter(self):
        return 2 * math.pi * self.r if not self.box else 2 * (self.w + self.d)

    def around(self, u, z, offset=0.0):
        """Point on the side at u (0..1 of the way round, starting at the front middle and going right),
        `offset` metres outside the surface. Returns (point, tangent, outward normal)."""
        u %= 1.0
        if not self.box:
            a = -math.pi / 2 + 2 * math.pi * u
            c, s = math.cos(a), math.sin(a)
            r = self.r + offset
            return Vector((r * c, r * s, z)), Vector((-s, c, 0)), Vector((c, s, 0))
        hw, hd = self.w / 2, self.d / 2
        dist = u * self.perimeter()
        for start, step, length, n in (((0, -hd), (1, 0), hw, (0, -1)), ((hw, -hd), (0, 1), self.d, (1, 0)),
                                       ((hw, hd), (-1, 0), self.w, (0, 1)), ((-hw, hd), (0, -1), self.d, (-1, 0)),
                                       ((-hw, -hd), (1, 0), hw, (0, -1))):
            if dist <= length or n == (0, -1) and start[0] < 0:
                x = start[0] + step[0] * dist + n[0] * offset
                y = start[1] + step[1] * dist + n[1] * offset
                return Vector((x, y, z)), Vector((step[0], step[1], 0)), Vector((n[0], n[1], 0))
            dist -= length

    def side_spots(self, spacing, z, offset=0.0, between=False):
        """Evenly spaced points around the side. Boxes keep them off the corners. `between` gives the
        points halfway between the usual ones (for staggered rows)."""
        if not self.box:
            n = max(6, round(self.perimeter() / spacing))
            return [self.around((i + (0.5 if between else 0.0)) / n, z, offset) for i in range(n)]
        pts = []
        hw, hd = self.w / 2 + offset, self.d / 2 + offset
        for a, b, n in ((Vector((-hw, -hd, z)), Vector((hw, -hd, z)), Vector((0, -1, 0))),
                        (Vector((hw, -hd, z)), Vector((hw, hd, z)), Vector((1, 0, 0))),
                        (Vector((hw, hd, z)), Vector((-hw, hd, z)), Vector((0, 1, 0))),
                        (Vector((-hw, hd, z)), Vector((-hw, -hd, z)), Vector((-1, 0, 0)))):
            count = max(1, round((b - a).length / spacing))
            tan = (b - a).normalized()
            spots = [(i + 1) / count for i in range(count - 1)] if between else [(i + 0.5) / count for i in range(count)]
            pts += [(a.lerp(b, f), tan, n) for f in spots]
        return pts

    def top_random(self, rnd, frac=0.85):
        z = self.zt + self.top_lift
        if not self.box:
            a = rnd.uniform(0, 2 * math.pi)
            r = math.sqrt(rnd.random()) * frac * self.r
            return Vector((r * math.cos(a), r * math.sin(a), z))
        return Vector((rnd.uniform(-1, 1) * frac * self.w / 2, rnd.uniform(-1, 1) * frac * self.d / 2, z))


def face_frame(pos, normal, up=Vector((0, 0, 1)), roll=0.0, scale=1.0):
    """Matrix that turns a template facing -Y so it faces `normal`, standing on `up`."""
    n = Vector(normal).normalized()
    y = -n
    z = up - n * up.dot(n)
    if z.length < 1e-6:
        z = Vector((0, 1, 0))
    z.normalize()
    if roll:
        z = (Matrix.Rotation(roll, 3, n) @ z).normalized()
    x = y.cross(z)
    m = Matrix((x, y, z)).transposed().to_4x4()
    return Matrix.Translation(pos) @ m @ S(scale)


def edge_frame(pos, tangent, scale=1.0):
    """Matrix for border pieces modelled along +X: X = along the edge, Z = up."""
    x = Vector(tangent).normalized()
    z = Vector((0, 0, 1))
    y = z.cross(x)
    m = Matrix((x, y, z)).transposed().to_4x4()
    return Matrix.Translation(pos) @ m @ S(scale)


def upright(pos, rot_z=0.0, scale=1.0, tilt_x=0.0):
    return Matrix.Translation(pos) @ R(rot_z, "Z") @ R(tilt_x, "X") @ S(scale)


# ---------------------------------------------------------------------------
# Order -> cake description
# ---------------------------------------------------------------------------
def shape_for(recipe_name):
    """Port of cakeShapeFor() in cake_options.php."""
    name = (recipe_name or "").lower()
    tiers = 2 if re.search(r"\b2[\s-]*tier", name) else 1
    for key in ("square", "rectangle", "bento", "mini"):
        if key in name:
            return key, tiers
    return "round", tiers


def tier_sizes(shape, tiers, label):
    """Port of tierSizes() in cakebuilder.js, kept in inches: [(width, depth, height)], bottom first."""
    defaults = {"round": (7, 4), "mini": (3.5, 3), "bento": (4, 2.2), "square": (8, 4), "rectangle": (12, 2.5)}
    dims = []
    for part in str(label or "").split("/"):
        nums = [float(n) for n in re.findall(r"\d+(?:\.\d+)?", part) if float(n) > 0]
        if len(nums) >= 3:
            dims.append([nums[0], nums[1], nums[2]])
        elif len(nums) >= 2:
            dims.append([nums[0], nums[0], nums[1]])
    if not dims:
        d, h = defaults.get(shape, defaults["round"])
        dims = [[d, d * (0.75 if shape == "rectangle" else 1), h]]
    if shape in ("round", "mini", "bento"):
        dims = [[w, w, h] for w, _, h in dims]
    dims.sort(key=lambda t: -t[0])
    while len(dims) < tiers:
        w, d, h = dims[-1]
        dims.append([w * 0.72, d * 0.72, h * 0.9])
    for i in range(1, len(dims)):
        if dims[i][0] > dims[i - 1][0] * 0.85:
            k = dims[i - 1][0] * 0.78 / dims[i][0]
            dims[i] = [dims[i][0] * k, dims[i][1] * k, dims[i][2]]
    return dims


def finish_key(finish):
    f = (finish or "").lower()
    if "butter" in f:
        return "buttercream"
    if "semi" in f:
        return "semi"
    if "fondant" in f:
        return "fondant"
    if "icing" in f or "2d" in f:
        return "icing2d"
    return "plain"


def normalize_order(order):
    o = json.loads(json.dumps(DEFAULT_ORDER))
    for k, v in (order or {}).items():
        if k == "render" and isinstance(v, dict):
            o["render"].update(v)
        else:
            o[k] = v
    shape, tiers = shape_for(o.get("recipe_name", ""))
    o["shape"] = (o.get("shape") or shape).lower()
    o["tiers"] = int(o.get("tiers") or tiers)
    o["base_color"] = (o.get("base_color") or "#FFF4DC").upper()
    o["accent_color"] = (o.get("accent_color") or "#E8738A").upper()
    tops = o.get("toppings") or []
    if isinstance(tops, str):
        tops = [t.strip() for t in tops.split(",")]
    tops = [t for t in tops if t in TOPPINGS]
    if len(tops) > MAX_TOPPINGS and not o.get("_no_limit"):
        print("Cake: more than %d toppings given, keeping the first %d" % (MAX_TOPPINGS, MAX_TOPPINGS))
        tops = tops[:MAX_TOPPINGS]
    o["toppings"] = tops
    o["message"] = (o.get("message") or "")[:MESSAGE_MAX]
    sides = o.get("side_designs") or []
    if isinstance(sides, str):
        sides = [x.strip() for x in sides.split(",")]
    o["side_designs"] = [x if x in SIDE_DESIGNS else "none" for x in sides][:2]
    return o


# ---------------------------------------------------------------------------
# Building
# ---------------------------------------------------------------------------
def reset_collection(scene, hide_others):
    old = bpy.data.collections.get(COLLECTION)
    if old:
        for ob in list(old.objects):
            bpy.data.objects.remove(ob, do_unlink=True)
        bpy.data.collections.remove(old)
    for coll in (bpy.data.meshes, bpy.data.materials, bpy.data.curves, bpy.data.lights, bpy.data.cameras):
        for item in list(coll):
            if item.name.startswith(PREFIX) and item.users == 0:
                coll.remove(item)
    if hide_others:
        for ob in scene.objects:
            ob.hide_render = True
            ob.hide_set(True)
    coll = bpy.data.collections.new(COLLECTION)
    scene.collection.children.link(coll)
    return coll


def build_stand(order, tiers, coll, M):
    """Returns the z (metres) where the bottom tier sits."""
    stand = order["render"].get("stand", "white")
    shape = order["shape"]
    bottom = tiers[0]
    if shape == "bento":
        return build_bento_box(bottom, coll, M, order)
    if stand == "none":
        return 0.0
    half = math.hypot(bottom[0], bottom[1]) / 2 if shape in ("square", "rectangle") else bottom[0] / 2
    rp = (half + 1.0) * IN
    k = float(order["render"].get("stand_height", 3.18)) / 3.18   # squash the pedestal for a lower stand
    p = [(0, 0), (2.4 * IN, 0), (2.5 * IN, 0.06 * IN), (2.4 * IN, 0.18 * IN), (1.2 * IN, 0.45 * IN),
         (0.6 * IN, 0.9 * IN), (0.45 * IN, 1.6 * IN), (0.45 * IN, 2.5 * IN), (0.8 * IN, 2.85 * IN),
         (rp - 0.15 * IN, 2.95 * IN)]
    p += [(x, z) for x, z in arc_points(rp - 0.1 * IN, 3.05 * IN, 0.1 * IN, -90, 90, 6)]
    p += [(rp - 0.2 * IN, 3.18 * IN), (0, 3.18 * IN)]
    p = [(r, z * k) for r, z in p]
    lathe("Stand", p, coll, M["stand"], segs=128)
    return 3.18 * IN * k


def build_bento_box(bottom, coll, M, order):
    inner = (bottom[0] + 1.3) * IN
    wall, t = 1.1 * IN, 0.05 * IN
    rounded_box("BentoBase", inner + 2 * t, inner + 2 * t, t, 0, 0.01 * IN, coll, M["kraft"])
    for name, x, y, w, d in (("BentoFront", 0, -(inner + t) / 2, inner + 2 * t, t),
                             ("BentoBack", 0, (inner + t) / 2, inner + 2 * t, t),
                             ("BentoLeft", -(inner + t) / 2, 0, t, inner),
                             ("BentoRight", (inner + t) / 2, 0, t, inner)):
        ob = rounded_box(name, w, d, wall, 0, 0.008 * IN, coll, M["kraft"])
        ob.location = (x, y, 0)
    # Lid, opened up and leaning back behind the cake.
    lid = rounded_box("BentoLid", inner + 2 * t, inner + 2 * t, t, 0, 0.01 * IN, coll, M["kraft"])
    lid.location = (0, (inner + 2 * t) / 2, wall)
    lid.data.transform(Tr(0, (inner + 2 * t) / 2, 0))
    lid.rotation_euler = (math.radians(100), 0, 0)
    # Heart sticker on the front wall.
    b = Builder("BentoSticker")
    disk = t_cylinder(32, 1, 1, 0.04)
    b.add(disk, Tr(0, -(inner / 2 + t + 0.01 * IN), wall * 0.55) @ R(90, "X") @ S(0.38 * IN), M["sticker"])
    b.add(t_heart2d(), Tr(0, -(inner / 2 + t + 0.04 * IN), wall * 0.55) @ S(0.22 * IN), M["piping"])
    b.finish(coll)
    return t


def build_tiers(order, sizes, z0, coll, M, finish, finish_top=None):
    box = order["shape"] in ("square", "rectangle")
    tiers = []
    z = z0
    for i, (w, d, h) in enumerate(sizes):
        t = Tier(box, w * IN, d * IN, h * IN, z)
        name = "Tier%d" % (i + 1)
        tier_finish = (finish_top or finish) if i > 0 else finish
        frosting = M["frosting_top"] if i > 0 else M["frosting"]
        bevel = (0.14 if tier_finish in ("fondant", "semi") else 0.06) * IN
        if box:
            t.obj = rounded_box(name, t.w, t.d, t.h, t.zb, bevel, coll, frosting)
        else:
            r = t.r
            prof = [(0, t.zb)]
            prof += arc_points(r - 0.03 * IN, t.zb + 0.03 * IN, 0.03 * IN, -90, 0, 3)[0:]
            prof += [(r, t.zb + t.h * k) for k in (0.25, 0.5, 0.75)]
            prof += arc_points(r - bevel, t.zt - bevel, bevel, 0, 90, 6)
            prof += [(r * 0.5, t.zt), (0, t.zt)]
            t.obj = lathe(name, prof, coll, frosting, segs=128)
        tiers.append(t)
        z = t.zt
    return tiers


def build_finish(tiers, finish, coll, M, rnd, all_fondant, has_drip, piping="rosette",
                 finish_top=None, all_fondant_top=False):
    """Borders, ribbon, bow, 2D designs. Returns the trim height per tier (for pearls).
    `piping` is the buttercream top border: rosette (default), shell or bead.
    `finish_top` / `all_fondant_top` give the tiers above the bottom one a different finish."""
    trims = []
    shell = t_loft(lambda t: (t ** 0.5) * (1 - t) ** 1.1 / 0.385, layers=14, points=7, inner=0.78, axis="X")
    rosette = t_loft(lambda t: math.sqrt(max(0.0, 1 - t * t)), layers=12, points=7, inner=0.6, twist=4.0)
    bead = t_sphere(14, 8)
    bottom_finish, bottom_all_fondant = finish, all_fondant
    for idx, t in enumerate(tiers):
        # Each tier is decorated for its own finish.
        upper = idx > 0 and finish_top
        finish = finish_top if upper else bottom_finish
        all_fondant = all_fondant_top if upper else bottom_all_fondant
        is_top = idx == len(tiers) - 1
        s = t.scale
        b = Builder("Finish%d" % (idx + 1))
        trim = 0.3 * IN * s
        if finish == "buttercream":
            for p, tan, n in t.ring(t.zb + 0.16 * IN * s, 0.36 * IN * s, 0.06 * IN * s):
                b.add(shell, edge_frame(p - tan * 0.27 * IN * s, tan) @ S(0.62 * IN * s, 0.2 * IN * s, 0.2 * IN * s), M["piping"])
            lift = 0.08 * IN if (is_top and has_drip) else 0.0
            if piping == "shell":
                for p, tan, n in t.top_ring(0.36 * IN * s, 0.2 * IN * s):
                    p = p + Vector((0, 0, lift + 0.02 * IN * s))
                    b.add(shell, edge_frame(p - tan * 0.27 * IN * s, tan) @ S(0.62 * IN * s, 0.2 * IN * s, 0.2 * IN * s), M["piping"])
            elif piping == "bead":
                for p, tan, n in t.top_ring(0.34 * IN * s, 0.2 * IN * s):
                    p = p + Vector((0, 0, lift + 0.07 * IN * s))
                    b.add(bead, Matrix.Translation(p) @ S(0.17 * IN * s, 0.17 * IN * s, 0.14 * IN * s), M["piping"])
            else:
                for p, tan, n in t.top_ring(0.42 * IN * s, 0.2 * IN * s):
                    p = p + Vector((0, 0, lift))
                    b.add(rosette, upright(p, rnd.uniform(0, 360)) @ S(0.21 * IN * s, 0.21 * IN * s, 0.22 * IN * s), M["piping"])
            trim = 0.4 * IN * s
        elif finish in ("semi", "fondant"):
            band = 0.35 * IN * s
            if t.box:
                rounded_box("Ribbon%d" % (idx + 1), t.w + 0.04 * IN, t.d + 0.04 * IN, band, t.zb, 0.06 * IN, coll, M["ribbon"])
            else:
                lathe("Ribbon%d" % (idx + 1), [(t.r - 0.05 * IN, t.zb), (t.r + 0.025 * IN, t.zb),
                                               (t.r + 0.025 * IN, t.zb + band), (t.r - 0.05 * IN, t.zb + band)],
                      coll, M["ribbon"], segs=128)
            if finish == "semi":
                for p, tan, n in t.top_ring(0.3 * IN * s, 0.06 * IN):
                    b.add(bead, Matrix.Translation(p + Vector((0, 0, 0.06 * IN + (0.06 * IN if is_top and has_drip else 0)))) @ S(0.14 * IN * s, 0.14 * IN * s, 0.11 * IN * s), M["piping"])
            if all_fondant:
                p, n = t.side_front(0.0, t.zb + band / 2 + 0.3 * IN * s, 0.08 * IN)
                b.add(t_bow(), face_frame(p, n, scale=1.0 * IN * s), [M["piping_dark"], M["ribbon"]])
            trim = band
        elif finish == "icing2d":
            zmid = t.zb + t.h * (0.42 if is_top else 0.5)
            size = min(1.1, (t.h / IN) / 3.0) * 0.55 * IN * s
            for xf, tmpl, roll in ((-0.62, t_star2d(), -12), (0.62, t_heart2d(), 10)):
                p, n = t.side_front(xf, zmid, 0.06 * IN)
                b.add(tmpl, face_frame(p, n, roll=math.radians(roll), scale=size), M["piping"])
            for p, tan, n in t.top_ring(0.27 * IN * s, 0.06 * IN):
                b.add(bead, Matrix.Translation(p + Vector((0, 0, 0.05 * IN + (0.06 * IN if is_top and has_drip else 0)))) @ S(0.12 * IN * s, 0.12 * IN * s, 0.1 * IN * s), M["piping"])
            trim = 0.0
        else:  # plain: bead border at the bottom
            for p, tan, n in t.ring(t.zb + 0.14 * IN * s, 0.3 * IN * s, 0.04 * IN):
                b.add(bead, Matrix.Translation(p) @ S(0.15 * IN * s), M["piping"])
        b.finish(coll)
        trims.append(trim)
    return trims


def build_drip(t, coll, M, rnd):
    s = t.scale
    lip = 0.1 * IN
    if t.box:
        rounded_box("DripTop", t.w + 0.08 * IN, t.d + 0.08 * IN, lip, t.zt - 0.03 * IN, 0.04 * IN, coll, M["chocolate"])
    else:
        r = t.r
        prof = [(0, t.zt + lip), (r - 0.15 * IN, t.zt + lip)]
        prof += arc_points(r - 0.02 * IN, t.zt + lip - 0.13 * IN, 0.13 * IN, 90, 0, 5)[1:]
        prof += [(r + 0.11 * IN, t.zt - 0.15 * IN), (r - 0.05 * IN, t.zt - 0.2 * IN)]
        lathe("DripTop", prof, coll, M["chocolate"], segs=128)
    t.top_lift = lip
    b = Builder("Drips")
    end = t_sphere(12, 8)
    for p, tan, n in t.ring(t.zt, 0.42 * IN * s, 0.06 * IN):
        if rnd.random() > 0.82:
            continue
        w = rnd.uniform(0.11, 0.17) * IN * s
        length = 0.25 * IN + rnd.random() * min(t.h * 0.45, 1.4 * IN)
        top = p + Vector((0, 0, -0.05 * IN))
        path = [top + Vector((0, 0, -length * k / 6)) for k in range(7)]
        b.add(t_tube(path, w, 0.06 * IN, segs=10, side_hint=tan, caps=False,
                     radius_fn=lambda f: 1.0 + 0.15 * f), Matrix(), M["chocolate"])
        bulb = path[-1]
        b.add(end, face_frame(bulb, n) @ S(w * 1.18 / IN * IN, 0.07 * IN, w * 1.3), M["chocolate"])
    b.finish(coll)


def build_side_toppings(order, tiers, trims, coll, M, rnd, has_drip):
    tops = order["toppings"]
    top = tiers[-1]
    if "pearls" in tops:
        b = Builder("Pearls")
        pearl = t_sphere(16, 10)
        for t, trim in zip(tiers, trims):
            z = t.zb + trim + 0.14 * IN
            for p, tan, n in t.ring(z, 0.27 * IN, 0.05 * IN):
                b.add(pearl, Matrix.Translation(p) @ S(0.125 * IN), M["pearl"])
        b.finish(coll)
    if "sprinkles" in tops:
        b = Builder("Sprinkles")
        jimmy = t_sphere(8, 5)
        dot = t_sphere(6, 4)
        for ti, t in enumerate(tiers):
            area_side = (2 * math.pi * t.r if not t.box else 2 * (t.w + t.d)) * t.h / IN ** 2
            for i in range(int(area_side * 2.2)):
                p, n = t.side_random(rnd, t.zb + 0.5 * IN, t.zt - 0.2 * IN, 0.015 * IN)
                m = M["sprinkles"][i % len(SPRINKLE_COLORS)]
                tmpl, sc = (dot, S(0.035 * IN)) if i % 4 == 3 else (jimmy, S(0.11 * IN, 0.03 * IN, 0.03 * IN))
                b.add(tmpl, face_frame(p, n, roll=rnd.uniform(0, math.pi)) @ R(90, "Y") @ sc if tmpl is jimmy
                      else Matrix.Translation(p) @ sc, m)
            if ti == len(tiers) - 1 and not has_drip:
                area_top = (math.pi * t.r ** 2 if not t.box else t.w * t.d) / IN ** 2
                keep_clear = order["render"].get("message_placement") == "top" and order["message"]
                half_d = t.d / 2 if t.box else t.r
                for i in range(int(area_top * 3.5)):
                    p = t.top_random(rnd, 0.8) + Vector((0, 0, 0.015 * IN))
                    if keep_clear and abs(p.y + 0.18 * half_d) < 0.22 * half_d:
                        continue  # leave the message readable
                    m = M["sprinkles"][(i + 2) % len(SPRINKLE_COLORS)]
                    if i % 4 == 3:
                        b.add(dot, Matrix.Translation(p) @ S(0.035 * IN), m)
                    else:
                        b.add(jimmy, upright(p, rnd.uniform(0, 360)) @ S(0.11 * IN, 0.03 * IN, 0.03 * IN), m)
        b.finish(coll)
    if "gold_leaf" in tops:
        b = Builder("GoldLeaf")
        for i in range(9):
            xf = -0.42 + rnd.uniform(-0.2, 0.2)
            z = top.zb + top.h * rnd.uniform(0.45, 0.85)
            p, n = top.side_front(xf, z, 0.012 * IN)
            b.add(t_gold_flake(rnd), face_frame(p, n, roll=rnd.uniform(0, 6.28),
                                                 scale=rnd.uniform(0.15, 0.3) * IN * top.scale), M["gold"])
        for i in range(3 if not top.box else 2):
            p = top.top_random(rnd, 0.7) + Vector((0, 0, 0.012 * IN))
            b.add(t_gold_flake(rnd), face_frame(p, Vector((0, 0, 1)), up=Vector((0, 1, 0)),
                                                 roll=rnd.uniform(0, 6.28), scale=0.25 * IN), M["gold"])
        b.finish(coll)
    if "flowers" in tops:
        b = Builder("SideFlowers")
        s = top.scale
        z = top.zt - top.h * 0.3
        for xf, dz, sc in ((0.55, 0.0, 1.25), (0.68, -0.32, 1.0), (0.46, -0.5, 0.8)):
            p, n = top.side_front(xf, z + dz * IN * s, 0.08 * IN)
            b.add(t_blossom(), face_frame(p, n, roll=rnd.uniform(0, 1), scale=0.42 * IN * s * sc),
                  [M["piping"], M["blossom_center"]])
        for xf, dz, roll in ((0.62, 0.15, -0.6), (0.43, -0.12, 0.9)):
            p, n = top.side_front(xf, z + dz * IN * s, 0.05 * IN)
            b.add(t_leaf(), face_frame(p, n, roll=roll, scale=0.45 * IN * s), M["leaf"])
        b.finish(coll)


# Side designs: a pattern around the side of a tier, in the side design colour. Same keys as
# cakeSideDesigns() in cake_options.php. "none" = plain sides.
SIDE_DESIGNS = ["stripes", "dots", "quilted", "swags", "ruffles", "zigzag"]


def t_grid(rings, closed=True):
    """A surface through rings of points (each ring the same length), e.g. a ruffle all round a tier."""
    bm = bmesh.new()
    vs = [[bm.verts.new(p) for p in ring] for ring in rings]
    n = len(rings[0])
    last = n if closed else n - 1
    for a, b in zip(vs, vs[1:]):
        for k in range(last):
            bm.faces.new((a[k], a[(k + 1) % n], b[(k + 1) % n], b[k]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    t = T_from_bm(bm)
    bm.free()
    return t


def build_side_designs(order, tiers, coll, M):
    """The side design of each tier (order["side_designs"], bottom tier first). Each tier's pattern is its
    own object, Pattern1 (bottom) and Pattern2 (upper), so the web builder can render them separately."""
    designs = order.get("side_designs") or []
    lens = t_sphere(16, 10)
    for idx, t in enumerate(tiers):
        key = designs[min(idx, len(designs) - 1)] if designs else "none"
        if key not in SIDE_DESIGNS:
            continue
        s = t.scale
        # The band the pattern fills: clear of the bottom border/ribbon and a little under the top edge.
        lo = t.zb + max(0.42 * IN * s, 0.12 * t.h)
        hi = t.zt - max(0.2 * IN * s, 0.08 * t.h)
        span = hi - lo
        b = Builder("Pattern%d" % (idx + 1))
        mat = M["side"]
        if key == "stripes":
            half, thick = 0.13 * IN * s, 0.035 * IN
            for p, tan, n in t.side_spots(0.75 * IN * s, 0.0, thick * 0.4):
                path = [Vector((p.x, p.y, lo + span * k / 7)) for k in range(8)]
                b.add(t_tube(path, half, thick, segs=12, side_hint=tan,
                             radius_fn=lambda f: 1.0 if 0.06 < f < 0.94 else 0.75), Matrix(), mat)
        elif key == "dots":
            rows = max(2, round(span / (0.62 * IN * s)) + 1)
            r = 0.13 * IN * s
            for j in range(rows):
                z = lo + r + (span - 2 * r) * j / (rows - 1)
                for p, tan, n in t.side_spots(0.72 * IN * s, z, 0.0, between=j % 2 == 1):
                    b.add(lens, face_frame(p, n) @ S(r, 0.38 * r, r), mat)
        elif key == "quilted":
            width = 0.85 * IN * s
            n = max(8, round(t.perimeter() / width))
            height = min(span, width * 1.25)
            steps = max(6, round(span / (0.08 * IN)))
            rad = 0.028 * IN * s
            for k in range(n):
                for sign in (1, -1):
                    path = []
                    for i in range(steps + 1):
                        z = lo + span * i / steps
                        path.append(t.around((k + sign * (z - lo) / height) / n, z, rad * 0.6)[0])
                    b.add(t_tube(path, rad, segs=8), Matrix(), mat)
            # A little bead where the lines cross.
            j = 0
            while lo + j * height / 2 <= hi + 1e-6:
                z = lo + j * height / 2
                for k in range(n):
                    p, tan, nrm = t.around((k + j / 2) / n, z, 0.0)
                    b.add(lens, face_frame(p, nrm) @ S(0.07 * IN * s, 0.05 * IN * s, 0.07 * IN * s), mat)
                j += 1
        elif key == "swags":
            n = max(6, round(t.perimeter() / (1.6 * IN * s)))
            depth = min(0.75 * IN * s, span * 0.55)
            top = hi
            for k in range(n):
                ua, ub = (k + 0.5) / n, (k + 1.5) / n
                for sag, rad in ((1.0, 0.06 * IN * s), (0.55, 0.04 * IN * s)):
                    path = []
                    for i in range(17):
                        f = i / 16
                        z = top - depth * sag * (1 - (2 * f - 1) ** 2)
                        path.append(t.around(ua + (ub - ua) * f, z, rad * 0.7)[0])
                    b.add(t_tube(path, rad, segs=10, radius_fn=lambda f: 0.65 + 0.55 * math.sin(math.pi * f)),
                          Matrix(), mat)
                # Where two swags meet: a bead, and a drop hanging under it.
                p, tan, nrm = t.around(ua, top + 0.03 * IN, 0.02 * IN)
                b.add(lens, face_frame(p, nrm) @ S(0.11 * IN * s, 0.08 * IN * s, 0.11 * IN * s), mat)
                p, tan, nrm = t.around(ua, top - 0.24 * IN * s, 0.01 * IN)
                b.add(lens, face_frame(p, nrm) @ S(0.06 * IN * s, 0.06 * IN * s, 0.12 * IN * s), mat)
        elif key == "ruffles":
            # Rows of gathered frills, each hanging from its own line and flaring out at the bottom
            # edge, so every row throws a shadow on the one below.
            rows = max(2, round(span / (0.55 * IN * s)))
            band = span / rows * 1.08
            per = t.perimeter()
            samples = max(160, round(per / (0.04 * IN)))
            waves = max(12, round(per / (0.42 * IN * s)))
            amp = 0.11 * IN * s
            for j in range(rows):
                z_top = hi - span * j / rows
                rings = []
                for level, flare, lift in ((0.0, 0.0, 0.0), (0.3, 0.3, 0.0), (0.65, 0.75, 0.0),
                                           (0.92, 1.0, 0.0), (1.0, 1.05, 0.04)):
                    ring = []
                    for i in range(samples):
                        u = i / samples
                        wave = 0.5 + 0.5 * math.sin(2 * math.pi * waves * u + j * 1.7)
                        off = 0.012 * IN + (0.05 * IN * s + amp * wave) * flare
                        ring.append(t.around(u, z_top - band * level + lift * IN * s, off)[0])
                    rings.append(ring)
                b.add(t_grid(rings), Matrix(), mat)
        elif key == "zigzag":
            rows = max(1, round(span / (0.75 * IN * s)))
            n = max(10, round(t.perimeter() / (0.6 * IN * s)))
            rad = 0.045 * IN * s
            for j in range(rows):
                zc = lo + span * (j + 0.5) / rows
                amp = min(0.22 * IN * s, span / rows * 0.32)
                path = []
                for i in range(2 * n):
                    for f in (0.0, 0.5):   # an extra point midway so the line hugs a round side
                        z = zc + amp * ((1 - 2 * f) if i % 2 == 0 else (2 * f - 1))
                        path.append(t.around((i + f) / (2 * n), z, rad * 0.6)[0])
                b.add(t_tube(path, rad, segs=8, closed=True), Matrix(), mat)
        b.finish(coll)


# Fixed spots (degrees around the top, 90 = back, 270 = front) used when every topping type must
# fit at once, e.g. for the web builder's image layers. Candles always go in the middle.
FIXED_SLOTS = {"flowers": [90], "macarons": [138, 42], "strawberries": [196, 344], "cherries": [242, 298]}


def build_top_toppings(order, top, coll, M, rnd, message_on_top):
    tops = order["toppings"]
    fixed = bool(order.get("_fixed_slots"))
    s = max(0.7, min(1.2, (top.front / IN) / 7.0))
    z = top.zt + top.top_lift
    rx = (top.w / 2 if top.box else top.r) * 0.62
    ry = (top.d / 2 if top.box else top.r) * 0.62
    spots = []   # (topping, where it stands[, size multiplier])
    # One piece alone in the middle: used to render the single toppings the web builder lets people drag around.
    single = order.get("_single_piece")
    # Pieces placed by the customer in the web builder: [[topping, u, v, size], ...] with (u, v) a spot on the
    # top of the cake, each -1..1 (u = left to right, v = front to back).
    placed = (order.get("layout") or {}).get("items")
    if single:
        spots = [(single[0], Vector((0, 0, z)))]
    elif placed is not None:
        half_w = top.w / 2 if top.box else top.r
        half_d = top.d / 2 if top.box else top.r
        spots = [(item[0], Vector((float(item[1]) * half_w, float(item[2]) * half_d, z)), float(item[3]) if len(item) > 3 else 1.0)
                 for item in placed if item and item[0] in tops]
    elif fixed:
        for key, angles in FIXED_SLOTS.items():
            if key in tops:
                spots += [(key, Vector((rx * math.cos(math.radians(a)), ry * math.sin(math.radians(a)), z))) for a in angles]
    else:
        others = []
        for key in ("strawberries", "cherries", "macarons", "flowers"):
            if key in tops:
                others += [key] if key == "flowers" else [key, key]
        n = len(others)
        for i, key in enumerate(others):
            if message_on_top:
                a = math.radians(20 + 140 * (i / max(1, n - 1))) if n > 1 else math.radians(90)
            else:
                a = math.radians(90 + 180 / n + 360 * i / n)
            spots.append((key, Vector((rx * math.cos(a), ry * math.sin(a), z))))
    if "candles" in tops and not single and placed is None:
        cy = 0.32 * ry if message_on_top else 0.0
        alone = len(spots) == 0 and not fixed
        pts = [(-0.3, 0.0), (0.0, 0.0), (0.3, 0.0)] if alone else [(-0.16, -0.08), (0.16, -0.08), (0.0, 0.16)]
        spots += [("candles", Vector((px * rx / 0.62, cy + py * ry / 0.62, z))) for px, py in pts]
    if not spots:
        return
    builders = {}
    straw_whole, straw_half = t_strawberry(False), t_strawberry(True)
    cherry, macaron, rose, candle = t_cherry(), t_macaron(), t_rose(), t_candle()
    counts = {single[0]: int(single[1]) - 1} if single else {}
    base_scale = s
    for spot in spots:
        key, p = spot[0], spot[1]
        s = base_scale * (spot[2] if len(spot) > 2 else 1.0)   # this piece's own size
        b = builders.setdefault(key, Builder("Top_" + key))
        counts[key] = counts.get(key, 0) + 1
        k = counts[key]
        if key == "strawberries":
            tmpl = straw_whole if k % 2 else straw_half
            b.add(tmpl, upright(p, rnd.uniform(-15, 15), 0.6 * IN * s), [M["strawberry"], M["leaf"], M["berry_inside"]])
        elif key == "cherries":
            b.add(cherry, upright(p, rnd.uniform(0, 360), 0.42 * IN * s, tilt_x=(-6 if k % 2 else 8)), [M["cherry"], M["stem"]])
        elif key == "macarons":
            # Standing on edge, leaning back a little, facing outwards.
            out = math.degrees(math.atan2(p.y, p.x)) - 90 if p.length_squared > 1e-9 else 0
            if fixed or placed is not None:
                out = (90 if p.x >= 0 else -90) + (18 if k % 2 else -18)   # side-on, so the sandwich shows
            mat = (Matrix.Translation(p + Vector((0, 0, 0.78 * IN * s))) @ R(out + rnd.uniform(-15, 15), "Z")
                   @ R(70, "X") @ S(0.78 * IN * s) @ Tr(0, 0, -0.82))
            b.add(macaron, mat, [M["macaron"], M["macaron_feet"], M["cream"]])
        elif key == "flowers":
            # Bigger and tipped toward the front so the spiral of petals shows.
            mat = (Matrix.Translation(p + Vector((0, 0, 0.12 * IN * s))) @ R(32, "X")
                   @ R(rnd.uniform(0, 360), "Z") @ S(1.15 * IN * s))
            b.add(rose, mat, [M["piping"], M["leaf"]])
        elif key == "candles":
            mat = upright(p, rnd.uniform(0, 360), IN * s)
            b.add(candle, mat, [M["candle"], M["wick"], M["flame"]])
            light = bpy.data.lights.new(PREFIX + "Flame", "POINT")
            light.energy = 0.6
            light.color = (1.0, 0.7, 0.35)
            light.shadow_soft_size = 0.004
            lo = bpy.data.objects.new(PREFIX + "FlameLight", light)
            lo.location = mat @ Vector((0, 0, 2.85))
            coll.objects.link(lo)
    for b in builders.values():
        b.finish(coll)


FONT_CANDIDATES = [
    "C:/Windows/Fonts/segoescb.ttf", "C:/Windows/Fonts/segoesc.ttf", "C:/Windows/Fonts/BRUSHSCI.TTF",
    "C:/Windows/Fonts/LHANDW.TTF", "/System/Library/Fonts/Supplemental/SnellRoundhand.ttc",
    "/System/Library/Fonts/Supplemental/Brush Script.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf",
]


# The web builder's other message fonts. They are Google Fonts: install them in Windows (or drop the
# .ttf files in a "fonts" folder next to this script) and they are used; otherwise the script font is.
FONT_FILES = {
    "pacifico": ["Pacifico-Regular.ttf", "Pacifico.ttf"],
    "dancing": ["DancingScript-Bold.ttf", "DancingScript-VariableFont_wght.ttf", "DancingScript-Regular.ttf"],
    "playfair": ["PlayfairDisplay-Bold.ttf", "PlayfairDisplay-VariableFont_wght.ttf", "georgiab.ttf"],
    "fredoka": ["Fredoka-SemiBold.ttf", "Fredoka-VariableFont_wdth,wght.ttf", "trebucbd.ttf"],
}


def font_dirs():
    dirs = ["C:/Windows/Fonts", os.path.join(os.environ.get("LOCALAPPDATA", ""), "Microsoft", "Windows", "Fonts")]
    try:
        dirs.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "fonts"))
    except NameError:   # run from Blender's text editor
        pass
    return dirs


def load_font(key="script"):
    wanted = [os.path.join(d, name) for name in FONT_FILES.get(key or "script", []) for d in font_dirs()]
    for path in wanted + FONT_CANDIDATES:
        if os.path.exists(path):
            try:
                return bpy.data.fonts.load(path, check_existing=True)
            except RuntimeError:
                continue
    return None


def build_photo(photo, top, coll):
    """A picture printed on edible paper, lying on top of the cake: {"file", "u", "v", "s"}.
    Round cakes get a round print, square and rectangle cakes a square one (like the web builder)."""
    path = (photo or {}).get("file") or ""
    if not os.path.exists(path):
        print("Cake: photo not found, skipped:", path)
        return
    half_w = top.w / 2 if top.box else top.r
    half_d = top.d / 2 if top.box else top.r
    r = min(0.5 * float(photo.get("s") or 1), 0.9) * half_w      # half the print's width
    r = min(r, 0.9 * half_d)
    cx = max(-(0.95 * half_w - r), min(0.95 * half_w - r, float(photo.get("u") or 0) * half_w))
    cy = max(-(0.95 * half_d - r), min(0.95 * half_d - r, float(photo.get("v") or 0) * half_d))
    z = top.zt + top.top_lift + 0.0008
    bm = bmesh.new()
    uv = bm.loops.layers.uv.new("UVMap")
    if top.box:
        ring = [(-1, -1), (1, -1), (1, 1), (-1, 1)]
    else:
        ring = [(math.cos(2 * math.pi * i / 72), math.sin(2 * math.pi * i / 72)) for i in range(72)]
    verts = [bm.verts.new((cx + x * r, cy + y * r, z)) for x, y in ring]
    face = bm.faces.new(verts)
    for loop, (x, y) in zip(face.loops, ring):
        loop[uv].uv = (0.5 + x / 2, 0.5 + y / 2)     # the picture's top edge is at the back of the cake
    me = bpy.data.meshes.new(PREFIX + "Photo")
    bm.to_mesh(me)
    bm.free()
    m = material("Photo", "#FFFFFF", rough=0.55)
    N, L = m.node_tree.nodes, m.node_tree.links
    tex = N.new("ShaderNodeTexImage")
    tex.image = bpy.data.images.load(path, check_existing=True)
    tex.extension = "EXTEND"
    L.new(tex.outputs["Color"], N.get("Principled BSDF").inputs["Base Color"])
    me.materials.append(m)
    coll.objects.link(bpy.data.objects.new(PREFIX + "Photo", me))


def build_message(text, top, coll, M, placement, font_key="script", moved=None):
    """`moved` = [dx, dy, size] from the web builder: how far the message was dragged (-1..1 sideways,
    -1..1 up/down with down positive) and its size multiplier."""
    if not text:
        return
    dx, dy, grow = [float(x) for x in (list(moved or []) + [0, 0, 1])[:3]]
    cu = bpy.data.curves.new(PREFIX + "MessageCurve", "FONT")
    cu.body = text
    font = load_font(font_key)
    if font:
        cu.font = font
    cu.align_x = "CENTER"
    cu.align_y = "CENTER"
    cu.resolution_u = 6
    cu.size = 1.0
    tmp = bpy.data.objects.new(PREFIX + "MessageTmp", cu)
    coll.objects.link(tmp)
    bpy.context.view_layer.update()
    width1, height1 = max(tmp.dimensions.x, 1e-6), max(tmp.dimensions.y, 1e-6)
    if placement == "top":
        room = (top.w if top.box else top.r * 2) * 0.72
        size = min(0.9 * IN * top.scale, room / width1)
    else:
        room = top.front * 0.8
        size = min(top.h * (0.26 if top.top_lift else 0.32) / max(height1, 0.6), room / width1)
    size = min(size * max(0.6, min(1.6, grow)), room / width1 * 1.15)
    cu.size = size
    bpy.context.view_layer.update()
    dg = bpy.context.evaluated_depsgraph_get()
    me = bpy.data.meshes.new_from_object(tmp.evaluated_get(dg))
    me.name = PREFIX + "Message"
    bpy.data.objects.remove(tmp, do_unlink=True)
    bm = bmesh.new()
    bm.from_mesh(me)
    bmesh.ops.triangulate(bm, faces=bm.faces[:])
    bm.to_mesh(me)
    bm.free()
    me.materials.clear()
    me.materials.append(M["ink"])
    ob = bpy.data.objects.new(PREFIX + "Message", me)
    coll.objects.link(ob)
    if placement == "top":
        half_w = top.w / 2 if top.box else top.r
        half_d = top.d / 2 if top.box else top.r
        ob.location = (dx * 0.3 * half_w, (-0.18 - dy * 0.3) * half_d, top.zt + top.top_lift + 0.002)
    else:
        z = top.zb + top.h * ((0.3 if top.top_lift else 0.42) - dy * 0.25)
        p, n = top.side_front(dx * 0.45, z, 0.002)
        # Face outwards from wherever on the side it was dragged to.
        ob.rotation_euler = (math.radians(90), 0, math.atan2(n.x, -n.y))
        ob.location = p
        if not top.box:
            sw = ob.modifiers.new("Wrap", "SHRINKWRAP")
            sw.target = top.obj
            sw.wrap_method = "NEAREST_SURFACEPOINT"
            sw.offset = 0.0004
    sol = ob.modifiers.new("Thickness", "SOLIDIFY")
    sol.thickness = 0.0012
    sol.offset = -1.0 if placement != "top" else 1.0


def build_backdrop(coll, M, size):
    """Curved studio sweep (floor that bends up into a back wall)."""
    r = 0.6 * size
    prof = [(-3.0 * size, 0.0)]
    prof += [(1.0 * size + r * math.sin(math.radians(a)), r - r * math.cos(math.radians(a))) for a in range(0, 91, 10)]
    prof += [(1.0 * size + r, 3.0 * size)]
    bm = bmesh.new()
    left = [bm.verts.new((-3 * size, y, z)) for y, z in prof]
    right = [bm.verts.new((3 * size, y, z)) for y, z in prof]
    for i in range(len(prof) - 1):
        bm.faces.new((left[i], right[i], right[i + 1], left[i + 1]))
    for f in bm.faces:
        f.smooth = True
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces[:])
    me = bpy.data.meshes.new(PREFIX + "Backdrop")
    bm.to_mesh(me)
    bm.free()
    for p in me.polygons:
        if p.normal.z < -0.1 or p.normal.y > 0.1:
            p.flip()
    me.materials.append(M["backdrop"])
    ob = bpy.data.objects.new(PREFIX + "Backdrop", me)
    coll.objects.link(ob)


def add_area_light(coll, name, loc, target, power, size, color=(1, 1, 1)):
    li = bpy.data.lights.new(PREFIX + name, "AREA")
    li.energy = power
    li.size = size
    li.color = color
    ob = bpy.data.objects.new(PREFIX + name, li)
    ob.location = loc
    d = Vector(target) - Vector(loc)
    ob.rotation_euler = d.to_track_quat("-Z", "Y").to_euler()
    coll.objects.link(ob)
    return ob


def setup_world(scene, backdrop_hex):
    world = scene.world or bpy.data.worlds.new(PREFIX + "World")
    scene.world = world
    if bpy.app.version < (5, 0, 0):
        world.use_nodes = True
    bg = world.node_tree.nodes.get("Background")
    if bg:
        bg.inputs["Color"].default_value = rgba(mix(backdrop_hex, "#FFFFFF", 0.3))
        bg.inputs["Strength"].default_value = 0.3


def setup_lights_and_camera(scene, coll, width, height):
    size = max(width, height)
    target = Vector((0, 0, height * 0.48))
    k = max(1.0, size / 0.35)
    # Tuned so a front-lit frosting surface comes out close to its hex colour in "Standard" view.
    add_area_light(coll, "Key", (-1.1 * k, -1.3 * k, 1.5 * k), target, 62 * k * k, 1.2 * k, (1.0, 0.97, 0.93))
    add_area_light(coll, "Fill", (1.5 * k, -1.0 * k, 0.7 * k), target, 20 * k * k, 1.6 * k, (0.95, 0.97, 1.0))
    add_area_light(coll, "Rim", (0.5 * k, 1.6 * k, 1.6 * k), target, 40 * k * k, 0.9 * k)
    cam_data = bpy.data.cameras.new(PREFIX + "Camera")
    cam_data.lens = 70
    cam = bpy.data.objects.new(PREFIX + "Camera", cam_data)
    coll.objects.link(cam)
    scene.camera = cam
    return cam, target


def place_camera(cam, target, width, height, view):
    elev, az = {"hero": (20, 0), "three_quarter": (24, 35), "top": (72, 0), "side": (6, 0)}.get(view, (20, 0))
    fov = 2 * math.atan(18 / cam.data.lens)
    span = max(width * 1.18, height * 1.3)
    dist = span / (2 * math.tan(fov / 2)) + width / 2
    e, a = math.radians(elev), math.radians(az)
    direction = Vector((math.sin(a) * math.cos(e), -math.cos(a) * math.cos(e), math.sin(e)))
    cam.location = target + direction * dist
    cam.rotation_euler = (target - cam.location).to_track_quat("-Z", "Y").to_euler()
    cam.data.clip_start = 0.01
    cam.data.clip_end = dist * 10


def setup_render(scene, r):
    engine = (r.get("engine") or "CYCLES").upper()
    if engine.startswith("EEVEE"):
        for name in ("BLENDER_EEVEE_NEXT", "BLENDER_EEVEE"):
            try:
                scene.render.engine = name
                break
            except TypeError:
                continue
    else:
        scene.render.engine = "CYCLES"
        scene.cycles.samples = int(r.get("samples", 128))
        scene.cycles.use_denoising = True
        try:
            prefs = bpy.context.preferences.addons["cycles"].preferences
            for kind in ("OPTIX", "CUDA", "HIP", "METAL", "ONEAPI"):
                try:
                    prefs.compute_device_type = kind
                except TypeError:
                    continue
                prefs.get_devices()
                gpus = [d for d in prefs.devices if d.type != "CPU"]
                if gpus:
                    for d in prefs.devices:
                        d.use = True
                    scene.cycles.device = "GPU"
                    break
        except Exception:
            pass
    res = r.get("resolution") or [1600, 1600]
    scene.render.resolution_x, scene.render.resolution_y = int(res[0]), int(res[1])
    scene.render.resolution_percentage = 100
    scene.render.image_settings.file_format = "PNG"
    # "Standard" shows the frosting in the same colour as the hex the customer picked.
    try:
        scene.view_settings.view_transform = r.get("color_view", "Standard")
        scene.view_settings.look = r.get("color_look", "None")
    except TypeError:
        scene.view_settings.view_transform = "Standard"
    scene.view_settings.exposure = float(r.get("exposure", 0.0))


def build_cake(order=None):
    o = normalize_order(order)
    scene = bpy.context.scene
    r = o["render"]
    coll = reset_collection(scene, r.get("hide_other_objects", True))
    seed = sum(ord(c) for c in (o["message"] + o.get("size", "") + o["base_color"])) + 7
    rnd = random.Random(seed)
    finish = finish_key(o.get("finish"))
    pal = palette(o["base_color"], o["accent_color"])
    sizes = tier_sizes(o["shape"], o["tiers"], o.get("size"))
    # Two-tier cakes may have another finish on the upper tier.
    finish_top = finish_key(o.get("finish_top")) if o.get("finish_top") and len(sizes) > 1 else finish
    o["_finish_top"] = finish_top
    M = build_materials(o, pal, finish)
    has_drip = "chocolate_drip" in o["toppings"]

    z0 = build_stand(o, sizes, coll, M)
    tiers = build_tiers(o, sizes, z0, coll, M, finish, finish_top)
    top = tiers[-1]
    if o.get("color_style") == "ombre" and is_hex(o.get("base_color2")):
        for mat in {M["frosting"], M["frosting_top"]}:
            apply_ombre(mat, o["base_color"], o["base_color2"], tiers[0].zb, top.zt)
    if has_drip:
        build_drip(top, coll, M, random.Random(seed + 1))
    trims = build_finish(tiers, finish, coll, M, rnd, "all" in (o.get("finish") or "").lower(), has_drip,
                         o.get("piping") or "rosette",
                         finish_top if finish_top != finish or o.get("finish_top") else None,
                         "all" in (o.get("finish_top") or "").lower())
    build_side_designs(o, tiers, coll, M)
    build_side_toppings(o, tiers, trims, coll, M, rnd, has_drip)
    if o.get("photo"):
        build_photo(o["photo"], top, coll)
    placement = r.get("message_placement", "front")
    build_message(o["message"], top, coll, M, placement, o.get("font") or "script", (o.get("layout") or {}).get("msg"))
    build_top_toppings(o, top, coll, M, rnd, placement == "top" and bool(o["message"]))

    # Frame the whole stand (square cakes sit on a plate as wide as their diagonal) and tall toppings.
    width = (math.hypot(tiers[0].w, tiers[0].d) if tiers[0].box else tiers[0].w) + 2.0 * IN
    extra = 4.0 if "candles" in o["toppings"] else (2.5 if o["toppings"] else 0.6)
    height = top.zt + top.top_lift + extra * IN * top.scale
    build_backdrop(coll, M, max(width, height) * 1.6)
    setup_world(scene, r.get("backdrop", "#EFE8E1"))
    cam, target = setup_lights_and_camera(scene, coll, width, height)
    place_camera(cam, target, width, height, (r.get("views") or ["hero"])[0])
    setup_render(scene, r)

    summary = {
        "shape": o["shape"], "tiers_in": sizes, "finish": finish, "colors": pal,
        "toppings": o["toppings"], "message": o["message"], "notes": o.get("notes", ""),
    }
    print("Cake built:", json.dumps(summary))
    return {"order": o, "camera": cam, "target": target, "width": width, "height": height, "summary": summary,
            "tiers": tiers, "stand_top": z0, "collection": coll, "materials": M}


def render_views(built, output=None, views=None):
    o = built["order"]
    output = output or o["render"].get("output")
    if not output:
        return []
    views = views or o["render"].get("views") or ["hero"]
    scene = bpy.context.scene
    base, ext = os.path.splitext(output)
    ext = ext or ".png"
    files = []
    for v in views:
        place_camera(built["camera"], built["target"], built["width"], built["height"], v)
        path = os.path.abspath(output if len(views) == 1 else "%s_%s%s" % (base, v, ext))
        os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
        scene.render.filepath = path
        bpy.ops.render.render(write_still=True)
        files.append(path)
        print("Rendered", path)
    return files


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
def _cli_order():
    """Reads `-- order.json --render out.png --samples 64 --engine EEVEE --views hero,top`."""
    argv = sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else []
    order, extra = None, {}
    i = 0
    while i < len(argv):
        a = argv[i]
        if a == "--render":
            extra["output"] = argv[i + 1]; i += 2
        elif a == "--samples":
            extra["samples"] = int(argv[i + 1]); i += 2
        elif a == "--engine":
            extra["engine"] = argv[i + 1]; i += 2
        elif a == "--views":
            extra["views"] = argv[i + 1].split(","); i += 2
        elif a == "--save":
            extra["save_blend"] = argv[i + 1]; i += 2
        else:
            with open(a, encoding="utf-8") as fh:
                order = json.load(fh)
            i += 1
    return order, extra


def main():
    g = globals()
    order, extra = _cli_order()
    if g.get("ORDER_OVERRIDE"):
        order = g["ORDER_OVERRIDE"]
    elif g.get("ORDER_FILE"):
        with open(g["ORDER_FILE"], encoding="utf-8") as fh:
            order = json.load(fh)
    order = dict(order or DEFAULT_ORDER)
    if extra:
        order["render"] = dict(order.get("render") or {}, **extra)
    built = build_cake(order)
    render_views(built)
    save = built["order"]["render"].get("save_blend")
    if save:
        bpy.ops.wm.save_as_mainfile(filepath=os.path.abspath(save))
        print("Saved", save)
    return built


if not globals().get("CAKE_NO_MAIN"):
    CAKE = main()
