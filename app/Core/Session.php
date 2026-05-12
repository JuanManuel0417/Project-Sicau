<?php

namespace App\Core;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Forzar el path de la cookie de sesión para que funcione en /Project-Sicau
            $cookieParams = session_get_cookie_params();
            if ($cookieParams['path'] !== '/Project-Sicau') {
                session_set_cookie_params([
                    'lifetime' => $cookieParams['lifetime'],
                    'path' => '/Project-Sicau',
                    'domain' => $cookieParams['domain'],
                    'secure' => $cookieParams['secure'],
                    'httponly' => $cookieParams['httponly'],
                    'samesite' => $cookieParams['samesite'] ?? 'Lax',
                ]);
            }
            session_start();
        }
    }

    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}
