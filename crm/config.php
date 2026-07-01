<?php
/**
 * config.php — Application bootstrap
 *
 * Include this file at the top of every page.
 * It loads all subsystems: database, authentication, and helpers.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Global exception handler to output tracebacks on screen
set_exception_handler(function ($e) {
    echo "<div style='padding:20px; background:#FFEBEB; border:1px solid #FF3B30; color:#FF3B30; font-family:sans-serif; margin:20px; border-radius:8px; z-index:9999; position:relative;'>";
    echo "<h3 style='margin-top:0;'>⚠️ [Debug] Uncaught Exception</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " on line <strong>" . $e->getLine() . "</strong></p>";
    echo "<pre style='background:#fff; padding:10px; border-radius:4px; overflow-x:auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
});

// Convert PHP errors/warnings to Exceptions so they are caught by the handler
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

