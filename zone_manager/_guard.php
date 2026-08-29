<?php
/**
 * Role directory guard — every page in this directory belongs to one role.
 * Anyone else is sent back to their own role's dashboard.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
requireLogin();
if (($_SESSION['role'] ?? '') !== basename(__DIR__)) {
    header('Location: ' . roleHome());
    exit;
}
define('ENTRY_OK', true);
