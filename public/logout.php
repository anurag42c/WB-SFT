<?php
require __DIR__ . '/_layout.php';
$_SESSION = []; session_destroy();
redirect('login.php');
