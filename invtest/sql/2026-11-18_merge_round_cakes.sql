-- Custom Cake: the two one-tier round recipes ("1-Tier Base 6d Round Cake" and "1-Tier Base 8d Round Cake")
-- become one recipe, "1-Tier Base Round Cake", that offers every size of both (6x2 to 6x7 and 8x2 to 8x7).
-- The size/finish variants keep their ids (RD6-..., RD8-...), so existing production batches and
-- recipe ingredients are untouched; only the recipe they belong to changes.
-- Run once on an existing database:  mysql -u root cl-inv_test < 2026-11-18_merge_round_cakes.sql

START TRANSACTION;

INSERT INTO `m22-recipe_master` (`rec-Id`, `reccat-Id`, `rec-Name`, `rec-PhotoPath`)
SELECT 'CUS-1TR-RND', 'CAT-CCK', '1-Tier Base Round Cake', NULL
WHERE NOT EXISTS (SELECT 1 FROM `m22-recipe_master` WHERE `rec-Id` = 'CUS-1TR-RND');

UPDATE `m222-recipe_variant2` SET `rec-Id` = 'CUS-1TR-RND' WHERE `rec-Id` IN ('CUS-1TR-RD6', 'CUS-1TR-RD8');
UPDATE `m222-recipe_variant3` SET `rec-Id` = 'CUS-1TR-RND' WHERE `rec-Id` IN ('CUS-1TR-RD6', 'CUS-1TR-RD8');
UPDATE `m8-dispatch_log`      SET `rec-Id` = 'CUS-1TR-RND' WHERE `rec-Id` IN ('CUS-1TR-RD6', 'CUS-1TR-RD8');

DELETE FROM `m22-recipe_master` WHERE `rec-Id` IN ('CUS-1TR-RD6', 'CUS-1TR-RD8');

COMMIT;
