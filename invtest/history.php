<?php
require_once __DIR__ . '/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$history = [];
$error = '';

try {
    $result = $conn->query(
        'SELECT
                CASE
                    WHEN `ing-Id` IS NOT NULL THEN `ing-Id`
                    WHEN `fgm-Id` IS NOT NULL THEN `fgm-Id`
                    WHEN `recing-Id` IS NOT NULL THEN `recing-Id`
                    WHEN `prodbatch-Id` IS NOT NULL THEN `prodbatch-Id`
                    ELSE NULL
                END AS `display-id`,
                CASE
                    WHEN `ing-Id` IS NOT NULL THEN \'Raw Ingredients\'
                    WHEN `fgm-Id` IS NOT NULL THEN \'Finished Goods\'
                    WHEN `recing-Id` IS NOT NULL THEN \'Recipe Ingredient Table\'
                    WHEN `prodbatch-Id` IS NOT NULL THEN \'Production Queue\'
                    ELSE `hist-Source`
                END AS `display-source`,
                `hist-Action`, `hist-Timestamp`
         FROM `m6-history`
         ORDER BY `hist-Timestamp` DESC, `hist-Id` DESC'
    );
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);
    $error = 'History could not be loaded: ' . $exception->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory History</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css">
</head>
<body class="bg-body-tertiary">
    <?php include 'nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <h1 class="page-title mb-1">History</h1>
            <p class="text-body-secondary mb-4">Changes recorded across raw ingredients, finished goods, recipe ingredients, and the production queue.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Source</th>
                                    <th>ID</th>
                                    <th>Implemented</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($history === []): ?>
                                    <tr><td colspan="4" class="text-center text-body-secondary py-4">No history has been recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history as $entry): ?>
                                        <tr>
                                            <td class="text-nowrap"><time datetime="<?php echo e($entry['hist-Timestamp']); ?>"><?php echo e($entry['hist-Timestamp']); ?></time></td>
                                            <td><?php echo e($entry['display-source']); ?></td>
                                            <td><?php echo e($entry['display-id'] ?? '-'); ?></td>
                                            <td><?php echo e($entry['hist-Action']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
