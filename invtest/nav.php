<?php
// Sidebar navigation. Pages using it must load Bootstrap 5 + nav.css in <head>,
// the Bootstrap bundle JS before </body>, and wrap their content in <main class="app-main">.

$navCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');

// [label, href, icon, pages that should highlight this link]
$navSections = [
    '' => [
        ['Dashboard', 'dashboard.php', 'bi-speedometer2', ['dashboard.php']],
    ],
    'Inventory' => [
        ['Raw Ingredients', 'category.php', 'bi-basket', ['category.php', 'item.php', 'master.php']],
        ['Finished Goods', 'finishg.php', 'bi-box-seam', ['finishg.php']],
    ],
    'Recipes & Production' => [
        ['Recipe Categories', 'rcategory.php', 'bi-journal-richtext', ['rcategory.php', 'ritem.php', 'rmaster.php']],
        ['Production Form', 'prodform.php', 'bi-clipboard-check', ['prodform.php', 'prodqueue.php', 'prodlog.php']],
    ],
    'Records' => [
        ['Stock History', 'history.php', 'bi-clock-history', ['history.php']],
        ['Waste Log', 'waste.php', 'bi-trash3', ['waste.php']],
    ],
];
?>
<!-- Top bar: only visible below the lg breakpoint -->
<header class="app-topbar navbar navbar-dark d-lg-none sticky-top px-3">
    <a class="app-brand" href="/cl-test/invtest/dashboard.php">
        <span class="app-brand-mark"><i class="bi bi-cup-hot"></i></span>
        <span>Bakery Inventory</span>
    </a>
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
        <i class="bi bi-list fs-5"></i>
    </button>
</header>

<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="offcanvas-header">
        <a class="app-brand" id="appSidebarLabel" href="/cl-test/invtest/dashboard.php">
            <span class="app-brand-mark"><i class="bi bi-cup-hot"></i></span>
            <span>Bakery Inventory</span>
        </a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <a class="app-brand d-none d-lg-flex px-4 py-4" href="/cl-test/invtest/dashboard.php">
            <span class="app-brand-mark"><i class="bi bi-cup-hot"></i></span>
            <span>Bakery Inventory</span>
        </a>

        <nav aria-label="Main navigation">
            <?php foreach ($navSections as $navSectionLabel => $navLinks): ?>
                <?php if ($navSectionLabel !== ''): ?>
                    <div class="app-nav-label"><?php echo htmlspecialchars($navSectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <ul class="nav flex-column">
                    <?php foreach ($navLinks as $navLink): ?>
                        <?php $navIsActive = in_array($navCurrentPage, $navLink[3], true); ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $navIsActive ? ' active' : ''; ?>"
                               href="/cl-test/invtest/<?php echo $navLink[1]; ?>"
                               <?php echo $navIsActive ? 'aria-current="page"' : ''; ?>>
                                <i class="bi <?php echo $navLink[2]; ?>"></i>
                                <span><?php echo htmlspecialchars($navLink[0], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="app-sidebar-footer">
            <i class="bi bi-shop me-1"></i> Inventory &amp; Recipe Manager
        </div>
    </div>
</aside>
