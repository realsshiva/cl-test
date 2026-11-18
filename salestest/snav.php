<?php
// Sales sidebar navigation. Uses the same app shell as invtest/nav.php and postest/pos_nav.php:
// pages must load Bootstrap 5, Bootstrap Icons and /cl-test/invtest/nav.css in <head>,
// the Bootstrap bundle JS before </body>, and wrap their content in <main class="app-main">.
// Variables are prefixed with $salesNav so they never clash with the including page.

$salesNavCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');

// [label, href, icon, pages that should highlight this link]
// tretail.php is the PoS-Retail list shown inside Transaction History's frame.
$salesNavSections = [
    '' => [
        ['Dashboard', 'dashboard.php', 'bi-speedometer2', ['dashboard.php']],
    ],
    'Reports' => [
        ['Sales Report', 'sreport.php', 'bi-bar-chart-line', ['sreport.php']],
        ['Transaction History', 'thistory.php', 'bi-receipt', ['thistory.php', 'tretail.php', 'tkiosk.php', 'tb2b.php']],
    ],
    // Hrefs starting with "/" are used as-is, so they can point outside salestest/.
    'Switch System' => [
        ['Bakery Inventory', '/cl-test/invtest/dashboard.php', 'bi-cup-hot', []],
        ['Point of Sale', '/cl-test/postest/counter.php', 'bi-shop', []],
    ],
];
?>
<!-- Top bar: only visible below the lg breakpoint -->
<header class="app-topbar navbar navbar-dark d-lg-none sticky-top px-3">
    <a class="app-brand" href="/cl-test/salestest/dashboard.php">
        <span class="app-brand-mark"><i class="bi bi-graph-up-arrow"></i></span>
        <span>Bakery Sales</span>
    </a>
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#salesSidebar" aria-controls="salesSidebar" aria-label="Open menu">
        <i class="bi bi-list fs-5"></i>
    </button>
</header>

<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="salesSidebar" aria-labelledby="salesSidebarLabel">
    <div class="offcanvas-header">
        <a class="app-brand" id="salesSidebarLabel" href="/cl-test/salestest/dashboard.php">
            <span class="app-brand-mark"><i class="bi bi-graph-up-arrow"></i></span>
            <span>Bakery Sales</span>
        </a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#salesSidebar" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <a class="app-brand d-none d-lg-flex px-4 py-4" href="/cl-test/salestest/dashboard.php">
            <span class="app-brand-mark"><i class="bi bi-graph-up-arrow"></i></span>
            <span>Bakery Sales</span>
        </a>

        <nav aria-label="Sales navigation">
            <?php foreach ($salesNavSections as $salesNavSectionLabel => $salesNavLinks): ?>
                <?php if ($salesNavSectionLabel !== ''): ?>
                    <div class="app-nav-label"><?php echo htmlspecialchars($salesNavSectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <ul class="nav flex-column">
                    <?php foreach ($salesNavLinks as $salesNavLink): ?>
                        <?php $salesNavIsActive = in_array($salesNavCurrentPage, $salesNavLink[3], true); ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $salesNavIsActive ? ' active' : ''; ?>"
                               href="<?php echo $salesNavLink[1][0] === '/' ? $salesNavLink[1] : '/cl-test/salestest/' . $salesNavLink[1]; ?>"
                               <?php echo $salesNavIsActive ? 'aria-current="page"' : ''; ?>>
                                <i class="bi <?php echo $salesNavLink[2]; ?>"></i>
                                <span><?php echo htmlspecialchars($salesNavLink[0], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="app-sidebar-footer">
            <i class="bi bi-graph-up-arrow me-1"></i> CakeLab Sales
        </div>
    </div>
</aside>
