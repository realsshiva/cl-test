<?php
// Shared choices for the Cake Builder (cakebuilder.php) and the production queue.
// The builder only accepts values listed here, so the queue can trust them.

const CAKE_CATEGORY_ID = 'CAT-CCK';
const CAKE_MAX_TOPPINGS = 4;
const CAKE_MESSAGE_MAX = 40;
const CAKE_MAX_QUANTITY = 50;

// hex => display name
function cakeBaseColors(): array
{
    return [
        '#FFF4DC' => 'Vanilla Cream',
        '#FFFFFF' => 'Classic White',
        '#F9C6D0' => 'Blush Pink',
        '#FCD5B5' => 'Peach',
        '#FBE7A1' => 'Butter Yellow',
        '#C6EBD9' => 'Mint',
        '#C5E1F5' => 'Sky Blue',
        '#D9C8F0' => 'Lavender',
        '#B23A48' => 'Red Velvet',
        '#7B4B36' => 'Chocolate',
    ];
}

function cakeAccentColors(): array
{
    return [
        '#E8738A' => 'Rose',
        '#D4A63A' => 'Gold',
        '#4A2C21' => 'Dark Chocolate',
        '#9B2C5C' => 'Berry',
        '#2A9D8F' => 'Teal',
        '#9D7BD8' => 'Lilac',
        '#F08A5D' => 'Coral',
        '#FFFFFF' => 'Snow White',
    ];
}

// Drip colors (the default drip is chocolate).
function cakeDripColors(): array
{
    return [
        '#FFF8EC' => 'White Chocolate',
        '#C98A3C' => 'Caramel',
        '#E8738A' => 'Strawberry',
        '#D4A63A' => 'Gold',
        '#9B2C5C' => 'Berry',
        '#2A9D8F' => 'Teal',
    ];
}

// Cake stand colors (the default stand is white).
function cakeBoardColors(): array
{
    return [
        '#F2D7DD' => 'Blush',
        '#E6C27A' => 'Gold',
        '#CFD6E0' => 'Silver',
        '#C6EBD9' => 'Mint',
        '#B98B66' => 'Wood',
        '#4A4A52' => 'Charcoal',
    ];
}

// Message colors (the default is picked to read well on the cake).
function cakeInkColors(): array
{
    return [
        '#FFFFFF' => 'White',
        '#4A2C21' => 'Chocolate',
        '#9B2C5C' => 'Berry',
        '#D4A63A' => 'Gold',
        '#E8738A' => 'Rose',
        '#2A9D8F' => 'Teal',
        '#3F51B5' => 'Royal Blue',
    ];
}

// How the cake's own color is used: key => label.
function cakeStyles(): array
{
    return ['solid' => 'One color', 'ombre' => 'Ombre', 'tiers' => 'Two-tone tiers'];
}

// Buttercream top border: key => label.
function cakePipingStyles(): array
{
    return ['rosette' => 'Rosettes', 'shell' => 'Shells', 'bead' => 'Beads'];
}

// Patterns around the sides of the cake, chosen per tier: key => [label, short description].
// Drawn in the side design color (default: the piping & trim color). Same keys as SIDE_DESIGNS in
// blender_cake_kit/cake_builder_blender.py, which models them.
function cakeSideDesigns(): array
{
    return [
        'none'    => ['Plain', 'Smooth sides'],
        'stripes' => ['Stripes', 'Up-and-down stripes'],
        'dots'    => ['Polka dots', 'Rows of little dots'],
        'quilted' => ['Quilted', 'Diamond lattice with beads'],
        'swags'   => ['Swags', 'Draped garlands near the top'],
        'ruffles' => ['Ruffles', 'Rows of soft frills'],
        'zigzag'  => ['Zigzag', 'Piped zigzag lines'],
    ];
}

// Message fonts: key => [label, CSS font-family]. The web fonts are loaded from Google Fonts by the pages.
function cakeFonts(): array
{
    return [
        'script'   => ['Classic Script', "'Segoe Script','Brush Script MT','Lucida Handwriting',cursive"],
        'pacifico' => ['Pacifico', "'Pacifico','Segoe Script',cursive"],
        'dancing'  => ['Dancing Script', "'Dancing Script','Segoe Script',cursive"],
        'playfair' => ['Playfair', "'Playfair Display',Georgia,serif"],
        'fredoka'  => ['Fredoka', "'Fredoka','Trebuchet MS',sans-serif"],
    ];
}

const CAKE_FONTS_URL = 'https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Fredoka:wght@600&family=Pacifico&family=Playfair+Display:wght@700&display=swap';

