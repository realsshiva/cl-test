<?php
require_once __DIR__ . '/db.php';

// Accept category id as string (ingcat-Id is varchar in your schema)
$categoryId = isset($_GET['ingcat-Id']) ? $_GET['ingcat-Id'] : '';

// Handle form submission to add a new item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $newId = isset($_POST['ingmaster-Id']) ? trim($_POST['ingmaster-Id']) : '';
    $newCat = isset($_POST['ingcat-Id']) ? trim($_POST['ingcat-Id']) : '';
    $newName = isset($_POST['ingmaster-Name']) ? trim($_POST['ingmaster-Name']) : '';
    $newUnit = isset($_POST['ingmaster-UnitType']) ? trim($_POST['ingmaster-UnitType']) : '';
    $newThreshold = isset($_POST['ingmaster-Threshold']) ? trim($_POST['ingmaster-Threshold']) : '';

    if ($newId !== '' && $newCat !== '' && $newName !== '') {
        $ins = $conn->prepare('INSERT INTO `m11-ingredient_master` (`ingmaster-Id`,`ingcat-Id`,`ingmaster-Name`,`ingmaster-UnitType`,`ingmaster-Threshold`) VALUES (?,?,?,?,?)');
        $ins->bind_param('sssss', $newId, $newCat, $newName, $newUnit, $newThreshold);
        $ins->execute();
        $ins->close();

        // Redirect to avoid resubmission and keep URL with category
        $redirect = '/cl-test/invtest/item.php';
        if ($newCat !== '') {
            $redirect .= '?ingcat-Id=' . urlencode($newCat);
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$categoryName = '';
if ($categoryId !== '') {
    $stmt = $conn->prepare('SELECT `ingcat-Name` FROM `m1-ingredient_category` WHERE `ingcat-Id` = ? LIMIT 1');
    $stmt->bind_param('s', $categoryId);
    $stmt->execute();
    $stmt->bind_result($categoryName);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($categoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-2">
                    <li class="breadcrumb-item"><a href="/cl-test/invtest/category.php">Raw Ingredients</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($categoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></li>
                </ol>
            </nav>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <h1 class="page-title mb-0"><?php echo htmlspecialchars($categoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="d-flex gap-2">
                    <a href="/cl-test/invtest/category.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to categories
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Item
                    </button>
                </div>
            </div>

            <?php
            if ($categoryId !== '') {
                $q = $conn->prepare('SELECT `ingmaster-Id`,`ingmaster-Name`,`ingmaster-UnitType`,`ingmaster-Threshold` FROM `m11-ingredient_master` WHERE `ingcat-Id` = ? ORDER BY `ingmaster-Name` ASC');
                $q->bind_param('s', $categoryId);
                $q->execute();
                $res = $q->get_result();

                if ($res && $res->num_rows > 0) {
                    echo '<div class="row row-cols-1 row-cols-md-3 g-4">';
                    while ($row = $res->fetch_assoc()) {
                        // clickable card that links to master.php with the ingmaster-Id
                        echo '<div class="col">';
                        echo '<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/invtest/master.php?ingmaster-Id=' . rawurlencode($row['ingmaster-Id']) . '">';
                        echo '<div class="card-body">';
                        echo '<div class="d-flex align-items-start justify-content-between gap-2 mb-3">';
                        echo '<h3 class="h6 fw-semibold text-body mb-0">' . htmlspecialchars($row['ingmaster-Name'], ENT_QUOTES, 'UTF-8') . '</h3>';
                        echo '<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>';
                        echo '</div>';
                        echo '<div class="d-flex flex-wrap gap-2 small">';
                        echo '<span class="badge rounded-pill text-bg-light border fw-normal">ID: ' . htmlspecialchars($row['ingmaster-Id'], ENT_QUOTES, 'UTF-8') . '</span>';
                        if ((string)$row['ingmaster-UnitType'] !== '') {
                            echo '<span class="badge rounded-pill bg-primary-subtle text-primary-emphasis fw-normal">Unit: ' . htmlspecialchars($row['ingmaster-UnitType'], ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                        if ((string)$row['ingmaster-Threshold'] !== '') {
                            echo '<span class="badge rounded-pill bg-warning-subtle text-warning-emphasis fw-normal">Threshold: ' . htmlspecialchars($row['ingmaster-Threshold'], ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                        echo '</div>';
                        echo '</div>';
                        echo '</a>';
                        echo '</div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="card border-0 shadow-sm"><div class="card-body text-center text-body-secondary py-5">';
                    echo '<i class="bi bi-inbox fs-1 d-block mb-2"></i>No items found for this category.';
                    echo '</div></div>';
                }

                $q->close();
            } else {
                echo '<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>Invalid category.</div>';
            }
            ?>

        </div>
    </main>

    <!-- Add Item modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/cl-test/invtest/item.php">
                    <input type="hidden" name="action" value="add_item">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="addItemModalLabel">Add Item</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="ingmaster-Id" class="form-label">Item ID</label>
                                <input type="text" class="form-control" id="ingmaster-Id" name="ingmaster-Id" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ingcat-Id" class="form-label">Category</label>
                                <select class="form-select" id="ingcat-Id" name="ingcat-Id" required>
                                    <?php
                                    // populate categories for dropdown
                                    $cats = $conn->query('SELECT `ingcat-Id` AS id, `ingcat-Name` AS name FROM `m1-ingredient_category`');
                                    if ($cats) {
                                        while ($c = $cats->fetch_assoc()) {
                                            $sel = ($c['id'] === $categoryId) ? ' selected' : '';
                                            echo '<option value="' . htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="ingmaster-Name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="ingmaster-Name" name="ingmaster-Name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ingmaster-UnitType" class="form-label">Unit Type</label>
                                <select class="form-select" id="ingmaster-UnitType" name="ingmaster-UnitType">
                                    <option value="g">g</option>
                                    <option value="kg">kg</option>
                                    <option value="mL">mL</option>
                                    <option value="L">L</option>
                                    <option value="pcs">pcs</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="ingmaster-Threshold" class="form-label">Threshold</label>
                                <input type="text" class="form-control" id="ingmaster-Threshold" name="ingmaster-Threshold">
                                <div class="form-text">Low-stock warning level.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
