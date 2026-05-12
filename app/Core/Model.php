<?php

namespace App\Core;

use App\Core\Database;
use PDO;

abstract class Model
{
    protected PDO $db;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';
        $this->db = Database::connect($config['db']);
    }
}
