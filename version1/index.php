<?php
declare(strict_types=1);

/*****************************************************
 * index.php
 * Modern Minimal SQLite Board with HTMX-powered navigation
 * - Uses self-hosted CSS (api/style.css), custom JS (api/script.js) and HTMX (api/htmx.js)
 * - Configuration variables for board title, max file size, media display sizes,
 *   posts per page, and rate limit (seconds between posts) are defined at the top.
 * - If an uploaded file is too large or of an unapproved type, a "rules" page is shown.
 * - On the main board, the new thread form shows the subject and message fields first,
 *   then the file upload field with the file rules underneath.
 * - In the post listing (both main board and thread view):
 *   - If media exists, it appears in a floated container at the top left.
 *   - Immediately below (after clearing the float and one line break), the subject is centered.
 *   - Then the message is shown.
 *   - On the main board, the reply button is centered below the message.
 *****************************************************/

// Configuration variables
$boardTitle      = "Modern Chess Board";       // Board title
$maxFileSize     = 2 * 1024 * 1024;              // Maximum file size allowed (2 MB)
$thumbnailWidth  = 75;                         // Thumbnail width (in pixels) for main board view
$thumbnailHeight = 75;                         // Thumbnail height (in pixels) for main board view
$fullsizeWidth   = 250;                        // Fullsize width (in pixels) for thread view
$fullsizeHeight  = 250;                        // Fullsize height (in pixels) for thread view
$threadsPerPage  = 5;                          // Number of posts (threads) to show on the main page
$rateLimitSeconds = 10;                        // Seconds required between posts

// Determine if this is an HTMX (partial) request
$isPartial = isset($_SERVER['HTTP_HX_REQUEST']);

// Start session for CSRF and rate limiting
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Basic config for DB and board
$dbFile    = __DIR__ . '/board.sqlite';
$uploadDir = __DIR__ . '/uploads';
$siteTitle = $boardTitle;

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Function: Show the "rules" page for invalid uploads
function showRulesPage(string $errorMessage): never {
    global $boardTitle, $maxFileSize;
    $maxFileSizeMB = $maxFileSize / (1024 * 1024);
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
      <p>Maximum allowed file size: {$maxFileSizeMB} MB.</p>
      <p><a href="?" class="big-link">Return to Board</a></p>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}

