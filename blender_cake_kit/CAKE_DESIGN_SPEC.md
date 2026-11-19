# Custom Cake Design Spec (for 3D rendering in Blender)

This is the full list of choices a customer can make in the bakery's **Cake Builder**
(`invtest/cakebuilder.php`) and exactly how each one should look in 3D.
`cake_builder_blender.py` already follows this spec. Use this file when you need
to check, fix or extend a render by hand.

---

## 1. Order fields

| Field | Example | Rules |
|---|---|---|
| `recipe_name` | `Round 2-Tier Custom Cake` | Shape and tier count come from the name (see §2) |
| `size` | `8x4/6x3` | Inches. See §3 |
| `finish` | `Buttercream` | See §4 |
| `base_color` | `#F9C6D0` | Frosting colour. Any 6-digit hex |
| `accent_color` | `#D4A63A` | Piping, borders, ribbon, roses, macarons, candle stripes, message ink. Any hex |
| `toppings` | `["chocolate_drip","macarons"]` | **Max 4.** See §6 |
| `message` | `Happy Birthday, Mia!` | **Max 40 characters.** See §7 |
| `customer`, `pickup_date` | | Not drawn; only for file names and labels |
| `notes` | `Unicorn theme, no nuts` | Free text from the customer. Read it and add theme details by hand (see §9) |

These match the `m3.1-custom_cake_design` table (`ccd-BaseColor`, `ccd-AccentColor`,
`ccd-Toppings` comma list, `ccd-Message`, `ccd-Notes`) plus the recipe name and size
from the production batch.

---

## 2. Shape and tiers (from the recipe name)

Same rule as `cakeShapeFor()` in `cake_options.php`:

- Name contains `2 tier` / `2-tier` → **2 tiers**, otherwise 1.
- Contains `square` → **square** · `rectangle` → **rectangle** · `bento` → **bento** · `mini` → **mini** · anything else → **round**.

| Shape | Model | Default size if none given |
|---|---|---|
| round | Cylinder, slightly rounded top edge | 7 in across × 4 in tall |
| square | Box with softly rounded edges | 8 × 8 × 4 in |
| rectangle | Box, depth = 75% of length unless given | 12 × 9 × 2.5 in |
| mini | Small round cake | 3.5 in × 3 in |
| bento | Small round cake **inside an open kraft-paper clamshell box** (lid standing open behind it, white round sticker with an accent heart on the front wall). No pedestal | 4 in × 2.2 in |

---

## 3. Size label → real dimensions

Size labels are inches, written the way the bakery stores them (`recvar-SizeOrPcs`):

- `6x4` → 6 in across, 4 in tall.
- `12x9x2.5` → 12 in long, 9 in deep, 2.5 in tall (rectangles).
- `6x4/4x3` → two tiers: 6×4 bottom, 4×3 on top. Biggest tier always goes at the bottom.
- A 2-tier cake with only one size given: top tier = **72%** of the bottom's width and **90%** of its height.
- A top tier is never wider than **85%** of the tier below; if it is, shrink it to 78%.
- 1 in = 0.0254 m. Model at real scale.

---

## 4. Finish (the `finish` value; matched by keyword, case-insensitive)

| Finish | Frosting surface | Decorations (all in the **accent** colour) |
|---|---|---|
| **Buttercream** (`butter`) | Matte, soft, faint horizontal spatula ridges, slight subsurface glow | **Shell border** around the bottom of every tier + **rosette swirls** all around the top edge of every tier |
| **Semi-fondant** (`semi`) | Smooth, light satin sheen | **Ribbon band** (~0.35 in) around the base of every tier + small **bead border** on the top edge |
| **Fondant** (`fondant`) | Very smooth, satin sheen, rounder top edge | **Ribbon band** around the base of every tier |
| **All Fondant** (`fondant` + `all`) | Same as Fondant | Ribbon band + a **ribbon bow** centred on the front of each tier's band (bow in a slightly darker accent) |
| **Icing / 2D** (`icing` or `2d`) | Smooth piped icing | A flat 2D **star** (front-left, tilted −12°) and **heart** (front-right, tilted +10°) on the side + small **bead border** on top. No bottom trim |
| **Plain** (anything else) | Smooth | **Bead border** around the bottom of every tier |

---

## 5. Colours

Same maths as `applyColors()` in `cakebuilder.js`:

- `base_light` = base mixed 35% toward white (highlights, top surface)
- `base_dark` = base mixed 14% toward black (shading)
- `accent_dark` = accent mixed 20% toward black (bow, rose shading)
- **Message ink** = `accent_dark`, unless its contrast against `base_light` is below 2.2:1 — then use base mixed 65% toward black (light cakes) or white (dark cakes).

Preset names (the customer may also pick any custom hex from the colour wheel):

**Base:** `#FFF4DC` Vanilla Cream · `#FFFFFF` Classic White · `#F9C6D0` Blush Pink · `#FCD5B5` Peach ·
`#FBE7A1` Butter Yellow · `#C6EBD9` Mint · `#C5E1F5` Sky Blue · `#D9C8F0` Lavender · `#B23A48` Red Velvet · `#7B4B36` Chocolate

