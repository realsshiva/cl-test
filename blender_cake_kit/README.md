# Blender Cake Kit

Turns a Cake Builder order (shape, size, finish, colours, toppings, message, notes) into a
3D model and a product render in Blender. You can drive it from the Claude Desktop app,
or run it yourself.

See `sample_renders/` for what the five example orders look like.

## What's in the folder

| File | What it is |
|---|---|
| `PROMPT.md` | The prompt to paste into Claude Desktop, with an order form at the bottom |
| `CAKE_DESIGN_SPEC.md` | Every Cake Builder option and how it should look in 3D. Attach it for Claude |
| `cake_builder_blender.py` | The Blender script that builds and renders the cake |
| `render_builder_assets.py`, `build_builder_images.bat`, `list_cake_sizes.php`, `cake_sizes.txt` | Make the web Cake Builder's 3D images (see below) |
| `orders/order_template.json` | Blank order to copy |
| `orders/example_*.json` | Five tested example orders (one per shape) |
| `sample_renders/` | Renders of the examples, for reference |

## 3D images for the web Cake Builder

The Cake Builder page (`invtest/cakebuilder.php`) shows a 3D-rendered cake preview built from
pre-rendered image layers in `invtest/assets/cake-renders/`. Each cake part is its own
see-through image: stand, cake body, finish decorations, each topping. Parts that take the
customer's colours are rendered white, and the page tints them live. The message is live text.

| File | What it does |
|---|---|
| `build_builder_images.bat` | **Double-click to rebuild all the images.** Reads the cake sizes from the database, then renders. About an hour with the GPU |
| `list_cake_sizes.php` | Lists every shape + size the builder offers (writes `cake_sizes.txt`) |
| `render_builder_assets.py` | The Blender script that renders the layers and writes `manifest.json` |

- **Run it again whenever you add or change a Custom Cake size.** Until you do, a new size uses
  the generic image for its shape, scaled to roughly the right width, so its height won't be exact.
- If `invtest/assets/cake-renders/manifest.json` is missing, the builder falls back to its old drawn cake.
- To re-render only some shapes: `build_builder_images.bat --rigs none` skips the generic shapes.
  Or run Blender directly with `--sizes round-1:6x4` and `--sizes-file ""` for a single size.
- Toppings sit in fixed spots so any mix of them fits together: candles in the middle, rose at the back,
  macarons back-left and back-right, strawberries at the sides, cherries at the front.
  These one-image-per-topping layers are only a fallback now: see "single topping pieces" below.
- **Watch the progress** at <http://localhost/cl-test/blender_cake_kit/render_status.php>. The renderer writes
  `render_progress.json` as it goes, so the bar is right for partial runs too.

### What gets rendered for the customisable options

| Images | Used for |
|---|---|
| `pieces/<view>/*.webp` | **Single topping pieces** (one strawberry, cherry, macaron, rose, candle) per camera angle (`round`, `box`, `bento`). The page places them wherever the customer drags them. Made by a full run, or on their own with `--pieces only` |
| `drip-tint.webp` | The drip in white, so the page can give it any colour. `drip.webp` is still the real chocolate one |
| `side-<design>-t1.webp`, `side-<design>-t2.webp` | **Side designs** (stripes, dots, quilted, swags, ruffles, zigzag) in white, one image per tier (`t1` = bottom). The page tints them with the side design colour. Only these: `--only "side-*"` |
| `body-<surface>-top.webp` | The upper tier on its own (two-tier shapes), for two-tone tiers |
| `finish-buttercream-shell*.webp`, `finish-buttercream-bead*.webp` | The other buttercream top borders (`PIPING_STYLES` in `render_builder_assets.py`) |

| `finish-<finish>-t1.webp`, `finish-<finish>-t2[-drip].webp` | Two-tier shapes: each tier's decorations on their own, so the upper tier can have a different finish |

Each shape's entry in `manifest.json` also has `top`: where the top of the cake is on screen, which is how the
page turns a dragged position into a spot on the cake. Stand colour, trim colour and ombre need no extra
images; the page tints the existing ones.

- To add only new kinds of image to every shape without redoing the rest:
  `build_builder_images.bat --only drip-tint,body-buttercream-top` (list the image names; `*` is a
  wildcard, e.g. `--only "finish-*-t1,finish-*-t2*"`).
- `--only none` renders nothing and just refreshes each shape's measurements in the manifest.

## Using it with the Claude Desktop app

