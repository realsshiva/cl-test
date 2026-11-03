<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Counter</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
    <?php include __DIR__ . '/pos_nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <h1 class="page-title mb-1">Counter</h1>
            <p class="text-body-secondary mb-4">Ring up sales at the shop counter.</p>

            <!-- Placeholder until the counter (sales) screen is built. -->
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-body-secondary py-5">
                    <span class="icon-tile bg-primary-subtle text-primary-emphasis mb-3"><i class="bi bi-calculator"></i></span>
                    <h2 class="h5 text-body">The counter is not set up yet</h2>
                    <p class="mb-4">Sales will be recorded here. In the meantime you can manage stock and products.</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a class="btn btn-primary" href="/cl-test/postest/supply.php"><i class="bi bi-truck me-1"></i>Go to Supply</a>
                        <a class="btn btn-outline-secondary" href="/cl-test/postest/settings.php"><i class="bi bi-gear me-1"></i>Settings</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
