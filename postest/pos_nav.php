<?php
// POS sidebar navigation. Uses the same app shell as invtest/nav.php:
// pages must load Bootstrap 5, Bootstrap Icons and /cl-test/invtest/nav.css in <head>,
// the Bootstrap bundle JS before </body>, and wrap their content in <main class="app-main">.
// Variables are prefixed with $posNav so they never clash with the including page.

$posNavCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$posNavInventoryView = ($_GET['view'] ?? '') === 'inventory';

// Product pages belong to Inventory when opened in inventory view, otherwise to Settings.
$posNavProductPages = ['pcat.php', 'pmaster.php'];

// [label, href, icon, pages that should highlight this link]
$posNavSections = [
    'Sales' => [
        ['Counter', 'counter.php', 'bi-calculator', ['counter.php']],
    ],
    'Stock' => [
        ['Supply', 'supply.php', 'bi-truck', ['supply.php', 'incoming.php']],
        ['Inventory', 'inventory.php', 'bi-boxes', array_merge(['inventory.php', 'pinfo.php'], $posNavInventoryView ? $posNavProductPages : [])],
        ['Records', 'records.php', 'bi-journal-text', ['records.php', 'transaction.php', 'dprecords.php', 'pos_waste.php', 'voidrecord.php']],
    ],
    'Manage' => [
        ['Settings', 'settings.php', 'bi-gear', array_merge(['settings.php'], $posNavInventoryView ? [] : $posNavProductPages)],
        ['Account', 'pos_account.php', 'bi-person-circle', ['pos_account.php']],
    ],
    // Hrefs starting with "/" are used as-is, so they can point outside postest/.
    'Switch System' => [
        ['Bakery Inventory', '/cl-test/invtest/dashboard.php', 'bi-cup-hot', []],
        ['Sales', '/cl-test/salestest/dashboard.php', 'bi-graph-up-arrow', []],
    ],
];
?>
<!-- Top bar: only visible below the lg breakpoint -->
<header class="app-topbar navbar navbar-dark d-lg-none sticky-top px-3">
    <a class="app-brand" href="/cl-test/postest/counter.php">
        <span class="app-brand-mark"><i class="bi bi-shop"></i></span>
        <span>Bakery POS</span>
    </a>
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#posSidebar" aria-controls="posSidebar" aria-label="Open menu">
        <i class="bi bi-list fs-5"></i>
    </button>
</header>

<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="posSidebar" aria-labelledby="posSidebarLabel">
    <div class="offcanvas-header">
        <a class="app-brand" id="posSidebarLabel" href="/cl-test/postest/counter.php">
            <span class="app-brand-mark"><i class="bi bi-shop"></i></span>
            <span>Bakery POS</span>
        </a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#posSidebar" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <a class="app-brand d-none d-lg-flex px-4 py-4" href="/cl-test/postest/counter.php">
            <span class="app-brand-mark"><i class="bi bi-shop"></i></span>
            <span>Bakery POS</span>
        </a>

        <nav aria-label="POS navigation">
            <?php foreach ($posNavSections as $posNavSectionLabel => $posNavLinks): ?>
                <div class="app-nav-label"><?php echo htmlspecialchars($posNavSectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <ul class="nav flex-column">
                    <?php foreach ($posNavLinks as $posNavLink): ?>
                        <?php $posNavIsActive = in_array($posNavCurrentPage, $posNavLink[3], true); ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $posNavIsActive ? ' active' : ''; ?>"
                               href="<?php echo $posNavLink[1][0] === '/' ? $posNavLink[1] : '/cl-test/postest/' . $posNavLink[1]; ?>"
                               <?php echo $posNavIsActive ? 'aria-current="page"' : ''; ?>>
                                <i class="bi <?php echo $posNavLink[2]; ?>"></i>
                                <span><?php echo htmlspecialchars($posNavLink[0], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="app-sidebar-footer">
            <i class="bi bi-shop me-1"></i> CakeLab Point of Sale
        </div>
    </div>
</aside>
