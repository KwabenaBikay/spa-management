<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/db.php';

try {
    $file = create_database_backup($conn);
    fwrite(STDOUT, $file . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed.\n");
    exit(1);
}
