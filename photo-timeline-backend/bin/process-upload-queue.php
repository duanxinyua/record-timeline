<?php

require dirname(__DIR__) . '/src/autoload.php';

use App\Controllers\UploadController;

$config = require dirname(__DIR__) . '/config.php';
$controller = new UploadController($config);

try {
    $controller->processPendingChunkUploads();
} catch (Throwable $e) {
    error_log('Chunk upload queue processing failed: ' . $e->getMessage());
    exit(1);
}

exit(0);
