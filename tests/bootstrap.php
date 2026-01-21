<?php
/**
 * PHPUnit bootstrap file for EasyAudit CLI tests.
 *
 * Defines constants normally defined in bin/easyaudit.
 */

// Define ANSI color constants used throughout the application
if (!defined('RESET')) {
    define('RESET', "\033[0m");
}
if (!defined('GREEN')) {
    define('GREEN', "\033[32m");
}
if (!defined('RED')) {
    define('RED', "\033[31m");
}
if (!defined('YELLOW')) {
    define('YELLOW', "\033[33m");
}
if (!defined('BLUE')) {
    define('BLUE', "\033[34m");
}
if (!defined('BOLD')) {
    define('BOLD', "\033[1m");
}

// Autoload
require dirname(__DIR__) . '/vendor/autoload.php';