// Toppings that stand on the cake as separate pieces the customer can move, copy and resize.
const CAKE_PIECE_KEYS = ['strawberries', 'cherries', 'macarons', 'flowers', 'candles'];
const CAKE_MAX_PIECES_EACH = 8;

// Allergens the customer can ask the bakers to avoid: key => label.
function cakeAllergens(): array
{
    return ['nuts' => 'Nuts', 'peanuts' => 'Peanuts', 'gluten' => 'Gluten', 'dairy' => 'Dairy', 'eggs' => 'Eggs', 'soy' => 'Soy'];
}

// Photos customers add to be printed on top of the cake (edible print). The builder uploads a square
// JPEG through cake_photo.php, which stores it here under a random 32-character id.
const CAKE_PHOTO_DIR = __DIR__ . '/uploads/cake-photos';
const CAKE_PHOTO_URL = '/cl-test/invtest/uploads/cake-photos/';

function cakePhotoFile(string $id): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    $file = CAKE_PHOTO_DIR . '/' . $id . '.jpg';
    return is_file($file) ? $file : null;
}

// The design extras with nothing customised.
function cakeDefaultDesign(): array
{
    return [
        'style' => 'solid', 'base2' => '', 'trim' => 'auto', 'drip' => 'auto', 'board' => 'auto', 'ink' => 'auto',
        'sides' => [], 'side' => 'auto',
        'piping' => 'rosette', 'font' => 'script', 'msg' => [0, 0, 1], 'items' => null,
        'finish2' => '', 'photo' => null, 'allergens' => [],
    ];
}

// Checks the design extras sent by the builder and returns a clean copy. Anything unexpected falls back
// to its default, so the saved design can be trusted by the production queue.
//   $in: style, base2, trim, drip, board, ink, side (side design color), sides (side design per tier,
//        bottom tier first), piping, font, finish2, allergens (list) and
//        layout (JSON: {items: [[key,u,v,size]], msg: [dx,dy,size], photo: [id,u,v,size]})
//   $toppings: the topping keys chosen for this cake; $tiers: how many tiers the cake has;
//   $finishes: the finish names this cake's size comes in (for the upper tier's finish).
function cakeCleanDesign(array $in, array $toppings, int $tiers, array $finishes = []): array
{
    $design = cakeDefaultDesign();
    $text = fn(string $key) => is_string($in[$key] ?? null) ? trim($in[$key]) : '';
    $color = function (string $key) use ($text): string {
        $value = strtoupper($text($key));
        return cakeIsColor($value) ? $value : 'auto';
    };
    $number = fn($value, float $min, float $max, float $default) => is_numeric($value) ? round(max($min, min($max, (float)$value)), 3) : $default;

    $style = $text('style');
    $base2 = $color('base2');
    if (isset(cakeStyles()[$style]) && $style !== 'solid' && $base2 !== 'auto' && ($style !== 'tiers' || $tiers > 1)) {
        $design['style'] = $style;
        $design['base2'] = $base2;
    }
    foreach (['trim', 'drip', 'board', 'ink', 'side'] as $key) $design[$key] = $color($key);
    // One side design per tier; a cake with plain sides all round stores none.
    $sides = [];
    for ($i = 0; $i < max(1, min(2, $tiers)); $i++) {
        $key = is_string($in['sides'][$i] ?? null) ? $in['sides'][$i] : 'none';
        $sides[] = isset(cakeSideDesigns()[$key]) ? $key : 'none';
    }
    $design['sides'] = array_diff($sides, ['none']) ? $sides : [];
    if (!$design['sides']) $design['side'] = 'auto';
    if (isset(cakePipingStyles()[$text('piping')])) $design['piping'] = $text('piping');
    if (isset(cakeFonts()[$text('font')])) $design['font'] = $text('font');

    // A different finish on the upper tier: two-tier cakes only, and only one this size is sold in.
    if ($tiers > 1 && in_array($text('finish2'), $finishes, true)) $design['finish2'] = $text('finish2');
    if (is_array($in['allergens'] ?? null)) {
        $design['allergens'] = array_values(array_intersect(array_keys(cakeAllergens()), array_map('strval', $in['allergens'])));
    }

    $layout = json_decode($text('layout'), true);
    if (is_array($layout)) {
        $photo = $layout['photo'] ?? null;
        if (is_array($photo) && is_string($photo[0] ?? null) && cakePhotoFile($photo[0]) !== null) {
            $design['photo'] = [$photo[0], $number($photo[1] ?? 0, -1, 1, 0), $number($photo[2] ?? 0, -1, 1, 0), $number($photo[3] ?? 1, 0.6, 1.6, 1)];
        }
        $msg = $layout['msg'] ?? null;
        if (is_array($msg)) {
            $design['msg'] = [$number($msg[0] ?? 0, -1, 1, 0), $number($msg[1] ?? 0, -1, 1, 0), $number($msg[2] ?? 1, 0.6, 1.6, 1)];
        }
        if (is_array($layout['items'] ?? null)) {
            $items = [];
            $counts = [];
            foreach ($layout['items'] as $item) {
                if (!is_array($item) || !is_string($item[0] ?? null)) continue;
                $key = $item[0];
                if (!in_array($key, CAKE_PIECE_KEYS, true) || !in_array($key, $toppings, true)) continue;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($counts[$key] > CAKE_MAX_PIECES_EACH) continue;
                $items[] = [$key, $number($item[1] ?? 0, -1, 1, 0), $number($item[2] ?? 0, -1, 1, 0), $number($item[3] ?? 1, 0.6, 1.6, 1)];
            }
            $design['items'] = $items;
        }
    }
    return $design;
}

