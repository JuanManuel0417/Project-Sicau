<?php

namespace App\Core;

class Response
{
    public function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }
}
