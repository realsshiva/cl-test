<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cake_options.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Removes control characters and 4-byte characters (emoji); the connection charset is utf8 (3-byte).
function cleanText(string $value): string
{
    $value = preg_replace('/[\x{0}-\x{1F}\x{7F}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return trim($value);
}

function cleanNotes(string $value): string
{
    $value = preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return trim($value);
}

$baseColors = cakeBaseColors();
$accentColors = cakeAccentColors();
$toppings = cakeToppings();

// ---------- Recipes and variants for the Custom Cake category ----------
// Variant ids come from variant2 (sizes + finishes) and must also exist in variant3,
// because the production batch table has a foreign key to variant3.
$recipes = [];
$load = $conn->prepare(
    'SELECT r.`rec-Id`, r.`rec-Name`, v.`recvar-Id`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation`,
            (SELECT COUNT(*) FROM `m2222-recipe_ingredient` i WHERE i.`recvar-Id` = v.`recvar-Id`) AS ingredientCount
     FROM `m22-recipe_master` r
     JOIN `m222-recipe_variant2` v ON v.`rec-Id` = r.`rec-Id`
     JOIN `m222-recipe_variant3` v3 ON v3.`recvar-Id` = v.`recvar-Id`
     WHERE r.`reccat-Id` = ?
     ORDER BY r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation`'
);
$categoryId = CAKE_CATEGORY_ID;
$load->bind_param('s', $categoryId);
$load->execute();
$result = $load->get_result();
while ($row = $result->fetch_assoc()) {
    $recipeId = (string)$row['rec-Id'];
    if (!isset($recipes[$recipeId])) {
        $recipes[$recipeId] = ['id' => $recipeId, 'name' => (string)$row['rec-Name'], 'sizes' => []]
            + cakeShapeFor((string)$row['rec-Name']);
    }
    $size = trim((string)$row['recvar-SizeOrPcs']);
    $recipes[$recipeId]['sizes'][$size][] = [
        'id' => (string)$row['recvar-Id'],
        'name' => trim((string)$row['recvar-Foundation']) ?: 'Plain',
        'ready' => (int)$row['ingredientCount'] > 0,
    ];
}
$load->close();

// Sort sizes naturally (6x2 before 6x10) and reshape for JavaScript.
foreach ($recipes as &$recipe) {
    uksort($recipe['sizes'], 'strnatcasecmp');
    $sizes = [];
    foreach ($recipe['sizes'] as $label => $finishes) {
        $sizes[] = ['label' => (string)$label, 'finishes' => $finishes];
    }
    $recipe['sizes'] = $sizes;
}
unset($recipe);

// ---------- Save a finished cake ----------
$error = '';
$posted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_custom_cake') {
    $toppingInput = $_POST['toppings'] ?? [];
    $posted = [
        'recipe' => trim((string)($_POST['rec-Id'] ?? '')),
        'size' => trim((string)($_POST['size'] ?? '')),
        'variant' => trim((string)($_POST['recvar-Id'] ?? '')),
        'base' => strtoupper(trim((string)($_POST['base-color'] ?? ''))),
        'accent' => strtoupper(trim((string)($_POST['accent-color'] ?? ''))),
        'toppings' => is_array($toppingInput) ? array_values(array_unique(array_map('strval', $toppingInput))) : [],
        'message' => cleanText((string)($_POST['message'] ?? '')),
        'customer' => cleanText((string)($_POST['customer'] ?? '')),
        'pickup' => trim((string)($_POST['pickup'] ?? '')),
        'notes' => cleanNotes((string)($_POST['notes'] ?? '')),
        'qty' => trim((string)($_POST['batch-quantity'] ?? '1')),
    ];
    $quantity = filter_var($posted['qty'], FILTER_VALIDATE_INT);
    $pickupDate = null;
    if ($posted['pickup'] !== '') {
        $parsed = DateTime::createFromFormat('!Y-m-d', $posted['pickup']);
        if ($parsed && $parsed->format('Y-m-d') === $posted['pickup'] && $parsed >= new DateTime('today')) {
            $pickupDate = $posted['pickup'];
        }
    }

    $selection = null;
    if ($posted['variant'] !== '') {
        $check = $conn->prepare(
            'SELECT r.`rec-Id`, r.`rec-Name`, v.`recvar-SizeOrPcs`, v.`recvar-Foundation`
             FROM `m222-recipe_variant2` v
             JOIN `m222-recipe_variant3` v3 ON v3.`recvar-Id` = v.`recvar-Id`
             JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
             WHERE v.`recvar-Id` = ? AND r.`reccat-Id` = ? LIMIT 1'
        );
        $check->bind_param('ss', $posted['variant'], $categoryId);
        $check->execute();
        $selection = $check->get_result()->fetch_assoc();
        $check->close();
    }

    if (!$selection || $selection['rec-Id'] !== $posted['recipe']) {
        $error = 'Please choose a cake shape, size and finish.';
    } elseif (!cakeIsColor($posted['base']) || !cakeIsColor($posted['accent'])) {
        $error = 'Please choose a cake color and an accent color.';
    } elseif (count($posted['toppings']) > CAKE_MAX_TOPPINGS || array_diff($posted['toppings'], array_keys($toppings))) {
        $error = 'Please choose up to ' . CAKE_MAX_TOPPINGS . ' toppings from the list.';
    } elseif (mb_strlen($posted['message']) > CAKE_MESSAGE_MAX) {
        $error = 'The cake message can be at most ' . CAKE_MESSAGE_MAX . ' characters.';
    } elseif (mb_strlen($posted['customer']) > 60) {
        $error = 'The customer name can be at most 60 characters.';
    } elseif (mb_strlen($posted['notes']) > 500) {
        $error = 'Notes can be at most 500 characters.';
    } elseif ($posted['pickup'] !== '' && $pickupDate === null) {
        $error = 'The pickup date must be today or later.';
    } elseif ($quantity === false || $quantity < 1 || $quantity > CAKE_MAX_QUANTITY) {
        $error = 'Quantity must be a whole number from 1 to ' . CAKE_MAX_QUANTITY . '.';
    } else {
        $parts = trim((string)$selection['rec-Name']) . ' | ' . trim((string)$selection['recvar-SizeOrPcs']);
        $foundation = trim((string)$selection['recvar-Foundation']);
        if ($foundation !== '') $parts .= ' | ' . $foundation;
        $batchName = 'Custom ' . $parts;
        if (mb_strlen($batchName) > 60) $batchName = $parts;
        if (mb_strlen($batchName) > 60) $batchName = mb_substr($batchName, 0, 60);

        $toppingList = implode(',', array_intersect(array_keys($toppings), $posted['toppings']));
        $customer = $posted['customer'] !== '' ? $posted['customer'] : null;
        $notes = $posted['notes'] !== '' ? $posted['notes'] : null;
        $status = 'Pending';

        try {
            $conn->begin_transaction();
            $insert = $conn->prepare('INSERT INTO `m3-production_batch` (`recvar-Id`, `prodbatch-Name`, `prodbatch-Qty`, `prodbatch-Status`) VALUES (?, ?, ?, ?)');
            $insert->bind_param('ssis', $posted['variant'], $batchName, $quantity, $status);
            $insert->execute();
            $batchId = (int)$conn->insert_id;
            $insert->close();

            $design = $conn->prepare(
                'INSERT INTO `m3.1-custom_cake_design`
                 (`prodbatch-Id`, `ccd-BaseColor`, `ccd-AccentColor`, `ccd-Toppings`, `ccd-Message`, `ccd-CustomerName`, `ccd-PickupDate`, `ccd-Notes`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $design->bind_param('isssssss', $batchId, $posted['base'], $posted['accent'], $toppingList, $posted['message'], $customer, $pickupDate, $notes);
            $design->execute();
            $design->close();
            $conn->commit();

            header('Location: prodqueue.php?message=custom_added&selected=' . rawurlencode((string)$batchId));
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $error = 'The cake could not be saved. Please try again.';
        }
    }
}

$steps = [
    ['Shape', 'bi-bounding-box-circles'],
    ['Size', 'bi-arrows-angle-expand'],
    ['Finish', 'bi-brush'],
    ['Colors', 'bi-palette'],
    ['Toppings', 'bi-stars'],
    ['Message', 'bi-chat-heart'],
    ['Review', 'bi-clipboard-check'],
];

// Pre-rendered Blender layers (made by blender_cake_kit/render_builder_assets.py).
// Without them the preview falls back to the drawn SVG cake.
$cakeRenders = null;
$manifestFile = __DIR__ . '/assets/cake-renders/manifest.json';
if (is_file($manifestFile)) {
    $manifest = json_decode((string)file_get_contents($manifestFile), true);
    if (is_array($manifest) && !empty($manifest['rigs'])) {
        $cakeRenders = [
            'base' => '/cl-test/invtest/assets/cake-renders/',
            'version' => (string)filemtime($manifestFile),
            'rigs' => $manifest['rigs'],
        ];
    }
}

$builderData = [
    'renders' => $cakeRenders,
    'recipes' => array_values($recipes),
    'baseColors' => $baseColors,
    'accentColors' => $accentColors,
    'toppings' => array_map(fn($t) => $t[0], $toppings),
    'maxToppings' => CAKE_MAX_TOPPINGS,
    'initial' => $posted,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$value = fn(string $key, string $default = '') => e($posted[$key] ?? $default);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cake Builder</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
    <link rel="stylesheet" href="/cl-test/invtest/cakebuilder.css?v=20261112b">
</head>
<body class="bg-body-tertiary">
<?php readfile(__DIR__ . '/assets/cake-sprite.svg'); ?>
<?php include 'nav.php'; ?>

<main class="app-main">
    <div class="container-fluid py-4 px-3 px-lg-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="/cl-test/invtest/rcategory.php">Recipe Categories</a></li>
                <li class="breadcrumb-item"><a href="/cl-test/invtest/ritem.php?reccat-Id=<?php echo rawurlencode(CAKE_CATEGORY_ID); ?>">Custom Cake</a></li>
                <li class="breadcrumb-item active" aria-current="page">Cake Builder</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="page-title mb-1"><i class="bi bi-cake2 text-danger-emphasis me-2"></i>Cake Builder</h1>
                <p class="text-body-secondary mb-0">Design a custom cake step by step. When it's ready, it goes straight to the production queue.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="/cl-test/invtest/ritem.php?reccat-Id=<?php echo rawurlencode(CAKE_CATEGORY_ID); ?>">
                    <i class="bi bi-journal-richtext me-1"></i>Custom Cake recipes
                </a>
                <button class="btn cb-btn-surprise" type="button" id="cb-surprise"><i class="bi bi-dice-5 me-1"></i>Surprise me</button>
            </div>
        </div>

        <noscript><div class="alert alert-warning">The Cake Builder needs JavaScript turned on.</div></noscript>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if (count($recipes) === 0): ?>
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-body-secondary py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>No Custom Cake recipes with sizes are set up yet.
            </div></div>
        <?php else: ?>
        <form id="cake-form" method="post" action="cakebuilder.php" novalidate>
            <input type="hidden" name="action" value="add_custom_cake">
            <div class="row g-4">
                <!-- Live preview -->
                <div class="col-lg-5">
                    <div class="cb-stage-wrap">
                        <div class="cb-stage" id="cb-stage">
                            <svg id="cb-cake" class="cb-cake" viewBox="0 0 400 360" role="img" aria-labelledby="cb-caption"></svg>
                            <div class="cb-fx" id="cb-fx" aria-hidden="true"></div>
                        </div>
                        <div class="cb-caption" id="cb-caption" aria-live="polite">Pick a shape to start baking!</div>
                        <button class="btn btn-link btn-sm text-body-secondary w-100 mt-1" type="button" id="cb-reset">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Start over
                        </button>
                    </div>
                </div>

                <!-- Steps -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm cb-panel">
                        <div class="card-body p-3 p-md-4">
                            <ol class="cb-steps" id="cb-steps">
                                <?php foreach ($steps as $index => [$label, $icon]): ?>
                                    <li>
                                        <button type="button" class="cb-step-dot" data-goto="<?php echo $index; ?>">
                                            <span class="cb-step-num"><i class="bi <?php echo e($icon); ?>"></i></span>
                                            <span class="cb-step-label"><?php echo e($label); ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <div class="progress cb-progress mb-4" role="progressbar" aria-label="Builder progress" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" id="cb-progress-bar"></div>
                            </div>

                            <!-- 1. Shape -->
                            <section class="cb-step" data-step="0" aria-labelledby="cb-h0">
                                <h2 class="cb-step-title" id="cb-h0">Choose your cake</h2>
                                <p class="cb-step-hint">Every great cake starts with a shape.</p>
                                <div class="cb-grid cb-grid-shapes" role="radiogroup" aria-labelledby="cb-h0">
                                    <?php foreach ($recipes as $recipe): ?>
                                        <?php $inputId = 'rec-' . preg_replace('/[^A-Za-z0-9_-]/', '', $recipe['id']); ?>
                                        <div>
                                            <input class="btn-check" type="radio" name="rec-Id" id="<?php echo e($inputId); ?>" value="<?php echo e($recipe['id']); ?>">
                                            <label class="cb-option cb-shape" for="<?php echo e($inputId); ?>">
                                                <svg class="cb-thumb" viewBox="10 100 380 250" aria-hidden="true" data-shape="<?php echo e($recipe['shape']); ?>" data-tiers="<?php echo e($recipe['tiers']); ?>" data-size="<?php echo e($recipe['sizes'][0]['label'] ?? ''); ?>"></svg>
                                                <span class="cb-option-name"><?php echo e($recipe['name']); ?></span>
                                                <span class="cb-option-meta"><?php echo e($recipe['tiers']); ?> tier<?php echo $recipe['tiers'] > 1 ? 's' : ''; ?> · <?php echo count($recipe['sizes']); ?> size<?php echo count($recipe['sizes']) > 1 ? 's' : ''; ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <!-- 2. Size -->
                            <section class="cb-step" data-step="1" aria-labelledby="cb-h1" hidden>
                                <h2 class="cb-step-title" id="cb-h1">How big?</h2>
                                <p class="cb-step-hint">Sizes are shown as they're listed in the recipe. The preview grows with your choice.</p>
                                <div class="cb-chips" id="cb-sizes" role="radiogroup" aria-labelledby="cb-h1"></div>
                            </section>

                            <!-- 3. Finish -->
                            <section class="cb-step" data-step="2" aria-labelledby="cb-h2" hidden>
                                <h2 class="cb-step-title" id="cb-h2">Pick a finish</h2>
                                <p class="cb-step-hint">How the outside of the cake is covered.</p>
                                <div class="cb-grid cb-grid-finishes" id="cb-finishes" role="radiogroup" aria-labelledby="cb-h2"></div>
                                <div class="alert alert-warning small mt-3 mb-0" id="cb-not-ready" hidden>
                                    <i class="bi bi-info-circle me-1"></i>Ingredients aren't set up for this size and finish yet. You can still queue it, but bakers need to add the ingredients before it can be produced.
                                </div>
                            </section>

                            <!-- 4. Colors -->
                            <section class="cb-step" data-step="3" aria-labelledby="cb-h3" hidden>
                                <h2 class="cb-step-title" id="cb-h3">Make it colorful</h2>
                                <p class="cb-step-hint">Tap a ready-made shade, or drag on the color wheel to mix any color you like.</p>

                                <!-- Which color the wheel edits. These two radios are not sent with the form. -->
                                <div class="cb-target mb-3" role="radiogroup" aria-label="Color to edit">
                                    <input class="btn-check" type="radio" name="cb-target" id="target-base" value="base" form="cb-no-form" checked>
                                    <label class="cb-target-btn" for="target-base"><span class="cb-dot" id="cb-target-base-dot"></span>Cake color</label>
                                    <input class="btn-check" type="radio" name="cb-target" id="target-accent" value="accent" form="cb-no-form">
                                    <label class="cb-target-btn" for="target-accent"><span class="cb-dot" id="cb-target-accent-dot"></span>Accent color</label>
                                </div>

                                <div class="cb-color-layout">
                                    <div class="cb-wheel" id="cb-wheel">
                                        <div class="cb-wheel-shade" id="cb-wheel-shade"></div>
                                        <div class="cb-wheel-handle" id="cb-wheel-handle" role="slider" tabindex="0"
                                             aria-label="Color wheel. Left and right arrows change the hue, up and down change the strength."
                                             aria-valuemin="0" aria-valuemax="360" aria-valuenow="0"></div>
                                    </div>

                                    <div class="cb-color-side">
                                        <label class="cb-sub d-block" for="cb-brightness">Brightness</label>
                                        <input class="form-range cb-brightness mb-3" type="range" id="cb-brightness" min="10" max="100" step="1" value="100" form="cb-no-form">

                                        <label class="cb-sub d-block" for="cb-hex">Color code</label>
                                        <div class="input-group mb-3 cb-hex-group">
                                            <span class="input-group-text"><span class="cb-dot m-0" id="cb-hex-dot"></span></span>
                                            <input class="form-control font-monospace" type="text" id="cb-hex" maxlength="7" spellcheck="false" autocomplete="off" form="cb-no-form" aria-describedby="cb-hex-help">
                                        </div>
                                        <div class="visually-hidden" id="cb-hex-help">Type a 6-digit hex code such as #F9C6D0.</div>

                                        <h3 class="cb-sub" id="cb-preset-label">Ready-made shades</h3>
                                        <?php foreach (['base' => $baseColors, 'accent' => $accentColors] as $group => $palette): ?>
                                            <div class="cb-swatches" data-group="<?php echo $group; ?>" role="radiogroup" aria-labelledby="cb-preset-label"<?php echo $group === 'accent' ? ' hidden' : ''; ?>>
                                                <?php $i = 0; foreach ($palette as $hex => $name): $id = $group . '-' . $i++; ?>
                                                    <input class="btn-check" type="radio" name="<?php echo $group; ?>-color" id="<?php echo $id; ?>" value="<?php echo e($hex); ?>">
                                                    <label class="cb-swatch" for="<?php echo $id; ?>" style="--sw: <?php echo e($hex); ?>" title="<?php echo e($name); ?>">
                                                        <span class="visually-hidden"><?php echo e($name); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                                <!-- Filled in by the color wheel -->
                                                <input class="btn-check cb-custom" type="radio" name="<?php echo $group; ?>-color" id="<?php echo $group; ?>-custom" value="" disabled>
                                                <label class="cb-swatch cb-swatch-custom" for="<?php echo $group; ?>-custom" title="Your color" hidden>
                                                    <span class="visually-hidden">Your color</span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="small text-body-secondary mt-3 mb-0" id="cb-color-names" aria-live="polite"></p>
                            </section>

                            <!-- 5. Toppings -->
                            <section class="cb-step" data-step="4" aria-labelledby="cb-h4" hidden>
                                <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                                    <h2 class="cb-step-title" id="cb-h4">Add toppings</h2>
                                    <span class="badge rounded-pill cb-count" id="cb-topping-count">0/<?php echo CAKE_MAX_TOPPINGS; ?> chosen</span>
                                </div>
                                <p class="cb-step-hint">Choose up to <?php echo CAKE_MAX_TOPPINGS; ?>, or skip this step for a clean look.</p>
                                <div class="cb-chips" role="group" aria-labelledby="cb-h4">
                                    <?php foreach ($toppings as $key => [$label, $icon]): ?>
                                        <input class="btn-check cb-topping" type="checkbox" name="toppings[]" id="top-<?php echo e($key); ?>" value="<?php echo e($key); ?>">
                                        <label class="cb-chip cb-chip-topping" for="top-<?php echo e($key); ?>">
                                            <svg class="cb-chip-art" viewBox="-20 -40 40 44" aria-hidden="true"><use href="#cb-ico-<?php echo e($key); ?>"/></svg><?php echo e($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <!-- 6. Message -->
                            <section class="cb-step" data-step="5" aria-labelledby="cb-h5" hidden>
                                <h2 class="cb-step-title" id="cb-h5">Write a message</h2>
                                <p class="cb-step-hint">Optional. It's piped onto the cake.</p>
                                <label class="form-label" for="cb-message">Cake message</label>
                                <input class="form-control form-control-lg" type="text" id="cb-message" name="message" maxlength="<?php echo CAKE_MESSAGE_MAX; ?>" placeholder="Happy Birthday, Mia!" value="<?php echo $value('message'); ?>" autocomplete="off">
                                <div class="d-flex justify-content-between small text-body-secondary mt-1">
                                    <span>Ideas:
                                        <?php foreach (['Happy Birthday!', 'Congrats!', 'Love you', 'Cheers!'] as $idea): ?>
                                            <button class="btn btn-link btn-sm p-0 align-baseline cb-idea" type="button"><?php echo e($idea); ?></button><span class="cb-sep">·</span>
                                        <?php endforeach; ?>
                                    </span>
                                    <span id="cb-message-count">0/<?php echo CAKE_MESSAGE_MAX; ?></span>
                                </div>
                            </section>

                            <!-- 7. Review -->
                            <section class="cb-step" data-step="6" aria-labelledby="cb-h6" hidden>
                                <h2 class="cb-step-title" id="cb-h6">Ready for the oven?</h2>
                                <p class="cb-step-hint">Check your cake, then send it to the production queue.</p>
                                <dl class="cb-summary" id="cb-summary"></dl>
                                <div class="row g-3 mt-1">
                                    <div class="col-sm-5">
                                        <label class="form-label" for="cb-qty">Quantity</label>
                                        <div class="input-group cb-qty">
                                            <button class="btn btn-outline-secondary" type="button" data-qty="-1" aria-label="Decrease quantity"><i class="bi bi-dash-lg"></i></button>
                                            <input class="form-control text-center" type="number" id="cb-qty" name="batch-quantity" min="1" max="<?php echo CAKE_MAX_QUANTITY; ?>" step="1" value="<?php echo $value('qty', '1'); ?>" required>
                                            <button class="btn btn-outline-secondary" type="button" data-qty="1" aria-label="Increase quantity"><i class="bi bi-plus-lg"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-sm-7">
                                        <label class="form-label" for="cb-pickup">Pickup date <span class="text-body-secondary">(optional)</span></label>
                                        <input class="form-control" type="date" id="cb-pickup" name="pickup" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo $value('pickup'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="cb-customer">Customer name <span class="text-body-secondary">(optional)</span></label>
                                        <input class="form-control" type="text" id="cb-customer" name="customer" maxlength="60" value="<?php echo $value('customer'); ?>" autocomplete="off">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="cb-notes">Notes for the bakers <span class="text-body-secondary">(optional)</span></label>
                                        <textarea class="form-control" id="cb-notes" name="notes" rows="2" maxlength="500" placeholder="Allergies, theme, extra details..."><?php echo $value('notes'); ?></textarea>
                                    </div>
                                </div>
                            </section>

                            <div class="cb-nav">
                                <button class="btn btn-outline-secondary" type="button" id="cb-back"><i class="bi bi-arrow-left me-1"></i>Back</button>
                                <button class="btn btn-primary cb-next" type="button" id="cb-next">Next<i class="bi bi-arrow-right ms-1"></i></button>
                                <button class="btn btn-lg cb-submit" type="submit" id="cb-submit" hidden><i class="bi bi-send-fill me-2"></i>Send to Production Queue</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>

<script type="application/json" id="cb-data"><?php echo json_encode($builderData, $jsonFlags); ?></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="/cl-test/invtest/cakebuilder.js?v=20261111c"></script>
</body>
</html>
