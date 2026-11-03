<?php
require_once __DIR__ . '/../invtest/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';
$inventoryMode = ($_GET['view'] ?? '') === 'inventory';
$embedded = $inventoryMode && ($_GET['embedded'] ?? '') === '1';
$categorySource = '';
$recipeCategoryId = '';
$externalCategory = '';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    $categorySource = $_POST['category-source'] ?? '';

    if ($categorySource === 'bakeshop') {
        $recipeCategoryId = trim($_POST['reccat-Id'] ?? '');
        if ($recipeCategoryId === '') {
            $error = 'Choose a Bakeshop category.';
        } else {
            $categoryQuery = $conn->prepare(
                'SELECT `reccat-Name` FROM `m2-recipe_category` WHERE `reccat-Id` = ? LIMIT 1'
            );
            $categoryQuery->bind_param('s', $recipeCategoryId);
            $categoryQuery->execute();
            $categoryResult = $categoryQuery->get_result();
            $recipeCategory = $categoryResult->fetch_assoc();

            if (!$recipeCategory) {
                $error = 'The selected Bakeshop category does not exist.';
            } else {
                $categoryName = $recipeCategory['reccat-Name'];
                $insert = $conn->prepare(
                    'INSERT INTO `m10-product_category` (`reccat-Id`, `ptc-Category`) VALUES (?, ?)'
                );
                $insert->bind_param('ss', $recipeCategoryId, $categoryName);
                $insert->execute();
                $success = 'Product category added.';
            }
        }
    } elseif ($categorySource === 'external') {
        $externalCategory = trim($_POST['category'] ?? '');
        if ($externalCategory === '') {
            $error = 'Enter a category name.';
        } else {
            $insert = $conn->prepare(
                'INSERT INTO `m10-product_category` (`reccat-Id`, `ptc-Category`) VALUES (NULL, ?)'
            );
            $insert->bind_param('s', $externalCategory);
            $insert->execute();
            $success = 'Product category added.';
        }
    } else {
        $error = 'Choose a category type.';
    }
}

$recipeCategories = [];
$result = $conn->query(
    'SELECT `reccat-Id`, `reccat-Name` FROM `m2-recipe_category` ORDER BY `reccat-Name`'
);
while ($row = $result->fetch_assoc()) {
    $recipeCategories[] = $row;
}

$productCategories = [];
$result = $conn->query(
    'SELECT `ptc-Id`, `ptc-Category` FROM `m10-product_category` ORDER BY `ptc-Category`'
);
while ($row = $result->fetch_assoc()) {
    $productCategories[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Categories</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
    <?php if (!$embedded): ?>
        <?php include __DIR__ . '/pos_nav.php'; ?>
    <?php endif; ?>

    <main class="<?php echo $embedded ? '' : 'app-main'; ?>">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <?php if (!$inventoryMode): ?>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb small mb-2">
                        <li class="breadcrumb-item"><a href="/cl-test/postest/settings.php">Settings</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Products</li>
                    </ol>
                </nav>
            <?php endif; ?>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="<?php echo $embedded ? 'h5 fw-semibold' : 'page-title'; ?> mb-1"><?= $inventoryMode && !$embedded ? 'Inventory' : 'Product Category' ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $inventoryMode ? 'Choose a category to see its products and stock.' : 'Choose a category to manage its products and prices.' ?>
                    </p>
                </div>
                <button class="btn btn-primary" id="open-category-form" type="button" data-bs-toggle="modal" data-bs-target="#category-dialog">
                    <i class="bi bi-plus-lg me-1"></i>Add Product Category
                </button>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($productCategories): ?>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-4">
                    <?php foreach ($productCategories as $category): ?>
                        <div class="col">
                            <a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/postest/pmaster.php?ptc-Id=<?= rawurlencode((string)$category['ptc-Id']) ?><?= $inventoryMode ? '&amp;view=inventory' . ($embedded ? '&amp;embedded=1' : '') : '' ?>">
                                <div class="card-body d-flex align-items-center gap-3">
                                    <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-tags"></i></span>
                                    <div class="flex-grow-1 min-w-0">
                                        <h2 class="h6 fw-semibold text-body mb-1 text-truncate"><?= e($category['ptc-Category']) ?></h2>
                                        <small class="text-body-secondary"><?= $inventoryMode ? 'View stock' : 'View products' ?></small>
                                    </div>
                                    <i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-body-secondary py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>No product categories yet.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade" id="category-dialog" tabindex="-1" aria-labelledby="dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="dialog-title">Add Product Category</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="form-label mb-2">Category type</p>
                        <div class="list-group mb-3">
                            <label class="list-group-item list-group-item-action d-flex gap-3 py-3">
                                <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="category-source" value="bakeshop" required>
                                <span>
                                    <span class="d-block fw-semibold">From Bakeshop</span>
                                    <small class="text-body-secondary">Link to an existing recipe category.</small>
                                </span>
                            </label>
                            <label class="list-group-item list-group-item-action d-flex gap-3 py-3">
                                <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="category-source" value="external" required>
                                <span>
                                    <span class="d-block fw-semibold">New External Category</span>
                                    <small class="text-body-secondary">For products not made by the bakeshop.</small>
                                </span>
                            </label>
                        </div>

                        <div id="bakeshop-field" hidden>
                            <label for="reccat-Id" class="form-label">Bakeshop Category</label>
                            <select id="reccat-Id" class="form-select" name="reccat-Id" disabled>
                                <option value="">Choose a category</option>
                                <?php foreach ($recipeCategories as $category): ?>
                                    <option value="<?= e($category['reccat-Id']) ?>"><?= e($category['reccat-Name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="external-field" hidden>
                            <label for="category" class="form-label">Category</label>
                            <input id="category" class="form-control" name="category" type="text" maxlength="30" disabled>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" id="cancel-category-form" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Opening and closing the dialog is handled by Bootstrap (data-bs-toggle / data-bs-dismiss).
        const bakeshopField = document.getElementById('bakeshop-field');
        const externalField = document.getElementById('external-field');
        const bakeshopSelect = document.getElementById('reccat-Id');
        const externalInput = document.getElementById('category');

        document.querySelectorAll('input[name="category-source"]').forEach((choice) => {
            choice.addEventListener('change', () => {
                const fromBakeshop = choice.value === 'bakeshop' && choice.checked;
                bakeshopField.hidden = !fromBakeshop;
                bakeshopSelect.disabled = !fromBakeshop;
                bakeshopSelect.required = fromBakeshop;
                externalField.hidden = fromBakeshop;
                externalInput.disabled = fromBakeshop;
                externalInput.required = !fromBakeshop;
            });
        });
    </script>
</body>
</html>
