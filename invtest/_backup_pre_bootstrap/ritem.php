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
        $ins->bind_param('sssss', $newrId, $newrCat, $newrName);
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
        <h2><?php echo htmlspecialchars($rcategoryName ?: 'Items', ENT_QUOTES, 'UTF-8'); ?></h2>
        <button id="addBtn" class="btn btn-primary">Add Item</button>
        <a href="/cl-test/invtest/rcategory.php" style="margin-left:auto">Back to categories</a>
    </div>

    <?php
    if ($rcategoryId !== '') {
        $q = $conn->prepare('SELECT `rec-Id`,`rec-Name` FROM `m22-recipe_master` WHERE `reccat-Id` = ? ORDER BY `rec-Name` ASC');
        $q->bind_param('s', $rcategoryId);
        $q->execute();
        $res = $q->get_result();

        if ($res && $res->num_rows > 0) {
            echo '<div class="container">';
            while ($row = $res->fetch_assoc()) {
                // clickable card that links to master.php with the ingmaster-Id
                echo '<a class="card" href="/cl-test/invtest/rmaster.php?rec-Id=' . rawurlencode($row['rec-Id']) . '">';
                echo '<h3>' . htmlspecialchars($row['rec-Name'], ENT_QUOTES, 'UTF-8') . '</h3>';
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
            <form method="post" action="/cl-test/invtest/ritem.php">
                <input type="hidden" name="action" value="add_item">
                <div class="field">
                    <label for="rec-Id">Item ID</label>
                    <input type="text" id="rec-Id" name="rec-Id" required>
                </div>
                <div class="field">
                    <label for="reccat-Id">Category</label>
                    <select id="reccat-Id" name="reccat-Id" required>
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
                <div class="field">
                    <label for="rec-Name">Name</label>
                    <input type="text" id="rec-Name" name="rec-Name" required>
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