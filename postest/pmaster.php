<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$categoryId = trim($_GET['ptc-Id'] ?? $_POST['ptc-Id'] ?? '');
if ($categoryId === '' || !ctype_digit($categoryId)) {
    http_response_code(400);
    exit('A valid product category is required.');
}

$categoryQuery = $conn->prepare(
    'SELECT `ptc-Id`, `ptc-Category`, `reccat-Id`
     FROM `m10-product_category`
     WHERE `ptc-Id` = ?
     LIMIT 1'
);
$categoryQuery->bind_param('i', $categoryId);
$categoryQuery->execute();
$category = $categoryQuery->get_result()->fetch_assoc();
$categoryQuery->close();

if (!$category) {
    http_response_code(404);
    exit('Product category not found.');
}

$inventoryMode = ($_GET['view'] ?? '') === 'inventory';
$embedded = $inventoryMode && ($_GET['embedded'] ?? '') === '1';
$error = '';
$success = '';
$selectedSource = '';
$selectedRecipeId = '';
$selectedVariantId = '';
$externalName = '';
$externalVariant = '';
$productPrice = '';
$editProductId = '';
$editProductName = '';
$editProductVariant = '';
$editIsExternal = false;
$editingProduct = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'add_product';

    if ($action === 'edit_product') {
        $editingProduct = true;
        $editProductId = trim($_POST['pti-Id'] ?? '');
        $editProductName = trim($_POST['product-name'] ?? '');
        $editProductVariant = trim($_POST['product-variant'] ?? '');
        $productPrice = trim($_POST['price'] ?? '');

        if ($editProductId === '' || !ctype_digit($editProductId)) {
            $error = 'Choose a valid product to edit.';
        } else {
            $existingProductQuery = $conn->prepare(
                'SELECT p.`pti-Id`, p.`pti-Name`, p.`pti-Variant`,
                        EXISTS (
                            SELECT 1
                            FROM `m22-recipe_master` r
                            INNER JOIN `m10-product_category` c ON c.`ptc-Id` = p.`ptc-Id`
                            LEFT JOIN `m222-recipe_variant3` v ON v.`rec-Id` = r.`rec-Id`
                            WHERE r.`reccat-Id` = c.`reccat-Id`
                              AND r.`rec-Name` = p.`pti-Name`
                              AND (
                                  (v.`recvar-Id` IS NULL AND p.`pti-Variant` IS NULL)
                                  OR v.`recvar-Type` <=> p.`pti-Variant`
                              )
                        ) AS is_bakeshop
                 FROM `m10.1-product_information` p
                 WHERE p.`pti-Id` = ? AND p.`ptc-Id` = ?
                 LIMIT 1'
            );
            $existingProductQuery->bind_param('ii', $editProductId, $categoryId);
            $existingProductQuery->execute();
            $existingProduct = $existingProductQuery->get_result()->fetch_assoc();
            $existingProductQuery->close();

            if (!$existingProduct) {
                $error = 'Product not found in this category.';
                $editingProduct = false;
            } else {
                $editIsExternal = !(bool)$existingProduct['is_bakeshop'];
                if ($error === '') {
                    if ($productPrice === '' || !is_numeric($productPrice) || (float)$productPrice < 0 || (float)$productPrice > 99999999.99) {
                        $error = 'Enter a valid product price from 0.00 to 99999999.99.';
                    } elseif ($editIsExternal && ($editProductName === '' || strlen($editProductName) > 50)) {
                        $error = 'Enter a product name no longer than 50 characters.';
                    } elseif ($editIsExternal && strlen($editProductVariant) > 50) {
                        $error = 'Product variant must be no longer than 50 characters.';
                    } else {
                        $price = (float)$productPrice;
                        if ($editIsExternal) {
                            $variantName = $editProductVariant === '' ? null : $editProductVariant;
                            $update = $conn->prepare(
                                'UPDATE `m10.1-product_information`
                                 SET `pti-Name` = ?, `pti-Variant` = ?, `pti-Price` = ?
                                 WHERE `pti-Id` = ? AND `ptc-Id` = ?'
                            );
                            $update->bind_param('ssdii', $editProductName, $variantName, $price, $editProductId, $categoryId);
                        } else {
                            $update = $conn->prepare(
                                'UPDATE `m10.1-product_information`
                                 SET `pti-Price` = ?
                                 WHERE `pti-Id` = ? AND `ptc-Id` = ?'
                            );
                            $update->bind_param('dii', $price, $editProductId, $categoryId);
                        }
                        $update->execute();
                        $update->close();
                        $success = 'Product updated.';
                        $editingProduct = false;
                    }
                }
            }
        }
    } elseif ($action === 'add_product') {
        $selectedSource = $_POST['product-source'] ?? '';
        $productPrice = trim($_POST['price'] ?? '');

        if ($productPrice === '' || !is_numeric($productPrice) || (float)$productPrice < 0 || (float)$productPrice > 99999999.99) {
            $error = 'Enter a valid product price from 0.00 to 99999999.99.';
        } elseif ($selectedSource === 'bakeshop') {
        $selectedRecipeId = trim($_POST['rec-Id'] ?? '');
        $selectedVariantId = trim($_POST['recvar-Id'] ?? '');

        if ($category['reccat-Id'] === null) {
            $error = 'Bakeshop products can only be added to a category linked to a Bakeshop category.';
        } elseif ($selectedRecipeId === '') {
            $error = 'Choose a Bakeshop product name.';
        } else {
            $recipeQuery = $conn->prepare(
                'SELECT `rec-Name`
                 FROM `m22-recipe_master`
                 WHERE `rec-Id` = ? AND `reccat-Id` = ?
                 LIMIT 1'
            );
            $recipeQuery->bind_param('ss', $selectedRecipeId, $category['reccat-Id']);
            $recipeQuery->execute();
            $recipe = $recipeQuery->get_result()->fetch_assoc();
            $recipeQuery->close();

            if (!$recipe) {
                $error = 'The selected Bakeshop product does not belong to this category.';
            } elseif (strlen($recipe['rec-Name']) > 50) {
                $error = 'The selected Bakeshop product name is longer than the product name field allows.';
            } else {
                $variantName = null;
                $variantCountQuery = $conn->prepare(
                    'SELECT COUNT(*) AS variant_count
                     FROM `m222-recipe_variant3`
                     WHERE `rec-Id` = ?'
                );
                $variantCountQuery->bind_param('s', $selectedRecipeId);
                $variantCountQuery->execute();
                $variantCount = (int)$variantCountQuery->get_result()->fetch_assoc()['variant_count'];
                $variantCountQuery->close();

                if ($variantCount > 0 && $selectedVariantId === '') {
                    $error = 'Choose a Bakeshop product variant.';
                } elseif ($selectedVariantId !== '') {
                    $variantQuery = $conn->prepare(
                        'SELECT `recvar-Type`
                         FROM `m222-recipe_variant3`
                         WHERE `recvar-Id` = ? AND `rec-Id` = ?
                         LIMIT 1'
                    );
                    $variantQuery->bind_param('ss', $selectedVariantId, $selectedRecipeId);
                    $variantQuery->execute();
                    $variant = $variantQuery->get_result()->fetch_assoc();
                    $variantQuery->close();

                    if (!$variant) {
                        $error = 'The selected variant does not belong to this Bakeshop product.';
                    } else {
                        $variantName = $variant['recvar-Type'];
                    }
                }

                if ($error === '') {
                    $insert = $conn->prepare(
                        'INSERT INTO `m10.1-product_information`
                            (`ptc-Id`, `pti-Name`, `pti-Variant`, `pti-Price`)
                         VALUES (?, ?, ?, ?)'
                    );
                    $price = (float)$productPrice;
                    $insert->bind_param('issd', $categoryId, $recipe['rec-Name'], $variantName, $price);
                    $insert->execute();
                    $insert->close();
                    $success = 'Product added.';
                }
            }
        }
    } elseif ($selectedSource === 'external') {
        $externalName = trim($_POST['product-name'] ?? '');
        $externalVariant = trim($_POST['product-variant'] ?? '');

        if ($externalName === '' || strlen($externalName) > 50) {
            $error = 'Enter a product name no longer than 50 characters.';
        } elseif (strlen($externalVariant) > 50) {
            $error = 'Product variant must be no longer than 50 characters.';
        } else {
            $variantName = $externalVariant === '' ? null : $externalVariant;
            $insert = $conn->prepare(
                'INSERT INTO `m10.1-product_information`
                    (`ptc-Id`, `pti-Name`, `pti-Variant`, `pti-Price`)
                 VALUES (?, ?, ?, ?)'
            );
            $price = (float)$productPrice;
            $insert->bind_param('issd', $categoryId, $externalName, $variantName, $price);
            $insert->execute();
            $insert->close();
            $success = 'Product added.';
        }
    } else {
        $error = 'Choose a product type.';
    }
    } else {
        $error = 'Invalid product action.';
    }
}

