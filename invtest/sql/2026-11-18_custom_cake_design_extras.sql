-- Cake Builder: extra design choices per custom cake (second cake color and how it is used,
-- trim / drip / stand / message colors, piping style, message font, and where the toppings
-- and the message sit). Stored as JSON written by cakeCleanDesign() in cake_options.php.
-- Older cakes keep NULL and are shown with the default design.
-- Run once on an existing database:  mysql -u root cl-inv_test < 2026-11-18_custom_cake_design_extras.sql

ALTER TABLE `m3.1-custom_cake_design`
  ADD COLUMN IF NOT EXISTS `ccd-Design` text DEFAULT NULL AFTER `ccd-Notes`;
