<?php
// Guard so this helper can't be declared twice if another file also loads or defines it.
if (!function_exists('recordHistory')) {
    function recordHistory(
        mysqli $conn,
        ?int $ingredientId,
        ?int $recipeIngredientId,
        ?int $productionBatchId,
        ?int $finishedGoodId,
        string $action,
        string $source
    ): void {
        $insert = $conn->prepare(
            'INSERT INTO `m6-history`
                (`ing-Id`, `recing-Id`, `prodbatch-Id`, `fgm-Id`, `hist-Action`, `hist-Source`, `hist-Timestamp`)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insert->bind_param(
            'iiiiss',
            $ingredientId,
            $recipeIngredientId,
            $productionBatchId,
            $finishedGoodId,
            $action,
            $source
        );
        $insert->execute();
        $insert->close();
    }
}
