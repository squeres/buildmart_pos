<?php
/**
 * views/partials/icons.php
 *
 * Feather icon renderer.
 * Emits an <i data-feather> tag which the Feather JS library
 * (loaded in footer) replaces with an inline SVG at runtime.
 *
 * Usage:
 *   echo feather_icon('truck');          // default 18px
 *   echo feather_icon('check', 14);      // custom size
 *   echo feather_icon('save', 16, 'ml-1'); // with extra CSS class
 */
function feather_icon(string $name, int $size = 18, string $class = ''): string
{
    $s = e($name);
    $c = $class ? ' class="' . e($class) . '"' : '';
    return "<i data-feather=\"{$s}\" style=\"width:{$size}px;height:{$size}px;vertical-align:middle\"{$c}></i>";
}
