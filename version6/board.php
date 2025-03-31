<?php
declare(strict_types=1);
// board.php

require_once 'config.php';

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

function logError(string $msg): void {
    file_put_contents(__DIR__ . '/error.txt', date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
}

session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// connect DB
try {
    $db = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $ex) {
    logError("DB error: " . $ex->getMessage());
    die("DB connection failure: " . $ex->getMessage());
}

// If no POST, redirect to home (we do not do dynamic listings)
if (!isset($_POST['submit_post'])) {
    header("Location: /index.html");
    exit;
}

// If CSRF is enabled
if ($enableCsrf) {
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== ($_SESSION['csrf_token'] ?? '')) {
        errorExit("Invalid CSRF token.");
    }
}

// Basic spam/ratelimit
$rateLimitSeconds = 10;
$now = time();
if (!empty($_SESSION['last_post_time']) && ($now - $_SESSION['last_post_time']) < $rateLimitSeconds) {
    errorExit("Posting too fast. Wait a few seconds.");
}
$_SESSION['last_post_time'] = $now;

// Gather form data
$parent  = (int)($_POST['parent'] ?? 0);
$message = trim($_POST['message'] ?? '');
$name    = trim($_POST['name'] ?? 'Anonymous');
if ($name === '') {
    $name = 'Anonymous';
}

if ($message === '') {
    errorExit("Message is required.");
} elseif (strlen($message) > 20000) {
    errorExit("Message limit is 20,000 characters.");
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function errorExit(string $msg): never {
    logError($msg);
    echo "<p style='color:red;'>Error: " . h($msg) . "</p>";
    exit;
}

// Figure out the board
if ($parent === 0) {
    // It's a new thread
    $board   = trim($_POST['board'] ?? '1');  // default to "1"
    if ($board === '') $board = '1';

    $subject = trim($_POST['subject'] ?? '');
    if ($subject === '') {
        errorExit("Subject required for a new thread.");
    } elseif (strlen($subject) > 100) {
        errorExit("Subject max length is 100.");
    }
} else {
    // It's a reply. The board is whatever the parent's board is
    $stmt = $db->prepare("SELECT board FROM posts WHERE id=:pid LIMIT 1");
    $stmt->execute([':pid' => $parent]);
    $parentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$parentRow) {
        errorExit("Parent thread not found.");
    }
    $board   = $parentRow['board'];
    $subject = "";
}

// Insert the new post
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        INSERT INTO posts (board, parent, name, subject, message, image, timestamp, bumped)
        VALUES (:b, :p, :n, :s, :m, '', :ts, :ts)
    ");
    $stmt->execute([
        ':b'  => $board,
        ':p'  => $parent,
        ':n'  => ($name ?: 'Anonymous'),
        ':s'  => $subject,
        ':m'  => $message,
        ':ts' => $now
    ]);
    $postID = (int)$db->lastInsertId();

    // Handle file (if any)
    if (!empty($_FILES['image']['name'])) {
        if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $maxFileSize = 2 * 1024 * 1024;
            if ($_FILES['image']['size'] > $maxFileSize) {
                throw new RuntimeException("File too large (max 2MB).");
            }
            $origName  = $_FILES['image']['name'];
            $ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed   = ['png','jpg','jpeg','gif','webp','mp4'];
            if (!in_array($ext, $allowed)) {
                throw new RuntimeException("Invalid file type: $ext");
            }

            // Save into /{board}/uploads
            $boardDir = __DIR__ . '/' . $board;
            $uploadDir = $boardDir . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $tempPath  = $_FILES['image']['tmp_name'];
            $finalName = $postID . '.' . $ext;
            $destPath  = $uploadDir . '/' . $finalName;

            if (!move_uploaded_file($tempPath, $destPath)) {
                throw new RuntimeException("Failed moving uploaded file.");
            }
            // Double-check MIME
            if (!verifyFileType($destPath, $ext)) {
                @unlink($destPath);
                throw new RuntimeException("File content does not match extension.");
            }

            // Update DB row with the filename
            $upd = $db->prepare("UPDATE posts SET image=:img WHERE id=:pid");
            $upd->execute([':img' => $finalName, ':pid' => $postID]);
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException("File upload error code: " . $_FILES['image']['error']);
        }
    }

    // If reply, bump parent
    if ($parent !== 0) {
        $upd2 = $db->prepare("UPDATE posts SET bumped=:b WHERE id=:pid");
        $upd2->execute([':b' => $now, ':pid' => $parent]);
    }

    $db->commit();

    // Re-generate board pages
    regenerateStaticBoardPages($db, $board);
    // Re-generate the thread page
    if ($parent === 0) {
        // brand-new thread => generate its own thread page
        regenerateStaticThreadPage($db, $board, $postID);
        header("Location: /{$board}/index.html");
    } else {
        // reply => refresh the parent thread page
        regenerateStaticThreadPage($db, $board, $parent);
        header("Location: /{$board}/thread_{$parent}.html");
    }
    exit;

} catch (RuntimeException $ex) {
    $db->rollBack();
    // If partial insert
    if (!empty($postID)) {
        $del = $db->prepare("DELETE FROM posts WHERE id=:pid");
        $del->execute([':pid' => $postID]);
    }
    errorExit("Posting Failure: " . $ex->getMessage());
}

