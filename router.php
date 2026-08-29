<?php
/**
 * Dev-server router for `php -S`, mirroring the .htaccess pretty-URL rewrite:
 *   /path -> path.php, when path.php exists and /path itself doesn't.
 * Not used in production (Apache/.htaccess handles it there).
 */
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = __DIR__ . $uri;

if ($uri !== '/' && (is_file($path) || is_dir($path))) {
    return false;
}

$trimmed = trim($uri, '/');

if ($trimmed === '') {
    require __DIR__ . '/index.php';
    return true;
}

$phpFile = __DIR__ . '/' . $trimmed . '.php';
if (is_file($phpFile)) {
    require $phpFile;
    return true;
}

return false;
