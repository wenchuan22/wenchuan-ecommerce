<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}
redirect('/login.php');
