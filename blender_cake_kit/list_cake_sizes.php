<?php
// Lists every Custom Cake shape + size the Cake Builder offers, one per line ("round-1:6x4"),
// so render_builder_assets.py can render an exact image set for each.
// Run: D:\Xampp\php\php.exe list_cake_sizes.php > cake_sizes.txt
require_once __DIR__ . '/../invtest/db.php';
require_once __DIR__ . '/../invtest/cake_options.php';

$sql = 'SELECT DISTINCT r.`rec-Name`, v.`recvar-SizeOrPcs`, r.`rec-Id`
        FROM `m22-recipe_master` r
        JOIN `m222-recipe_variant2` v ON v.`rec-Id` = r.`rec-Id`
        JOIN `m222-recipe_variant3` v3 ON v3.`recvar-Id` = v.`recvar-Id`
        WHERE r.`reccat-Id` = ?';
$stmt = $conn->prepare($sql);
$category = CAKE_CATEGORY_ID;
$stmt->bind_param('s', $category);
$stmt->execute();
$result = $stmt->get_result();

$lines = [];
while ($row = $result->fetch_row()) {
    $size = trim((string)$row[1]);
    if ($size === '') continue;
    ['shape' => $shape, 'tiers' => $tiers] = cakeShapeFor((string)$row[2], (string)$row[0]);
    // Same rule as rigFor() in cakebuilder.js: a "6x4/4x3" label means two tiers.
    $parts = 0;
    foreach (explode('/', $size) as $part) {
        if (preg_match_all('/\d+(?:\.\d+)?/', $part) >= 2) $parts++;
    }
    $tiers = min(2, max($tiers, $parts));
    $lines["$shape-$tiers:$size"] = true;
}
echo implode(PHP_EOL, array_keys($lines)) . PHP_EOL;
