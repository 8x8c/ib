<?php
declare(strict_types=1);

// board.php

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database credentials
$dbHost = 'localhost';
$dbName = 'articles_db';
$dbUser = 'root';
$dbPass = '';

// Connect
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
try {
    $db = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $ex) {
    error_log("DB Error: " . $ex->getMessage());
    die("DB connection failed: " . $ex->getMessage());
}

// If no form submission, redirect to home
if (!isset($_POST['post'])) {
    header("Location: /index.html");
    exit;
}

// Basic rate limit
$now = time();
if (!empty($_SESSION['last_post_time']) && ($now - $_SESSION['last_post_time']) < 10) {
    die("You're posting too fast. Wait a few seconds.");
}
$_SESSION['last_post_time'] = $now;

// Gather data
$board  = trim($_POST['board'] ?? '1');
$parent = (int)($_POST['parent'] ?? 0);
$name   = trim($_POST['name'] ?? 'Anonymous');
$subject= trim($_POST['subject'] ?? '');
$body   = trim($_POST['body'] ?? '');

// Minimal checks
if ($name === '') {
    $name = 'Anonymous';
}
if ($parent === 0 && $subject === '') {
    die("Subject is required for a new thread.");
}
if ($body === '') {
    die("Comment is required.");
}

$db->beginTransaction();
try {
    // If replying, ensure we match parent's board
    if ($parent !== 0) {
        $chk = $db->prepare("SELECT board FROM posts WHERE id=:pid LIMIT 1");
        $chk->bindValue(':pid', $parent, PDO::PARAM_INT);
        $chk->execute();
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Parent thread does not exist.");
        }
        $board = $row['board'];
        // replies don't keep subject
        $subject = '';
    }

    // Insert base post
    $stmt = $db->prepare("
      INSERT INTO posts (board, parent, name, subject, message, image, thumb, timestamp, bumped)
      VALUES (:b, :p, :n, :s, :m, '', '', :ts, :ts)
    ");
    $stmt->bindValue(':b',  $board);
    $stmt->bindValue(':p',  $parent, PDO::PARAM_INT);
    $stmt->bindValue(':n',  $name);
    $stmt->bindValue(':s',  $subject);
    $stmt->bindValue(':m',  $body);
    $stmt->bindValue(':ts', $now, PDO::PARAM_INT);
    $stmt->execute();

    $postID = (int)$db->lastInsertId();

    // If file upload
    if (!empty($_FILES['file']['name'])) {
        if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
            // Ensure subdirs exist
            $boardDir = __DIR__ . '/' . $board;
            $srcDir   = $boardDir . '/src';
            $thumbDir = $boardDir . '/thumb';
            $resDir   = $boardDir . '/res';

            if (!is_dir($boardDir))  mkdir($boardDir, 0777, true);
            if (!is_dir($srcDir))    mkdir($srcDir, 0777, true);
            if (!is_dir($thumbDir))  mkdir($thumbDir, 0777, true);
            if (!is_dir($resDir))    mkdir($resDir, 0777, true);

            $origName = $_FILES['file']['name'];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $filename = $postID . '.' . $ext;      // e.g. 12.jpg
            $thumb    = $postID . 's.' . $ext;     // e.g. 12s.jpg
            $srcPath  = $srcDir   . '/' . $filename;
            $thmPath  = $thumbDir . '/' . $thumb;

            move_uploaded_file($_FILES['file']['tmp_name'], $srcPath);

            // optional: generate a smaller thumbnail (JPEG only)
            generateThumbnail($srcPath, $thmPath, 255, 255);

            // Update DB
            $upd = $db->prepare("UPDATE posts SET image=:img, thumb=:th WHERE id=:id");
            $upd->bindValue(':img', $filename);
            $upd->bindValue(':th',  $thumb);
            $upd->bindValue(':id',  $postID, PDO::PARAM_INT);
            $upd->execute();
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException("File upload error code: " . $_FILES['file']['error']);
        }
    }

    // If reply, bump the thread
    if ($parent !== 0) {
        $bump = $db->prepare("UPDATE posts SET bumped=:b WHERE id=:pid");
        $bump->bindValue(':b',   $now, PDO::PARAM_INT);
        $bump->bindValue(':pid', $parent, PDO::PARAM_INT);
        $bump->execute();
    }

    $db->commit();

    // Regenerate board pages
    regenerateBoardPages($db, $board);

    if ($parent === 0) {
        // new thread => build that thread page, go to board index
        regenerateThreadPage($db, $board, $postID);
        header("Location: /$board/index.html");
    } else {
        // reply => build parent thread
        regenerateThreadPage($db, $board, $parent);
        header("Location: /$board/index.html");
    }
    exit;

} catch (\Exception $ex) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (!empty($postID)) {
        $del = $db->prepare("DELETE FROM posts WHERE id=:pid");
        $del->bindValue(':pid', $postID, PDO::PARAM_INT);
        $del->execute();
    }
    die("Posting Failure: " . $ex->getMessage());
}

