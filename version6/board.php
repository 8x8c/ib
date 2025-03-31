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

// We'll use one global upload folder
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// connect DB
try {
    $db = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $ex) {
    logError("DB error: " . $ex->getMessage());
    die("DB connection failure: " . $ex->getMessage());
}

// Quick helper
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function errorExit(string $msg): never {
    logError($msg);
    echo "<p style='color:red;'>Error: " . h($msg) . "</p>";
    exit;
}
function redirect(string $url): never {
    header("Location: $url");
    exit;
}

/**
 * MIME-check
 */
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
    $imageMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    $videoMimes = ['video/mp4'];

    if (in_array($extension, ['png','jpg','jpeg','gif','webp']) && in_array($mime, $imageMimes)) {
        return true;
    }
    if ($extension === 'mp4' && in_array($mime, $videoMimes)) {
        return true;
    }
    return false;
}

// We won't display a dynamic board. We'll only handle POST.
if (!isset($_POST['submit_post'])) {
    // If someone visits /board.php directly, show a simple message or redirect to /index.html:
    redirect('/index.html');
}

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
    errorExit("You're posting too fast. Wait a few seconds.");
}
$_SESSION['last_post_time'] = $now;

// Grab form inputs
$parent  = (int)($_POST['parent'] ?? 0);
$message = trim($_POST['message'] ?? '');
$name    = trim($_POST['name'] ?? 'Anonymous');
if ($name === '') {
    $name = 'Anonymous';
}

// Validate message
if ($message === '') {
    errorExit("Message is required.");
} elseif (strlen($message) > 20000) {
    errorExit("Message cannot exceed 20,000 characters.");
}

// If parent=0, it's a new thread
if ($parent === 0) {
    $board   = trim($_POST['board'] ?? '1'); // default to "1" if none
    if ($board === '') {
        $board = '1';
    }
    $subject = trim($_POST['subject'] ?? '');
    if ($subject === '') {
        errorExit("Subject is required for a new thread.");
    } elseif (strlen($subject) > 100) {
        errorExit("Subject max length is 100.");
    }
} else {
    // It's a reply. We must find the parent's board from DB
    $stmt = $db->prepare("SELECT board FROM posts WHERE id=:pid LIMIT 1");
    $stmt->execute([':pid' => $parent]);
    $parentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$parentRow) {
        errorExit("Parent thread does not exist.");
    }
    $board   = $parentRow['board']; // Force the reply into the same board as the parent
    $subject = "";                  // replies have no separate subject
}

// Insert post
$db->beginTransaction();
try {
    // The new post: set "board" field
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

    // If there's an uploaded file, handle it
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
            $tempPath  = $_FILES['image']['tmp_name'];
            $finalName = $postID . '.' . $ext;
            $destPath  = $uploadDir . '/' . $finalName;

            if (!move_uploaded_file($tempPath, $destPath)) {
                throw new RuntimeException("Failed to move uploaded file.");
            }
            // Double-check MIME
            if (!verifyFileType($destPath, $ext)) {
                @unlink($destPath);
                throw new RuntimeException("File content does not match extension.");
            }

            // Update the row with the filename
            $upd = $db->prepare("UPDATE posts SET image=:img WHERE id=:pid");
            $upd->execute([':img' => $finalName, ':pid' => $postID]);
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException("File upload error code: " . $_FILES['image']['error']);
        }
    }

    // If it's a reply, bump the parent
    if ($parent !== 0) {
        $upd2 = $db->prepare("UPDATE posts SET bumped=:bump WHERE id=:pid");
        $upd2->execute([':bump' => $now, ':pid' => $parent]);
    }

    $db->commit();

    // Re-generate the static pages
    regenerateStaticBoardPages($db, $board);
    if ($parent === 0) {
        // brand-new thread => generate its thread page
        regenerateStaticThreadPage($db, $board, $postID);
        // redirect to new board's index
        redirect("/{$board}/index.html");
    } else {
        // reply => re-generate parent thread
        regenerateStaticThreadPage($db, $board, $parent);
        redirect("/{$board}/thread_{$parent}.html");
    }

} catch (RuntimeException $ex) {
    $db->rollBack();
    // If the post was inserted partially, remove it
    if (!empty($postID)) {
        $del = $db->prepare("DELETE FROM posts WHERE id=:pid");
        $del->execute([':pid' => $postID]);
    }
    errorExit("Posting Failure: " . $ex->getMessage());
}

/**********************************************
 * generate static board pages
 **********************************************/
function regenerateStaticBoardPages(PDO $db, string $board): void {
    // We'll show 5 threads per page. Could make it a global or config.
    $threadsPerPage = 5;

    // Clean up or create the board folder
    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) {
        mkdir($boardDir, 0777, true);
    }

    // Count how many top-level threads in this board
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM posts WHERE board=:b AND parent=0");
    $stmtCount->execute([':b' => $board]);
    $totalThreads = (int)$stmtCount->fetchColumn();

    // If no threads, just keep the default "no threads" index.html.
    if ($totalThreads < 1) {
        // But if you want to rewrite the index.html to show 0 threads, do so.
        // We'll do it anyway for consistency:
        $html = renderStaticBoard($db, $board, $threadsPerPage, 1, 1);
        file_put_contents($boardDir . '/index.html', $html);
        return;
    }

    // We have threads, compute pages
    $totalPages = (int)ceil($totalThreads / $threadsPerPage);
    // Generate each page: 1 => index.html, 2 => "2.html", etc.
    for ($page = 1; $page <= $totalPages; $page++) {
        $html   = renderStaticBoard($db, $board, $threadsPerPage, $page, $totalPages);
        $fname  = ($page === 1) ? 'index.html' : "{$page}.html";
        file_put_contents($boardDir . '/' . $fname, $html);
    }
}

