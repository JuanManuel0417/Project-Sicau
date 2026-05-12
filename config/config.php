<?php
return [
    'app' => [
        'name' => 'SICAU',
        'base_url' => 'http://localhost/Project-Sicau',
        'upload_path' => __DIR__ . '/../storage/uploads',
        'max_upload_size' => 5 * 1024 * 1024,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'admin_path' => '/pages/admin.html',
        'analysis' => [
            'min_confidence' => 70,
            'ocr_engine' => 'heuristic-ocr',
            'pdftotext_bin' => 'C:\\poppler\\Library\\bin\\pdftotext.exe',
            'pdftoppm_bin' => 'C:\\poppler\\Library\\bin\\pdftoppm.exe',
            'tesseract_bin' => 'C:\\Tesseract-OCR\\tesseract.exe',
            'tesseract_lang' => 'spa+eng',
            'tesseract_psm' => 6,
        ],
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'sicau',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
