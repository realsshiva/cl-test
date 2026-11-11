<?php
// Shared choices for the Cake Builder (cakebuilder.php) and the production queue.
// The builder only accepts values listed here, so the queue can trust them.

const CAKE_CATEGORY_ID = 'CAT-CCK';
const CAKE_MAX_TOPPINGS = 4;
const CAKE_MESSAGE_MAX = 40;
const CAKE_MAX_QUANTITY = 50;

// hex => display name
function cakeBaseColors(): array
{
    return [
        '#FFF4DC' => 'Vanilla Cream',
        '#FFFFFF' => 'Classic White',
        '#F9C6D0' => 'Blush Pink',
        '#FCD5B5' => 'Peach',
        '#FBE7A1' => 'Butter Yellow',
        '#C6EBD9' => 'Mint',
        '#C5E1F5' => 'Sky Blue',
        '#D9C8F0' => 'Lavender',
        '#B23A48' => 'Red Velvet',
        '#7B4B36' => 'Chocolate',
    ];
}

function cakeAccentColors(): array
{
    return [
        '#E8738A' => 'Rose',
        '#D4A63A' => 'Gold',
        '#4A2C21' => 'Dark Chocolate',
        '#9B2C5C' => 'Berry',
        '#2A9D8F' => 'Teal',
        '#9D7BD8' => 'Lilac',
        '#F08A5D' => 'Coral',
        '#FFFFFF' => 'Snow White',
    ];
}

// key => [label, Bootstrap icon]
function cakeToppings(): array
{
    return [
        'sprinkles'      => ['Sprinkles', 'bi-stars'],
        'strawberries'   => ['Strawberries', 'bi-heart-fill'],
        'cherries'       => ['Cherries', 'bi-circle-fill'],
        'macarons'       => ['Macarons', 'bi-hdd-stack-fill'],
        'flowers'        => ['Flowers', 'bi-flower1'],
        'pearls'         => ['Pearls', 'bi-record-circle'],
        'chocolate_drip' => ['Chocolate Drip', 'bi-droplet-half'],
        'gold_leaf'      => ['Gold Leaf', 'bi-gem'],
        'candles'        => ['Candles', 'bi-fire'],
    ];
}

// Works out how to draw a recipe from its name, so new Custom Cake recipes show up on their own.
function cakeShapeFor(string $recipeName): array
{
    $name = strtolower($recipeName);
    $tiers = preg_match('/\b2[\s-]*tier/', $name) ? 2 : 1;
    if (strpos($name, 'square') !== false) {
        $shape = 'square';
    } elseif (strpos($name, 'rectangle') !== false) {
        $shape = 'rectangle';
    } elseif (strpos($name, 'bento') !== false) {
        $shape = 'bento';
    } elseif (strpos($name, 'mini') !== false) {
        $shape = 'mini';
    } else {
        $shape = 'round';
    }
    return ['shape' => $shape, 'tiers' => $tiers];
}

// Turns a stored topping list ("sprinkles,candles") into labels.
function cakeToppingLabels(string $stored): array
{
    $all = cakeToppings();
    $labels = [];
    foreach (array_filter(explode(',', $stored)) as $key) {
        if (isset($all[$key])) $labels[$key] = $all[$key];
    }
    return $labels;
}

// Any 6-digit hex color, e.g. #F9C6D0 (the color wheel can produce any of them).
function cakeIsColor(string $hex): bool
{
    return (bool)preg_match('/^#[0-9A-F]{6}$/', $hex);
}

function cakeColorName(string $hex, bool $accent = false): string
{
    $hex = strtoupper($hex);
    $list = $accent ? cakeAccentColors() : cakeBaseColors();
    return $list[$hex] ?? 'Custom';
}