/***********************************************************************
 * generateThumbnail() - create a smaller JPEG for the thumbnail
 ***********************************************************************/
function generateThumbnail(string $srcFile, string $thumbFile, int $maxW, int $maxH): void {
    $info = @getimagesize($srcFile);
    if (!$info) return; // not an image

    [$origW, $origH] = $info;
    $mime = $info['mime'];

    // For example: only handle JPEG
    if ($mime !== 'image/jpeg') {
        return;
    }
    $src = imagecreatefromjpeg($srcFile);

    $ratio = min($maxW / $origW, $maxH / $origH);
    $newW  = (int)($origW * $ratio);
    $newH  = (int)($origH * $ratio);

    $thumb = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    imagejpeg($thumb, $thumbFile, 85);

    imagedestroy($thumb);
    imagedestroy($src);
}

/********************************************************************************
 * regenerateBoardPages() - build /<board>/index.html, /<board>/2.html, etc.
 * with vichan-like HTML
 ********************************************************************************/
function regenerateBoardPages(PDO $db, string $board): void {
    $threadsPerPage = 5;

    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) mkdir($boardDir, 0777, true);

    $srcDir   = $boardDir . '/src';
    $thumbDir = $boardDir . '/thumb';
    $resDir   = $boardDir . '/res';
    if (!is_dir($srcDir))   mkdir($srcDir, 0777, true);
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0777, true);
    if (!is_dir($resDir))   mkdir($resDir, 0777, true);

    // how many top-level threads
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM posts WHERE board=:b AND parent=0");
    $stmtCount->bindValue(':b', $board);
    $stmtCount->execute();
    $totalThreads = (int)$stmtCount->fetchColumn();

    if ($totalThreads < 1) {
        // none => minimal index
        $html = renderBoardPage($db, $board, 1, 1, $threadsPerPage);
        file_put_contents("$boardDir/index.html", $html);
        return;
    }

    $totalPages = (int)ceil($totalThreads / $threadsPerPage);
    for ($p = 1; $p <= $totalPages; $p++) {
        $filename = ($p === 1) ? "index.html" : "$p.html";
        $html     = renderBoardPage($db, $board, $p, $totalPages, $threadsPerPage);
        file_put_contents("$boardDir/$filename", $html);
    }
}

/********************************************************************************
 * renderBoardPage() - produce a vichan-like index page
 ********************************************************************************/
