<?php

/*
 * GENERATED — PHP 7.4 build. Do not edit.
 *
 * String helpers added in PHP 8.0, which src/Client.php uses. Guarded so the
 * file is harmless if it is ever loaded on 8.x.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/*
 * Stringable arrived in 8.0, and PostId implements it. Declared rather than
 * stripped from the class, so `instanceof Stringable` keeps working for callers
 * on either build. PHP 8 makes any class with __toString() implement this
 * implicitly, which is why nothing has to change on the 8.1 side.
 *
 * Worth noting how this was found: it is not a syntax error, so parsing the
 * output under the 7.4 grammar said it was fine. Only running it on 7.4 did.
 */
if (!interface_exists('Stringable')) {
    interface Stringable
    {
        public function __toString(): string;
    }
}
