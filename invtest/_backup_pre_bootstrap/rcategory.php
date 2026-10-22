<?php
require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Categories</title>
    <link rel="stylesheet" href="/cl-test/invtest/style.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
        .card { display: inline-block; padding: 24px; border: 1px solid #ccc; border-radius: 10px; text-decoration: none; color: #222; background: #f8f8f8; }
        .card:hover { background: #eee; }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>
    <h1>Recipe Categories</h1>
    <div class="container">
        <?php
        $sql = "SELECT `reccat-Id` AS id, `reccat-Name` AS name FROM `m2-recipe_category`";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // pass the original category id string in the URL (do not cast to int)
                echo '<a class="card" href="/cl-test/invtest/ritem.php?reccat-Id=' . rawurlencode($row['id']) . '">';
                echo '<h2>' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</h2>';
                echo '</a>';
            }
        } else {
            echo '<p>No categories found.</p>';
        }
        ?>
    </div>
</body>
</html>