function renderBoardPage(PDO $db, string $board, int $page, int $totalPages, int $threadsPerPage): string {
    $boardTitle = "/$board/ - Chess Board"; // or whatever title you want
    $offset     = ($page - 1) * $threadsPerPage;

    // Grab top-level threads
    $stmt = $db->prepare("
      SELECT p.*,
        (SELECT COUNT(*) FROM posts WHERE board=:b AND parent=p.id) AS reply_count
      FROM posts p
      WHERE board=:b AND parent=0
      ORDER BY bumped DESC
      LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':b',   $board);
    $stmt->bindValue(':lim', $threadsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    ?>
<!doctype html>
<html>
   <head>
      <meta charset="utf-8">
      <script type="text/javascript">
         var active_page = "index", board_name = "<?php echo htmlspecialchars($board); ?>";
      </script>
      <link rel="stylesheet" media="screen" href="/stylesheets/style.css">
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
      <link rel="stylesheet" href="/stylesheets/font-awesome/css/font-awesome.min.css">
      <!-- inline-expanding.js references, etc. -->
      <script type="text/javascript">
         var configRoot="/", inMod=false, modRoot=configRoot+(inMod?"mod.php?/":"");
      </script>
      <script type="text/javascript" src="/js/main.js"></script>
      <script type="text/javascript" src="/js/jquery.min.js"></script>
      <script type="text/javascript" src="/js/inline-expanding.js"></script>

      <title><?php echo htmlspecialchars($boardTitle); ?></title>
   </head>
   <body class="8chan vichan is-not-moderator active-index" data-stylesheet="default">
      <header>
         <h1><?php echo $boardTitle; ?></h1>
         <div class="subtitle"></div>
      </header>

      <!-- Post form (New Topic) -->
      <form name="post" onsubmit="return doPost(this);" enctype="multipart/form-data" action="/board.php" method="post">
         <input type="hidden" name="board" value="<?php echo htmlspecialchars($board); ?>">
         <table>
            <tr>
               <th>Name</th>
               <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off"></td>
            </tr>
            <tr>
               <th>Subject</th>
               <td>
                  <input type="text" name="subject" size="25" maxlength="100" autocomplete="off">
                  <input accesskey="s" type="submit" name="post" value="New Topic">
               </td>
            </tr>
            <tr>
               <th>Comment</th>
               <td><textarea name="body" id="body" rows="5" cols="35"></textarea></td>
            </tr>
            <tr id="upload">
               <th>File</th>
               <td>
                  <input type="file" name="file" id="upload_file">
                  <script type="text/javascript">
                     if (typeof init_file_selector !== 'undefined') init_file_selector(1);
                  </script>
               </td>
            </tr>
         </table>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="parent" value="0">
      </form>
      <script type="text/javascript">rememberStuff();</script>
      <hr />

      <!-- Thread listing -->
      <form name="postcontrols" action="/board.php" method="post">
         <input type="hidden" name="board" value="<?php echo htmlspecialchars($board); ?>">

         <?php
         if (!$threads) {
             echo "<p>No threads yet.</p>";
         } else {
             foreach ($threads as $t) {
                 echo renderThread($t, $board);
             }
         }
         ?>
      </form>

      <!-- Pagination -->
      <?php if ($totalPages > 1) { ?>
      <div class="pagination">
         <?php
         for ($i = 1; $i <= $totalPages; $i++) {
             if ($i === $page) {
                 echo " [$i] ";
             } else {
                 $fn = ($i === 1) ? "index.html" : "{$i}.html";
                 echo " <a href=\"$fn\">[$i]</a> ";
             }
         }
         ?>
      </div>
      <?php } ?>

      <script type="text/javascript">ready();</script>
   </body>
</html>
    <?php
    return ob_get_clean();
}

/********************************************************************************
 * renderThread() - shows an OP post in the board index (vichan-like)
 ********************************************************************************/
function renderThread(array $op, string $board): string {
    $id       = (int)$op['id'];
    $subject  = htmlspecialchars($op['subject'], ENT_QUOTES);
    $name     = htmlspecialchars($op['name'], ENT_QUOTES);
    $msg      = nl2br(htmlspecialchars($op['message'], ENT_QUOTES));
    $timeISO  = date('c', $op['timestamp']);
    $timeDisp = date('m/d/y (D) H:i:s', $op['timestamp']);
    $image    = $op['image'] ?? '';
    $thumb    = $op['thumb'] ?? '';

    // We'll pretend we have a size or dimension. If you actually store them in DB, you can show them. 
    $filesize   = "?? KB";
    $dimensions = "???x???";

    // Full path
    $threadUrl = "/$board/res/$id.html";
    $srcUrl    = $image ? "/$board/src/$image" : '';
    $thumbUrl  = $thumb ? "/$board/thumb/$thumb" : '';

    ob_start();
    ?>
<div class="thread" id="thread_<?php echo $id; ?>" data-board="<?php echo htmlspecialchars($board); ?>">
   <?php if ($image) { ?>
   <div class="files">
      <div class="file">
         <p class="fileinfo">
            File: <a href="<?php echo $srcUrl; ?>"><?php echo basename($srcUrl); ?></a>
            <span class="unimportant">(<?php echo $filesize; ?>, <?php echo $dimensions; ?>, 
               <a class="postfilename" href="<?php echo $srcUrl; ?>" download="<?php echo basename($srcUrl); ?>" title="Save as original filename">
                  <?php echo basename($srcUrl); ?>
               </a>)
            </span>
         </p>
         <a href="<?php echo $srcUrl; ?>" target="_blank"
            onclick="return expandImage(event, this);"
            data-fullimg="<?php echo $srcUrl; ?>">
            <img class="post-image" src="<?php echo $thumbUrl; ?>" style="width:255px;height:255px" alt="">
         </a>
      </div>
   </div>
   <?php } ?>

   <div class="post op" id="op_<?php echo $id; ?>">
      <p class="intro">
         <input type="checkbox" class="delete" name="delete_<?php echo $id; ?>" id="delete_<?php echo $id; ?>">
         <label for="delete_<?php echo $id; ?>">
            <span class="subject"><?php echo $subject; ?> </span>
            <span class="name"><?php echo $name; ?> </span>
            <time datetime="<?php echo $timeISO; ?>"><?php echo $timeDisp; ?></time>
         </label>&nbsp;
         <a class="post_no" id="post_no_<?php echo $id; ?>" onclick="highlightReply(<?php echo $id; ?>)" href="<?php echo $threadUrl; ?>#<?php echo $id; ?>">No.</a>
         <a class="post_no" onclick="citeReply(<?php echo $id; ?>)" href="<?php echo $threadUrl; ?>#q<?php echo $id; ?>"><?php echo $id; ?></a>
         <a href="<?php echo $threadUrl; ?>">[Reply]</a>
      </p>
      <div class="body"><?php echo $msg; ?></div>
   </div>
   <br class="clear"/>
   <hr/>
</div>
    <?php
    return ob_get_clean();
}

/********************************************************************************
 * regenerateThreadPage() - produce /<board>/res/<id>.html (vichan-like)
 ********************************************************************************/
function regenerateThreadPage(PDO $db, string $board, int $threadID): void {
    $boardDir = __DIR__ . '/' . $board;
    if (!is_dir($boardDir)) mkdir($boardDir, 0777, true);

    $srcDir   = $boardDir . '/src';
    $thumbDir = $boardDir . '/thumb';
    $resDir   = $boardDir . '/res';
    if (!is_dir($srcDir))   mkdir($srcDir, 0777, true);
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0777, true);
    if (!is_dir($resDir))   mkdir($resDir, 0777, true);

    // fetch OP
    $stmtOp = $db->prepare("SELECT * FROM posts WHERE board=:b AND id=:tid AND parent=0");
    $stmtOp->bindValue(':b',   $board);
    $stmtOp->bindValue(':tid', $threadID, PDO::PARAM_INT);
    $stmtOp->execute();
    $op = $stmtOp->fetch(PDO::FETCH_ASSOC);
    if (!$op) {
        return; // thread doesn't exist
    }

    // fetch replies
    $stmtRep = $db->prepare("SELECT * FROM posts WHERE board=:b AND parent=:tid ORDER BY timestamp ASC");
    $stmtRep->bindValue(':b',   $board);
    $stmtRep->bindValue(':tid', $threadID, PDO::PARAM_INT);
    $stmtRep->execute();
    $replies = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    ?>
<!doctype html>
<html>
   <head>
      <meta charset="utf-8">
      <script type="text/javascript">
         var active_page = "thread", board_name = "<?php echo htmlspecialchars($board); ?>";
      </script>
      <link rel="stylesheet" media="screen" href="/stylesheets/style.css">
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
      <link rel="stylesheet" href="/stylesheets/font-awesome/css/font-awesome.min.css">
      <script type="text/javascript">
         var configRoot="/", inMod=false, modRoot=configRoot+(inMod?"mod.php?/":"");
      </script>
      <script type="text/javascript" src="/js/main.js"></script>
      <script type="text/javascript" src="/js/jquery.min.js"></script>
      <script type="text/javascript" src="/js/inline-expanding.js"></script>

      <title>Thread #<?php echo $threadID; ?> - /<?php echo htmlspecialchars($board); ?>/</title>
   </head>
   <body class="8chan vichan is-not-moderator active-index" data-stylesheet="default">
      <header>
         <h1>/<?php echo htmlspecialchars($board); ?>/ - Thread #<?php echo $threadID; ?></h1>
      </header>

      <!-- Quick Reply form -->
      <form onsubmit="return doPost(this);" enctype="multipart/form-data" action="/board.php" method="post">
         <input type="hidden" name="board" value="<?php echo htmlspecialchars($board); ?>">
         <table>
            <tr>
               <th>Name</th>
               <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off"></td>
            </tr>
            <tr>
               <th>Comment</th>
               <td><textarea name="body" rows="5" cols="35"></textarea></td>
            </tr>
            <tr id="upload">
               <th>File</th>
               <td><input type="file" name="file"></td>
            </tr>
         </table>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="parent" value="<?php echo $op['id']; ?>">
         <input type="submit" name="post" value="Reply">
      </form>
      <script type="text/javascript">rememberStuff();</script>
      <hr />

      <!-- OP post -->
      <?php echo renderThreadPost($op, $board); ?>

      <!-- Replies -->
      <?php
      if ($replies) {
          foreach ($replies as $r) {
              echo renderThreadPost($r, $board);
          }
      } else {
          echo "<p>No replies yet.</p>";
      }
      ?>

      <script type="text/javascript">ready();</script>
   </body>
</html>
    <?php
    $html = ob_get_clean();
    file_put_contents("$boardDir/res/$threadID.html", $html);
}

