<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/cl-test/invtest/nav.css?v=20261103">
</head>
<body class="bg-body-tertiary">
    <?php include __DIR__ . '/pos_nav.php'; ?>

    <main class="app-main">
        <div class="container-fluid py-4 px-3 px-lg-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-2">
                    <li class="breadcrumb-item"><a href="/cl-test/postest/settings.php">Settings</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Accounts</li>
                </ol>
            </nav>
            <h1 class="page-title mb-1">Accounts</h1>
            <p class="text-body-secondary mb-4">Manage who can sign in to the POS.</p>

            <!-- Placeholder until account management is built. -->
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-body-secondary py-5">
                    <span class="icon-tile bg-success-subtle text-success-emphasis mb-3"><i class="bi bi-people"></i></span>
                    <h2 class="h5 text-body">Account management is not set up yet</h2>
                    <p class="mb-4">POS accounts will be listed here.</p>
                    <a class="btn btn-outline-secondary" href="/cl-test/postest/settings.php"><i class="bi bi-arrow-left me-1"></i>Back to Settings</a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
