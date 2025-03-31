<?php
declare(strict_types=1);

// config.php

// If your XAMPP root URL is truly http://localhost/, use '/'. 
// If you put everything in a subfolder (e.g., http://localhost/mychan/),
// then change this to '/mychan/' and put all files there.
$baseUrl = '/';

// Database credentials
$dsn    = 'mysql:host=localhost;dbname=articles_db;charset=utf8mb4';
$dbUser = 'root';
$dbPass = ''; // Change if your MySQL has a password

// Toggle CSRF for local dev
$enableCsrf = false;
