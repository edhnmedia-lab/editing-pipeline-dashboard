<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_json(200, ['user' => ff_current_user()]);
