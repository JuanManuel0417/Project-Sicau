<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Core\Validator;
use App\Models\User;

class AuthController extends Controller
{
    public function login($request): void
    {
        $data = $request->all();
        $validator = new Validator();
        $validator->required('email', $data['email'] ?? '', 'El correo es obligatorio')
                  ->required('password', $data['password'] ?? '', 'La contraseña es obligatoria');

        if ($validator->fails()) {
            $this->json(['errors' => $validator->getErrors()], 422);
            return;
        }

        $userModel = new User();
        $user = $userModel->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            $this->json(['error' => 'Credenciales inválidas'], 401);
            return;
        }

        Session::set('user', [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'document_number' => $user['document_number'] ?? null,
            'role_id' => $user['role_id'],
            'role_name' => $user['role_name'] ?? null,
            'role_slug' => $user['role_slug'] ?? null,
        ]);

        $this->json(['message' => 'Autenticación exitosa', 'user' => Session::get('user')]);
    }

    public function logout(): void
    {
        Session::destroy();
        $this->json(['message' => 'Sesión cerrada']);
    }

    public function me(): void
    {
        $user = Session::get('user');
        if (!$user) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }
        $this->json(['user' => $user]);
    }
}
