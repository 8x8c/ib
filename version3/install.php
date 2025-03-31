<?php
declare(strict_types=1);
require_once 'config.php';

$errorLogFile = __DIR__ . '/error.txt';

try {
    // Connect to the database using PDO
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Disable foreign key checks to allow dropping tables referenced by other tables
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Drop only the posts table used by this board
    $db->exec("DROP TABLE IF EXISTS posts");
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Create the posts table needed for the board
    $db->exec("
        CREATE TABLE posts (
          id        INT AUTO_INCREMENT PRIMARY KEY,
          parent    INT NOT NULL DEFAULT 0,
          subject   VARCHAR(40) NOT NULL,
          message   TEXT NOT NULL,
          image     VARCHAR(255) NOT NULL DEFAULT '',
          timestamp INT NOT NULL,
          bumped    INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "<p>Installation complete. The posts table has been recreated for the board.</p>";
} catch (PDOException $ex) {
    $errorMessage = "Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
    file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - " . $ex->getMessage() . "\n", FILE_APPEND);
    echo "<p>{$errorMessage}</p>";
}