/***************************************
 * verifyFileType() checks actual MIME
 ***************************************/
function verifyFileType(string $filepath, string $extension): bool {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $filepath);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($filepath);
    } else {
        $mime = '';
    }
    $imageMimes = ['image/png','image/jpeg','image/gif','image/webp'];
    $videoMimes = ['video/mp4'];

    if (in_array($extension, ['png','jpg','jpeg','gif','webp']) && in_array($mime, $imageMimes)) {
        return true;
    }
    if ($extension === 'mp4' && in_array($mime, $videoMimes)) {
        return true;
    }
    return false;
}

/*****************************************
 * Re-generate static board pages
 *****************************************/
function regenerateStaticBoardPages(PDO $db, string $board): void {
    $threadsPerPage = 5;

    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) {
        mkdir($boardDir, 0777, true);
        mkdir($boardDir . '/uploads', 0777, true);
    }

    // Count top-level threads for this board
    $stmtCount = $db->prepare("
        SELECT COUNT(*) FROM posts 
        WHERE board=:b AND parent=0
    ");
    $stmtCount->execute([':b' => $board]);
    $totalThreads = (int)$stmtCount->fetchColumn();

    if ($totalThreads < 1) {
        // Write out an index.html that says no threads.
        $html = renderBoardPage($db, $board, $threadsPerPage, 1, 1);
        file_put_contents($boardDir . '/index.html', $html);
        return;
    }

    $totalPages = (int)ceil($totalThreads / $threadsPerPage);
    for ($page = 1; $page <= $totalPages; $page++) {
        $html = renderBoardPage($db, $board, $threadsPerPage, $page, $totalPages);
        $fname = ($page === 1) ? 'index.html' : "$page.html";
        file_put_contents($boardDir . '/' . $fname, $html);
    }
}

/**
 * Render a single board page (like /1/index.html or /1/2.html)
 */
function renderBoardPage(PDO $db, string $board, int $threadsPerPage, int $page, int $totalPages): string {
    ob_start();
    ?>
    <h1>Board <?php echo h($board); ?></h1>
    <!-- New Thread Form -->
    <form action="/board.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="board" value="<?php echo h($board); ?>">
      <table>
        <tr><th>Name</th><td><input type="text" name="name" maxlength="35"></td></tr>
        <tr>
          <th>Subject</th>
          <td>
            <input type="text" name="subject" maxlength="100">
            <input type="submit" name="submit_post" value="New Topic">
          </td>
        </tr>
        <tr>
          <th>Comment</th>
          <td><textarea name="message" rows="5" cols="35"></textarea></td>
        </tr>
        <tr>
          <th>File</th>
          <td><input type="file" name="image"></td>
        </tr>
      </table>
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token'] ?? ''); ?>">
      <input type="hidden" name="parent" value="0">
    </form>
    <hr>
    <?php

    // Grab the threads for this page
    $offset = ($page - 1) * $threadsPerPage;
    $stmt = $db->prepare("
      SELECT *, 
             (SELECT COUNT(*) FROM posts WHERE board=:b AND parent=p.id) as reply_count
      FROM posts p
      WHERE board=:b AND parent=0
      ORDER BY bumped DESC
      LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':b', $board);
    $stmt->bindValue(':lim', $threadsPerPage, \PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    $threads = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (!$threads) {
        echo "<p>No threads yet.</p>";
    } else {
        foreach ($threads as $t) {
            $tid = (int)$t['id'];
            showPostPreview($board, $t);
            echo "<p><a href='thread_{$tid}.html'>[Reply]</a></p>";
            echo "<hr>";
        }
    }

    // Pagination
    if ($totalPages > 1) {
        echo "<div class='pagination'>";
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === $page) {
                echo " [{$i}] ";
            } else {
                $link = ($i === 1) ? "index.html" : "{$i}.html";
                echo " <a href='{$link}'>[{$i}]</a> ";
            }
        }
        echo "</div>";
    }

    $content = ob_get_clean();
    return wrapHtml("Board $board - Page $page", $content);
}

