<?php

require_once __DIR__ . '/app/Core/Database.php';
require_once __DIR__ . '/app/Core/Model.php';
require_once __DIR__ . '/app/Core/Controller.php';
require_once __DIR__ . '/app/Core/Request.php';
require_once __DIR__ . '/app/Core/Response.php';
require_once __DIR__ . '/app/Core/Router.php';
require_once __DIR__ . '/app/Core/Session.php';
require_once __DIR__ . '/app/Core/Validator.php';

require_once __DIR__ . '/app/Controllers/AuthController.php';
require_once __DIR__ . '/app/Controllers/DocumentController.php';
require_once __DIR__ . '/app/Controllers/AdminController.php';

require_once __DIR__ . '/app/Models/Role.php';
require_once __DIR__ . '/app/Models/User.php';
require_once __DIR__ . '/app/Models/DocumentType.php';
require_once __DIR__ . '/app/Models/DocumentStatus.php';
require_once __DIR__ . '/app/Models/StudentDocument.php';
require_once __DIR__ . '/app/Models/DocumentHistory.php';
require_once __DIR__ . '/app/Models/DocumentAnalysis.php';
require_once __DIR__ . '/app/Models/DocumentObservation.php';
require_once __DIR__ . '/app/Models/ValidationRule.php';
require_once __DIR__ . '/app/Models/Permission.php';

require_once __DIR__ . '/app/Services/DocumentIntelligenceService.php';

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Controllers\AuthController;
use App\Controllers\DocumentController;
use App\Controllers\AdminController;

Session::start();

$request = new Request();
$router = new Router();
$auth = new AuthController();
$documents = new DocumentController();
$admin = new AdminController();

$router->add('GET', '/', fn() => include __DIR__ . '/index.html');
$router->add('GET', '/index.php', fn() => include __DIR__ . '/index.html');
$router->add('GET', '/index.html', fn() => include __DIR__ . '/index.html');
$router->add('GET', '/admin', fn() => include __DIR__ . '/pages/admin.html');
$router->add('POST', '/login', fn($req) => $auth->login($req));
$router->add('POST', '/logout', fn() => $auth->logout());
$router->add('GET', '/me', fn() => $auth->me());
$router->add('GET', '/api/documents', fn($req) => $documents->list($req));
$router->add('POST', '/api/documents/upload', fn($req) => $documents->upload($req));
$router->add('GET', '/api/documents/{id}/file', fn($req, $params) => $documents->download($req, $params));
$router->add('GET', '/api/documents/{id}/history', fn($req, $params) => $documents->history($req, $params));
$router->add('PUT', '/api/documents/{id}/status', fn($req, $params) => $documents->changeState($req, $params));
$router->add('GET', '/api/admin/dashboard', fn($req) => $admin->dashboard());
$router->add('GET', '/api/admin/documents', fn($req) => $admin->listDocuments($req));
$router->add('GET', '/api/admin/documents/{id}', fn($req, $params) => $admin->documentDetail($req, $params));
$router->add('PUT', '/api/admin/documents/{id}/reanalyze', fn($req, $params) => $admin->reanalyzeDocument($req, $params));
$router->add('PUT', '/api/admin/documents/{id}/review', fn($req, $params) => $admin->reviewDocument($req, $params));
$router->add('GET', '/api/admin/users', fn() => $admin->listUsers());
$router->add('POST', '/api/admin/users', fn($req) => $admin->createUser($req));
$router->add('GET', '/api/admin/document-types', fn() => $admin->listDocumentTypes());
$router->add('GET', '/api/admin/document-states', fn() => $admin->listStates());
$router->add('GET', '/api/admin/permissions', fn() => $admin->listPermissions());

$router->dispatch($request);
