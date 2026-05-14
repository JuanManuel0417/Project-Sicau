<?php

$uploadDir = __DIR__ . '/../storage/uploads';

if (!is_dir($uploadDir)) {
    echo "No existe el directorio de uploads: $uploadDir\n";
    exit(1);
}

function removeDirectoryContents(string $dir): void
{
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            removeDirectoryContents($path);
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}

removeDirectoryContents($uploadDir);
echo "Archivos subidos eliminados en: $uploadDir\n";