1. **Start Blender** (`D:\SteamLibrary\steamapps\common\Blender\blender.exe`).
2. **Connect the Blender MCP add-on:** in the 3D viewport press `N`, open the **BlenderMCP** tab,
   and click **Connect to Claude**. (If you don't see the tab, install `addon.py` from the
   blender-mcp project: Edit → Preferences → Add-ons → Install from Disk.)
3. In Claude Desktop, check that the **blender** connector is switched on (the tools icon in the chat box).
4. Best setup: make a **Project** in Claude Desktop called "Cake Renders".
   - Paste the prompt from `PROMPT.md` (the part between the lines, without the ORDER block)
     into the project's **instructions**.
   - Add `CAKE_DESIGN_SPEC.md` to the project's **files**.
   - Then each new chat only needs the order (see "Shorter message" in `PROMPT.md`).

   Or, without a project: start a new chat, attach `CAKE_DESIGN_SPEC.md`, and paste the whole
   prompt with the order filled in.
5. Claude builds the cake, checks it with a screenshot, adds any theme details from the
   notes, and saves the PNGs to the `output` path (for example `C:/CakeRenders/...`).

> Claude reads the script straight from `D:\Xampp\htdocs\cl-test\blender_cake_kit\`. If you move
> this folder, update the path in `PROMPT.md`.

## Using it without Claude

**In Blender:** Scripting tab → Open → `cake_builder_blender.py` → edit `DEFAULT_ORDER` near the
top → Run Script. The cake appears in a `CakeRender` collection. Press F12 to render.

**From a terminal** (no window, writes PNGs):

```
"D:\SteamLibrary\steamapps\common\Blender\blender.exe" -b -P cake_builder_blender.py -- orders\example_square_fondant.json --render C:\CakeRenders\square.png --views hero,top --samples 128
```

Options: `--render <png>`, `--views hero,three_quarter,top,side`, `--samples N`,
`--engine EEVEE` (fast preview), `--save <file.blend>`.

## Rendering a cake saved in the web Cake Builder

In the production queue, open a custom cake and click **Blender order file**. It downloads
`cake-order-<number>.json` with the whole design: colours (second colour, trim, drip, stand, message),
piping style, upper tier finish, lettering, where each topping and the message were placed, and the
photo for the top, and the side design of each tier. Render it like any other order:

```
"D:\SteamLibrary\steamapps\common\Blender\blender.exe" -b -P cake_builder_blender.py -- cake-order-17.json --render C:\CakeRenders\cake-17.png --views hero,top
```

The message fonts other than the script one are Google Fonts (Pacifico, Dancing Script, Playfair Display,
Fredoka). Install them in Windows, or put their `.ttf` files in a `fonts` folder next to the script;
without them the script font is used.

## Order fields

Same names as the Cake Builder. See `CAKE_DESIGN_SPEC.md` §1 for the details. The optional design
extras (`color_style`, `base_color2`, `trim_color`, `drip_color`, `stand_color`, `message_color`, `piping`,
`finish_top`, `side_designs`, `side_color`, `font`, `layout`, `photo`) are described next to `DEFAULT_ORDER`
in `cake_builder_blender.py`.

```json
{
  "recipe_name": "Round 2-Tier Custom Cake",
  "size": "8x4/6x3",
  "finish": "Buttercream",
  "base_color": "#F9C6D0",
  "accent_color": "#D4A63A",
  "toppings": ["chocolate_drip", "macarons", "flowers", "gold_leaf"],
  "message": "Happy Birthday, Mia!",
  "notes": "Pastel theme",
  "render": { "output": "C:/CakeRenders/mia.png", "views": ["hero", "top"], "stand": "white", "message_placement": "front" }
}
```

## Notes

- Tested on Blender 5.3 (Steam) and written to work on 4.2 or later.
- Re-running replaces only the `CakeRender` collection. Other objects in your scene are hidden,
  not deleted. Set `"hide_other_objects": false` to keep them visible.
- Colours: the lights are tuned so frosting renders close to the exact hex the customer picked
  ("Standard" colour mode). For a softer, more photographic look, set `"color_view": "AgX"`
  in `render`. Colours will be paler.
- The message font is Segoe Script (comes with Windows). If it's missing, Blender's default font is used.
- Cycles uses your GPU automatically if it finds one (NVIDIA OptiX/CUDA, AMD HIP, Intel oneAPI).
  A "HIPEW initialization failed" warning just means there's no AMD GPU. You can ignore it.
- The script does not act on the `notes` field. Claude reads the notes and adds theme details by
  hand (see the spec, §9).