**Accent:** `#E8738A` Rose · `#D4A63A` Gold · `#4A2C21` Dark Chocolate · `#9B2C5C` Berry · `#2A9D8F` Teal ·
`#9D7BD8` Lilac · `#F08A5D` Coral · `#FFFFFF` Snow White

Hex colours are sRGB. Convert to linear before typing them into a Blender material
(the script does this). Render with the **Standard** view transform: the lights are tuned so a
front-lit frosting surface comes out within a few shades of the picked hex. (AgX looks softer but
washes the colours out, so the customer would not recognise their colour.)

---

## 6. Toppings (max 4)

| Key | Where | How it looks |
|---|---|---|
| `sprinkles` | Sides of **every** tier, and the top of the top tier **unless** there is a chocolate drip | Rainbow jimmies (rods ~0.2 in) with a few round nonpareils. Colours `#FF6B8B #FFD166 #06D6A0 #4CC9F0 #B388EB #FFFFFF`. If the message is on top, keep it clear |
| `strawberries` | Top of top tier, **2 pieces** | One whole (tip up, green leaves at the base), one halved with the pale pink cut face toward the camera. Glossy red, tiny seeds |
| `cherries` | Top, **2 pieces** | Deep glossy red (`#7A0A14`), curved green-brown stem, tilted slightly (−6° / +8°) |
| `macarons` | Top, **2 pieces** | In the **accent** colour, standing on edge leaning back ~20°, cream filling, ruffled "feet" |
| `flowers` | Top: **1 piped rose** · Side: a cascade of **3 small five-petal blossoms + 2 leaves** on the front-right of the top tier | Accent-coloured buttercream; blossoms have yellow centres; green leaves |
| `pearls` | A ring around the bottom of **every** tier, just above the trim (shell/ribbon/beads) | Small ivory pearls (~0.25 in), pearly sheen |
| `chocolate_drip` | **Top tier only** | Glossy dark ganache covering the top, rolled over the edge, ~80% of the edge has drips of random length (up to ~45% of the tier height) with round drops at the ends. Top-edge piping sits **on top of** the drip |
| `gold_leaf` | Top tier: a cluster of ~9 flakes on the front-left of the side, plus 2–3 flakes on top | Irregular, crinkled, metallic gold |
| `candles` | Top centre, **3 candles** | Thin white candles with spiral stripes in the accent colour, lit flames with a warm glow |

**Arrangement on top:** candles go in the middle (a small triangle, or a row if they are the only topping).
The other pieces sit on a ring at ~62% of the radius, spread evenly, starting at the back so
no piece hides another. If the message is on top, the pieces move to the back half.

---

## 7. Message

- Up to 40 characters, in a handwritten script font (Segoe Script / Brush Script / Lucida Handwriting), bold.
- **Default placement (matches the web builder):** piped on the **front side of the top tier**, centred,
  ~42% up the tier (~30% if there is a drip, so the drips don't cover it). Width at most 80% of the tier;
  letters ~32% of the tier height or smaller. On a round cake the letters follow the curve.
- **Optional:** `message_placement: "top"` — flat on the top surface, slightly toward the front, readable from the camera.
- Ink colour: see §5. Thin raised piping (~1 mm).

---

## 8. Scene and render

- **Stand:** white ceramic pedestal by default (options: `silver`, `gold`, `wood`, `none`). Plate is ~1 in wider than the cake on each side. Bento uses its kraft box instead.
- **Backdrop:** seamless curved studio sweep, warm off-white `#EFE8E1`.
- **Lights:** soft 3-point studio light (big warm key from front-left, cool fill from the right, rim from behind) + soft world light. Candles add a small warm point light.
- **Cameras** (front of the cake faces −Y):
  - `hero` — straight-on front, 20° above.
  - `three_quarter` — 35° to the right, 24° above.
  - `top` — 72° above (to show the top decorations).
  - `side` — almost level, 6° above.
- **Output:** 1600×1600 PNG, Cycles 128 samples with denoising (EEVEE for quick previews).

---

## 9. Order notes (things the script does NOT do on its own)

The customer's `notes` field is free text. After running the script, read the notes and:

- **Theme words** (e.g. "unicorn", "dinosaur", "floral", "Frozen theme"): add a small, tasteful topper or
  extra decorations in the order's colours, placed on the top tier. Keep it bakery-realistic (fondant or
  buttercream figures), and do not cover the message.
- **Number / age** (e.g. "turning 7"): add a number candle or fondant number on top.
- **Allergy / kitchen notes** (e.g. "no nuts", "less sweet"): these don't change the look. Ignore them for the render.
- Do not use brand names, logos or copyrighted characters; make a generic version instead
  (e.g. "a cute cartoon mouse" rather than a specific studio's character).
- Put every extra object in the `CakeRender` collection so it is cleared on the next run.
