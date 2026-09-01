<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_start_session();
$_SESSION = [];
session_destroy();
ff_json(200, ['ok' => true]);
