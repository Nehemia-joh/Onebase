<?php
require_once __DIR__ . '/config.php';
header('Location: ' . (isLoggedIn() ? roleHome() : '/login'));
exit;