// Reads a saved design (the ccd-Design column). Older cakes have none and get the defaults.
function cakeDecodeDesign(?string $json): array
{
    $saved = $json ? json_decode($json, true) : null;
    return is_array($saved) ? array_merge(cakeDefaultDesign(), $saved) : cakeDefaultDesign();
}

// The side designs of a saved cake as text: "Stripes", or "Bottom tier: Stripes · Top tier: Plain".
// Returns '' when every side is plain.
function cakeSidesText(array $sides): string
{
    $names = array_map(fn($key) => cakeSideDesigns()[$key][0] ?? 'Plain', $sides);
    if (!array_diff($sides, ['none'])) return '';
    if (count($names) < 2) return $names[0];
    if ($names[0] === $names[1]) return $names[0] . ' (both tiers)';
    return 'Bottom tier: ' . $names[0] . ' · Top tier: ' . $names[1];
}

// The pre-rendered Blender image layers (made by blender_cake_kit/render_builder_assets.py),
// or null when they haven't been made; the pages then fall back to the drawn SVG cake.
function cakeRenders(): ?array
{
    $manifestFile = __DIR__ . '/assets/cake-renders/manifest.json';
    if (!is_file($manifestFile)) return null;
    $manifest = json_decode((string)file_get_contents($manifestFile), true);
    if (!is_array($manifest) || empty($manifest['rigs'])) return null;
    return [
        'base' => '/cl-test/invtest/assets/cake-renders/',
        'version' => (string)filemtime($manifestFile),
        'rigs' => $manifest['rigs'],
        'pieces' => $manifest['pieces'] ?? null,
    ];
}

// key => [label, Bootstrap icon]
function cakeToppings(): array
{
    return [
        'sprinkles'      => ['Sprinkles', 'bi-stars'],
        'strawberries'   => ['Strawberries', 'bi-heart-fill'],
        'cherries'       => ['Cherries', 'bi-circle-fill'],
        'macarons'       => ['Macarons', 'bi-hdd-stack-fill'],
        'flowers'        => ['Flowers', 'bi-flower1'],
        'pearls'         => ['Pearls', 'bi-record-circle'],
        'chocolate_drip' => ['Drip', 'bi-droplet-half'],   // chocolate unless a drip color is picked
        'gold_leaf'      => ['Gold Leaf', 'bi-gem'],
        'candles'        => ['Candles', 'bi-fire'],
    ];
}

// How each known Custom Cake recipe is drawn, by recipe id. Listing a recipe here keeps its
// preview right even if it is renamed. Shapes: round, square, rectangle, bento, mini.
function cakeKnownShapes(): array
{
    return [
        'CUS-1TR-MIN' => ['shape' => 'mini', 'tiers' => 1],
        'CUS-1TR-RND' => ['shape' => 'round', 'tiers' => 1],
        'CUS-2TR-MIN' => ['shape' => 'mini', 'tiers' => 2],
        'CUS-2TR-RND' => ['shape' => 'round', 'tiers' => 2],
        'CUS-CHC-BNT' => ['shape' => 'bento', 'tiers' => 1],
        'CUS-RDV-BNT' => ['shape' => 'bento', 'tiers' => 1],
        'CUS-REC-CAK' => ['shape' => 'rectangle', 'tiers' => 1],
        'CUS-SQR-CAK' => ['shape' => 'square', 'tiers' => 1],
    ];
}

