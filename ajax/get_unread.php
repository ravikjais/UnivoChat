<?php
require_once __DIR__ . '/../includes/api.php';
require_approved();

json_response([
    'ok'     => true,
    'unread' => get_total_unread(current_uid()),
]);