$recipes = [];
$variants = [];
if ($category['reccat-Id'] !== null) {
    $recipeList = $conn->prepare(
        'SELECT `rec-Id`, `rec-Name`
         FROM `m22-recipe_master`
         WHERE `reccat-Id` = ?
         ORDER BY `rec-Name`'
    );
    $recipeList->bind_param('s', $category['reccat-Id']);
    $recipeList->execute();
    $recipeResult = $recipeList->get_result();
    while ($row = $recipeResult->fetch_assoc()) {
        $recipes[] = $row;
    }
    $recipeList->close();

    $variantList = $conn->prepare(
        'SELECT v.`recvar-Id`, v.`rec-Id`, v.`recvar-Type`
         FROM `m222-recipe_variant3` v
         INNER JOIN `m22-recipe_master` r ON r.`rec-Id` = v.`rec-Id`
         WHERE r.`reccat-Id` = ?
         ORDER BY v.`recvar-Type`'
    );
    $variantList->bind_param('s', $category['reccat-Id']);
    $variantList->execute();
    $variantResult = $variantList->get_result();
    while ($row = $variantResult->fetch_assoc()) {
        $variants[] = $row;
    }
    $variantList->close();
}

$productsQuery = $conn->prepare(
    'SELECT p.`pti-Id`, p.`pti-Photo`, p.`pti-Name`, p.`pti-Variant`, p.`pti-Price`,
            NOT EXISTS (
                SELECT 1
                FROM `m22-recipe_master` r
                INNER JOIN `m10-product_category` c ON c.`ptc-Id` = p.`ptc-Id`
                LEFT JOIN `m222-recipe_variant3` v ON v.`rec-Id` = r.`rec-Id`
                WHERE r.`reccat-Id` = c.`reccat-Id`
                  AND r.`rec-Name` = p.`pti-Name`
                  AND (
                      (v.`recvar-Id` IS NULL AND p.`pti-Variant` IS NULL)
                      OR v.`recvar-Type` <=> p.`pti-Variant`
                  )
            ) AS is_external
     FROM `m10.1-product_information` p
     WHERE p.`ptc-Id` = ?
     ORDER BY p.`pti-Name`, p.`pti-Variant`'
);
$productsQuery->bind_param('i', $categoryId);
$productsQuery->execute();
$productsResult = $productsQuery->get_result();
$products = [];
while ($row = $productsResult->fetch_assoc()) {
    $products[] = $row;
}
$productsQuery->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($category['ptc-Category']) ?> Products</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
    <?php if (!$embedded): ?>
        <?php include __DIR__ . '/pos_nav.php'; ?>
    <?php endif; ?>
    <?php $categoryListUrl = $inventoryMode ? 'pcat.php?view=inventory' . ($embedded ? '&amp;embedded=1' : '') : 'pcat.php'; ?>

    <main class="<?php echo $embedded ? '' : 'app-main'; ?>">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-2">
                    <?php if (!$inventoryMode): ?>
                        <li class="breadcrumb-item"><a href="/cl-test/postest/settings.php">Settings</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item"><a href="<?= $categoryListUrl ?>">Product Categories</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($category['ptc-Category']) ?></li>
                </ol>
            </nav>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1"><?= e($category['ptc-Category']) ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $inventoryMode ? 'Choose a product to see its stock batches.' : 'Click a product to edit it.' ?>
                        <span class="badge rounded-pill text-bg-light border ms-1"><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></span>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($inventoryMode): ?>
                        <a class="btn btn-outline-secondary" href="pcat.php?view=inventory<?php echo $embedded ? '&amp;embedded=1' : ''; ?>"><i class="bi bi-arrow-left me-1"></i>Product Categories</a>
                    <?php else: ?>
                        <a class="btn btn-outline-secondary" href="pcat.php"><i class="bi bi-arrow-left me-1"></i>Back</a>
                        <button class="btn btn-primary" id="open-product-form" type="button" data-bs-toggle="modal" data-bs-target="#product-dialog"><i class="bi bi-plus-lg me-1"></i>Add Product</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($products): ?>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-4 g-4">
                    <?php foreach ($products as $product): ?>
                        <div class="col">
                            <a
                                href="<?= $inventoryMode
                                    ? 'pinfo.php?pti-Id=' . rawurlencode((string)$product['pti-Id']) . '&amp;ptc-Id=' . rawurlencode((string)$categoryId) . ($embedded ? '&amp;embedded=1' : '')
                                    : '#edit-product-dialog' ?>"
                                class="product-card card card-hover h-100 border-0 shadow-sm text-decoration-none overflow-hidden"
                                data-product-id="<?= e($product['pti-Id']) ?>"
                                data-product-name="<?= e($product['pti-Name']) ?>"
                                data-product-variant="<?= e($product['pti-Variant'] ?? '') ?>"
                                data-product-price="<?= e($product['pti-Price']) ?>"
                                data-is-external="<?= $product['is_external'] ? '1' : '0' ?>"
                            >
                                <?php if ($product['pti-Photo'] !== null && $product['pti-Photo'] !== ''): ?>
                                    <img class="product-photo card-img-top bg-body-secondary" src="<?= e($product['pti-Photo']) ?>" alt="">
                                <?php else: ?>
                                    <div class="product-photo card-img-top bg-body-secondary d-flex align-items-center justify-content-center text-body-tertiary" aria-hidden="true">
                                        <i class="bi bi-image fs-1"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                        <h3 class="h6 fw-semibold text-body mb-0"><?= e($product['pti-Name']) ?></h3>
                                        <?php if ($product['is_external']): ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis flex-shrink-0">External</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary-subtle text-primary-emphasis flex-shrink-0">Bakeshop</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($product['pti-Variant'] !== null && $product['pti-Variant'] !== ''): ?>
                                        <p class="small text-body-secondary mb-2"><?= e($product['pti-Variant']) ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex align-items-center justify-content-between mt-auto pt-2">
                                        <span class="fs-5 fw-semibold text-body"><?= e(number_format((float)$product['pti-Price'], 2)) ?></span>
                                        <small class="text-primary">
                                            <?= $inventoryMode ? 'View stock' : 'Edit' ?> <i class="bi bi-chevron-right card-arrow d-inline-block"></i>
                                        </small>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-body-secondary py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>No products have been added to this category yet.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade" id="product-dialog" tabindex="-1" aria-labelledby="dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="dialog-title">Add Product</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_product">
                        <input type="hidden" name="ptc-Id" value="<?= e($categoryId) ?>">

                        <p class="form-label mb-2">Product type</p>
                        <div class="list-group mb-3">
                            <label class="list-group-item list-group-item-action d-flex gap-3 py-3">
                                <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="product-source" value="bakeshop" required <?= $selectedSource === 'bakeshop' ? 'checked' : '' ?>>
                                <span>
                                    <span class="d-block fw-semibold">From Bakeshop</span>
                                    <small class="text-body-secondary">Pick a recipe made by the bakeshop.</small>
                                </span>
                            </label>
                            <label class="list-group-item list-group-item-action d-flex gap-3 py-3">
                                <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="product-source" value="external" required <?= $selectedSource === 'external' ? 'checked' : '' ?>>
                                <span>
                                    <span class="d-block fw-semibold">New External Product</span>
                                    <small class="text-body-secondary">Type in a product bought from elsewhere.</small>
                                </span>
                            </label>
                        </div>

                        <div class="mb-3" id="bakeshop-fields" hidden>
                            <label for="rec-Id" class="form-label">Product Name</label>
                            <select id="rec-Id" class="form-select" name="rec-Id" disabled>
                                <option value="">Choose a product</option>
                                <?php foreach ($recipes as $recipe): ?>
                                    <option value="<?= e($recipe['rec-Id']) ?>" <?= $selectedRecipeId === $recipe['rec-Id'] ? 'selected' : '' ?>>
                                        <?= e($recipe['rec-Name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="recvar-Id" class="form-label mt-3">Product Variant</label>
                            <select id="recvar-Id" class="form-select" name="recvar-Id" disabled>
                                <option value="">Choose a variant</option>
                            </select>
                        </div>
                        <div class="mb-3" id="external-fields" hidden>
                            <label for="product-name" class="form-label">Product Name</label>
                            <input id="product-name" class="form-control" name="product-name" type="text" maxlength="50" value="<?= e($externalName) ?>" disabled>

                            <label for="product-variant" class="form-label mt-3">Product Variant <span class="text-body-secondary">(optional)</span></label>
                            <input id="product-variant" class="form-control" name="product-variant" type="text" maxlength="50" value="<?= e($externalVariant) ?>" disabled>
                        </div>
                        <div>
                            <label for="price" class="form-label">Set Price</label>
                            <input id="price" class="form-control" name="price" type="number" min="0" max="99999999.99" step="0.01" value="<?= e($productPrice) ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" id="cancel-product-form" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="edit-product-dialog" tabindex="-1" aria-labelledby="edit-dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="edit-dialog-title">Edit Product</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_product">
                        <input type="hidden" name="ptc-Id" value="<?= e($categoryId) ?>">
                        <input type="hidden" id="edit-product-id" name="pti-Id" value="<?= e($editProductId) ?>">
                        <div class="alert alert-info small py-2" id="edit-bakeshop-note" <?= $editIsExternal || !$editingProduct ? 'hidden' : '' ?>>
                            <i class="bi bi-info-circle me-1"></i>Bakeshop products take their name and variant from the recipe. Only the price can be changed.
                        </div>
                        <div class="mb-3">
                            <label for="edit-product-name" class="form-label">Product Name</label>
                            <input
                                id="edit-product-name"
                                class="form-control"
                                name="product-name"
                                type="text"
                                maxlength="50"
                                value="<?= e($editProductName) ?>"
                                <?= $editIsExternal ? 'required' : 'readonly' ?>
                            >
                        </div>
                        <div class="mb-3">
                            <label for="edit-product-variant" class="form-label">Product Variant <span class="text-body-secondary">(optional)</span></label>
                            <input
                                id="edit-product-variant"
                                class="form-control"
                                name="product-variant"
                                type="text"
                                maxlength="50"
                                value="<?= e($editProductVariant) ?>"
                                <?= $editIsExternal ? '' : 'readonly' ?>
                            >
                        </div>
                        <div>
                            <label for="edit-product-price" class="form-label">Set Price</label>
                            <input
                                id="edit-product-price"
                                class="form-control"
                                name="price"
                                type="number"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                value="<?= e($productPrice) ?>"
                                required
                            >
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" id="cancel-edit-product" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Bootstrap modal instances replace the old <dialog> showModal()/close() calls.
        const dialog = bootstrap.Modal.getOrCreateInstance(document.getElementById('product-dialog'));
        const editDialog = bootstrap.Modal.getOrCreateInstance(document.getElementById('edit-product-dialog'));
        const bakeshopFields = document.getElementById('bakeshop-fields');
        const externalFields = document.getElementById('external-fields');
        const recipeSelect = document.getElementById('rec-Id');
        const variantSelect = document.getElementById('recvar-Id');
        const externalNameInput = document.getElementById('product-name');
        const externalVariantInput = document.getElementById('product-variant');
        const variants = <?= json_encode($variants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const selectedVariantId = <?= json_encode($selectedVariantId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateVariants() {
            const recipeId = recipeSelect.value;
            const matchingVariants = variants.filter((variant) => variant['rec-Id'] === recipeId);
            variantSelect.replaceChildren(new Option('Choose a variant', ''));
            matchingVariants.forEach((variant) => {
                variantSelect.add(new Option(variant['recvar-Type'] || 'Variant', variant['recvar-Id']));
            });
            variantSelect.disabled = recipeSelect.disabled || recipeId === '';
            variantSelect.required = matchingVariants.length > 0;
            if (matchingVariants.some((variant) => variant['recvar-Id'] === selectedVariantId)) {
                variantSelect.value = selectedVariantId;
            }
        }

        function updateSourceFields(source) {
            const fromBakeshop = source === 'bakeshop';
            bakeshopFields.hidden = !fromBakeshop;
            externalFields.hidden = fromBakeshop;
            recipeSelect.disabled = !fromBakeshop;
            externalNameInput.disabled = fromBakeshop;
            externalVariantInput.disabled = fromBakeshop;
            externalNameInput.required = !fromBakeshop;
            updateVariants();
        }

        const inventoryMode = <?= json_encode($inventoryMode) ?>;
        if (!inventoryMode) {
            document.querySelectorAll('.product-card').forEach((card) => {
                card.addEventListener('click', (event) => {
                    event.preventDefault();
                    document.getElementById('edit-product-id').value = card.dataset.productId;
                    document.getElementById('edit-product-name').value = card.dataset.productName;
                    document.getElementById('edit-product-variant').value = card.dataset.productVariant;
                    document.getElementById('edit-product-price').value = card.dataset.productPrice;
                    const isExternal = card.dataset.isExternal === '1';
                    document.getElementById('edit-product-name').readOnly = !isExternal;
                    document.getElementById('edit-product-variant').readOnly = !isExternal;
                    document.getElementById('edit-bakeshop-note').hidden = isExternal;
                    editDialog.show();
                });
            });
            recipeSelect.addEventListener('change', updateVariants);
            document.querySelectorAll('input[name="product-source"]').forEach((choice) => {
                choice.addEventListener('change', () => updateSourceFields(choice.value));
            });

            updateVariants();
            const checkedSource = document.querySelector('input[name="product-source"]:checked');
            if (checkedSource) {
                updateSourceFields(checkedSource.value);
            }
        }
        <?php if ($error !== ''): ?>
        <?php if ($editingProduct): ?>
        editDialog.show();
        <?php else: ?>
        dialog.show();
        <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>
