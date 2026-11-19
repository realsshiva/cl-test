<?php
// Downloads a saved custom cake as an order file for the Blender Cake Kit
// (blender_cake_kit/cake_builder_blender.py), so the exact design can be rendered in 3D:
//   blender -b -P cake_builder_blender.py -- cake-order-15.json
// Open: cake_order.php?id=<production batch id>   (the "Blender order file" button in the production queue)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cake_options.php';

$batchId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$cake = null;
if ($batchId !== false && $batchId > 0) {
    $query = $conn->prepare(
        'SELECT b.`prodbatch-Id`, r.`rec-Id`, r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation`,
                d.`ccd-BaseColor`, d.`ccd-AccentColor`, d.`ccd-Toppings`, d.`ccd-Message`, d.`ccd-CustomerName`,
                d.`ccd-PickupDate`, d.`ccd-Notes`, d.`ccd-Design`
         FROM `m3-production_batch` b
         JOIN `m3.1-custom_cake_design` d ON d.`prodbatch-Id` = b.`prodbatch-Id`
         JOIN `m222-recipe_variant2` v ON v.`recvar-Id` = b.`recvar-Id`
         JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
         WHERE b.`prodbatch-Id` = ? LIMIT 1'
    );
    $query->bind_param('i', $batchId);
    $query->execute();
    $cake = $query->get_result()->fetch_assoc();
    $query->close();
}
if (!$cake) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That custom cake could not be found.';
    exit;
}

$design = cakeDecodeDesign($cake['ccd-Design']);
$shape = cakeShapeFor((string)$cake['rec-Id'], (string)$cake['rec-Name']);
$color = fn(string $key) => cakeIsColor((string)$design[$key]) ? $design[$key] : '';
$toppings = array_keys(cakeToppingLabels((string)$cake['ccd-Toppings']));
$photoFile = $design['photo'] ? cakePhotoFile((string)$design['photo'][0]) : null;

$allergens = array_intersect_key(cakeAllergens(), array_flip($design['allergens']));
$notes = trim((string)$cake['ccd-Notes']);
if ($allergens) $notes = trim('Avoid: ' . implode(', ', $allergens) . '. ' . $notes);

$order = [
    'recipe_name' => (string)$cake['rec-Name'],
    'shape' => $shape['shape'],
    'tiers' => $shape['tiers'],
    'size' => trim((string)$cake['recvar-SizeOrPcs']),
    'finish' => trim((string)$cake['recvar-Foundation']) ?: 'Plain',
    'base_color' => (string)$cake['ccd-BaseColor'],
    'accent_color' => (string)$cake['ccd-AccentColor'],
    'toppings' => $toppings,
    'message' => (string)$cake['ccd-Message'],
    'customer' => (string)($cake['ccd-CustomerName'] ?? ''),
    'pickup_date' => (string)($cake['ccd-PickupDate'] ?? ''),
    'notes' => $notes,
    // Design extras (see DEFAULT_ORDER in cake_builder_blender.py).
    'color_style' => $design['style'],
    'base_color2' => $design['style'] !== 'solid' ? $color('base2') : '',
    'trim_color' => $color('trim'),
    'drip_color' => $color('drip'),
    'stand_color' => $color('board'),
    'message_color' => $color('ink'),
    'piping' => $design['piping'],
    'finish_top' => (string)$design['finish2'],
    'font' => $design['font'],
    // Cakes saved before toppings could be moved have no item list; the script then uses its usual spots.
    'layout' => ['msg' => $design['msg']] + ($design['items'] !== null ? ['items' => $design['items']] : []),
    'photo' => $photoFile !== null
        ? ['file' => str_replace('\\', '/', $photoFile), 'u' => $design['photo'][1], 'v' => $design['photo'][2], 's' => $design['photo'][3]]
        : null,
    // More than four toppings never comes from the builder, but keep whatever was saved.
    '_no_limit' => true,
    'render' => [
        'output' => 'C:/CakeRenders/cake-' . (int)$cake['prodbatch-Id'] . '.png',
        'views' => ['hero', 'top'],
    ],
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="cake-order-' . (int)$cake['prodbatch-Id'] . '.json"');
header('Cache-Control: no-store');
echo json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