/**
 * Generate the content of a single board page (like /1/index.html or /1/2.html).
 */
function renderStaticBoard(PDO $db, string $board, int $threadsPerPage, int $currentPage, int $totalPages): string {
    ob_start();

    // Show the new thread form
    ?>
    <h1>Board <?php echo h($board); ?></h1>
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
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="parent" value="0">
    </form>
    <hr>
    <?php

    // Grab the correct set of top-level threads
    $offset = ($currentPage - 1) * $threadsPerPage;
    $stmt = $db->prepare("
      SELECT *, 
             (SELECT COUNT(*) FROM posts WHERE board=:b AND parent=p.id) as reply_count
      FROM posts p
      WHERE board=:b AND parent=0
      ORDER BY bumped DESC
      LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':b', $board, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $threadsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$threads) {
        echo "<p>No threads yet.</p>";
    } else {
        foreach ($threads as $t) {
            $tid   = (int)$t['id'];
            $time  = date('m/d/y (D) H:i:s', $t['timestamp']);
            echo "<div class='thread'>";
            if ($t['image']) {
                $ext = strtolower(pathinfo($t['image'], PATHINFO_EXTENSION));
                if ($ext === 'mp4') {
                    echo "<div class='file'>
                            <a href='/uploads/" . h($t['image']) . "' target='_blank'>
                            <video src='/uploads/" . h($t['image']) . "' controls class='post-image'></video></a>
                          </div>";
                } else {
                    echo "<div class='file'>
                            <a href='/uploads/" . h($t['image']) . "' target='_blank'>
                            <img src='/uploads/" . h($t['image']) . "' class='post-image'></a>
                          </div>";
                }
            }
            echo "<div class='post op'>";
            echo "<p>
                    <strong>" . h($t['subject']) . "</strong> 
                    " . h($t['name']) . " 
                    <time>{$time}</time> 
                    <a href='thread_{$tid}.html'>[Reply]</a>
                  </p>";
            echo "<div class='body'>" . nl2br(h($t['message'])) . "</div>";
            echo "</div>";
            echo "</div><hr>";
        }
    }

    // Pagination
    if ($totalPages > 1) {
        echo "<div class='pagination'>";
        for ($p = 1; $p <= $totalPages; $p++) {
            if ($p === $currentPage) {
                echo " [{$p}] ";
            } else {
                $file = ($p === 1) ? "index.html" : "{$p}.html";
                echo " <a href='{$file}'>[{$p}]</a> ";
            }
        }
        echo "</div>";
    }

    $content = ob_get_clean();
    return wrapHtml("Board {$board}", $content);
}

/**********************************************
 * generate static thread pages
 **********************************************/
function regenerateStaticThreadPage(PDO $db, string $board, int $threadID): void {
    // Make sure the board folder exists
    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) {
        mkdir($boardDir, 0777, true);
    }

    // Fetch OP
    $stmtOp = $db->prepare("SELECT * FROM posts WHERE id=:tid AND board=:b AND parent=0");
    $stmtOp->execute([':tid' => $threadID, ':b' => $board]);
    $op = $stmtOp->fetch(PDO::FETCH_ASSOC);
    if (!$op) {
        // The OP is not found or not in this board, do nothing
        return;
    }

    ob_start();
    // Quick reply form
    ?>
    <h1>Thread #<?php echo $op['id']; ?> (Board <?php echo h($board); ?>)</h1>
    <form action="/board.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
      <input type="hidden" name="parent" value="<?php echo (int)$op['id']; ?>">
      <!-- board is determined by the parent's board, but let's also include it for safety -->
      <input type="hidden" name="board" value="<?php echo h($board); ?>">
      <textarea name="message" rows="4" cols="40" placeholder="Reply text"></textarea><br>
      <input type="file" name="image"><br>
      <button type="submit" name="submit_post">Reply</button>
    </form>
    <hr>
    <?php

    // Display the OP
    showPost($op);

    // Now fetch replies
    $stmtRep = $db->prepare("SELECT * FROM posts WHERE board=:b AND parent=:tid ORDER BY timestamp ASC");
    $stmtRep->execute([':b' => $board, ':tid' => $threadID]);
    $replies = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

    if ($replies) {
        foreach ($replies as $r) {
            showPost($r);
        }
    } else {
        echo "<p>No replies yet.</p>";
    }

    $threadHtml = ob_get_clean();
    $fullHtml   = wrapHtml("Thread #{$threadID} - Board {$board}", $threadHtml);

    $filename = $boardDir . "/thread_{$threadID}.html";
    file_put_contents($filename, $fullHtml);
}

/**
 * Helper to display a single post record (OP or reply).
 */
function showPost(array $row): void {
    $time = date('m/d/y (D) H:i:s', $row['timestamp']);
    echo "<div class='post'>";
    if ($row['image'] !== '') {
        $ext = strtolower(pathinfo($row['image'], PATHINFO_EXTENSION));
        if ($ext === 'mp4') {
            echo "<div class='file'>
                    <a href='/uploads/" . h($row['image']) . "' target='_blank'>
                    <video src='/uploads/" . h($row['image']) . "' controls class='post-image'></video></a>
                  </div>";
        } else {
            echo "<div class='file'>
                    <a href='/uploads/" . h($row['image']) . "' target='_blank'>
                    <img src='/uploads/" . h($row['image']) . "' class='post-image'></a>
                  </div>";
        }
    }
    echo "<p>
            <strong>" . h($row['subject']) . "</strong> 
            " . h($row['name']) . " 
            <time>{$time}</time>
          </p>";
    echo "<div class='body'>" . nl2br(h($row['message'])) . "</div>";
    echo "</div><hr>";
}

/**
 * Utility to wrap content in minimal HTML with absolute CSS/JS paths.
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
