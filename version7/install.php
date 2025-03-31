<?php
declare(strict_types=1);

// install.php (in base dir)

// DB config
$dbHost = 'localhost';
$dbName = 'articles_db';
$dbUser = 'root';
$dbPass = ''; // Change if your MySQL has a password

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
    // 1) Connect without specifying DB so we can drop & create it
    $pdo0 = new PDO("mysql:host=$dbHost", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Drop the database if it exists
    $pdo0->exec("DROP DATABASE IF EXISTS $dbName");
    // Recreate
    $pdo0->exec("CREATE DATABASE $dbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2) Now connect to that DB
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $db  = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 3) Create the `posts` table with a `board` column and `thumb` column
    $db->exec("
        CREATE TABLE posts (
          id        INT AUTO_INCREMENT PRIMARY KEY,
          board     VARCHAR(20) NOT NULL,
          parent    INT NOT NULL DEFAULT 0,
          name      VARCHAR(35) NOT NULL,
          subject   VARCHAR(100) NOT NULL,
          message   TEXT NOT NULL,
          image     VARCHAR(255) NOT NULL DEFAULT '',
          thumb     VARCHAR(255) NOT NULL DEFAULT '',
          timestamp INT NOT NULL,
          bumped    INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 4) Create numeric board directories (1..100) with src/thumb/res, plus a minimal index.html
    for ($i = 1; $i <= 100; $i++) {
        $boardDir = __DIR__ . '/' . $i;

        // If it exists, remove it
        if (is_dir($boardDir)) {
            rrmdir($boardDir);
        }
        // Make the board folder
        mkdir($boardDir, 0777, true);

        // Make subfolders for full images, thumbnails, and thread pages
        mkdir($boardDir . '/src', 0777, true);
        mkdir($boardDir . '/thumb', 0777, true);
        mkdir($boardDir . '/res', 0777, true);

        // A placeholder index.html that references /board.php
        // This won't show any actual threads or pagination yet—board.php will overwrite
        // it or regenerate a new one once the user posts a thread on this board.
        $placeholder = <<<HTML
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Board %%BOARD%% (Placeholder)</title>
    <link rel="stylesheet" href="/stylesheets/style.css">
  </head>
  <body>
    <h1>Board %%BOARD%%</h1>
    <p>No threads yet. Once you post, the static pages get generated.</p>
    <form action="/board.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="board" value="%%BOARD%%">
      <table>
        <tr><th>Name</th><td><input type="text" name="name" maxlength="35"></td></tr>
        <tr><th>Subject</th><td>
          <input type="text" name="subject" maxlength="100">
          <input type="submit" name="post" value="New Topic">
        </td></tr>
        <tr><th>Comment</th><td><textarea name="body" rows="5" cols="35"></textarea></td></tr>
        <tr><th>File</th><td><input type="file" name="file"></td></tr>
      </table>
      <input type="hidden" name="parent" value="0">
      <input type="hidden" name="csrf_token" value="PLACEHOLDER_TOKEN">
    </form>
  </body>
</html>
HTML;
        $placeholder = str_replace('%%BOARD%%', (string)$i, $placeholder);
        file_put_contents($boardDir . '/index.html', $placeholder);
    }

    echo "<p>Installation complete. Database dropped & recreated, table created, and boards 1..100 set up with placeholder index.html!</p>";

} catch (PDOException $ex) {
    echo "<p>DB Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES) . "</p>";
}
