<?php
declare(strict_types=1);
// install.php

require_once 'config.php';

$errorLogFile = __DIR__ . '/error.txt';

/**
 * Recursively delete a directory and all its contents.
 */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object !== "." && $object !== "..") {
            $path = $dir . DIRECTORY_SEPARATOR . $object;
            if (is_dir($path)) {
                rrmdir($path);
            } else {
                unlink($path);
            }
        }
    }
    rmdir($dir);
}

try {
    // 1) Drop & recreate the entire database
    $dbNoDB = new PDO("mysql:host=localhost", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $dbNoDB->exec("DROP DATABASE IF EXISTS articles_db");
    $dbNoDB->exec("CREATE DATABASE articles_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2) Connect to that database
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 3) Create `posts` table with a `board` column
    $db->exec("
        CREATE TABLE posts (
          id        INT AUTO_INCREMENT PRIMARY KEY,
          board     VARCHAR(20) NOT NULL,
          parent    INT NOT NULL DEFAULT 0,
          name      VARCHAR(35) NOT NULL,
          subject   VARCHAR(100) NOT NULL,
          message   TEXT NOT NULL,
          image     VARCHAR(255) NOT NULL DEFAULT '',
          timestamp INT NOT NULL,
          bumped    INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 4) Insert a sample post for board "1"
    $now = time();
    $stmt = $db->prepare("
        INSERT INTO posts (board, parent, name, subject, message, image, timestamp, bumped)
        VALUES ('1', 0, 'Admin', 'Welcome to Board #1', 'Sample post on board #1.', '', :ts, :ts)
    ");
    $stmt->execute([':ts' => $now]);

    // 5) Generate numeric board folders (1..100)
    for ($i = 1; $i <= 100; $i++) {
        $boardDir = __DIR__ . '/' . $i;

        // Remove if exists
        if (is_dir($boardDir)) {
            rrmdir($boardDir);
        }
        mkdir($boardDir, 0777, true);
        // Also create an uploads subfolder inside each board
        mkdir($boardDir . '/uploads', 0777, true);

        // Default board index.html
        $defaultHtml = <<<HTML
<!doctype html>
<html>
  <head>
    <meta charset='utf-8'>
    <title>Board %%BOARD%%</title>
    <link rel='stylesheet' href='/stylesheets/style.css'>
  </head>
  <body>
    <div style="margin-bottom: 1em;">
      <a href='/index.html'>[HOME]</a>
    </div>
    <h1>Welcome to Board %%BOARD%%</h1>
    <form action='/board.php' method='post' enctype='multipart/form-data'>
      <input type='hidden' name='board' value='%%BOARD%%'>
      <table>
        <tr>
          <th>Name</th>
          <td><input type='text' name='name' size='25' maxlength='35'></td>
        </tr>
        <tr>
          <th>Subject</th>
          <td>
            <input type='text' name='subject' size='25' maxlength='100'>
            <input type='submit' name='submit_post' value='New Topic'>
          </td>
        </tr>
        <tr>
          <th>Comment</th>
          <td><textarea name='message' rows='5' cols='35'></textarea></td>
        </tr>
        <tr>
          <th>File</th>
          <td><input type='file' name='image'></td>
        </tr>
      </table>
      <input type='hidden' name='csrf_token' value='PLACEHOLDER_CSRF_TOKEN'>
      <input type='hidden' name='parent' value='0'>
    </form>
    <hr>
    <p>No threads yet.</p>
    <div class='pagination'>
      <span>[1]</span>
    </div>
    <script src='/js/jquery.min.js'></script>
    <script src='/js/main.js'></script>
  </body>
</html>
HTML;
        $defaultHtml = str_replace('%%BOARD%%', (string)$i, $defaultHtml);
        file_put_contents($boardDir . '/index.html', $defaultHtml);
    }

    echo "<p>Installation complete: DB dropped & recreated, boards 1–100 with /uploads each.</p>";
} catch (PDOException $ex) {
    $msg = "Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
    file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - " . $ex->getMessage() . "\n", FILE_APPEND);
    echo "<p>{$msg}</p>";
}
?>
