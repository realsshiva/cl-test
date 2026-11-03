<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
    <?php include __DIR__ . '/pos_nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <h1 class="page-title mb-1">Settings</h1>
            <p class="text-body-secondary mb-4">Manage what the POS sells and who can use it.</p>

            <div class="row row-cols-1 row-cols-md-2 g-4" style="max-width: 760px">
                <div class="col">
                    <a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/postest/pcat.php">
                        <div class="card-body d-flex align-items-center gap-3 p-4">
                            <span class="icon-tile bg-primary-subtle text-primary-emphasis"><i class="bi bi-tags"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <h2 class="h6 fw-semibold text-body mb-1">Products</h2>
                                <small class="text-body-secondary">Manage product categories.</small>
                            </div>
                            <i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a class="card card-hover h-100 border-0 shadow-sm text-decoration-none" href="/cl-test/postest/pos_account.php">
                        <div class="card-body d-flex align-items-center gap-3 p-4">
                            <span class="icon-tile bg-success-subtle text-success-emphasis"><i class="bi bi-people"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <h2 class="h6 fw-semibold text-body mb-1">Accounts</h2>
                                <small class="text-body-secondary">Manage POS accounts.</small>
                            </div>
                            <i class="bi bi-chevron-right text-body-secondary card-arrow"></i>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
