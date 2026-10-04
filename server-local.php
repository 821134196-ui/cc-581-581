<?php

/**
 * PHP 内置服务器路由（本地一键启动用，生产不需要）：
 * 存在的静态文件直接返回，其余交给 Laravel。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = __DIR__ . '/public' . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/public/index.php';