/********************************************************************************
 * renderThreadPost() - show an individual post (OP or reply) in the thread page
 ********************************************************************************/
function renderThreadPost(array $p, string $board): string {
    $id       = (int)$p['id'];
    $subject  = htmlspecialchars($p['subject'], ENT_QUOTES);
    $name     = htmlspecialchars($p['name'], ENT_QUOTES);
    $msg      = nl2br(htmlspecialchars($p['message'], ENT_QUOTES));
    $timeISO  = date('c', $p['timestamp']);
    $timeDisp = date('m/d/y (D) H:i:s', $p['timestamp']);
    $image    = $p['image'] ?? '';
    $thumb    = $p['thumb'] ?? '';

    $filesize   = "?? KB";   // if you store file sizes in DB, put them here
    $dimensions = "???x???"; // if you store w/h in DB, put them here

    $srcUrl   = $image ? "/$board/src/$image"   : '';
    $thumbUrl = $thumb ? "/$board/thumb/$thumb" : '';

    ob_start();
    ?>
<div class="thread" id="reply_<?php echo $id; ?>">
   <?php if ($image) { ?>
   <div class="files">
      <div class="file">
         <p class="fileinfo">
           File: <a href="<?php echo $srcUrl; ?>"><?php echo basename($srcUrl); ?></a>
           <span class="unimportant">(<?php echo $filesize; ?>, <?php echo $dimensions; ?>, 
             <a class="postfilename" href="<?php echo $srcUrl; ?>" download="<?php echo basename($srcUrl); ?>" title="Save as original filename">
               <?php echo basename($srcUrl); ?>
             </a>)
           </span>
         </p>
         <a href="<?php echo $srcUrl; ?>" target="_blank"
            onclick="return expandImage(event, this);"
            data-fullimg="<?php echo $srcUrl; ?>">
            <img class="post-image" src="<?php echo $thumbUrl; ?>" style="width:255px;height:255px" alt="">
         </a>
      </div>
   </div>
   <?php } ?>

   <div class="post op" id="op_<?php echo $id; ?>">
      <p class="intro">
         <input type="checkbox" class="delete" name="delete_<?php echo $id; ?>" id="delete_<?php echo $id; ?>">
         <label for="delete_<?php echo $id; ?>">
            <span class="subject"><?php echo $subject; ?> </span>
            <span class="name"><?php echo $name; ?> </span>
            <time datetime="<?php echo $timeISO; ?>"><?php echo $timeDisp; ?></time>
         </label>
         &nbsp;
         <a class="post_no" onclick="highlightReply(<?php echo $id; ?>)" href="#<?php echo $id; ?>">No.</a>
         <a class="post_no" onclick="citeReply(<?php echo $id; ?>)" href="#q<?php echo $id; ?>"><?php echo $id; ?></a>
      </p>
      <div class="body"><?php echo $msg; ?></div>
   </div>
   <br class="clear"/>
   <hr/>
</div>
    <?php
    return ob_get_clean();
}
