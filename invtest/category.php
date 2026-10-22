<?php
require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Categories</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="page-title mb-1">Raw Ingredients</h1>
                    <p class="text-body-secondary mb-0">Choose a category to view and manage its ingredients.</p>
                </div>
            </div>

            <?php
            $sql = "SELECT `ingcat-Id` AS id, `ingcat-Name` AS name FROM `m1-ingredient_category`";
            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                echo '<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-4">';
                while ($row = $result->fetch_assoc()) {
                    // pass the original category id string in the URL (do not cast to int)
                    echo '<div class="col">';
                    echo '<a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/invtest/item.php?ingcat-Id=' . rawurlencode($row['id']) . '">';
                    echo '<div class="card-body d-flex align-items-center gap-3">';
                    echo '<span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-basket"></i></span>';
                    echo '<div class="flex-grow-1 min-w-0">';
                    echo '<h2 class="h6 fw-semibold text-body mb-1 text-truncate">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</h2>';
                    echo '<small class="text-body-secondary">View items</small>';
                    echo '</div>';
                    echo '<i class="bi bi-chevron-right text-body-secondary card-arrow"></i>';
                    echo '</div>';
                    echo '</a>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<div class="card border-0 shadow-sm"><div class="card-body text-center text-body-secondary py-5">';
                echo '<i class="bi bi-inbox fs-1 d-block mb-2"></i>No categories found.';
                echo '</div></div>';
            }
            ?>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