// Connect to SQLite database
$db = new PDO("sqlite:" . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$db->exec("PRAGMA foreign_keys = ON");

// Create table if it doesn't exist
$db->exec("
CREATE TABLE IF NOT EXISTS posts (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  parent    INTEGER NOT NULL DEFAULT 0,
  subject   TEXT    NOT NULL,
  message   TEXT    NOT NULL,
  image     TEXT    NOT NULL DEFAULT '',
  timestamp INTEGER NOT NULL,
  bumped    INTEGER NOT NULL
)
");

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url = '?'): never {
    header("Location: $url");
    exit;
}

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

/****************************************************
 * Process POST: New Thread or Reply
 ****************************************************/
if (isset($_POST['submit_post'])) {
    // CSRF check
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error: invalid CSRF token.");
    }
    // Rate-limit using $rateLimitSeconds variable
    $now = time();
    if (isset($_SESSION['last_post_time']) && ($now - $_SESSION['last_post_time']) < $rateLimitSeconds) {
        die("Error: too fast. Wait a few seconds before posting again.");
    }
    $_SESSION['last_post_time'] = $now;
    $parent  = (int)($_POST['parent'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        die("Error: Message is required.");
    }
    if (strlen($message) > 20000) {
        die("Error: Message cannot exceed 20,000 characters.");
    }
    $subject = "";
    if ($parent === 0) {
        // New thread requires a subject
        $subject = trim($_POST['subject'] ?? '');
        if ($subject === '') {
            die("Error: Subject is required.");
        }
        if (strlen($subject) > 39) {
            die("Error: Subject max length is 39.");
        }
    }
    // Insert new post (thread or reply) with image=''
    $stmt = $db->prepare("
      INSERT INTO posts (parent, subject, message, image, timestamp, bumped)
      VALUES (:p, :sub, :msg, '', :ts, :ts)
    ");
    $stmt->bindValue(':p', $parent);
    $stmt->bindValue(':sub', $subject);
    $stmt->bindValue(':msg', $message);
    $stmt->bindValue(':ts', $now);
    $stmt->execute();
    $postID = $db->lastInsertId();
    // Handle optional file upload (only for new threads)
    if ($parent === 0 && !empty($_FILES['image']['name'])) {
        $err = $_FILES['image']['error'];
        if ($err === UPLOAD_ERR_OK) {
            // Check file size first
            if ($_FILES['image']['size'] > $maxFileSize) {
                $db->exec("DELETE FROM posts WHERE id=$postID");
                showRulesPage("Error: File too large. Maximum allowed file size is " . ($maxFileSize / (1024 * 1024)) . " MB.");
            }
            $origName = $_FILES['image']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','gif','webp','mp4'];
            if (!in_array($ext, $allowed)) {
                $db->exec("DELETE FROM posts WHERE id=$postID");
                showRulesPage("Error: Wrong file type. Allowed types: PNG, JPG, JPEG, GIF, WEBP, MP4.");
            }
            $tempPath = $_FILES['image']['tmp_name'];
            $finalName = $postID . '.' . $ext;
            $destPath  = $uploadDir . '/' . $finalName;
            if (!move_uploaded_file($tempPath, $destPath)) {
                $db->exec("DELETE FROM posts WHERE id=$postID");
                die("Error: could not move uploaded file.");
            }
            if (!verifyFileType($destPath, $ext)) {
                @unlink($destPath);
                $db->exec("DELETE FROM posts WHERE id=$postID");
                showRulesPage("Error: File mismatch or unsafe.");
            }
            // Update the post with the filename
            $upd = $db->prepare("UPDATE posts SET image=:img WHERE id=:id");
            $upd->bindValue(':img', $finalName);
            $upd->bindValue(':id', $postID);
            $upd->execute();
        } elseif ($err !== UPLOAD_ERR_NO_FILE) {
            $db->exec("DELETE FROM posts WHERE id=$postID");
            die("Error uploading file, code:$err");
        }
    }
    // If reply, bump the parent thread's timestamp and redirect to thread view
    if ($parent !== 0) {
        $upd2 = $db->prepare("UPDATE posts SET bumped=:b WHERE id=:id");
        $upd2->bindValue(':b', $now);
        $upd2->bindValue(':id', $parent);
        $upd2->execute();
        redirect("?thread=$parent");
    } else {
        redirect("?");
    }
}

/****************************************************
 * Rendering Functions: Build the Main Area
 ****************************************************/
function renderMain(string $title, bool $showButtons, string $content, bool $isPartial): string {
    // Always output full header to ensure navigation is available.
    $isPartial = false;
    $nav = $showButtons ?
      '<div class="nav-buttons">
         <button id="newPostButton">New Post</button>
         <button hx-get="?" hx-target="#main" hx-swap="outerHTML">Home</button>
       </div>' :
      '<div class="nav-buttons">
         <button hx-get="?" hx-target="#main" hx-swap="outerHTML">&lt;&lt; Back</button>
       </div>';
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{$title}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="api/style.css">
  <script src="api/htmx.js"></script>
  <script src="api/script.js"></script>
</head>
<body>
  <div id="main">
    <header>
      <h1>{$title}</h1>
    </header>
    {$nav}
    <div id="content">
      {$content}
    </div>
    <footer>
      <p>&copy; {$title}</p>
    </footer>
  </div>
</body>
</html>
HTML;
}

/****************************************************
 * Main Content Generation
 ****************************************************/
ob_start();
if (isset($_GET['thread'])) {
    // Thread (Reply) View
    $tid = (int)$_GET['thread'];
    $stmtOp = $db->prepare("SELECT * FROM posts WHERE id=:tid AND parent=0 LIMIT 1");
    $stmtOp->bindValue(':tid', $tid);
    $stmtOp->execute();
    $op = $stmtOp->fetch(PDO::FETCH_ASSOC);
    if (!$op) {
        echo "<p class='error'>Thread not found.</p>";
    } else {
        // Reply form
        echo '<div class="post form-box">';
        echo '<form action="" method="POST">';
        echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
        echo '<input type="hidden" name="parent" value="' . $op['id'] . '">';
        echo '<textarea name="message" placeholder="Reply" required maxlength="20000" rows="4"></textarea>';
        echo '<button type="submit" name="submit_post">Send</button>';
        echo '</form>';
        echo '</div>';
        // Original post with fullsize media
        echo '<div class="post">';
        if ($op['image']) {
            $ext = strtolower(pathinfo($op['image'], PATHINFO_EXTENSION));
            echo '<div class="media-container" style="float: left; margin-right: 1rem;">';
            if ($ext === 'mp4') {
                echo "<video src='uploads/" . h($op['image']) . "' controls style='width:{$fullsizeWidth}px; height:{$fullsizeHeight}px; object-fit: cover;'></video>";
            } else {
                echo "<img src='uploads/" . h($op['image']) . "' alt='img' style='width:{$fullsizeWidth}px; height:{$fullsizeHeight}px; object-fit: cover;'>";
            }
            echo '</div>';
            echo '<div style="clear: both;"></div><br>';
        }
        echo '<h2 style="text-align: center;">' . h($op['subject']) . '</h2>';
        echo '<p class="break-words">' . h($op['message']) . '</p>';
        echo '</div>';
        // Replies with fullsize media
        $stmtRep = $db->prepare("SELECT * FROM posts WHERE parent=:tid ORDER BY timestamp ASC");
        $stmtRep->bindValue(':tid', $tid);
        $stmtRep->execute();
        $replies = $stmtRep->fetchAll(PDO::FETCH_ASSOC);
        if ($replies) {
            foreach ($replies as $r) {
                echo '<div class="post">';
                if ($r['image']) {
                    $rExt = strtolower(pathinfo($r['image'], PATHINFO_EXTENSION));
                    echo '<div class="media-container" style="float: left; margin-right: 1rem;">';
                    if ($rExt === 'mp4') {
                        echo "<video src='uploads/" . h($r['image']) . "' controls style='width:{$fullsizeWidth}px; height:{$fullsizeHeight}px; object-fit: cover;'></video>";
                    } else {
                        echo "<img src='uploads/" . h($r['image']) . "' alt='img' style='width:{$fullsizeWidth}px; height:{$fullsizeHeight}px; object-fit: cover;'>";
                    }
                    echo '</div>';
                    echo '<div style="clear: both;"></div><br>';
                }
                echo '<p class="break-words">' . h($r['message']) . '</p>';
                echo '</div>';
            }
        } else {
            echo "<p class='info'>No replies yet.</p>";
        }
    }
} else {
    // Main Board View
    // New thread form with subject and message first, then file upload with rules underneath
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
    // List threads with pagination; for each post:
    // if media exists, display it in a floated container at the top left,
    // then clear the float and add one line break,
    // then display the subject (centered) on its own line,
    // then the message,
    // then the reply button centered below.
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $threadsPerPage;
    $totalThreads = (int)$db->query("SELECT COUNT(*) FROM posts WHERE parent=0")->fetchColumn();
    $stmtThreads = $db->prepare("
      SELECT * FROM posts
      WHERE parent=0
      ORDER BY bumped DESC
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
            $countStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE parent=:tid");
            $countStmt->bindValue(':tid', $t['id'], PDO::PARAM_INT);
            $countStmt->execute();
            $replyCount = (int)$countStmt->fetchColumn();
            echo '<div class="post">';
            if ($t['image']) {
                $ext = strtolower(pathinfo($t['image'], PATHINFO_EXTENSION));
                echo '<div class="media-container" style="float: left; margin-right: 1rem;">';
                if ($ext === 'mp4') {
                    echo "<video src='uploads/" . h($t['image']) . "' controls style='width:{$thumbnailWidth}px; height:{$thumbnailHeight}px; object-fit: cover;'></video>";
                } else {
                    echo "<img src='uploads/" . h($t['image']) . "' alt='img' style='width:{$thumbnailWidth}px; height:{$thumbnailHeight}px; object-fit: cover;'>";
                }
                echo '</div>';
                // Clear float and add one line break to ensure subject appears below media.
                echo '<div style="clear: both;"></div><br>';
            }
            echo '<div class="post-content" style="overflow: hidden;">';
            echo '<h2 style="text-align: center;">' . h($t['subject']) . '</h2>';
            echo '<p class="break-words">' . h(strlen($t['message']) > 900 ? substr($t['message'], 0, 900) . "..." : $t['message']) . '</p>';
            echo '<div class="reply-container" style="text-align: center; margin-top: 1rem;">';
            echo "<button hx-get='?thread=" . $t['id'] . "' hx-target='#main' hx-swap='outerHTML' class='reply-button'>Reply [{$replyCount}]</button>";
            echo '</div>';
            echo '</div>'; // end post-content
            echo '<div style="clear: both;"></div>';
            echo '</div>'; // end post
        }
    }
    if ($totalThreads > $threadsPerPage) {
        echo '<div class="pagination">';
        $totalPages = ceil($totalThreads / $threadsPerPage);
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == $page) {
                echo "<span>[$i]</span>";
            } else {
                echo "<a hx-get='?page={$i}' hx-target='#main' hx-swap='outerHTML'>[$i]</a>";
            }
        }
        echo '</div>';
    }
}
$mainContent = ob_get_clean();

// Always output the full main area
echo renderMain($siteTitle, isset($_GET['thread']) ? false : true, $mainContent, $isPartial);
?>
