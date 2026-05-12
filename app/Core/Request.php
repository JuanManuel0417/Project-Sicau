<?php

namespace App\Core;

class Request
{
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public function path(): string
    {
        $path = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '/');
        $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        // Detectar si hay un prefijo tipo /Project-Sicau y removerlo
        $prefix = '/Project-Sicau';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }
        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        return strtok($path, '?') ?: '/';
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function json(): array
    {
        $payload = file_get_contents('php://input');
        return json_decode($payload, true) ?: [];
    }

    public function file(string $key)
    {
        return $_FILES[$key] ?? null;
    }
}