/*****************************************
 * Re-generate a single thread page
 *****************************************/
function regenerateStaticThreadPage(PDO $db, string $board, int $threadID): void {
    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) {
        mkdir($boardDir, 0777, true);
        mkdir($boardDir . '/uploads', 0777, true);
    }

    // Fetch the OP
    $stmtOp = $db->prepare("
      SELECT * FROM posts 
      WHERE board=:b AND id=:tid AND parent=0
    ");
    $stmtOp->execute([':b' => $board, ':tid' => $threadID]);
    $op = $stmtOp->fetch(\PDO::FETCH_ASSOC);
    if (!$op) {
        // Thread not found or not in this board
        return;
    }

    // Get replies
    $stmtRep = $db->prepare("
      SELECT * FROM posts
      WHERE board=:b AND parent=:tid
      ORDER BY timestamp ASC
    ");
    $stmtRep->execute([':b' => $board, ':tid' => $threadID]);
    $replies = $stmtRep->fetchAll(\PDO::FETCH_ASSOC);

    ob_start();
    ?>
    <h1>Thread #<?php echo (int)$op['id']; ?> - Board <?php echo h($board); ?></h1>
    <!-- Quick Reply Form -->
    <form action="/board.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token'] ?? ''); ?>">
      <input type="hidden" name="parent" value="<?php echo (int)$op['id']; ?>">
      <!-- board is deduced from the parent's board, but we also pass it for clarity -->
      <input type="hidden" name="board" value="<?php echo h($board); ?>">
      <textarea name="message" rows="4" cols="40" placeholder="Reply text"></textarea><br>
      <input type="file" name="image"><br>
      <button type="submit" name="submit_post">Reply</button>
    </form>
    <hr>
    <?php

    // Show the OP
    showPostPreview($board, $op);

    // Show replies
    if ($replies) {
        foreach ($replies as $r) {
            showPostPreview($board, $r);
        }
    } else {
        echo "<p>No replies yet.</p>";
    }

    $html = wrapHtml("Thread #{$threadID} - Board {$board}", ob_get_clean());
    file_put_contents($boardDir . "/thread_{$threadID}.html", $html);
}

/**
 * showPostPreview() displays an OP or reply, including the image/video link to /{board}/uploads/{filename}
 */
function showPostPreview(string $board, array $p): void {
    $id    = (int)$p['id'];
    $time  = date('m/d/y (D) H:i:s', $p['timestamp']);
    $img   = $p['image'];
    echo "<div class='post'>";
    if ($img !== '') {
        $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
        $url = "/{$board}/uploads/" . h($img);
        if ($ext === 'mp4') {
            echo "<div class='file'><a href='{$url}' target='_blank'>
                  <video src='{$url}' controls class='post-image'></video></a></div>";
        } else {
            echo "<div class='file'><a href='{$url}' target='_blank'>
                  <img src='{$url}' class='post-image'></a></div>";
        }
    }
    echo "<p><strong>" . h($p['subject']) . "</strong> " . h($p['name']) . " <time>{$time}</time></p>";
    echo "<div class='body'>" . nl2br(h($p['message'])) . "</div>";
    echo "</div>";
}

/**
 * Minimal HTML wrapper with absolute CSS/JS references
 */
function wrapHtml(string $title, string $body): string {
    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <link rel="stylesheet" href="/stylesheets/style.css">
</head>
<body>
  <a href="/index.html">[Home]</a>
  <br>
  {$body}
  <script src="/js/jquery.min.js"></script>
  <script src="/js/main.js"></script>
</body>
</html>
HTML;
}