// How to draw a recipe: the list above first, otherwise guessed from the name
// so new Custom Cake recipes still show up on their own.
function cakeShapeFor(string $recipeId, string $recipeName): array
{
    $known = cakeKnownShapes();
    if (isset($known[$recipeId])) return $known[$recipeId];

    $name = strtolower($recipeName);
    $tiers = preg_match('/\b(2|two|double)[\s-]*tier/', $name) ? 2 : 1;
    if (strpos($name, 'square') !== false) {
        $shape = 'square';
    } elseif (preg_match('/\brect|\bsheet\b|\bslab\b/', $name)) {
        $shape = 'rectangle';
    } elseif (strpos($name, 'bento') !== false || strpos($name, 'lunchbox') !== false) {
        $shape = 'bento';
    } elseif (strpos($name, 'mini') !== false) {
        $shape = 'mini';
    } else {
        $shape = 'round';
    }
    return ['shape' => $shape, 'tiers' => $tiers];
}

// True when the text has characters the database connection (utf8, 3-byte) can't store, such as emoji.
function cakeHasEmoji(string $value): bool
{
    return (bool)preg_match('/[\x{10000}-\x{10FFFF}]/u', $value);
}

// Turns a stored topping list ("sprinkles,candles") into labels.
function cakeToppingLabels(string $stored): array
{
    $all = cakeToppings();
    $labels = [];
    foreach (array_filter(explode(',', $stored)) as $key) {
        if (isset($all[$key])) $labels[$key] = $all[$key];
    }
    return $labels;
}

// Any 6-digit hex color, e.g. #F9C6D0 (the color wheel can produce any of them).
function cakeIsColor(string $hex): bool
{
    return (bool)preg_match('/^#[0-9A-F]{6}$/', $hex);
}

// Preset name for a color, or a description of a custom one ("Custom, near Rose", "Custom light green").
// cakebuilder.js (describeColor) uses the same rules, so the builder and the queue agree.
function cakeColorName(string $hex, bool $accent = false): string
{
    $hex = strtoupper($hex);
    $own = $accent ? cakeAccentColors() : cakeBaseColors();
    $all = $own + cakeBaseColors() + cakeAccentColors() + cakeDripColors() + cakeBoardColors() + cakeInkColors();
    if (isset($all[$hex])) return $all[$hex];
    if (!cakeIsColor($hex)) return 'Custom';
    return cakeDescribeColor($hex, $all);
}

function cakeHexToRgb(string $hex): array
{
    $n = hexdec(substr($hex, 1));
    return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
}

function cakeDescribeColor(string $hex, array $palette): string
{
    [$r, $g, $b] = cakeHexToRgb($hex);

    // Nearest preset, using the "redmean" distance (closer to how eyes see color than plain RGB).
    $nearest = '';
    $best = INF;
    foreach ($palette as $presetHex => $presetName) {
        [$pr, $pg, $pb] = cakeHexToRgb($presetHex);
        $mean = ($r + $pr) / 2;
        $distance = sqrt((2 + $mean / 256) * ($r - $pr) ** 2 + 4 * ($g - $pg) ** 2 + (2 + (255 - $mean) / 256) * ($b - $pb) ** 2);
        if ($distance < $best) {
            $best = $distance;
            $nearest = $presetName;
        }
    }
    if ($best <= 40) return 'Custom, near ' . $nearest;

    // Otherwise describe it by hue and lightness.
    $rf = $r / 255; $gf = $g / 255; $bf = $b / 255;
    $max = max($rf, $gf, $bf);
    $min = min($rf, $gf, $bf);
    $d = $max - $min;
    $l = ($max + $min) / 2;
    $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
    if ($s < 0.15) {
        if ($l >= 0.92) return 'Custom white';
        if ($l >= 0.7) return 'Custom light gray';
        if ($l >= 0.35) return 'Custom gray';
        if ($l >= 0.15) return 'Custom dark gray';
        return 'Custom black';
    }
    if ($max == $rf) $h = fmod(($gf - $bf) / $d + 6, 6);
    elseif ($max == $gf) $h = ($bf - $rf) / $d + 2;
    else $h = ($rf - $gf) / $d + 4;
    $h *= 60;

    if ($h < 15 || $h >= 345) $hue = $l >= 0.7 ? 'pink' : 'red';
    elseif ($h < 45) $hue = $l < 0.4 ? 'brown' : 'orange';
    elseif ($h < 70) $hue = $l < 0.35 ? 'olive' : 'yellow';
    elseif ($h < 165) $hue = 'green';
    elseif ($h < 195) $hue = 'teal';
    elseif ($h < 255) $hue = 'blue';
    elseif ($h < 290) $hue = 'purple';
    else $hue = 'pink';

    $shade = '';
    if ($hue !== 'brown' && $hue !== 'olive') {
        if ($l >= 0.8) $shade = 'pale ';
        elseif ($l >= 0.62) $shade = 'light ';
        elseif ($l < 0.3) $shade = 'dark ';
    }
    return 'Custom ' . $shade . $hue;
}
