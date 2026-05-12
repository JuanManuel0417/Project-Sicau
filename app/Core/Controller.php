<?php

namespace App\Core;

abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    protected function view(string $view, array $data = []): void
    {
        extract($data);
        require_once __DIR__ . '/../../views/' . $view . '.php';
    }

    protected function json($data, int $status = 200): void
    {
        $this->response->json($data, $status);
    }

    protected function config(): array
    {
        // require_once no retorna el array si ya fue incluido, así que usamos include para obtener el array siempre
        return include __DIR__ . '/../../config/config.php';
    }
}
