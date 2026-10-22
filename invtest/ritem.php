<?php
require_once __DIR__ . '/db.php';

// Accept category id as string (reccat-Id is varchar in your schema)
$rcategoryId = isset($_GET['reccat-Id']) ? $_GET['reccat-Id'] : '';

// Handle form submission to add a new item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $newrId = isset($_POST['rec-Id']) ? trim($_POST['rec-Id']) : '';
    $newrCat = isset($_POST['reccat-Id']) ? trim($_POST['reccat-Id']) : '';
    $newrName = isset($_POST['rec-Name']) ? trim($_POST['rec-Name']) : '';

    if ($newrId !== '' && $newrCat !== '' && $newrName !== '') {
        $ins = $conn->prepare('INSERT INTO `m22-recipe_master` (`rec-Id`,`reccat-Id`,`rec-Name`) VALUES (?,?,?)');
        // Three placeholders, three bound values ('sssss' here would throw on PHP 8).
        $ins->bind_param('sss', $newrId, $newrCat, $newrName);
        $ins->execute();
        $ins->close();

        // Redirect to avoid resubmission and keep URL with category
        $redirect = '/cl-test/invtest/ritem.php';
        if ($newrCat !== '') {
            $redirect .= '?reccat-Id=' . urlencode($newrCat);
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$rcategoryName = '';
if ($rcategoryId !== '') {
    $stmt = $conn->prepare('SELECT `reccat-Name` FROM `m2-recipe_category` WHERE `reccat-Id` = ? LIMIT 1');
    $stmt->bind_param('s', $rcategoryId);
    $stmt->execute();
    $stmt->bind_result($rcategoryName);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($rcategoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></title>
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
                    <li class="breadcrumb-item"><a href="/cl-test/invtest/rcategory.php">Recipe Categories</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($rcategoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></li>
                </ol>
            </nav>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <h1 class="page-title mb-0"><?php echo htmlspecialchars($rcategoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="d-flex gap-2">
                    <a href="/cl-test/invtest/rcategory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to categories
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecipeModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Item
                    </button>
                </div>
            </div>

            <?php
            if ($rcategoryId !== '') {
                $q = $conn->prepare('SELECT `rec-Id`,`rec-Name` FROM `m22-recipe_master` WHERE `reccat-Id` = ? ORDER BY `rec-Name` ASC');
                $q->bind_param('s', $rcategoryId);
                $q->execute();
                $res = $q->get_result();

                if ($res && $res->num_rows > 0) {
                    echo '<div class="row row-cols-1 row-cols-md-3 g-4">';
                    while ($row = $res->fetch_assoc()) {
                        // clickable card that links to rmaster.php with the rec-Id
                        echo '<div class="col">';
                        echo '<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/invtest/rmaster.php?rec-Id=' . rawurlencode($row['rec-Id']) . '">';
                        echo '<div class="card-body d-flex align-items-center gap-3">';
                        echo '<span class="icon-tile bg-warning-subtle text-warning-emphasis"><i class="bi bi-cake2"></i></span>';
                        echo '<div class="flex-grow-1 min-w-0">';
                        echo '<h3 class="h6 fw-semibold text-body mb-1 text-truncate">' . htmlspecialchars($row['rec-Name'], ENT_QUOTES, 'UTF-8') . '</h3>';
                        echo '<small class="text-body-secondary">ID: ' . htmlspecialchars($row['rec-Id'], ENT_QUOTES, 'UTF-8') . '</small>';
                        echo '</div>';
                        echo '<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>';
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

    <!-- Add Recipe modal -->
    <div class="modal fade" id="addRecipeModal" tabindex="-1" aria-labelledby="addRecipeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/cl-test/invtest/ritem.php">
                    <input type="hidden" name="action" value="add_item">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="addRecipeModalLabel">Add Item</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="rec-Id" class="form-label">Item ID</label>
                                <input type="text" class="form-control" id="rec-Id" name="rec-Id" required>
                            </div>
                            <div class="col-md-6">
                                <label for="reccat-Id" class="form-label">Category</label>
                                <select class="form-select" id="reccat-Id" name="reccat-Id" required>
                                    <?php
                                    // populate categories for dropdown
                                    $rcats = $conn->query('SELECT `reccat-Id` AS id, `reccat-Name` AS name FROM `m2-recipe_category`');
                                    if ($rcats) {
                                        while ($rc = $rcats->fetch_assoc()) {
                                            $sel = ($rcategoryId === $rc['id']) ? ' selected' : '';
                                            echo '<option value="' . htmlspecialchars($rc['id'], ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($rc['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="rec-Name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="rec-Name" name="rec-Name" required>
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
