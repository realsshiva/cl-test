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
    <link rel="stylesheet" href="/cl-test/invtest/style.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .top { display:flex; align-items:center; gap:16px; }
        .container { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top:20px; }
        .card { padding: 18px; border: 1px solid #ccc; border-radius: 10px; background:#f8f8f8; }
        .card h3 { margin:0 0 8px 0; }
        .btn { padding:8px 12px; border:1px solid #444; background:#fff; cursor:pointer; border-radius:4px; }
        .btn-primary { background:#2b7; border-color:#2a6; }
        /* simple modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; }
        .modal .panel { background:#fff; padding:20px; border-radius:8px; width:320px; }
        .field { margin-bottom:10px; }
        label { display:block; font-size:13px; margin-bottom:4px; }
        input[type=text], select { width:100%; padding:6px; box-sizing:border-box; }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>
    <div class="top">
        <h2><?php echo htmlspecialchars($categoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></h2>
        <button id="addBtn" class="btn btn-primary">Add Item</button>
        <a href="/cl-test/invtest/category.php" style="margin-left:auto">Back to categories</a>
    </div>

    <?php
    if ($categoryId !== '') {
        $q = $conn->prepare('SELECT `ingmaster-Id`,`ingmaster-Name`,`ingmaster-UnitType`,`ingmaster-Threshold` FROM `m11-ingredient_master` WHERE `ingcat-Id` = ? ORDER BY `ingmaster-Name` ASC');
        $q->bind_param('s', $categoryId);
        $q->execute();
        $res = $q->get_result();

        if ($res && $res->num_rows > 0) {
            echo '<div class="container">';
            while ($row = $res->fetch_assoc()) {
                // clickable card that links to master.php with the ingmaster-Id
                echo '<a class="card" href="/cl-test/invtest/master.php?ingmaster-Id=' . rawurlencode($row['ingmaster-Id']) . '">';
                echo '<h3>' . htmlspecialchars($row['ingmaster-Name'], ENT_QUOTES, 'UTF-8') . '</h3>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<p>No items found for this category.</p>';
        }

        $q->close();
    } else {
        echo '<p>Invalid category.</p>';
    }
    ?>

    <!-- Modal -->
    <div id="modal" class="modal">
        <div class="panel">
            <h3>Add Item</h3>
            <form method="post" action="/cl-test/invtest/item.php">
                <input type="hidden" name="action" value="add_item">
                <div class="field">
                    <label for="ingmaster-Id">Item ID</label>
                    <input type="text" id="ingmaster-Id" name="ingmaster-Id" required>
                </div>
                <div class="field">
                    <label for="ingcat-Id">Category</label>
                    <select id="ingcat-Id" name="ingcat-Id" required>
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
                <div class="field">
                    <label for="ingmaster-Name">Name</label>
                    <input type="text" id="ingmaster-Name" name="ingmaster-Name" required>
                </div>
                <div class="field">
                    <label for="ingmaster-UnitType">Unit Type</label>
                    <select id="ingmaster-UnitType" name="ingmaster-UnitType">
                        <option value="g">g</option>
                        <option value="kg">kg</option>
                        <option value="mL">mL</option>
                        <option value="L">L</option>
                        <option value="pcs">pcs</option>
                    </select>
                </div>
                <div class="field">
                    <label for="ingmaster-Threshold">Threshold</label>
                    <input type="text" id="ingmaster-Threshold" name="ingmaster-Threshold">
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
                    <button type="button" id="cancelBtn" class="btn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addBtn = document.getElementById('addBtn');
        const modal = document.getElementById('modal');
        const cancelBtn = document.getElementById('cancelBtn');
        addBtn.addEventListener('click', () => modal.style.display = 'flex');
        cancelBtn.addEventListener('click', () => modal.style.display = 'none');
        window.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
    </script>
</body>
</html>
