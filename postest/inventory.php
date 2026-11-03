<?php
$embedded = ($_GET['embedded'] ?? '') === '1' ? '&embedded=1' : '';
header('Location: pcat.php?view=inventory' . $embedded);
exit;