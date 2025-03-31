<?php
declare(strict_types=1);

// config.php

// IMPORTANT: If your XAMPP root URL is truly http://localhost/, use '/'.
// If you put everything in a subfolder like http://localhost/mychan/,
// change to '/mychan/' and place all files in xampp/htdocs/mychan.
$baseUrl = '/';

// Database credentials
$dsn    = 'mysql:host=localhost;dbname=articles_db;charset=utf8mb4';
$dbUser = 'root';
$dbPass = ''; // Adjust if your MySQL has a password

// Toggle CSRF for local dev
$enableCsrf = false;
