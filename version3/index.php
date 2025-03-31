<?php
declare(strict_types=1);
require_once 'config.php';

// Configure error logging
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

// A simple helper to log error messages
function logError(string $message): void {
    $logFile = __DIR__ . '/error.txt';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

/*****************************************************
 * Modern Minimal MySQL Board (No HTMX/JS)
 * - Reply page has a "<< Back" button.
 * - Entire page uses a dark background theme.
 *****************************************************/

// Board configuration
$boardTitle       = "Modern Chess Board";
$maxFileSize      = 2 * 1024 * 1024;  // 2 MB
$thumbnailWidth   = 75;
$thumbnailHeight  = 75;
$fullsizeWidth    = 250;
$fullsizeHeight   = 250;
$threadsPerPage   = 5;
$rateLimitSeconds = 10;

// Start session
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Paths
$uploadDir = __DIR__ . '/uploads';
$siteTitle = $boardTitle;

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        logError("Failed to create upload directory: $uploadDir");
        die("Failed to create upload directory.");
    }
}

// Connect to MySQL using PDO
try {
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $ex) {
    logError("DB Connection failed: " . $ex->getMessage());
    die("DB Connection failed: " . $ex->getMessage());
}

// Create table if needed (in case install.php hasn't been run)
try {
    $db->exec("
    CREATE TABLE IF NOT EXISTS posts (
      id        INT AUTO_INCREMENT PRIMARY KEY,
      parent    INT NOT NULL DEFAULT 0,
      subject   VARCHAR(40) NOT NULL,
      message   TEXT NOT NULL,
      image     VARCHAR(255) NOT NULL DEFAULT '',
      timestamp INT NOT NULL,
      bumped    INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $ex) {
    logError("Table creation failed: " . $ex->getMessage());
    die("Table creation failed: " . $ex->getMessage());
}

// Helper: HTML-escape
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Helper: error exit
function errorExit(string $msg): never {
    logError($msg);
    echo "<p class='error'>Error: " . h($msg) . "</p>";
    exit;
}

// Helper: redirect
function redirect(string $url = '?'): never {
    header("Location: $url");
    exit;
}

// Check mime type of uploaded file
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

function showRulesPage(string $errorMessage): never {
    global $boardTitle, $maxFileSize;
    $maxMB = $maxFileSize / (1024 * 1024);
    logError("Upload Failure: " . $errorMessage);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Upload Rules - {$boardTitle}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="api/style.css">
</head>
<body>
  <div class="container">
    <header>
      <h1>Upload Rules for {$boardTitle}</h1>
    </header>
    <div class="content">
      <p class="error">{$errorMessage}</p>
      <p>Allowed file types: PNG, JPG, JPEG, GIF, WEBP, MP4.</p>
      <p>Maximum file size: {$maxMB} MB</p>
      <p><a href="?" class="big-link">Return to Board</a></p>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}

/****************************************************
 * Process POST: New Thread / Reply
 ****************************************************/
if (isset($_POST['submit_post'])) {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        errorExit("Invalid CSRF token.");
    }

    $now = time();
    if (!empty($_SESSION['last_post_time']) && ($now - $_SESSION['last_post_time']) < $rateLimitSeconds) {
        errorExit("You're posting too fast. Please wait a few seconds.");
    }
    $_SESSION['last_post_time'] = $now;

    $parent  = (int)($_POST['parent'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        errorExit("Message is required.");
    }
    if (strlen($message) > 20000) {
        errorExit("Message cannot exceed 20,000 characters.");
    }

    $subject = "";
    if ($parent === 0) {
        $subject = trim($_POST['subject'] ?? '');
        if ($subject === '') {
            errorExit("Subject is required for a new thread.");
        }
        if (strlen($subject) > 39) {
            errorExit("Subject max length is 39.");
        }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO posts (parent, subject, message, image, timestamp, bumped)
            VALUES (:p, :s, :m, '', :ts, :ts)
        ");
        $stmt->execute([
            ':p'  => $parent,
            ':s'  => $subject,
            ':m'  => $message,
            ':ts' => $now
        ]);
        $postID = (int)$db->lastInsertId();

        if ($parent === 0 && !empty($_FILES['image']['name'])) {
            $err = $_FILES['image']['error'];
            if ($err === UPLOAD_ERR_OK) {
                if ($_FILES['image']['size'] > $maxFileSize) {
                    throw new RuntimeException("File too large (max: " . ($maxFileSize/(1024*1024)) . " MB).");
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
                if (!verifyFileType($destPath, $ext)) {
                    @unlink($destPath);
                    throw new RuntimeException("Invalid file content (mime mismatch).");
                }
                $upd = $db->prepare("UPDATE posts SET image=:img WHERE id=:id");
                $upd->execute([':img' => $finalName, ':id' => $postID]);
            } elseif ($err !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException("Error uploading file (code: $err).");
            }
        }

        if ($parent !== 0) {
            $upd2 = $db->prepare("UPDATE posts SET bumped=:b WHERE id=:id");
            $upd2->execute([':b' => $now, ':id' => $parent]);
        }

        $db->commit();
        if ($parent === 0) {
            redirect("?");
        } else {
            redirect("?thread=$parent");
        }
    } catch (RuntimeException $ex) {
        $db->rollBack();
        if (!empty($postID)) {
            $del = $db->prepare("DELETE FROM posts WHERE id=:id");
            $del->execute([':id' => $postID]);
        }
        errorExit("Upload Failure: " . $ex->getMessage());
    }
}

/****************************************************
 * Rendering functions (full page only)
 ****************************************************/
function renderMain(string $title, bool $isMainBoard, string $content): string {
    $nav = '';
    if ($isMainBoard) {
        $nav .= '<a href="#" id="newPostButton">New Post</a> | ';
        $nav .= '<a href="?">Home</a>';
    } else {
        $nav .= '<a href="?"><< Back</a>';
    }
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{$title}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="api/style.css">
</head>
<body>
  <div class="container">
    <div id="main">
      <header>
        <h1>{$title}</h1>
      </header>
      <div class="nav-buttons">
        {$nav}
      </div>
      <div id="content">
        {$content}
      </div>
    </div>
    <footer><p>&copy; {$title}</p></footer>
  </div>
  <script>
    // Simple toggle for the new post form
    document.getElementById('newPostButton')?.addEventListener('click', function(e) {
      e.preventDefault();
      var form = document.getElementById('newPostForm');
      if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
      } else {
        form.style.display = 'none';
      }
    });
  </script>
</body>
</html>
HTML;
}

/****************************************************
 * Main Logic
 ****************************************************/
ob_start();

// Thread view: if a thread is requested via URL parameter
if (isset($_GET['thread'])) {
    $tid = (int)$_GET['thread'];
    $stmtOp = $db->prepare("SELECT * FROM posts WHERE id=:tid AND parent=0 LIMIT 1");
    $stmtOp->execute([':tid' => $tid]);
    $op = $stmtOp->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        echo "<p class='error'>Thread not found.</p>";
    } else {
        echo '<div class="post form-box">';
        echo '<form method="POST">';
        echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
        echo '<input type="hidden" name="parent" value="' . $op['id'] . '">';
        echo '<textarea name="message" placeholder="Reply" required maxlength="20000" rows="4"></textarea>';
        echo '<button type="submit" name="submit_post">Send</button>';
        echo '</form>';
        echo '</div>';

        echo '<div class="post">';
        if ($op['image']) {
            $ext = strtolower(pathinfo($op['image'], PATHINFO_EXTENSION));
            if ($ext === 'mp4') {
                echo "<video src='uploads/" . h($op['image']) . "' controls class='fullsize'></video>";
            } else {
                echo "<img src='uploads/" . h($op['image']) . "' alt='img' class='fullsize'>";
            }
        }
        echo '<h2 style="text-align: center;">' . h($op['subject']) . '</h2>';
        echo '<p class="break-words">' . nl2br(h($op['message'])) . '</p>';
        echo '</div>';

        $stmtRep = $db->prepare("SELECT * FROM posts WHERE parent=:tid ORDER BY timestamp ASC");
        $stmtRep->execute([':tid' => $tid]);
        $reps = $stmtRep->fetchAll(PDO::FETCH_ASSOC);
        if ($reps) {
            foreach ($reps as $r) {
                echo '<div class="post">';
                if ($r['image']) {
                    $rExt = strtolower(pathinfo($r['image'], PATHINFO_EXTENSION));
                    if ($rExt === 'mp4') {
                        echo "<video src='uploads/" . h($r['image']) . "' controls class='fullsize'></video>";
                    } else {
                        echo "<img src='uploads/" . h($r['image']) . "' alt='img' class='fullsize'>";
                    }
                }
                echo '<p class="break-words">' . nl2br(h($r['message'])) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<p class="info">No replies yet.</p>';
        }
    }
    $threadContent = ob_get_clean();
    echo renderMain($siteTitle, false, $threadContent);
    exit;
}

// Main board view: display "New Post" form and list threads
echo '<div id="newPostForm" style="display: none;" class="form-box">';
echo '<form action="" method="POST" enctype="multipart/form-data">';
echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
echo '<input type="hidden" name="parent" value="0">';
echo '<input type="text" name="subject" placeholder="Subject" required maxlength="39">';
echo '<textarea name="message" placeholder="Message" rows="4" required maxlength="20000" class="break-words"></textarea>';
echo '<div>';
echo '<input type="file" name="image">';
echo '<p class="info">Accepted file types: PNG, JPG, JPEG, GIF, WEBP, MP4</p>';
echo '</div>';
echo '<button type="submit" name="submit_post">Send</button>';
echo '</form>';
echo '</div>';

$page   = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $threadsPerPage;

$totalThreads = (int)$db->query("SELECT COUNT(*) FROM posts WHERE parent=0")->fetchColumn();

$stmtThreads = $db->prepare("
  SELECT 
    p.*, 
    (SELECT COUNT(*) FROM posts WHERE parent=p.id) as reply_count
  FROM posts p
  WHERE p.parent=0
  ORDER BY p.bumped DESC
  LIMIT :lim OFFSET :off
");
$stmtThreads->bindValue(':lim', $threadsPerPage, PDO::PARAM_INT);
$stmtThreads->bindValue(':off', $offset, PDO::PARAM_INT);
$stmtThreads->execute();
$threads = $stmtThreads->fetchAll(PDO::FETCH_ASSOC);

if (!$threads) {
    echo "<p class='info'>No threads yet.</p>";
} else {
    foreach ($threads as $t) {
        $tid        = $t['id'];
        $replyCount = (int)$t['reply_count'];
        echo '<div class="post">';
        if ($t['image']) {
            $ext = strtolower(pathinfo($t['image'], PATHINFO_EXTENSION));
            if ($ext === 'mp4') {
                echo "<video src='uploads/" . h($t['image']) . "' controls class='thumbnail'></video>";
            } else {
                echo "<img src='uploads/" . h($t['image']) . "' alt='img' class='thumbnail'>";
            }
        }
        echo '<h2 style="text-align: center;">' . h($t['subject']) . '</h2>';
        $msg = $t['message'];
        if (strlen($msg) > 900) {
            $msg = substr($msg, 0, 900) . "...";
        }
        echo '<p class="break-words">' . nl2br(h($msg)) . '</p>';
        echo "<a href='?thread={$tid}' class='reply-button'>Reply [{$replyCount}]</a>";
        echo '</div>';
    }
    if ($totalThreads > $threadsPerPage) {
        echo '<div class="pagination">';
        $totalPages = (int)ceil($totalThreads / $threadsPerPage);
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === $page) {
                echo "<span>[$i]</span>";
            } else {
                echo "<a href='?page={$i}'>[$i]</a>";
            }
        }
        echo '</div>';
    }
}

$mainContent = ob_get_clean();
echo renderMain($siteTitle, true, $mainContent);
