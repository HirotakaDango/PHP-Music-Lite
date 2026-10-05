<?php
// Session configured for 1-year lifetime
$one_year = 31536000;
ini_set('session.cookie_lifetime', $one_year);
ini_set('session.gc_maxlifetime', $one_year);
session_set_cookie_params([
  'lifetime' => $one_year,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

// Strict Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// PWA Handlers
if (isset($_GET['pwa'])) {
  if ($_GET['pwa'] === 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      "name" => "PHP-Music-Lite",
      "short_name" => "Music Lite",
      "start_url" => ".",
      "display" => "standalone",
      "background_color" => "#030303",
      "theme_color" => "#121212",
      "description" => "A fast, lightweight music player.",
      "icons" => [
        [
          "src" => "?action=get_app_icon",
          "sizes" => "any",
          "type" => "image/svg+xml",
          "purpose" => "any"
        ]
      ]
    ]);
    exit;
  }
  if ($_GET['pwa'] === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo <<<SW
    const CACHE_NAME = 'php-music-lite-v2';
    const STATIC_ASSETS = [
      './',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
      'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js',
      'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'
    ];

    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(STATIC_ASSETS)));
      self.skipWaiting();
    });

    self.addEventListener('activate', event => {
      event.waitUntil(
        caches.keys().then(keys => Promise.all(
          keys.map(k => k !== CACHE_NAME ? caches.delete(k) : null)
        ))
      );
      self.clients.claim();
    });

    self.addEventListener('fetch', event => {
      const url = new URL(event.request.url);
      if (url.searchParams.has('action') || url.searchParams.has('share_type') || url.searchParams.has('view') || url.searchParams.has('pwa')) {
        event.respondWith(fetch(event.request));
        return;
      }
      event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request).then(resp => {
          if (resp && resp.ok) {
            caches.open(CACHE_NAME).then(c => c.put(event.request, resp.clone()));
          }
          return resp;
        }))
      );
    });
SW;
    exit;
  }
}

set_time_limit(0);

define('MUSIC_DIR', __DIR__);
define('DB_FILE', __DIR__ . '/music.db');
define('PAGE_SIZE', 25);

function get_db() {
  try {
    $db = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_TIMEOUT => 30]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys = ON;");
    return $db;
  } catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
  }
}

if (file_exists(__DIR__ . '/getid3/getid3.php')) {
  require_once __DIR__ . '/getid3/getid3.php';
}

function send_json($data, $code = 200) {
  if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function init_db($db) {
  $db->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      email TEXT UNIQUE,
      artist TEXT,
      password_hash TEXT,
      verified TEXT DEFAULT 'yes'
    );
    CREATE TABLE IF NOT EXISTS music (
      id INTEGER PRIMARY KEY,
      user_id INTEGER,
      file TEXT UNIQUE,
      title TEXT,
      artist TEXT,
      album TEXT,
      year INTEGER,
      duration INTEGER,
      image BLOB,
      last_modified INTEGER,
      bitrate INTEGER,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    );
    CREATE TABLE IF NOT EXISTS favorites (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS playlists (
      id INTEGER PRIMARY KEY,
      user_id INTEGER NOT NULL,
      name TEXT NOT NULL,
      public_id TEXT UNIQUE NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS playlist_songs (
      playlist_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (playlist_id, song_id),
      FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS music_artist_idx ON music(artist);
    CREATE INDEX IF NOT EXISTS music_album_idx ON music(album);
    CREATE INDEX IF NOT EXISTS fav_user_id_idx ON favorites(user_id);
    CREATE INDEX IF NOT EXISTS playlists_user_id_idx ON playlists(user_id);
    CREATE INDEX IF NOT EXISTS playlists_public_id_idx ON playlists(public_id);
    CREATE INDEX IF NOT EXISTS playlist_songs_playlist_id_idx ON playlist_songs(playlist_id);
  ");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO users (email, artist, password_hash, verified) VALUES (?, ?, ?, ?)")
      ->execute(['musiclibrary@mail.com', 'Music Library', password_hash('musiclibrary', PASSWORD_DEFAULT), 'yes']);
  }
}

function process_image_to_webp($imageData, $target_width = 300, $quality = 70) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    return null;
  }
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) return null;

  $resizedImage = imagecreatetruecolor($target_width, $target_width);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $target_width, $target_width, imagesx($sourceImage), imagesy($sourceImage));

  ob_start();
  imagewebp($resizedImage, null, $quality);
  $webpData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

// Router for API actions
if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  init_db($db);

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $user_id = $_SESSION['user_id'] ?? null;
  $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  switch ($action) {
    case 'get_app_icon':
      header('Content-Type: image/svg+xml');
      header('Cache-Control: public, max-age=31536000');
      $size = intval($_GET['size'] ?? 192);
      echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="'.$size.'" height="'.$size.'">'
        . '<rect width="200" height="200" rx="46" fill="#0d0d0f"/>'
        . '<rect x="26" y="76" width="15" height="40" rx="7.5" fill="#ffffff"/>'
        . '<rect x="52.5" y="51" width="15" height="90" rx="7.5" fill="#ff1744"/>'
        . '<rect x="79" y="26" width="15" height="140" rx="7.5" fill="#ffffff"/>'
        . '<rect x="105.5" y="51" width="15" height="90" rx="7.5" fill="#ffffff"/>'
        . '<rect x="132" y="76" width="15" height="40" rx="7.5" fill="#ffffff"/>'
        . '<rect x="158.5" y="51" width="15" height="90" rx="7.5" fill="#ffffff"/>'
        . '</svg>';
      exit;

    case 'get_session':
      if ($user_id) {
        $stmt = $db->prepare("SELECT id, email, artist FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user) send_json(['status' => 'loggedin', 'user' => $user]);
      }
      send_json(['status' => 'loggedout']);
      break;

    case 'register':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
      $artist = trim(htmlspecialchars($data['artist'] ?? '', ENT_QUOTES, 'UTF-8'));
      $password = $data['password'] ?? '';

      if (!$email || empty($artist) || strlen($password) < 6) {
        send_json(['status' => 'error', 'message' => 'Valid email and 6+ character password required.'], 400);
      }
      $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        send_json(['status' => 'error', 'message' => 'Email already registered.'], 409);
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $db->prepare("INSERT INTO users (email, artist, password_hash) VALUES (?, ?, ?)");
      $stmt->execute([$email, $artist, $hash]);
      $new_user_id = (int)$db->lastInsertId();

      session_regenerate_id(true);
      $_SESSION['user_id'] = $new_user_id;
      $_SESSION['user_artist'] = $artist;

      send_json([
        'status' => 'success',
        'message' => 'Registration successful.',
        'user' => ['id' => $new_user_id, 'email' => $email, 'artist' => $artist]
      ]);
      break;

    case 'login':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
      $password = $data['password'] ?? '';

      if (!$email || empty($password)) {
        send_json(['status' => 'error', 'message' => 'Email and password required.'], 400);
      }
      $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      $user = $stmt->fetch();
      if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_artist'] = $user['artist'];
        unset($user['password_hash']);
        send_json(['status' => 'success', 'user' => $user]);
      }
      send_json(['status' => 'error', 'message' => 'Invalid email or password.'], 401);
      break;

    case 'logout':
      $_SESSION = [];
      if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
      }
      session_destroy();
      send_json(['status' => 'success']);
      break;

    case 'change_name':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $new_name = trim(htmlspecialchars($data['artist'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($new_name)) send_json(['status' => 'error', 'message' => 'Name cannot be empty.'], 400);
      $db->prepare("UPDATE users SET artist = ? WHERE id = ?")->execute([$new_name, $user_id]);
      $_SESSION['user_artist'] = $new_name;
      send_json(['status' => 'success', 'message' => 'Display name updated.', 'artist' => $new_name]);
      break;

    case 'change_password':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $new_password = $data['new_password'] ?? '';
      if (strlen($new_password) < 6) send_json(['status' => 'error', 'message' => 'Password must be 6+ characters.'], 400);
      $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);
      send_json(['status' => 'success', 'message' => 'Password changed successfully.']);
      break;

    case 'delete_account':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
      $_SESSION = [];
      if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
      }
      session_destroy();
      send_json(['status' => 'success', 'message' => 'Account deleted successfully.']);
      break;

    case 'full_scan':
      perform_full_scan($db);
      exit;

    case 'get_songs':
      $sort_map = [
        'id_desc' => 'ORDER BY m.id DESC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.title COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'title_asc'] ?? $sort_map['title_asc'];

      $where_clauses = [];
      $params = [$user_id];
      if (!empty($_GET['artist'])) { $where_clauses[] = 'm.artist = ?'; $params[] = $_GET['artist']; }
      if (!empty($_GET['album'])) { $where_clauses[] = 'm.album = ?'; $params[] = $_GET['album']; }
      $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        {$where_sql} {$order_by} {$limit_clause}
      ");
      $stmt->execute($params);
      send_json($stmt->fetchAll());
      break;

    case 'get_favorites':
      if (!$user_id) { send_json([]); }
      $sort_map = [
        'manual_order' => 'ORDER BY f.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'manual_order'] ?? $sort_map['manual_order'];
      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id, 1 as is_favorite
        FROM music m
        JOIN favorites f ON m.id = f.song_id
        WHERE f.user_id = ? {$order_by} {$limit_clause}
      ");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'get_playlist_songs':
      $public_id = $_GET['public_id'] ?? '';
      $sort_map = [
        'manual_order' => 'ORDER BY ps.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'manual_order'] ?? $sort_map['manual_order'];

      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        JOIN playlist_songs ps ON m.id = ps.song_id
        JOIN playlists p ON ps.playlist_id = p.id
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE p.public_id = ?
        {$order_by} {$limit_clause}
      ");
      $stmt->execute([$user_id, $public_id]);
      send_json($stmt->fetchAll());
      break;

    case 'toggle_favorite':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = (int)($data['id'] ?? 0);
      $stmt = $db->prepare("SELECT song_id FROM favorites WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM favorites WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        send_json(['status' => 'removed', 'is_favorite' => false]);
      }
      $stmt_order = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM favorites WHERE user_id = ?");
      $stmt_order->execute([$user_id]);
      $max_order = (int)$stmt_order->fetchColumn();
      $db->prepare("INSERT INTO favorites (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
      send_json(['status' => 'added', 'is_favorite' => true]);
      break;

    case 'update_favorite_order':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $ordered_ids = $data['ids'] ?? [];
      $db->beginTransaction();
      try {
        $stmt = $db->prepare("UPDATE favorites SET sort_order = ? WHERE user_id = ? AND song_id = ?");
        foreach ($ordered_ids as $index => $song_id) {
          $stmt->execute([$index, (int)$user_id, (int)$song_id]);
        }
        $db->commit();
        send_json(['status' => 'success']);
      } catch (Exception $e) {
        $db->rollBack();
        send_json(['status' => 'error', 'message' => 'Failed to save order'], 500);
      }
      break;

    case 'get_view_ids':
      $post_data = json_decode(file_get_contents('php://input'), true);
      $view_type = $post_data['view_type'] ?? '';
      $param = urldecode($post_data['param'] ?? '');
      $sort = $post_data['sort'] ?? '';

      $sql = "SELECT m.id FROM music m ";
      $conditions = "";
      $params = [];
      $default_sort = 'title_asc';

      switch ($view_type) {
        case 'get_songs': break;
        case 'get_favorites':
          if (!$user_id) { send_json([]); }
          $sql = "SELECT m.id FROM music m JOIN favorites f ON m.id = f.song_id ";
          $conditions = "WHERE f.user_id = ?";
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'artist_songs':
          $conditions = "WHERE m.artist = ?";
          $params[] = $param;
          $default_sort = 'album_asc';
          break;
        case 'album_songs':
          $conditions = "WHERE m.album = ?";
          $params[] = $param;
          $default_sort = 'title_asc';
          break;
        case 'playlist_songs':
          $sql = "SELECT m.id FROM music m JOIN playlist_songs ps ON m.id = ps.song_id JOIN playlists p ON ps.playlist_id = p.id ";
          $conditions = "WHERE p.public_id = ?";
          $params[] = $param;
          $default_sort = 'manual_order';
          break;
        case 'search':
          $conditions = "WHERE m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param];
          break;
        default:
          send_json([]);
      }

      $sort_map = [
        'manual_order' => ($view_type === 'get_favorites') ? 'ORDER BY f.sort_order ASC' : 'ORDER BY ps.sort_order ASC',
        'id_desc' => 'ORDER BY m.id DESC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.title COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];

      $stmt = $db->prepare($sql . $conditions . " " . $order_by);
      $stmt->execute($params);
      send_json($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_artists':
      $sort_map = [
        'name_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
        'name_desc' => 'ORDER BY m.artist COLLATE NOCASE DESC',
        'song_count_desc' => 'ORDER BY song_count DESC',
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'name_asc'] ?? $sort_map['name_asc'];
      $stmt = $db->prepare("
        SELECT m.artist,
          (SELECT m2.id FROM music m2 WHERE m2.artist = m.artist AND m2.image IS NOT NULL LIMIT 1) AS image_id,
          COUNT(m.id) AS song_count
        FROM music m
        WHERE m.artist != '' AND m.artist IS NOT NULL
        GROUP BY m.artist
        {$order_by} {$limit_clause}
      ");
      $stmt->execute();
      send_json($stmt->fetchAll());
      break;

    case 'get_albums':
      $sort_map = [
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
        'album_desc' => 'ORDER BY m.album COLLATE NOCASE DESC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY MAX(m.year) DESC',
        'year_asc' => 'ORDER BY MAX(m.year) ASC',
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'album_asc'] ?? $sort_map['album_asc'];
      $stmt = $db->prepare("
        SELECT m.album, m.artist,
          (SELECT m2.id FROM music m2 WHERE m2.album = m.album AND m2.image IS NOT NULL LIMIT 1) AS image_id,
          COUNT(m.id) AS song_count
        FROM music m
        WHERE m.album != '' AND m.album IS NOT NULL
        GROUP BY m.album
        {$order_by} {$limit_clause}
      ");
      $stmt->execute();
      send_json($stmt->fetchAll());
      break;

    case 'get_view_data':
      $type = $_GET['type'] ?? '';
      $name = rawurldecode($_GET['name'] ?? '');
      $sort = $_GET['sort'] ?? 'title_asc';
      if (empty($type) || empty($name)) { send_json(['error' => 'Invalid params'], 400); }

      $details = null;
      $songs = [];

      if ($type === 'playlist') {
        $stmt_details = $db->prepare("
          SELECT p.name, p.public_id, u.artist as creator,
          (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = p.id) as song_count,
          (SELECT SUM(m.duration) FROM music m JOIN playlist_songs ps ON m.id = ps.song_id WHERE ps.playlist_id = p.id) as total_duration,
          (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) as image_id
          FROM playlists p JOIN users u ON p.user_id = u.id
          WHERE p.public_id = ?
        ");
        $stmt_details->execute([$name]);
        $details = $stmt_details->fetch();
        if ($details) $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);

        $sort_map = [
          'manual_order' => 'ORDER BY ps.sort_order ASC',
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
          'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['manual_order'];

        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id,
          CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m
          JOIN playlist_songs ps ON m.id = ps.song_id
          JOIN playlists p ON ps.playlist_id = p.id
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE p.public_id = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $name]);
        $songs = $stmt_songs->fetchAll();
      } elseif (in_array($type, ['artist', 'album'])) {
        $field = $type;
        $stmt_details = $db->prepare("
          SELECT COUNT(*) as song_count, SUM(duration) as total_duration,
          (SELECT id FROM music WHERE {$field} = ? AND image IS NOT NULL LIMIT 1) as image_id
          FROM music WHERE {$field} = ?
        ");
        $stmt_details->execute([$name, $name]);
        $details = $stmt_details->fetch();
        $details['name'] = $name;
        $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
        $details['public_id'] = null;

        $sort_map = [
          'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC',
          'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
          'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC',
          'year_desc' => 'ORDER BY m.year DESC',
          'year_asc' => 'ORDER BY m.year ASC',
        ];
        $order_by = $sort_map[$sort] ?? $sort_map['title_asc'];

        $stmt_songs = $db->prepare("
          SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id,
          CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
          FROM music m
          LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
          WHERE m.{$field} = ? {$order_by} {$limit_clause}
        ");
        $stmt_songs->execute([$user_id, $name]);
        $songs = $stmt_songs->fetchAll();
      }
      send_json(['details' => $details, 'songs' => $songs]);
      break;

    case 'search':
      $query = '%' . ($_GET['q'] ?? '') . '%';
      $stmt = $db->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?)
        ORDER BY m.title COLLATE NOCASE ASC {$limit_clause}
      ");
      $stmt->execute([$user_id, $query, $query, $query]);
      send_json($stmt->fetchAll());
      break;

    case 'get_song_data':
      $id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("
        SELECT m.id, m.file, m.title, m.artist, m.album, m.year, m.duration, m.bitrate, m.user_id,
        CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
        FROM music m
        LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ?
        WHERE m.id = ?
      ");
      $stmt->execute([$user_id, $id]);
      $song = $stmt->fetch();
      if ($song) {
        $song['stream_url'] = '?action=get_stream&id=' . $song['id'];
        $song['image_url'] = '?action=get_image&id=' . $song['id'];
      }
      send_json($song);
      break;

    case 'get_stream':
      $id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $file_path = $stmt->fetchColumn();

      $stmt = null;
      $db = null;
      session_write_close();

      if (!$file_path || !file_exists($file_path)) {
        http_response_code(404);
        exit("File not found");
      }

      $realMusicDir = realpath(MUSIC_DIR);
      $realFilePath = realpath($file_path);
      if (!$realFilePath || strpos($realFilePath, $realMusicDir) !== 0) {
        http_response_code(403);
        exit("Access denied");
      }

      $filesize = filesize($realFilePath);
      $ext = strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION));
      $mimes = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav'];
      $mime_type = $mimes[$ext] ?? 'audio/mpeg';

      if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
      @ini_set('zlib.output_compression', 'Off');
      while (ob_get_level() > 0) { @ob_end_clean(); }

      header('Content-Type: ' . $mime_type);
      header('Accept-Ranges: bytes');
      header('Cache-Control: no-cache, no-store, must-revalidate');
      header('Pragma: no-cache');
      header('Expires: 0');

      $start = 0;
      $end = $filesize - 1;

      if (isset($_SERVER['HTTP_RANGE'])) {
        $range = str_replace('bytes=', '', $_SERVER['HTTP_RANGE']);
        list($r_start, $r_end) = explode('-', $range, 2);
        $start = (int)$r_start;
        if ($r_end !== '') { $end = (int)$r_end; }
        if ($start > $end || $start >= $filesize) {
          header('HTTP/1.1 416 Range Not Satisfiable');
          header("Content-Range: bytes */$filesize");
          exit;
        }
        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header('Content-Length: ' . $length);
        header("Content-Range: bytes $start-$end/$filesize");
      } else {
        header('Content-Length: ' . $filesize);
      }

      $fp = fopen($realFilePath, 'rb');
      fseek($fp, $start);
      $buffer = 32768;
      while (!feof($fp) && ($pos = ftell($fp)) <= $end && !connection_aborted()) {
        if ($pos + $buffer > $end) {
          $buffer = $end - $pos + 1;
        }
        echo fread($fp, $buffer);
        flush();
      }
      fclose($fp);
      exit;

    case 'get_image':
      $id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $image_data = $stmt->fetchColumn();
      $stmt = null;
      $db = null;
      session_write_close();

      if ($image_data) {
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=604800');
        echo $image_data;
      } else {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=604800');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" fill="#404040" class="bi bi-disc" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M10 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/></svg>';
      }
      exit;

    case 'download_song':
      $song_id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $file_path = $stmt->fetchColumn();
      $stmt = null;
      $db = null;
      session_write_close();

      $realMusicDir = realpath(MUSIC_DIR);
      $realFilePath = realpath($file_path);
      if ($realFilePath && file_exists($realFilePath) && strpos($realFilePath, $realMusicDir) === 0) {
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($realFilePath));
        header('Content-Disposition: attachment; filename="' . basename($realFilePath) . '"');
        readfile($realFilePath);
        exit;
      }
      send_json(['status' => 'error', 'message' => 'File not found.'], 404);
      break;

    case 'get_user_playlists':
      if (!$user_id) { send_json([]); }
      $sort_map = [
        'name_asc' => 'ORDER BY p.name COLLATE NOCASE ASC',
        'name_desc' => 'ORDER BY p.name COLLATE NOCASE DESC',
        'modified_desc' => 'ORDER BY p.created_at DESC',
      ];
      $order_by = $sort_map[$_GET['sort'] ?? 'name_asc'] ?? $sort_map['name_asc'];
      $stmt = $db->prepare("
        SELECT p.id, p.name, p.public_id,
          (SELECT COUNT(*) FROM playlist_songs ps WHERE ps.playlist_id = p.id) AS song_count,
          (SELECT ps.song_id FROM playlist_songs ps WHERE ps.playlist_id = p.id ORDER BY ps.added_at DESC LIMIT 1) AS image_id
        FROM playlists p
        WHERE p.user_id = ? {$order_by}
      ");
      $stmt->execute([$user_id]);
      send_json($stmt->fetchAll());
      break;

    case 'create_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($name)) send_json(['status' => 'error', 'message' => 'Playlist name cannot be empty.'], 400);
      $public_id = bin2hex(random_bytes(8));
      $db->prepare("INSERT INTO playlists (user_id, name, public_id) VALUES (?, ?, ?)")->execute([$user_id, $name, $public_id]);
      send_json(['status' => 'success', 'message' => 'Playlist created.']);
      break;

    case 'edit_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'] ?? '';
      $new_name = trim(htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8'));
      if (empty($new_name)) send_json(['status' => 'error', 'message' => 'Name cannot be empty.'], 400);
      $db->prepare("UPDATE playlists SET name = ? WHERE public_id = ? AND user_id = ?")->execute([$new_name, $public_id, $user_id]);
      send_json(['status' => 'success', 'message' => 'Playlist updated.']);
      break;

    case 'delete_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['public_id'] ?? '';
      $db->prepare("DELETE FROM playlists WHERE public_id = ? AND user_id = ?")->execute([$public_id, $user_id]);
      send_json(['status' => 'success', 'message' => 'Playlist deleted.']);
      break;

    case 'add_to_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $playlist_id = (int)($data['playlist_id'] ?? 0);
      $song_id = (int)($data['song_id'] ?? 0);

      $stmt_owner = $db->prepare("SELECT id FROM playlists WHERE id = ? AND user_id = ?");
      $stmt_owner->execute([$playlist_id, $user_id]);
      if (!$stmt_owner->fetch()) send_json(['status' => 'error', 'message' => 'Permission denied.'], 403);

      $stmt_exists = $db->prepare("SELECT 1 FROM playlist_songs WHERE playlist_id = ? AND song_id = ?");
      $stmt_exists->execute([$playlist_id, $song_id]);
      if ($stmt_exists->fetch()) send_json(['status' => 'exists', 'message' => 'Song is already in this playlist.']);

      $stmt_order = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM playlist_songs WHERE playlist_id = ?");
      $stmt_order->execute([$playlist_id]);
      $max_order = (int)$stmt_order->fetchColumn();

      $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)")
        ->execute([$playlist_id, $song_id, $max_order + 1]);
      send_json(['status' => 'success', 'message' => 'Added to playlist.']);
      break;

    case 'remove_from_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $db->prepare("DELETE FROM playlist_songs WHERE song_id = ? AND playlist_id = (SELECT id FROM playlists WHERE public_id = ? AND user_id = ?)")
        ->execute([(int)($data['song_id'] ?? 0), $data['playlist_public_id'] ?? '', $user_id]);
      send_json(['status' => 'success', 'message' => 'Song removed from playlist.']);
      break;

    case 'update_playlist_order':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      $data = json_decode(file_get_contents('php://input'), true);
      $public_id = $data['playlist_public_id'] ?? '';
      $ordered_ids = $data['ids'] ?? [];

      $stmt = $db->prepare("SELECT id FROM playlists WHERE public_id = ? AND user_id = ?");
      $stmt->execute([$public_id, $user_id]);
      $playlist = $stmt->fetch();
      if (!$playlist) send_json(['status' => 'error', 'message' => 'Not found'], 404);

      $db->beginTransaction();
      try {
        $update_stmt = $db->prepare("UPDATE playlist_songs SET sort_order = ? WHERE playlist_id = ? AND song_id = ?");
        foreach ($ordered_ids as $index => $song_id) {
          $update_stmt->execute([$index, $playlist['id'], (int)$song_id]);
        }
        $db->commit();
        send_json(['status' => 'success']);
      } catch (Exception $e) {
        $db->rollBack();
        send_json(['status' => 'error', 'message' => 'Failed to save order'], 500);
      }
      break;

    case 'export_playlist':
      $public_id = $_GET['public_id'] ?? '';
      $stmt = $db->prepare("SELECT id, name FROM playlists WHERE public_id = ?");
      $stmt->execute([$public_id]);
      $pl = $stmt->fetch();
      if (!$pl) send_json(['error' => 'Playlist not found'], 404);

      $stmt_songs = $db->prepare("
        SELECT m.title, m.artist, m.album, m.duration, ps.sort_order
        FROM playlist_songs ps
        JOIN music m ON ps.song_id = m.id
        WHERE ps.playlist_id = ?
        ORDER BY ps.sort_order ASC
      ");
      $stmt_songs->execute([$pl['id']]);
      header('Content-Type: application/json; charset=utf-8');
      header('Content-Disposition: attachment; filename="playlist_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $pl['name']) . '.json"');
      echo json_encode([
        'app' => 'PHP-Music-Lite',
        'playlist_name' => $pl['name'],
        'exported_at' => date('c'),
        'songs' => $stmt_songs->fetchAll()
      ], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
      exit;

    case 'import_playlist':
      if (!$user_id) { send_json(['status' => 'error', 'message' => 'Unauthorized'], 403); }
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        send_json(['status' => 'error', 'message' => 'No valid playlist file uploaded.'], 400);
      }

      $json = json_decode(file_get_contents($_FILES['file']['tmp_name']), true);
      if (!$json || empty($json['playlist_name']) || !isset($json['songs'])) {
        send_json(['status' => 'error', 'message' => 'Invalid playlist JSON format.'], 400);
      }

      $pl_name = trim(htmlspecialchars($json['playlist_name'], ENT_QUOTES, 'UTF-8'));
      $public_id = bin2hex(random_bytes(8));
      $db->prepare("INSERT INTO playlists (user_id, name, public_id) VALUES (?, ?, ?)")->execute([$user_id, $pl_name, $public_id]);
      $new_pl_id = $db->lastInsertId();

      $find_stmt = $db->prepare("SELECT id FROM music WHERE title = ? AND artist = ? LIMIT 1");
      $find_fallback = $db->prepare("SELECT id FROM music WHERE title = ? LIMIT 1");
      $ins_stmt = $db->prepare("INSERT OR IGNORE INTO playlist_songs (playlist_id, song_id, sort_order) VALUES (?, ?, ?)");

      $added = 0;
      foreach ($json['songs'] as $idx => $s) {
        $title = $s['title'] ?? '';
        $artist = $s['artist'] ?? '';
        if (!$title) continue;

        $find_stmt->execute([$title, $artist]);
        $sid = $find_stmt->fetchColumn();
        if (!$sid) {
          $find_fallback->execute([$title]);
          $sid = $find_fallback->fetchColumn();
        }
        if ($sid) {
          $ins_stmt->execute([$new_pl_id, $sid, $idx]);
          $added++;
        }
      }
      send_json(['status' => 'success', 'message' => "Imported playlist '{$pl_name}' with {$added} matched songs."]);
      break;
  }
  exit;
}

// Fast Full Scan
function perform_full_scan($db) {
  ini_set('memory_limit', '512M');
  header('Content-Type: text/plain; charset=utf-8');
  session_write_close();
  ob_implicit_flush();

  echo "PHP-Music-Lite - Fast Scan\n==========================\n\n";

  if (!class_exists('getID3')) die("FATAL: getID3 library not found in " . __DIR__ . "/getid3/\n");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  $library_user_id = (int)$stmt->fetchColumn();
  $db_files = $db->query("SELECT file, last_modified FROM music")->fetchAll(PDO::FETCH_KEY_PAIR);

  $files_on_disk = [];
  $dir = new RecursiveDirectoryIterator(MUSIC_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
  $iter = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::LEAVES_ONLY);

  foreach ($iter as $file) {
    if ($file->isDir()) continue;
    $filePath = $file->getRealPath();
    if (preg_match('/\.(mp3|m4a|flac|ogg|wav)$/i', $filePath)) {
      $files_on_disk[$filePath] = $file->getMTime();
    }
  }

  $files_to_add = array_diff_key($files_on_disk, $db_files);
  $files_to_delete = array_diff_key($db_files, $files_on_disk);
  $files_to_update = [];

  foreach (array_intersect_key($files_on_disk, $db_files) as $filePath => $mtime) {
    if ($mtime > $db_files[$filePath]) $files_to_update[$filePath] = $mtime;
  }

  $files_to_process = $files_to_add + $files_to_update;
  $total = count($files_to_process) + count($files_to_delete);
  echo "Files: " . count($files_on_disk) . " (Add: " . count($files_to_add) . ", Update: " . count($files_to_update) . ", Delete: " . count($files_to_delete) . ")\n\n";

  if ($total === 0) die("Library is completely up to date.\n");

  $db->exec("PRAGMA synchronous = OFF;");
  $db->beginTransaction();

  $insert_stmt = $db->prepare("
    INSERT OR REPLACE INTO music (user_id, file, title, artist, album, year, duration, bitrate, image, last_modified)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $delete_stmt = $db->prepare("DELETE FROM music WHERE file = ?");

  $getID3 = new getID3;
  $getID3->option_tag_lyrics3 = false;
  $getID3->option_tag_apetag = false;
  $getID3->option_tags_images = true;

  $count = 0;
  foreach ($files_to_process as $filePath => $mtime) {
    $count++;
    echo "[$count/$total] " . basename($filePath) . "\n";

    $info = $getID3->analyze($filePath);
    getid3_lib::CopyTagsToComments($info);

    $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
    $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
    $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
    $year = (int)($info['comments']['year'][0] ?? 0);
    $duration = (int)($info['playtime_seconds'] ?? 0);
    $bitrate = (int)($info['audio']['bitrate'] ?? 0);
    $raw_image = $info['comments']['picture'][0]['data'] ?? null;
    $webp_image = process_image_to_webp($raw_image, 300, 70);

    $insert_stmt->execute([
      $library_user_id, $filePath, $title, $artist, $album,
      $year, $duration, $bitrate, $webp_image, $mtime
    ]);

    if ($count % 50 === 0) {
      $db->commit();
      $db->beginTransaction();
    }
  }

  foreach ($files_to_delete as $filePath => $mtime) {
    $count++;
    echo "[$count/$total] Deleting: " . basename($filePath) . "\n";
    $delete_stmt->execute([$filePath]);
  }

  $db->commit();
  $db->exec("PRAGMA synchronous = NORMAL;");
  echo "\nScan complete! $total files processed.\n";
}

$initialViewJS = '';
if (isset($_GET['share_type']) && isset($_GET['id'])) {
  $db_share = get_db();
  $share_type = $_GET['share_type'];
  $share_id_raw = $_GET['id'];
  $view_config = null;

  switch ($share_type) {
    case 'song':
      $stmt = $db_share->prepare("SELECT album FROM music WHERE id = ?");
      $stmt->execute([(int)$share_id_raw]);
      $song_info = $stmt->fetch();
      if ($song_info) {
        $view_config = ['type' => 'album_songs', 'param' => rawurlencode($song_info['album']), 'sort' => 'title_asc', 'highlight' => (int)$share_id_raw];
      }
      break;
    case 'album':
      $view_config = ['type' => 'album_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'title_asc'];
      break;
    case 'artist':
      $view_config = ['type' => 'artist_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'title_asc'];
      break;
    case 'playlist':
      $stmt = $db_share->prepare("SELECT id FROM playlists WHERE public_id = ?");
      $stmt->execute([$share_id_raw]);
      if ($stmt->fetch()) {
        $view_config = ['type' => 'playlist_songs', 'param' => rawurlencode($share_id_raw), 'sort' => 'manual_order'];
      }
      break;
  }
  if ($view_config) {
    $initialViewJS = "<script>window.initialView = " . json_encode($view_config) . ";</script>";
  }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP-Music-Lite</title>
    <link rel="icon" type="image/svg+xml" href="?action=get_app_icon" />
    <link rel="apple-touch-icon" href="?action=get_app_icon" />
    <meta name="theme-color" content="#121212"/>
    <link rel="manifest" href="?pwa=manifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <?php echo $initialViewJS; ?>
    <style>
      :root {
        --ytm-bg: #030303;
        --ytm-surface: #121212;
        --ytm-surface-2: #282828;
        --ytm-primary-text: #ffffff;
        --ytm-secondary-text: #aaaaaa;
        --ytm-accent: #ff1744;
        --header-height-mobile: 64px;
      }
      html, body {
        height: 100dvh;
        min-height: 100dvh;
        margin: 0;
      }
      body {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        font-family: 'Roboto', sans-serif;
      }
      body.player-visible {
        padding-bottom: 140px;
      }
      .text-truncate {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
      }
      ::-webkit-scrollbar { width: 8px; height: 8px; }
      ::-webkit-scrollbar-track { background: var(--ytm-surface); }
      ::-webkit-scrollbar-thumb { background: var(--ytm-surface-2); border-radius: 8px; }
      ::-webkit-scrollbar-thumb:hover { background: #555; }
      .app-container {
        display: flex;
        height: 100dvh;
        min-height: 100dvh;
      }
      .sidebar {
        width: 240px;
        background-color: var(--ytm-bg);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        border: none !important;
      }
      .main-content {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
      }
      .content-area-wrapper {
        padding: 1.5rem 2rem 5rem 2rem;
        flex-grow: 1;
      }
      body.player-visible .content-area-wrapper {
        padding-bottom: 160px;
      }
      .view-details-header {
        display: flex;
        align-items: flex-end;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1rem;
        background-color: var(--ytm-surface);
        border-radius: 8px;
      }
      .view-details-header-info {
        min-width: 0;
        flex-grow: 1;
      }
      .view-details-header img {
        width: 150px;
        height: 150px;
        object-fit: cover;
        border-radius: 8px;
        flex-shrink: 0;
        background-color: var(--ytm-surface-2);
      }
      .view-details-header-info .type {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--ytm-secondary-text);
      }
      .view-details-header-info .name {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0.5rem 0;
      }
      .view-details-header-info .stats {
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
      }
      .view-details-header .share-view-btn {
        flex-shrink: 0;
        align-self: flex-start;
      }
      @media (min-width: 768px) {
        .sidebar {
          padding: 1.5rem 0;
          overflow-y: auto;
        }
        .sidebar .offcanvas-header {
          display: none;
        }
        .sidebar .offcanvas-body {
          padding: 0 !important;
        }
        .player-bar {
          background-color: var(--ytm-surface);
          left: 240px;
        }
        .page-header {
          position: sticky;
          top: 0;
          background-color: var(--ytm-bg);
          z-index: 1010;
          padding-top: 1.5rem;
          padding-bottom: 1.5rem;
        }
        .song-item {
          cursor: pointer;
          border-radius: 8px;
        }
        .song-artist-mobile {
          display: none !important;
        }
      }
      .offcanvas-body .nav-link {
        padding: 0.75rem 1.5rem;
      }
      .sidebar .logo {
        font-size: 1.5rem;
        font-weight: 700;
        padding: 0 1.5rem 1.5rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .sidebar .logo img {
        width: 30px;
        height: 30px;
        border-radius: 7px;
      }
      .sidebar .logo span {
        color: var(--ytm-accent);
      }
      .nav-link {
        color: var(--ytm-secondary-text);
        display: flex;
        align-items: center;
        font-weight: 500;
        border-left: 3px solid transparent;
        gap: 1rem;
        text-decoration: none;
      }
      .nav-link:hover, .nav-link.active {
        background-color: var(--ytm-surface);
        color: var(--ytm-primary-text);
      }
      .nav-link.active {
        border-left-color: var(--ytm-accent);
      }
      .nav-link .bi {
        font-size: 1.25rem;
        width: 24px;
        text-align: center;
      }
      .offcanvas {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        z-index: 999;
      }
      .offcanvas .offcanvas-header {
        padding: 0.75rem 1.5rem;
      }
      .page-header {
        padding: 1.5rem 2rem 1.5rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
      }
      .header-controls {
        display: flex;
        gap: 1rem;
        align-items: center;
        margin-left: auto;
      }
      #sort-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }
      #sort-select {
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
        border: 1px solid #404040;
        border-radius: 8px;
        padding: 0.25rem 0.5rem;
      }
      .search-bar.input-group {
        width: auto;
        min-width: 250px;
        border-radius: 50px;
      }
      .search-bar.input-group .form-control {
        background-color: var(--ytm-surface-2);
        border: none !important;
        color: var(--ytm-primary-text);
        border-radius: 50px 0 0 50px !important;
        height: 40px;
        box-shadow: none;
        padding-left: 1.25rem;
      }
      .search-bar.input-group .form-control:focus {
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
      }
      .search-bar.input-group .form-control::placeholder {
        color: var(--ytm-secondary-text);
      }
      .search-bar.input-group .btn,
      #search-btn-desktop,
      #search-btn-mobile {
        background-color: var(--ytm-surface-2) !important;
        border: none !important;
        border-radius: 0 50px 50px 0 !important;
        color: var(--ytm-secondary-text) !important;
        padding-left: 1rem !important;
        padding-right: 1.25rem !important;
        z-index: 5;
      }
      .search-bar.input-group .btn:hover,
      #search-btn-desktop:hover,
      #search-btn-mobile:hover,
      #search-btn-desktop:active,
      #search-btn-mobile:active {
        background-color: #383838 !important;
        color: var(--ytm-primary-text) !important;
      }
      .content-title {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0;
      }
      .song-list-header, .song-item {
        display: grid;
        grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) 80px 40px;
        align-items: center;
        gap: 1rem;
        padding: 0.6rem 1rem;
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
        border-radius: 8px;
        border: none !important;
      }
      .song-list-header {
        font-weight: 500;
      }
      .song-item.ghost {
        opacity: 0.4;
      }
      .song-item:hover {
        background-color: var(--ytm-surface-2);
      }
      .song-item .song-title {
        color: var(--ytm-primary-text);
        font-weight: 500;
      }
      .song-item .song-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 8px;
        background-color: var(--ytm-surface);
      }
      .song-item .song-more {
        justify-self: end;
        position: relative;
      }
      .song-item .more-btn, .playlist-more-btn {
        background: none;
        border: none;
        color: var(--ytm-secondary-text);
        padding: 5px;
        cursor: pointer;
        border-radius: 8px;
      }
      .song-item:hover .more-btn, .playlist-more-btn:hover {
        color: var(--ytm-primary-text);
      }
      .card.playlist-card {
        position: relative;
        border-radius: 8px;
      }
      .card-img-top:not(.rounded-circle) {
        border-radius: 8px;
      }
      .playlist-more-btn {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: transparent !important;
        border: none;
        padding: 0.25rem;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
      }
      .context-menu {
        display: none;
        position: fixed;
        background-color: var(--ytm-surface-2);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.6);
        z-index: 2050;
        list-style: none;
        padding: 0.5rem 0;
        min-width: 220px;
        max-width: 90vw;
        max-height: calc(100dvh - 30px);
        overflow-y: auto;
        box-sizing: border-box;
      }
      .context-menu-item {
        padding: 0.75rem 1.25rem;
        color: var(--ytm-primary-text);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .context-menu-item:hover {
        background-color: #404040;
      }
      .context-menu-item .bi {
        font-size: 1.1rem;
      }
      .player-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 90px;
        background-color: var(--ytm-bg);
        border-top: 1px solid var(--ytm-surface-2);
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(260px, 3fr) minmax(180px, 1fr);
        align-items: center;
        gap: 1.5rem;
        padding: 0 1.5rem;
        z-index: 998;
      }
      .player-bar .track-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 0;
      }
      .player-bar .track-info-art {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 8px;
        flex-shrink: 0;
      }
      .player-bar .track-info-text {
        overflow: hidden;
      }
      .player-bar .track-info-text .title {
        font-weight: 500;
      }
      .player-bar .track-info-text .artist {
        color: var(--ytm-secondary-text);
        font-size: 0.875rem;
      }
      .player-bar .player-controls {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 0;
        width: 100% !important;
      }
      .player-bar .player-buttons {
        display: flex;
        align-items: center;
        justify-content: space-between !important;
        width: 100% !important;
      }
      .player-btn {
        background: none;
        border: none !important;
        outline: none !important;
        color: var(--ytm-secondary-text);
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.2s;
        border-radius: 8px;
      }
      .player-btn:hover {
        color: var(--ytm-primary-text);
      }
      .player-btn.play-btn {
        color: var(--ytm-primary-text);
        background-color: var(--ytm-surface);
        width: 42px;
        height: 42px;
        border-radius: 50% !important;
        transition: transform 0.1s, background-color 0.2s;
      }
      .player-btn.play-btn:hover {
        transform: scale(1.08);
        background-color: #383838;
      }
      .player-btn .bi {
        font-size: 1.25rem;
      }
      .player-btn.play-btn .bi {
        font-size: 1.75rem;
      }
      .player-btn.active {
        color: var(--ytm-accent);
      }
      .player-bar .playback-bar {
        width: 100% !important;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-top: 8px;
      }
      .playback-bar .time {
        font-size: 0.75rem;
        color: var(--ytm-secondary-text);
        flex-shrink: 0;
      }
      .progress-bar-container {
        flex-grow: 1;
        height: 4px;
        border-radius: 8px;
        cursor: pointer;
        padding: 5px 0;
        position: relative;
        margin-bottom: 0.2em;
      }
      .progress-bar-bg {
        height: 4px;
        background-color: #404040;
        border-radius: 8px;
        position: absolute;
        top: 5px;
        left: 0;
        right: 0;
        pointer-events: none;
      }
      .progress-bar-fg {
        height: 4px;
        background-color: var(--ytm-primary-text);
        border-radius: 8px;
        width: 0%;
        position: relative;
      }
      .progress-bar-container:hover .progress-bar-fg {
        background-color: var(--ytm-accent);
      }
      .progress-bar-container:hover .progress-bar-fg::after {
        content: '';
        position: absolute;
        right: -5px;
        top: -4px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--ytm-primary-text);
      }
      .player-bar .extra-controls {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 1rem;
      }
      .volume-control {
        width: 150px;
        display: flex;
        align-items: center;
      }
      .volume-slider-container {
        flex-grow: 1;
        padding: 5px 0.5rem;
        position: relative;
        display: flex;
        align-items: center;
      }
      #volume-slider.form-range {
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        cursor: pointer;
        outline: none;
        padding: 0;
        height: 4px;
        border-radius: 8px;
        background: var(--ytm-surface-2);
      }
      #volume-slider.form-range::-webkit-slider-runnable-track {
        -webkit-appearance: none;
        background: none;
        border: none;
        height: 4px;
      }
      #volume-slider.form-range::-moz-range-track {
        background: none;
        border: none;
        height: 4px;
      }
      #volume-slider.form-range::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        height: 12px;
        width: 12px;
        background-color: var(--ytm-primary-text);
        border-radius: 50%;
        margin-top: -4px;
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
      }
      #volume-slider.form-range::-moz-range-thumb {
        height: 12px;
        width: 12px;
        background-color: var(--ytm-primary-text);
        border-radius: 50%;
        border: none;
        opacity: 0;
        transition: opacity 0.2s ease-in-out;
      }
      .volume-control:hover #volume-slider.form-range::-webkit-slider-thumb { opacity: 1; }
      .volume-control:hover #volume-slider.form-range::-moz-range-thumb { opacity: 1; }
      .modal-content {
        background-color: var(--ytm-surface);
        border: none;
        border-radius: 8px;
      }
      .modal-footer {
        border-top: 1px solid var(--ytm-surface-2);
      }
      .form-control, .form-select {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        color: var(--ytm-primary-text);
        border-radius: 8px;
      }
      .form-control:focus, .form-select:focus {
        background-color: var(--ytm-surface-2);
        border-color: #666;
        color: var(--ytm-primary-text);
        box-shadow: none;
      }
      .btn {
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
        border-radius: 8px;
        transition: background-color 0.2s ease, color 0.2s ease;
      }
      .btn:not(.btn-danger):not(.btn-outline-danger):not(.player-btn):not(#search-btn-desktop):not(#search-btn-mobile) {
        background: transparent !important;
        color: var(--ytm-primary-text);
      }
      .btn:not(.btn-danger):not(.btn-outline-danger):not(.player-btn):not(#search-btn-desktop):not(#search-btn-mobile):hover {
        background: var(--ytm-surface-2) !important;
      }
      .btn-danger {
        background-color: var(--ytm-accent) !important;
        color: #ffffff !important;
      }
      .btn-danger:hover {
        background-color: #d50000 !important;
      }
      .btn-outline-danger {
        background: transparent !important;
        color: var(--ytm-accent) !important;
      }
      .btn-outline-danger:hover {
        background: rgba(255, 23, 68, 0.12) !important;
      }
      #metadata-modal .list-group-item {
        border: none !important;
        padding-left: 0;
        padding-right: 0;
      }
      body.logged-out .logged-in-only { display: none !important; }
      body.logged-in .logged-out-only { display: none !important; }
      .song-item .playing-icon {
        display: none;
        font-size: 1.5rem;
        color: var(--ytm-accent);
      }
      .song-item.now-playing .song-thumb { display: none; }
      .song-item.now-playing .playing-icon {
        display: inline-block;
        animation: soundwave-pulse 1.2s ease-in-out infinite;
      }
      .song-item.now-playing .song-title { color: var(--ytm-accent); }
      @keyframes soundwave-pulse {
        0% { transform: scaleY(0.4); }
        25% { transform: scaleY(1); }
        50% { transform: scaleY(0.6); }
        75% { transform: scaleY(0.8); }
        100% { transform: scaleY(0.4); }
      }
      @media (max-width: 767.98px) {
        body.player-visible { padding-bottom: 180px; }
        body.player-visible .content-area-wrapper { padding-bottom: 200px; }
        .main-content { padding-top: var(--header-height-mobile); }
        .content-area-wrapper { padding: 1rem 1rem 5rem 1rem; }
        .mobile-header {
          position: fixed;
          top: 0; left: 0; right: 0;
          height: var(--header-height-mobile);
          background-color: var(--ytm-bg);
          border-bottom: 1px solid var(--ytm-surface-2);
          z-index: 1000;
          display: flex;
          align-items: center;
          padding: 0 1rem;
          gap: 0.5rem;
        }
        .header-btn {
          background: none; border: none; color: var(--ytm-primary-text);
          font-size: 1.5rem; padding: 0.5rem;
        }
        .page-header { padding: 1rem 1rem 0 1rem; flex-wrap: wrap; }
        .content-title { font-size: 1.75rem; margin-bottom: 0.5rem; width: 100%; }
        .header-controls { margin-left: 0; width: 100%; justify-content: flex-end; }
        .player-bar {
          grid-template-columns: 1fr;
          display: flex;
          flex-direction: column;
          height: 150px;
          padding: 0.5rem 1rem;
          gap: 0;
        }
        .player-bar .track-info.d-md-none {
          order: 1; width: 100%; cursor: pointer; justify-content: space-between;
        }
        .player-bar .track-info-text { flex-grow: 1; }
        #player-more-btn-mobile { flex-shrink: 0; }
        .player-bar .player-controls { display: contents; width: 100% !important; }
        .player-bar .playback-bar { order: 2; width: 100% !important; margin-top: 8px; }
        .player-bar .player-buttons-mobile {
          order: 3; width: 100% !important; display: flex; justify-content: space-between;
          align-items: center; margin-top: 4px; margin-bottom: 8px;
        }
        .player-bar .player-buttons { display: none; }
        .player-bar .extra-controls { display: none; }
        .player-bar .track-info-art { width: 48px; height: 48px; }
        .player-btn.play-btn { width: 48px; height: 48px; border-radius: 50% !important; }
        .player-btn .bi { font-size: 1.5rem; }
        .player-btn.play-btn .bi { font-size: 2rem; }
        .song-list-header { display: none; }
        .song-item {
          grid-template-columns: 40px minmax(0, 1fr) 36px;
          grid-template-rows: auto auto;
          align-items: center;
          gap: 0.45rem 0.95rem;
          padding: 0.55rem 0.5rem;
          border: none !important;
        }
        .song-item .song-artist, .song-item .song-album, .song-item .song-duration {
          display: none !important;
        }
        .song-item .song-indicator-wrapper {
          grid-column: 1;
          grid-row: 1 / span 2;
          align-self: center;
          justify-self: center;
        }
        .song-item .song-title-wrapper {
          grid-column: 2;
          grid-row: 1;
          align-self: end;
          line-height: 1.25;
        }
        .song-item .song-artist-mobile {
          display: flex !important;
          justify-content: space-between;
          align-items: center;
          grid-column: 2;
          grid-row: 2;
          align-self: start;
          font-size: 0.8rem;
          color: var(--ytm-secondary-text);
          gap: 0.5rem;
          line-height: 1.25;
        }
        .song-item .song-more {
          grid-column: 3;
          grid-row: 1 / span 2;
          align-self: center;
          justify-self: end;
        }
        .view-details-header { flex-direction: column; align-items: center; text-align: center; }
      }
      .loader {
        text-align: center;
        padding: 3rem;
        font-size: 1.2rem;
        color: var(--ytm-secondary-text);
      }
      .player-modal-content {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        min-height: 100dvh;
        border-radius: 0;
      }
      .player-modal-header {
        border-bottom: 0;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.5rem;
      }
      .player-modal-header .player-btn,
      #player-modal-more-btn,
      #player-more-btn-mobile,
      #player-more-btn-desktop,
      .more-btn,
      .playlist-more-btn {
        padding: 0 !important;
        margin: 0 !important;
        background: transparent !important;
        background-color: transparent !important;
        border: none !important;
        box-shadow: none !important;
        outline: none !important;
        color: var(--ytm-primary-text);
      }
      .player-modal-header .player-btn:hover,
      .player-modal-header .player-btn:focus,
      .player-modal-header .player-btn:active,
      #player-modal-more-btn:hover,
      #player-modal-more-btn:focus,
      #player-modal-more-btn:active,
      #player-more-btn-mobile:hover,
      #player-more-btn-desktop:hover,
      .more-btn:hover,
      .playlist-more-btn:hover {
        background: transparent !important;
        background-color: transparent !important;
        box-shadow: none !important;
        outline: none !important;
        color: var(--ytm-primary-text);
      }
      .player-modal-header .player-btn .bi { font-size: 1.75rem; }
      #player-modal-more-btn {
        margin: 0 !important;
        padding: 0 !important;
        width: auto !important;
        height: auto !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
      }
      #player-modal-more-btn .bi {
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1 !important;
        width: auto !important;
      }
      .player-modal-body {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 0 1.5rem 2rem 1.5rem;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
      }
      .player-modal-art-wrapper {
        width: 100% !important;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.5rem;
        flex-grow: 1;
      }
      #player-modal-art {
        width: 100% !important;
        max-width: 100% !important;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        display: block;
      }
      .player-modal-track-info {
        text-align: left;
        margin-bottom: 1rem;
        width: 100% !important;
      }
      .player-modal-track-info .title { font-weight: 700; font-size: 1.5rem; }
      .player-modal-track-info .artist { color: var(--ytm-secondary-text); font-size: 1rem; }
      .player-modal-progress {
        width: 100% !important;
        margin-bottom: 1.5rem;
      }
      .player-modal-progress .time-stamps {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        color: var(--ytm-secondary-text);
        margin-top: 0.5rem;
      }
      .player-modal-controls {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        margin-bottom: 1.5rem;
        width: 100% !important;
        padding: 0 !important;
      }
      .player-modal-controls .player-btn {
        color: var(--ytm-primary-text);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0 !important;
      }
      .player-modal-controls .player-btn:first-child {
        justify-content: flex-start !important;
      }
      .player-modal-controls .player-btn:last-child {
        justify-content: flex-end !important;
      }
      .player-modal-controls .player-btn .bi {
        font-size: 1.35rem;
      }
      .player-modal-controls .player-btn.active {
        color: var(--ytm-accent);
      }
      .player-modal-controls .play-btn {
        width: 58px !important;
        height: 58px !important;
        border-radius: 50% !important;
        background-color: var(--ytm-surface);
      }
      .player-modal-controls .play-btn .bi {
        font-size: 3.5rem !important;
      }
      .add-to-playlist-item { cursor: pointer; }
      .add-to-playlist-item:hover { background-color: var(--ytm-surface-2); }
    </style>
  </head>
  <body class="logged-out">
    <div class="app-container">
      <nav class="sidebar offcanvas-md offcanvas-start" tabindex="-1" id="main-nav-offcanvas">
        <div class="offcanvas-header">
          <div class="logo">
            <img src="?action=get_app_icon&size=64" alt="logo">
            PHP<span>Music</span> Lite
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#main-nav-offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
          <div class="logo d-none d-md-flex">
            <img src="?action=get_app_icon&size=64" alt="logo">
            PHP<span>Music</span> <small class="text-danger fs-6">Lite</small>
          </div>
          <a href="#" class="nav-link active" data-view="get_songs">
            <i class="bi bi-music-note-list"></i>
            <span class="text-truncate">All Songs</span>
          </a>
          <a href="#" class="nav-link" data-view="get_albums">
            <i class="bi bi-disc-fill"></i>
            <span class="text-truncate">Albums</span>
          </a>
          <a href="#" class="nav-link" data-view="get_artists">
            <i class="bi bi-people-fill"></i>
            <span class="text-truncate">Artists</span>
          </a>
          <div class="logged-in-only">
            <a href="#" class="nav-link" data-view="get_favorites">
              <i class="bi bi-heart-fill"></i>
              <span class="text-truncate">Favorites</span>
            </a>
            <a href="#" class="nav-link" data-view="get_user_playlists">
              <i class="bi bi-music-note-beamed"></i>
              <span class="text-truncate">Playlists</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#settings-modal">
              <i class="bi bi-sliders"></i>
              <span class="text-truncate">Settings</span>
            </a>
            <a href="#" class="nav-link" id="sidebar-logout-btn">
              <i class="bi bi-box-arrow-left"></i>
              <span class="text-truncate">Logout</span>
            </a>
          </div>
          <div class="logged-out-only">
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#login-modal">
              <i class="bi bi-box-arrow-in-right"></i>
              <span class="text-truncate">Login</span>
            </a>
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#register-modal">
              <i class="bi bi-person-plus-fill"></i>
              <span class="text-truncate">Register</span>
            </a>
          </div>
          <div class="mt-auto">
            <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#full-scan-modal">
              <i class="bi bi-hdd-stack-fill"></i>
              <span class="text-truncate">Scan All</span>
            </a>
            <a href="#" class="nav-link d-none" id="install-pwa-btn">
              <i class="bi bi-cloud-arrow-down-fill"></i>
              <span class="text-truncate">Install App</span>
            </a>
          </div>
        </div>
      </nav>
      <main class="main-content" id="main-content">
        <div class="mobile-header d-md-none">
          <button class="header-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#main-nav-offcanvas">
            <i class="bi bi-list"></i>
          </button>
          <div class="input-group search-bar flex-grow-1">
            <input type="text" class="form-control" id="search-input-mobile" placeholder="Search your music" aria-label="Search your music">
            <button class="btn" type="button" id="search-btn-mobile"><i class="bi bi-search"></i></button>
          </div>
        </div>
        <div class="page-header">
          <h1 id="content-title" class="content-title text-truncate">All Songs</h1>
          <div class="header-controls">
            <div id="sort-controls" class="d-none">
              <label for="sort-select" class="text-secondary small">Sort by</label>
              <select id="sort-select" class="form-select form-select-sm" style="width: auto;"></select>
            </div>
            <div class="input-group search-bar d-none d-md-flex">
              <input type="text" class="form-control" id="search-input-desktop" placeholder="Search..." aria-label="Search...">
              <button class="btn" type="button" id="search-btn-desktop"><i class="bi bi-search"></i></button>
            </div>
          </div>
        </div>
        <div id="content-area" class="content-area-wrapper"></div>
        <div id="infinite-scroll-loader" class="loader d-none">Loading more...</div>
      </main>
    </div>

    <!-- Player Bar -->
    <div class="player-bar d-none" id="player-bar">
      <div class="track-info d-none d-md-flex">
        <img src="" alt="Album Art" class="track-info-art" id="player-art-desktop">
        <div class="track-info-text text-truncate">
          <div class="title text-truncate" id="player-title-desktop">Song Title</div>
          <div class="artist text-truncate" id="player-artist-desktop">Artist Name</div>
        </div>
      </div>
      <div class="player-controls">
        <div class="track-info d-md-none">
          <img src="" alt="Album Art" class="track-info-art" id="player-art-mobile">
          <div class="track-info-text text-truncate">
            <div class="title text-truncate" id="player-title-mobile">Song Title</div>
            <div class="artist text-truncate" id="player-artist-mobile">Artist Name</div>
          </div>
          <button class="player-btn" id="player-more-btn-mobile" title="More"><i class="bi bi-three-dots-vertical"></i></button>
        </div>
        <div class="playback-bar">
          <span class="time" id="current-time">0:00</span>
          <div class="progress-bar-container" id="progress-container">
            <div class="progress-bar-bg"></div>
            <div class="progress-bar-fg" id="progress-bar"></div>
          </div>
          <span class="time" id="time-left">0:00</span>
        </div>
        <div class="player-buttons d-none d-md-flex mt-md-2">
          <button class="player-btn" id="shuffle-btn-desktop" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-desktop" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-desktop" title="Play"></button>
          <button class="player-btn" id="next-btn-desktop" title="Next"></button>
          <button class="player-btn" id="repeat-btn-desktop" title="Repeat"></button>
        </div>
        <div class="player-buttons-mobile d-md-none">
          <button class="player-btn" id="shuffle-btn-mobile" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-mobile" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-mobile" title="Play"></button>
          <button class="player-btn" id="next-btn-mobile" title="Next"></button>
          <button class="player-btn" id="repeat-btn-mobile" title="Repeat"></button>
        </div>
      </div>
      <div class="extra-controls d-none d-md-flex">
        <div class="volume-control d-flex align-items-center">
          <button class="player-btn" id="volume-btn" title="Mute">
            <i class="bi bi-volume-up-fill"></i>
          </button>
          <div class="volume-slider-container">
            <input type="range" class="form-range" id="volume-slider" min="0" max="1" step="0.01" value="1">
          </div>
        </div>
        <button class="player-btn" id="player-more-btn-desktop" title="More"><i class="bi bi-three-dots-vertical"></i></button>
      </div>
    </div>
    <ul class="context-menu" id="context-menu"></ul>

    <!-- Fullscreen Player Modal for Mobile -->
    <div class="modal fade" id="player-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content player-modal-content">
          <div class="modal-header px-2 mx-1 ms-2 player-modal-header">
            <button type="button" class="player-btn" data-bs-dismiss="modal" aria-label="Close">
              <i class="bi bi-chevron-down"></i>
            </button>
            <button type="button" class="player-btn" id="player-modal-more-btn" title="More">
              <i class="bi bi-three-dots-vertical"></i>
            </button>
          </div>
          <div class="modal-body player-modal-body">
            <div class="player-modal-art-wrapper">
              <img src="" id="player-modal-art" alt="Album Art">
            </div>
            <div class="player-modal-track-info text-truncate">
              <h3 id="player-modal-title" class="title text-truncate">Song Title</h3>
              <p id="player-modal-artist" class="artist text-truncate">Artist Name</p>
            </div>
            <div class="player-modal-progress">
              <div class="progress-bar-container" id="player-modal-progress-container">
                <div class="progress-bar-bg"></div>
                <div class="progress-bar-fg" id="player-modal-progress-bar"></div>
              </div>
              <div class="time-stamps">
                <span id="player-modal-current-time">0:00</span>
                <span id="player-modal-time-left">0:00</span>
              </div>
            </div>
            <div class="player-modal-controls">
              <button class="player-btn" id="player-modal-shuffle-btn" title="Shuffle"></button>
              <button class="player-btn" id="player-modal-prev-btn" title="Previous"></button>
              <button class="player-btn play-btn" id="player-modal-play-pause-btn" title="Play"></button>
              <button class="player-btn" id="player-modal-next-btn" title="Next"></button>
              <button class="player-btn" id="player-modal-repeat-btn" title="Repeat"></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="login-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Login</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="login-form">
              <div class="mb-3">
                <label for="login-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="login-email" required autocomplete="email">
              </div>
              <div class="mb-3">
                <label for="login-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="login-password" required autocomplete="current-password">
              </div>
              <button type="submit" class="btn btn-danger w-100 py-2">Login</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="register-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Register</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="register-form">
              <div class="mb-3">
                <label for="register-artist" class="form-label">Artist/Display Name</label>
                <input type="text" class="form-control" id="register-artist" required>
              </div>
              <div class="mb-3">
                <label for="register-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="register-email" required autocomplete="email">
              </div>
              <div class="mb-3">
                <label for="register-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="register-password" required minlength="6" autocomplete="new-password">
              </div>
              <button type="submit" class="btn btn-danger w-100 py-2">Register</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal fade" id="settings-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Settings</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <h6>Display Name</h6>
            <form id="change-name-form" class="mb-4">
              <div class="mb-3">
                <input type="text" class="form-control" id="display-name-input" required placeholder="Display / Artist Name">
              </div>
              <button type="submit" class="btn btn-danger w-100">Update Name</button>
            </form>

            <h6 class="mt-4">Change Password</h6>
            <form id="change-password-form" class="mb-4">
              <div class="mb-3">
                <input type="password" class="form-control" id="new-password" required minlength="6" placeholder="New Password">
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Password</button>
            </form>

            <div class="mt-4 pt-3 border-top border-secondary">
              <h6>Storage Cache</h6>
              <p class="text-secondary small mb-3">Clear locally cached audio files and offline app cache.</p>
              <button type="button" class="btn btn-outline-light w-100" id="delete-cache-btn">
                <i class="bi bi-trash3 me-2"></i>Delete Local Cache
              </button>
            </div>

            <div class="mt-4 pt-3 border-top border-secondary">
              <h6 class="text-danger">Delete Account</h6>
              <p class="text-secondary small mb-3">Permanently remove your account and all playlists.</p>
              <button type="button" class="btn btn-outline-danger w-100" id="delete-account-btn">Delete My Account</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="create-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Create New Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="create-playlist-form">
              <div class="mb-3">
                <label for="playlist-name-input" class="form-label">Playlist Name</label>
                <input type="text" class="form-control" id="playlist-name-input" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Create</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="edit-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Edit Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="edit-playlist-form">
              <input type="hidden" id="edit-playlist-id-input">
              <div class="mb-3">
                <label for="edit-playlist-name-input" class="form-label">Playlist Name</label>
                <input type="text" class="form-control" id="edit-playlist-name-input" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Changes</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="add-to-playlist-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Add to Playlist</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="add-to-playlist-modal-body"></div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="metadata-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Song Metadata</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="metadata-modal-body"></div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="share-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title text-truncate" id="share-modal-title">Share</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-secondary text-center mb-4" id="share-modal-text">Share this with your friends!</p>
            <div class="input-group">
              <input type="text" class="form-control" id="share-url-input" readonly>
              <button class="btn btn-danger" type="button" id="copy-share-url-btn">Copy</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="full-scan-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title">Full Library Scan Log</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <iframe id="full-scan-iframe" src="about:blank" style="width: 100%; height: 50dvh; border: none; background-color: #030303;"></iframe>
          </div>
        </div>
      </div>
    </div>

    <input type="file" id="playlist-import-input" accept=".json,application/json" class="d-none">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        'use strict';

        // Blazing Fast OPFS Audio Storage Cache
        const opfs = {
          root: null,
          async init() {
            if (navigator.storage && navigator.storage.getDirectory) {
              try {
                this.root = await navigator.storage.getDirectory();
              } catch (e) {
                console.warn('OPFS init error:', e);
              }
            }
          },
          async getBlob(name) {
            if (!this.root) return null;
            try {
              const handle = await this.root.getFileHandle(name);
              return await handle.getFile();
            } catch {
              return null;
            }
          },
          async saveBlob(name, blob) {
            if (!this.root) return;
            try {
              const handle = await this.root.getFileHandle(name, { create: true });
              const writable = await handle.createWritable();
              await writable.write(blob);
              await writable.close();
            } catch (e) {
              console.warn('OPFS save error:', e);
            }
          }
        };
        opfs.init();

        const mainContent = document.getElementById('main-content');
        const contentArea = document.getElementById('content-area');
        const contentTitle = document.getElementById('content-title');
        const searchInputDesktop = document.getElementById('search-input-desktop');
        const searchInputMobile = document.getElementById('search-input-mobile');
        const searchBtnDesktop = document.getElementById('search-btn-desktop');
        const searchBtnMobile = document.getElementById('search-btn-mobile');
        const sortControls = document.getElementById('sort-controls');
        const sortSelect = document.getElementById('sort-select');
        const allNavLinks = document.querySelectorAll('.sidebar .nav-link');
        const contextMenu = document.getElementById('context-menu');
        const playerBar = document.getElementById('player-bar');
        const infiniteScrollLoader = document.getElementById('infinite-scroll-loader');
        const installPwaBtn = document.getElementById('install-pwa-btn');
        const fullScanModalEl = document.getElementById('full-scan-modal');
        const fullScanIframe = document.getElementById('full-scan-iframe');

        const createPlaylistModal = new bootstrap.Modal(document.getElementById('create-playlist-modal'));
        const editPlaylistModal = new bootstrap.Modal(document.getElementById('edit-playlist-modal'));
        const addToPlaylistModal = new bootstrap.Modal(document.getElementById('add-to-playlist-modal'));
        const addToPlaylistModalBody = document.getElementById('add-to-playlist-modal-body');
        const metadataModal = new bootstrap.Modal(document.getElementById('metadata-modal'));
        const metadataModalBody = document.getElementById('metadata-modal-body');
        const shareModal = new bootstrap.Modal(document.getElementById('share-modal'));
        const shareModalTitle = document.getElementById('share-modal-title');
        const shareUrlInput = document.getElementById('share-url-input');
        const copyShareUrlBtn = document.getElementById('copy-share-url-btn');

        const playerTrackInfoMobile = document.querySelector('.player-bar .track-info.d-md-none');
        const playerModalEl = document.getElementById('player-modal');
        const playerModal = playerModalEl ? new bootstrap.Modal(playerModalEl) : null;
        const playlistImportInput = document.getElementById('playlist-import-input');

        const playerElements = {
          art: [document.getElementById('player-art-desktop'), document.getElementById('player-art-mobile'), document.getElementById('player-modal-art')],
          title: [document.getElementById('player-title-desktop'), document.getElementById('player-title-mobile'), document.getElementById('player-modal-title')],
          artist: [document.getElementById('player-artist-desktop'), document.getElementById('player-artist-mobile'), document.getElementById('player-modal-artist')],
          currentTime: [document.getElementById('current-time'), document.getElementById('player-modal-current-time')],
          timeLeft: [document.getElementById('time-left'), document.getElementById('player-modal-time-left')],
          progress: [document.getElementById('progress-bar'), document.getElementById('player-modal-progress-bar')],
          progressContainer: [document.getElementById('progress-container'), document.getElementById('player-modal-progress-container')],
          playPauseBtn: [document.getElementById('play-pause-btn-desktop'), document.getElementById('play-pause-btn-mobile'), document.getElementById('player-modal-play-pause-btn')],
          prevBtn: [document.getElementById('prev-btn-desktop'), document.getElementById('prev-btn-mobile'), document.getElementById('player-modal-prev-btn')],
          nextBtn: [document.getElementById('next-btn-desktop'), document.getElementById('next-btn-mobile'), document.getElementById('player-modal-next-btn')],
          shuffleBtn: [document.getElementById('shuffle-btn-desktop'), document.getElementById('shuffle-btn-mobile'), document.getElementById('player-modal-shuffle-btn')],
          repeatBtn: [document.getElementById('repeat-btn-desktop'), document.getElementById('repeat-btn-mobile'), document.getElementById('player-modal-repeat-btn')],
          moreBtn: [document.getElementById('player-more-btn-desktop'), document.getElementById('player-more-btn-mobile'), document.getElementById('player-modal-more-btn')],
          volumeBtn: document.getElementById('volume-btn'),
          volumeSlider: document.getElementById('volume-slider'),
        };

        const audio = new Audio();
        audio.preload = 'metadata';
        let currentView = { type: 'get_songs', param: '', sort: 'title_asc' };
        let currentUser = null;
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = localStorage.getItem('php_music_shuffle') === 'true';
        let repeatMode = localStorage.getItem('php_music_repeat') || 'none';
        let sortable = null;
        let songIdForPlaylist = null;
        let contextMenuItemEl = null;
        let previousVolume = 1;
        let deferredInstallPrompt = null;
        let isAddingToPlaylist = false;

        const PAGE_SIZE = 25;
        let currentPage = 1;
        let isLoadingMore = false;
        let allContentloaded = false;

        const ICONS = {
          play: '<i class="bi bi-play-fill"></i>',
          pause: '<i class="bi bi-pause-fill"></i>',
          repeat: '<i class="bi bi-repeat"></i>',
          repeatOne: '<i class="bi bi-repeat-1"></i>',
          shuffle: '<i class="bi bi-shuffle"></i>',
          prev: '<i class="bi bi-skip-start-fill"></i>',
          next: '<i class="bi bi-skip-end-fill"></i>',
          heart: '<i class="bi bi-heart"></i>',
          heartFill: '<i class="bi bi-heart-fill"></i>',
          volumeUp: '<i class="bi bi-volume-up-fill"></i>',
          volumeDown: '<i class="bi bi-volume-down-fill"></i>',
          volumeMute: '<i class="bi bi-volume-mute-fill"></i>',
        };

        const formatTime = seconds => {
          if (isNaN(seconds) || seconds < 0) return '0:00';
          const min = Math.floor(seconds / 60);
          const sec = Math.floor(seconds % 60).toString().padStart(2, '0');
          return `${min}:${sec}`;
        };

        const fetchData = async (url, options = {}) => {
          try {
            options.cache = 'no-store';
            const res = await fetch(url, options);
            if (!res.ok) {
              const err = await res.json().catch(() => null);
              throw new Error(err ? err.message : `HTTP error: ${res.status}`);
            }
            if (res.headers.get("content-type")?.includes("application/json")) {
              return await res.json();
            }
            return await res.text();
          } catch (error) {
            showToast(error.message, 'error');
            return null;
          }
        };

        const showToast = (message, type = 'info') => {
          const container = document.createElement('div');
          container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
          container.style.zIndex = "2100";
          const toastEl = document.createElement('div');
          toastEl.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : 'success'} border-0`;
          toastEl.setAttribute('role', 'alert');
          toastEl.innerHTML = `
            <div class="d-flex">
              <div class="toast-body text-truncate">${message}</div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>`;
          document.body.appendChild(container);
          container.appendChild(toastEl);
          const toast = new bootstrap.Toast(toastEl);
          toast.show();
          toastEl.addEventListener('hidden.bs.toast', () => container.remove());
        };

        const showLoader = (initial = true) => {
          if (initial) {
            contentArea.innerHTML = '<div class="loader">Loading...</div>';
            contentTitle.classList.remove('d-none');
          } else {
            infiniteScrollLoader.classList.remove('d-none');
          }
        };

        const hideLoader = () => {
          infiniteScrollLoader.classList.add('d-none');
        };

        const updateContentTitle = (text, show = true) => {
          if (!show) {
            contentTitle.classList.add('d-none');
            return;
          }
          contentTitle.classList.remove('d-none');
          const decoded = decodeURIComponent(text.replace(/\+/g, ' '));
          contentTitle.textContent = decoded;
          document.title = decoded + ' - PHP-Music-Lite';
        };

        const buildViewUrl = view => {
          const base = window.location.pathname;
          if (view.type === 'get_songs') return base;
          if (view.type === 'get_albums') return `${base}?view=albums`;
          if (view.type === 'get_artists') return `${base}?view=artists`;
          if (view.type === 'get_favorites') return `${base}?view=favorites`;
          if (view.type === 'get_user_playlists') return `${base}?view=playlists`;
          if (view.type === 'album_songs') return `${base}?share_type=album&id=${view.param}`;
          if (view.type === 'artist_songs') return `${base}?share_type=artist&id=${view.param}`;
          if (view.type === 'playlist_songs') return `${base}?share_type=playlist&id=${view.param}`;
          if (view.type === 'search') return `${base}?search=${encodeURIComponent(view.param)}`;
          return base;
        };

        const renderViewDetailsHeader = (details, type) => {
          let typeText = type.toUpperCase();
          let statsText = `${details.song_count || 0} songs &bull; ${formatTime(details.total_duration || 0)}`;
          let exportBtn = (type === 'playlist') ? `
            <button class="btn me-2" title="Export Playlist" onclick="window.location.href='?action=export_playlist&public_id=${details.public_id}'">
              <i class="bi bi-box-arrow-up"></i> <span class="d-none d-md-inline ms-1">Export</span>
            </button>` : '';
          let shareBtn = `
            <button class="btn share-view-btn ms-auto" title="Share" data-share-id="${details.public_id || encodeURIComponent(details.name)}" data-share-name="${encodeURIComponent(details.name)}">
              <i class="bi bi-share-fill"></i> <span class="d-none d-md-inline ms-1">Share</span>
            </button>`;

          if (type === 'playlist') {
            typeText = `PLAYLIST BY ${details.creator}`;
          }

          const headerHTML = `
            <div class="view-details-header">
              <img src="${details.image_url}" alt="${details.name}">
              <div class="view-details-header-info text-truncate">
                <div class="type text-truncate">${typeText}</div>
                <h2 class="name text-truncate">${details.name}</h2>
                <div class="stats text-truncate">${statsText}</div>
              </div>
              <div class="d-flex align-items-center ms-auto">
                ${exportBtn}
                ${shareBtn}
              </div>
            </div>`;
          contentArea.insertAdjacentHTML('afterbegin', headerHTML);
        };

        const renderSongs = (songs, append = false) => {
          if (sortable) {
            sortable.destroy();
            sortable = null;
          }

          if (!append && !contentArea.querySelector('.view-details-header')) {
            contentArea.innerHTML = '';
          }

          if (!songs || songs.length === 0) {
            if (!append) contentArea.innerHTML += '<div class="text-center p-5 text-secondary">No songs found.</div>';
            allContentloaded = true;
            hideLoader();
            return;
          }

          let songList = contentArea.querySelector('.song-list');
          if (!songList) {
            songList = document.createElement('div');
            songList.className = 'song-list';
            contentArea.insertAdjacentHTML('beforeend', `
              <div class="song-list-header d-none d-md-grid">
                <div>#</div><div class="text-truncate">Title</div><div class="text-truncate">Artist</div><div class="text-truncate">Album</div><div>Time</div><div></div>
              </div>`);
            contentArea.appendChild(songList);
          }

          const escapeAttr = str => str ? String(str).replace(/'/g, "&apos;").replace(/"/g, "&quot;") : '';

          const songsHTML = songs.map(song => {
            const isNowPlaying = currentSong && currentSong.id === song.id;
            return `
              <div class="song-item py-md-3 ${isNowPlaying ? 'now-playing' : ''}"
                data-song-id="${song.id}"
                data-is-favorite="${song.is_favorite == 1 ? '1' : '0'}"
                data-song-title="${escapeAttr(song.title)}"
                data-song-artist="${escapeAttr(song.artist)}"
                data-song-album="${escapeAttr(song.album)}"
                data-song-user-id="${song.user_id}">
                <div class="song-indicator-wrapper d-flex align-items-center justify-content-center">
                  <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="art">
                  <i class="bi bi-soundwave playing-icon"></i>
                </div>
                <div class="song-title-wrapper text-truncate">
                  <div class="song-title text-truncate">${song.title}</div>
                </div>
                <div class="song-artist text-truncate" data-artist="${encodeURIComponent(song.artist)}">${song.artist}</div>
                <div class="song-album text-truncate" data-album="${encodeURIComponent(song.album)}">${song.album}</div>
                <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
                <div class="song-more">
                  <button class="more-btn" data-song-id="${song.id}">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                </div>
                <div class="song-artist-mobile d-md-none text-truncate">
                  <span class="text-truncate me-2">${song.artist}</span>
                  <span>${formatTime(song.duration)}</span>
                </div>
              </div>`;
          }).join('');

          songList.insertAdjacentHTML('beforeend', songsHTML);

          const isSortable = (currentView.type === 'get_favorites' || currentView.type === 'playlist_songs') && currentView.sort === 'manual_order';
          if (isSortable) {
            sortable = Sortable.create(songList, {
              animation: 150,
              delay: 200,
              delayOnTouchOnly: true,
              scroll: mainContent,
              onEnd: async () => {
                const ids = Array.from(songList.querySelectorAll('.song-item')).map(item => item.dataset.songId);
                const action = currentView.type === 'get_favorites' ? 'update_favorite_order' : 'update_playlist_order';
                const body = {
                  ids,
                  ...(currentView.type === 'playlist_songs' && { playlist_public_id: decodeURIComponent(currentView.param) })
                };
                await fetchData(`?action=${action}`, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(body)
                });
              }
            });
          }
        };

        const renderGrid = (items, type, append = false) => {
          if (!append) contentArea.innerHTML = '';
          if (type === 'get_user_playlists' && !append) {
            contentArea.innerHTML = `
              <div class="p-3 d-flex gap-2">
                <button class="btn btn-danger" id="create-new-playlist-btn"><i class="bi bi-plus-lg me-1"></i> Create New Playlist</button>
                <button class="btn" id="import-playlist-btn"><i class="bi bi-box-arrow-in-down me-1"></i> Import Playlist</button>
              </div>`;
          }

          if (!items || items.length === 0) {
            if (!append && type !== 'get_user_playlists') {
              contentArea.innerHTML += '<div class="text-center p-5 text-secondary">Nothing found.</div>';
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let grid = contentArea.querySelector('.row');
          if (!grid) {
            grid = document.createElement('div');
            grid.className = 'row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-4';
            contentArea.appendChild(grid);
          }

          const itemsHTML = items.map(item => {
            let name = '', subtext = '', imageId = 0, dataType = '', dataVal = '', isRound = false;

            if (type === 'get_albums') {
              name = item.album;
              subtext = item.artist;
              imageId = item.image_id || 0;
              dataType = 'album';
              dataVal = name;
            } else if (type === 'get_artists') {
              name = item.artist;
              subtext = `${item.song_count} songs`;
              imageId = item.image_id || 0;
              dataType = 'artist';
              dataVal = name;
              isRound = true;
            } else if (type === 'get_user_playlists') {
              name = item.name;
              subtext = `${item.song_count} songs`;
              imageId = item.image_id || 0;
              dataType = 'playlist';
              dataVal = item.public_id;
            }

            const moreBtn = (type === 'get_user_playlists') ? `
              <button class="playlist-more-btn" data-public-id="${item.public_id}" data-name="${name}">
                <i class="bi bi-three-dots-vertical"></i>
              </button>` : '';

            return `
              <div class="col">
                <div class="card h-100 bg-transparent text-white border-0 playlist-card" data-${dataType}="${encodeURIComponent(dataVal)}" style="cursor: pointer;">
                  ${moreBtn}
                  <img src="?action=get_image&id=${imageId}" class="card-img-top ${isRound ? 'rounded-circle' : 'rounded'}" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);" loading="lazy">
                  <div class="card-body px-0 py-2">
                    <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                    ${subtext ? `<p class="card-text small text-secondary text-truncate">${subtext}</p>` : ''}
                  </div>
                </div>
              </div>`;
          }).join('');

          grid.insertAdjacentHTML('beforeend', itemsHTML);
        };

        const setupSortOptions = viewType => {
          const sortConfigs = {
            'get_songs': { 'title_asc': 'Title', 'artist_asc': 'Artist', 'album_asc': 'Album', 'year_desc': 'Year (Newest)', 'id_desc': 'Recently Added' },
            'get_albums': { 'album_asc': 'Title (A-Z)', 'album_desc': 'Title (Z-A)', 'artist_asc': 'Artist', 'year_desc': 'Year' },
            'get_artists': { 'name_asc': 'Name (A-Z)', 'name_desc': 'Name (Z-A)', 'song_count_desc': 'Most Songs' },
            'get_favorites': { 'manual_order': 'My Order', 'title_asc': 'Title', 'artist_asc': 'Artist' },
            'playlist_songs': { 'manual_order': 'My Order', 'title_asc': 'Title', 'artist_asc': 'Artist' },
            'artist_songs': { 'title_asc': 'Title', 'album_asc': 'Album', 'year_desc': 'Year' },
            'album_songs': { 'title_asc': 'Track Title' }
          };

          const options = sortConfigs[viewType];
          if (options) {
            sortSelect.innerHTML = Object.entries(options)
              .map(([val, label]) => `<option value="${val}" ${currentView.sort === val ? 'selected' : ''}>${label}</option>`)
              .join('');
            sortControls.classList.remove('d-none');
          } else {
            sortControls.classList.add('d-none');
          }
        };

        const loadMoreContent = async () => {
          if (isLoadingMore || allContentloaded) return;
          isLoadingMore = true;
          showLoader(false);

          currentPage++;
          let data;
          const { type, param, sort } = currentView;
          const params = new URLSearchParams({ page: currentPage, sort });

          if (type === 'get_songs' || type === 'get_favorites') {
            data = await fetchData(`?action=${type}&${params.toString()}`);
            renderSongs(data, true);
          } else if (type === 'get_albums' || type === 'get_artists' || type === 'get_user_playlists') {
            data = await fetchData(`?action=${type}&${params.toString()}`);
            renderGrid(data, type, true);
          } else if (type === 'playlist_songs') {
            params.append('public_id', decodeURIComponent(param));
            data = await fetchData(`?action=get_playlist_songs&${params.toString()}`);
            renderSongs(data, true);
          } else if (type === 'artist_songs' || type === 'album_songs') {
            params.append(type.split('_')[0], decodeURIComponent(param));
            data = await fetchData(`?action=get_songs&${params.toString()}`);
            renderSongs(data, true);
          } else if (type === 'search') {
            params.delete('sort');
            params.append('q', param);
            data = await fetchData(`?action=search&${params.toString()}`);
            renderSongs(data, true);
          } else {
            allContentloaded = true;
          }

          if (!data || data.length < PAGE_SIZE) allContentloaded = true;
          isLoadingMore = false;
          hideLoader();
        };

        const updateActiveNavLink = viewType => {
          allNavLinks.forEach(l => l.classList.remove('active'));
          let active = document.querySelector(`.nav-link[data-view="${viewType}"]`);
          if (!active) {
            if (viewType === 'artist_songs') active = document.querySelector('.nav-link[data-view="get_artists"]');
            if (viewType === 'album_songs') active = document.querySelector('.nav-link[data-view="get_albums"]');
            if (viewType === 'playlist_songs') active = document.querySelector('.nav-link[data-view="get_user_playlists"]');
          }
          if (active) active.classList.add('active');
        };

        const loadView = async (viewConfig, pushToHistory = true) => {
          mainContent.scrollTop = 0;
          currentPage = 1;
          allContentloaded = false;
          isLoadingMore = false;
          showLoader();

          currentView = viewConfig;
          localStorage.setItem('php_music_last_view', JSON.stringify(currentView));

          if (pushToHistory) {
            history.pushState(currentView, '', buildViewUrl(currentView));
          }

          updateActiveNavLink(currentView.type);
          setupSortOptions(currentView.type);

          let data;
          const params = new URLSearchParams({ sort: currentView.sort, page: 1 });

          switch (currentView.type) {
            case 'get_songs':
              updateContentTitle('All Songs');
              data = await fetchData(`?action=get_songs&${params.toString()}`);
              renderSongs(data, false);
              break;
            case 'get_favorites':
              updateContentTitle('Favorites');
              data = await fetchData(`?action=get_favorites&${params.toString()}`);
              renderSongs(data, false);
              break;
            case 'get_albums':
              updateContentTitle('Albums');
              data = await fetchData(`?action=get_albums&${params.toString()}`);
              renderGrid(data, 'get_albums', false);
              break;
            case 'get_artists':
              updateContentTitle('Artists');
              data = await fetchData(`?action=get_artists&${params.toString()}`);
              renderGrid(data, 'get_artists', false);
              break;
            case 'get_user_playlists':
              updateContentTitle('Playlists');
              data = await fetchData(`?action=get_user_playlists&${params.toString()}`);
              renderGrid(data, 'get_user_playlists', false);
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'playlist_songs':
              const type = currentView.type.split('_')[0];
              updateContentTitle('', false);
              const viewData = await fetchData(`?action=get_view_data&type=${type}&name=${currentView.param}&sort=${currentView.sort}&page=1`);
              contentArea.innerHTML = '';
              if (viewData && viewData.details) {
                renderViewDetailsHeader(viewData.details, type);
                renderSongs(viewData.songs, false);
                data = viewData.songs;
              }
              break;
            case 'search':
              updateContentTitle(`Search: "${currentView.param}"`);
              params.delete('sort');
              params.append('q', currentView.param);
              data = await fetchData(`?action=search&${params.toString()}`);
              renderSongs(data, false);
              break;
          }

          if (data && data.length < PAGE_SIZE) allContentloaded = true;

          if (viewConfig.highlight) {
            setTimeout(() => {
              const el = contentArea.querySelector(`.song-item[data-song-id="${viewConfig.highlight}"]`);
              if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.backgroundColor = 'rgba(255, 23, 68, 0.2)';
                setTimeout(() => el.style.backgroundColor = '', 2000);
              }
            }, 300);
          }
          hideLoader();
        };

        window.addEventListener('popstate', e => {
          if (e.state && e.state.type) {
            loadView(e.state, false);
          } else {
            loadView({ type: 'get_songs', param: '', sort: 'title_asc' }, false);
          }
        });

        const playSongById = async songId => {
          const song = await fetchData(`?action=get_song_data&id=${songId}`);
          if (!song) return;
          currentSong = song;

          const cachedBlob = await opfs.getBlob(`song_${song.id}.audio`);
          if (cachedBlob) {
            audio.src = URL.createObjectURL(cachedBlob);
          } else {
            audio.src = currentSong.stream_url;
            audio.addEventListener('playing', () => {
              fetch(currentSong.stream_url)
                .then(r => r.ok ? r.blob() : null)
                .then(blob => { if (blob) opfs.saveBlob(`song_${song.id}.audio`, blob); })
                .catch(() => {});
            }, { once: true });
          }

          audio.load();
          audio.play().catch(e => console.error("Audio playback error:", e));
          isPlaying = true;
          updatePlayerUI();

          if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = new MediaMetadata({
              title: currentSong.title,
              artist: currentSong.artist,
              album: currentSong.album,
              artwork: [{ src: currentSong.image_url, sizes: '500x500', type: 'image/webp' }]
            });
            navigator.mediaSession.setActionHandler('play', togglePlayPause);
            navigator.mediaSession.setActionHandler('pause', togglePlayPause);
            navigator.mediaSession.setActionHandler('previoustrack', playPrev);
            navigator.mediaSession.setActionHandler('nexttrack', playNext);
          }
        };

        const updatePlayerUI = () => {
          if (!currentSong) return;
          if (playerBar.classList.contains('d-none')) {
            playerBar.classList.remove('d-none');
            document.body.classList.add('player-visible');
          }
          const imgUrl = `?action=get_image&id=${currentSong.id}`;
          playerElements.art.forEach(el => { if (el) el.src = imgUrl; });
          playerElements.title.forEach(el => { if (el) el.textContent = currentSong.title; });
          playerElements.artist.forEach(el => { if (el) el.textContent = currentSong.artist; });
          document.title = `${currentSong.title} • ${currentSong.artist}`;

          updatePlayPauseIcons();
          document.querySelectorAll('.song-item.now-playing').forEach(el => el.classList.remove('now-playing'));
          document.querySelectorAll(`.song-item[data-song-id="${currentSong.id}"]`).forEach(el => el.classList.add('now-playing'));
        };

        const updatePlayPauseIcons = () => {
          const icon = isPlaying ? ICONS.pause : ICONS.play;
          playerElements.playPauseBtn.forEach(btn => {
            if (btn) {
              btn.innerHTML = icon;
              btn.title = isPlaying ? "Pause" : "Play";
            }
          });
          if ('mediaSession' in navigator) {
            navigator.mediaSession.playbackState = isPlaying ? "playing" : "paused";
          }
        };

        const updateRepeatIcons = () => {
          let icon = ICONS.repeat, title = "Repeat Off";
          playerElements.repeatBtn.forEach(btn => btn?.classList.remove('active'));
          if (repeatMode === 'one') {
            icon = ICONS.repeatOne; title = "Repeat One";
            playerElements.repeatBtn.forEach(btn => btn?.classList.add('active'));
          } else if (repeatMode === 'all') {
            title = "Repeat All";
            playerElements.repeatBtn.forEach(btn => btn?.classList.add('active'));
          }
          playerElements.repeatBtn.forEach(btn => { if (btn) { btn.innerHTML = icon; btn.title = title; } });
        };

        const updateShuffleButtons = () => {
          playerElements.shuffleBtn.forEach(btn => {
            if (btn) {
              btn.classList.toggle('active', isShuffle);
              btn.title = isShuffle ? "Shuffle On" : "Shuffle Off";
            }
          });
        };

        const toggleFavorite = async songId => {
          if (!currentUser) {
            showToast('Please log in to manage favorites.', 'error');
            return;
          }
          const res = await fetchData('?action=toggle_favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: songId })
          });
          if (res) {
            if (currentSong && currentSong.id === songId) {
              currentSong.is_favorite = res.is_favorite ? 1 : 0;
            }
            const songEls = document.querySelectorAll(`.song-item[data-song-id="${songId}"]`);
            if (currentView.type === 'get_favorites' && !res.is_favorite) {
              songEls.forEach(el => el.remove());
            } else {
              songEls.forEach(el => el.dataset.isFavorite = res.is_favorite ? "1" : "0");
            }
            showToast(res.status === 'added' ? 'Added to favorites' : 'Removed from favorites', 'success');
          }
        };

        const showShareModal = (type, id, name) => {
          const decoded = decodeURIComponent(name);
          shareModalTitle.textContent = `Share "${decoded}"`;
          shareUrlInput.value = `${window.location.origin}${window.location.pathname}?share_type=${type}&id=${id}`;
          copyShareUrlBtn.textContent = 'Copy';
          shareModal.show();
        };

        // Perfectly Constrained Context Menu Positioning (Never Cut Off)
        const positionContextMenu = buttonEl => {
          contextMenu.style.visibility = 'hidden';
          contextMenu.style.display = 'block';

          const rect = buttonEl.getBoundingClientRect();
          const menuWidth = contextMenu.offsetWidth || 220;
          const menuHeight = contextMenu.offsetHeight || 220;

          let x = rect.right - menuWidth;
          if (x < 12) x = 12;
          if (x + menuWidth > window.innerWidth - 12) {
            x = window.innerWidth - menuWidth - 12;
          }

          let y = rect.bottom + 6;
          if (y + menuHeight > window.innerHeight - 12) {
            y = rect.top - menuHeight - 6;
          }
          if (y < 12) y = 12;

          contextMenu.style.left = `${Math.round(x)}px`;
          contextMenu.style.top = `${Math.round(y)}px`;
          contextMenu.style.visibility = 'visible';
        };

        const buildAndShowSongContextMenu = (btn, data) => {
          contextMenuItemEl = btn;
          const { id, title, artist, album, is_favorite } = data;
          let items = `
            <li class="context-menu-item text-truncate" data-action="share_song" data-id="${id}" data-name="${encodeURIComponent(title)}"><i class="bi bi-share-fill"></i> Share</li>
            <li class="context-menu-item text-truncate" data-action="go_artist" data-name="${encodeURIComponent(artist)}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item text-truncate" data-action="go_album" data-name="${encodeURIComponent(album)}"><i class="bi bi-disc-fill"></i> Go to Album</li>
            <li class="context-menu-item text-truncate" data-action="download_song" data-id="${id}"><i class="bi bi-download"></i> Download</li>
            <li class="context-menu-item text-truncate" data-action="show_metadata" data-id="${id}"><i class="bi bi-info-circle"></i> Info</li>`;

          if (currentUser) {
            const favText = is_favorite ? "Remove Favorite" : "Add Favorite";
            const favIcon = is_favorite ? ICONS.heartFill : ICONS.heart;
            items += `
              <li class="context-menu-item text-truncate" data-action="toggle_favorite" data-id="${id}">${favIcon} ${favText}</li>
              <li class="context-menu-item text-truncate" data-action="add_to_playlist" data-id="${id}"><i class="bi bi-plus-lg"></i> Add to Playlist</li>`;
            if (currentView.type === 'playlist_songs') {
              items += `<li class="context-menu-item text-danger text-truncate" data-action="remove_from_playlist" data-id="${id}"><i class="bi bi-x-circle"></i> Remove from Playlist</li>`;
            }
          }
          items += `<li class="context-menu-item" data-action="close_menu"><i class="bi bi-x-lg"></i> Close</li>`;
          contextMenu.innerHTML = items;
          positionContextMenu(btn);
        };

        const togglePlayPause = () => {
          if (!currentSong) return;
          isPlaying = !isPlaying;
          isPlaying ? audio.play() : audio.pause();
          updatePlayPauseIcons();
        };

        const playNext = () => {
          if (queue.length === 0) return;
          queueIndex++;
          if (queueIndex >= queue.length) {
            if (repeatMode === 'all') {
              queueIndex = 0;
            } else {
              isPlaying = false;
              audio.pause();
              updatePlayPauseIcons();
              queueIndex = queue.length - 1;
              return;
            }
          }
          playSongById(queue[queueIndex]);
        };

        const playPrev = () => {
          if (queue.length === 0) return;
          if (audio.currentTime > 3) {
            audio.currentTime = 0;
            return;
          }
          if (queueIndex <= 0) {
            if (repeatMode === 'all') {
              queueIndex = queue.length - 1;
            } else {
              audio.currentTime = 0;
              return;
            }
          } else {
            queueIndex--;
          }
          playSongById(queue[queueIndex]);
        };

        const toggleShuffle = () => {
          isShuffle = !isShuffle;
          localStorage.setItem('php_music_shuffle', isShuffle);
          if (queue.length > 0 && currentSong) {
            const currentId = currentSong.id;
            if (isShuffle) {
              queue = [...originalQueue];
              for (let i = queue.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [queue[i], queue[j]] = [queue[j], queue[i]];
              }
              const idx = queue.findIndex(id => id === currentId);
              if (idx > -1) [queue[0], queue[idx]] = [queue[idx], queue[0]];
            } else {
              queue = [...originalQueue];
            }
            queueIndex = queue.findIndex(id => id === currentId);
          }
          updateShuffleButtons();
        };

        const setQueueAndPlay = async startId => {
          const allIds = await fetchData('?action=get_view_ids', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ view_type: currentView.type, param: currentView.param, sort: currentView.sort })
          });
          if (!allIds || allIds.length === 0) return;
          originalQueue = allIds;
          queue = [...originalQueue];
          if (isShuffle) {
            isShuffle = false;
            toggleShuffle();
          }
          queueIndex = queue.findIndex(id => id === startId);
          if (queueIndex > -1) playSongById(startId);
        };

        // Navigation Links
        allNavLinks.forEach(link => {
          if (link.getAttribute('data-bs-toggle') === 'modal' || link.id === 'sidebar-logout-btn') return;
          link.addEventListener('click', e => {
            e.preventDefault();
            const viewType = e.currentTarget.dataset.view;
            let sort = 'title_asc';
            if (viewType === 'get_favorites' || viewType === 'get_user_playlists') sort = 'manual_order';
            if (viewType === 'get_albums') sort = 'album_asc';
            if (viewType === 'get_artists') sort = 'name_asc';
            loadView({ type: viewType, param: '', sort });

            const offcanvasEl = document.getElementById('main-nav-offcanvas');
            if (window.innerWidth < 768 && offcanvasEl) {
              const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
              if (offcanvas) offcanvas.hide();
            }
          });
        });

        // Search Handlers
        const runSearch = q => {
          if (q.trim()) loadView({ type: 'search', param: q.trim(), sort: 'title_asc' });
        };
        searchInputDesktop.addEventListener('keyup', e => { if (e.key === 'Enter') runSearch(e.target.value); });
        searchInputMobile.addEventListener('keyup', e => { if (e.key === 'Enter') runSearch(e.target.value); });
        searchBtnDesktop.addEventListener('click', () => runSearch(searchInputDesktop.value));
        searchBtnMobile.addEventListener('click', () => runSearch(searchInputMobile.value));

        sortSelect.addEventListener('change', e => {
          loadView({ ...currentView, sort: e.target.value });
        });

        // Player Controls Listeners
        playerElements.playPauseBtn.forEach(btn => btn?.addEventListener('click', togglePlayPause));
        playerElements.prevBtn.forEach(btn => btn?.addEventListener('click', playPrev));
        playerElements.nextBtn.forEach(btn => btn?.addEventListener('click', playNext));
        playerElements.shuffleBtn.forEach(btn => btn?.addEventListener('click', toggleShuffle));
        playerElements.repeatBtn.forEach(btn => btn?.addEventListener('click', () => {
          repeatMode = (repeatMode === 'none') ? 'all' : (repeatMode === 'all') ? 'one' : 'none';
          localStorage.setItem('php_music_repeat', repeatMode);
          updateRepeatIcons();
        }));
        playerElements.moreBtn.forEach(btn => btn?.addEventListener('click', e => {
          e.stopPropagation();
          if (currentSong) buildAndShowSongContextMenu(btn, currentSong);
        }));

        if (playerTrackInfoMobile) {
          playerTrackInfoMobile.addEventListener('click', e => {
            if (!e.target.closest('button') && playerModal) playerModal.show();
          });
        }

        if (playerElements.volumeSlider) {
          playerElements.volumeSlider.addEventListener('input', e => {
            audio.volume = e.target.value;
            audio.muted = false;
          });
        }
        if (playerElements.volumeBtn) {
          playerElements.volumeBtn.addEventListener('click', () => {
            audio.muted = !audio.muted;
            playerElements.volumeSlider.value = audio.muted ? 0 : (audio.volume > 0 ? audio.volume : previousVolume);
          });
        }
        audio.addEventListener('volumechange', () => {
          if (!audio.muted) previousVolume = audio.volume;
          const isMuted = audio.muted || audio.volume === 0;
          playerElements.volumeBtn.innerHTML = isMuted ? ICONS.volumeMute : (audio.volume < 0.5 ? ICONS.volumeDown : ICONS.volumeUp);
        });

        // Audio Progress
        audio.addEventListener('timeupdate', () => {
          if (!isFinite(audio.duration)) return;
          const progress = (audio.currentTime / audio.duration) * 100;
          playerElements.progress.forEach(p => { if (p) p.style.width = `${progress}%`; });
          playerElements.currentTime.forEach(c => { if (c) c.textContent = formatTime(audio.currentTime); });
          playerElements.timeLeft.forEach(t => { if (t) t.textContent = '-' + formatTime(audio.duration - audio.currentTime); });
        });
        audio.addEventListener('ended', () => repeatMode === 'one' ? audio.play() : playNext());
        playerElements.progressContainer.forEach(container => {
          container?.addEventListener('click', e => {
            if (!audio.duration || !isFinite(audio.duration)) return;
            const rect = container.getBoundingClientRect();
            const percent = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            audio.currentTime = percent * audio.duration;
          });
        });

        // Content Area Click Handlers
        contentArea.addEventListener('click', e => {
          const target = e.target;
          const moreBtn = target.closest('.more-btn');
          if (moreBtn) {
            e.stopPropagation();
            const songItem = moreBtn.closest('.song-item');
            buildAndShowSongContextMenu(moreBtn, {
              id: parseInt(songItem.dataset.songId),
              is_favorite: songItem.dataset.isFavorite === '1',
              title: songItem.dataset.songTitle,
              artist: songItem.dataset.songArtist,
              album: songItem.dataset.songAlbum
            });
            return;
          }

          const plMoreBtn = target.closest('.playlist-more-btn');
          if (plMoreBtn) {
            e.stopPropagation();
            contextMenuItemEl = plMoreBtn;
            const { publicId, name } = plMoreBtn.dataset;
            contextMenu.innerHTML = `
              <li class="context-menu-item text-truncate" data-action="edit_playlist" data-public-id="${publicId}" data-name="${name}"><i class="bi bi-pencil"></i> Edit</li>
              <li class="context-menu-item text-truncate" data-action="export_playlist" data-public-id="${publicId}"><i class="bi bi-box-arrow-up"></i> Export</li>
              <li class="context-menu-item text-danger text-truncate" data-action="delete_playlist" data-public-id="${publicId}"><i class="bi bi-trash"></i> Delete</li>
              <li class="context-menu-item" data-action="close_menu"><i class="bi bi-x-lg"></i> Close</li>`;
            positionContextMenu(plMoreBtn);
            return;
          }

          const shareBtn = target.closest('.share-view-btn');
          if (shareBtn) {
            e.stopPropagation();
            showShareModal(currentView.type.split('_')[0], shareBtn.dataset.shareId, shareBtn.dataset.shareName);
            return;
          }

          if (target.closest('#create-new-playlist-btn')) {
            createPlaylistModal.show();
            return;
          }

          if (target.closest('#import-playlist-btn')) {
            playlistImportInput.click();
            return;
          }

          const artistClick = target.closest('.song-artist');
          if (artistClick) {
            e.stopPropagation();
            loadView({ type: 'artist_songs', param: artistClick.dataset.artist, sort: 'title_asc' });
            return;
          }

          const albumClick = target.closest('.song-album');
          if (albumClick) {
            e.stopPropagation();
            loadView({ type: 'album_songs', param: albumClick.dataset.album, sort: 'title_asc' });
            return;
          }

          const card = target.closest('.card');
          if (card && !target.closest('.playlist-more-btn')) {
            if (card.dataset.artist) loadView({ type: 'artist_songs', param: card.dataset.artist, sort: 'title_asc' });
            else if (card.dataset.album) loadView({ type: 'album_songs', param: card.dataset.album, sort: 'title_asc' });
            else if (card.dataset.playlist) loadView({ type: 'playlist_songs', param: card.dataset.playlist, sort: 'manual_order' });
            return;
          }

          const songItem = target.closest('.song-item');
          if (songItem) {
            setQueueAndPlay(parseInt(songItem.dataset.songId));
          }
        });

        // Context Menu Click Handling
        document.addEventListener('click', e => {
          if (contextMenu.style.display === 'block' && !contextMenu.contains(e.target)) {
            contextMenu.style.display = 'none';
          }
        });

        contextMenu.addEventListener('click', async e => {
          const item = e.target.closest('.context-menu-item');
          if (!item) return;
          const { action, name, id, publicId } = item.dataset;
          contextMenu.style.display = 'none';

          switch (action) {
            case 'share_song':
              showShareModal('song', id, name);
              break;
            case 'go_artist':
              loadView({ type: 'artist_songs', param: name, sort: 'title_asc' });
              break;
            case 'go_album':
              loadView({ type: 'album_songs', param: name, sort: 'title_asc' });
              break;
            case 'toggle_favorite':
              toggleFavorite(parseInt(id));
              break;
            case 'download_song':
              window.location.href = `?action=download_song&id=${id}`;
              break;
            case 'export_playlist':
              window.location.href = `?action=export_playlist&public_id=${publicId}`;
              break;
            case 'show_metadata':
              const meta = await fetchData(`?action=get_song_data&id=${id}`);
              if (meta) {
                metadataModalBody.innerHTML = `
                  <ul class="list-group list-group-flush">
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between text-truncate"><span>Title:</span> <strong class="text-truncate">${meta.title}</strong></li>
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between text-truncate"><span>Artist:</span> <strong class="text-truncate">${meta.artist}</strong></li>
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between text-truncate"><span>Album:</span> <strong class="text-truncate">${meta.album}</strong></li>
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between"><span>Year:</span> <strong>${meta.year || 'N/A'}</strong></li>
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between"><span>Duration:</span> <strong>${formatTime(meta.duration)}</strong></li>
                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between"><span>Bitrate:</span> <strong>${meta.bitrate ? Math.round(meta.bitrate / 1000) + ' kbps' : 'N/A'}</strong></li>
                  </ul>`;
                metadataModal.show();
              }
              break;
            case 'add_to_playlist':
              songIdForPlaylist = parseInt(id);
              addToPlaylistModalBody.innerHTML = '<div class="text-center p-3 text-secondary">Loading playlists...</div>';
              addToPlaylistModal.show();
              const playlists = await fetchData('?action=get_user_playlists');
              if (playlists && playlists.length > 0) {
                addToPlaylistModalBody.innerHTML = playlists.map(p =>
                  `<button class="list-group-item list-group-item-action bg-transparent text-white border-0 text-truncate my-1 p-2 rounded add-to-playlist-item" data-playlist-id="${p.id}">${p.name}</button>`
                ).join('');
              } else {
                addToPlaylistModalBody.innerHTML = '<p class="text-secondary text-center mb-0">No playlists found. Create one first!</p>';
              }
              break;
            case 'remove_from_playlist':
              const removed = await fetchData('?action=remove_from_playlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ song_id: parseInt(id), playlist_public_id: decodeURIComponent(currentView.param) })
              });
              if (removed && removed.status === 'success') {
                showToast('Removed from playlist', 'success');
                loadView(currentView, false);
              }
              break;
            case 'edit_playlist':
              document.getElementById('edit-playlist-id-input').value = publicId;
              document.getElementById('edit-playlist-name-input').value = name;
              editPlaylistModal.show();
              break;
            case 'delete_playlist':
              if (confirm('Delete this playlist?')) {
                const del = await fetchData('?action=delete_playlist', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ public_id: publicId })
                });
                if (del && del.status === 'success') {
                  showToast('Playlist deleted', 'success');
                  loadView(currentView, false);
                }
              }
              break;
          }
        });

        // Add to Playlist Selection
        addToPlaylistModalBody.addEventListener('click', async e => {
          const item = e.target.closest('.add-to-playlist-item');
          if (!item || !songIdForPlaylist || isAddingToPlaylist) return;
          isAddingToPlaylist = true;
          const plId = item.dataset.playlistId;
          const sId = songIdForPlaylist;
          songIdForPlaylist = null;
          try {
            const res = await fetchData('?action=add_to_playlist', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ playlist_id: plId, song_id: sId })
            });
            if (res) {
              showToast(res.message, res.status === 'success' ? 'success' : 'info');
              addToPlaylistModal.hide();
            }
          } finally {
            isAddingToPlaylist = false;
          }
        });

        // Import Playlist
        playlistImportInput.addEventListener('change', async e => {
          const file = e.target.files[0];
          if (!file) return;
          const formData = new FormData();
          formData.append('file', file);
          const res = await fetchData('?action=import_playlist', { method: 'POST', body: formData });
          if (res && res.status === 'success') {
            showToast(res.message, 'success');
            loadView({ type: 'get_user_playlists', param: '', sort: 'name_asc' });
          }
          playlistImportInput.value = '';
        });

        // Infinite Scroll
        mainContent.addEventListener('scroll', () => {
          if (mainContent.scrollTop + mainContent.clientHeight >= mainContent.scrollHeight - 300) {
            loadMoreContent();
          }
        });

        // Forms and Authentication
        document.getElementById('login-form').addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('login-email').value;
          const password = document.getElementById('login-password').value;
          const res = await fetchData('?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
          });
          if (res && res.status === 'success') {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('login-modal')).hide();
            e.target.reset();
            showToast('Logged in successfully', 'success');
            await checkSession();
            loadView(currentView, false);
          }
        });

        document.getElementById('register-form').addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('register-email').value;
          const artist = document.getElementById('register-artist').value;
          const password = document.getElementById('register-password').value;
          const res = await fetchData('?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, artist, password })
          });
          if (res && res.status === 'success') {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('register-modal')).hide();
            e.target.reset();
            showToast(res.message, 'success');
            await checkSession();
            loadView(currentView, false);
          }
        });

        document.getElementById('sidebar-logout-btn').addEventListener('click', async e => {
          e.preventDefault();
          await fetchData('?action=logout');
          currentUser = null;
          updateUIForAuthState();
          loadView({ type: 'get_songs', param: '', sort: 'title_asc' });
        });

        // Change Display Name
        document.getElementById('change-name-form').addEventListener('submit', async e => {
          e.preventDefault();
          const artist = document.getElementById('display-name-input').value;
          const res = await fetchData('?action=change_name', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ artist })
          });
          if (res && res.status === 'success') {
            currentUser.artist = res.artist;
            showToast(res.message, 'success');
          }
        });

        // Change Password
        document.getElementById('change-password-form').addEventListener('submit', async e => {
          e.preventDefault();
          const new_password = document.getElementById('new-password').value;
          const res = await fetchData('?action=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ new_password })
          });
          if (res && res.status === 'success') {
            e.target.reset();
            showToast(res.message, 'success');
          }
        });

        // Delete Cache Button
        document.getElementById('delete-cache-btn').addEventListener('click', async () => {
          try {
            if (navigator.storage && navigator.storage.getDirectory) {
              const root = await navigator.storage.getDirectory();
              for await (const name of root.keys()) {
                await root.removeEntry(name, { recursive: true }).catch(() => {});
              }
            }
            if ('caches' in window) {
              const keys = await caches.keys();
              await Promise.all(keys.map(k => caches.delete(k)));
            }
            showToast('Cache deleted successfully!', 'success');
          } catch (err) {
            showToast('Error clearing cache: ' + err.message, 'error');
          }
        });

        // Delete Account
        document.getElementById('delete-account-btn').addEventListener('click', async () => {
          if (confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
            const res = await fetchData('?action=delete_account', { method: 'POST' });
            if (res && res.status === 'success') {
              bootstrap.Modal.getOrCreateInstance(document.getElementById('settings-modal')).hide();
              currentUser = null;
              updateUIForAuthState();
              showToast(res.message, 'success');
              loadView({ type: 'get_songs', param: '', sort: 'title_asc' });
            }
          }
        });

        document.getElementById('create-playlist-form').addEventListener('submit', async e => {
          e.preventDefault();
          const name = document.getElementById('playlist-name-input').value;
          const res = await fetchData('?action=create_playlist', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
          });
          if (res && res.status === 'success') {
            createPlaylistModal.hide();
            e.target.reset();
            showToast('Playlist created', 'success');
            if (currentView.type === 'get_user_playlists') loadView(currentView, false);
          }
        });

        document.getElementById('edit-playlist-form').addEventListener('submit', async e => {
          e.preventDefault();
          const public_id = document.getElementById('edit-playlist-id-input').value;
          const name = document.getElementById('edit-playlist-name-input').value;
          const res = await fetchData('?action=edit_playlist', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ public_id, name })
          });
          if (res && res.status === 'success') {
            editPlaylistModal.hide();
            showToast('Playlist updated', 'success');
            if (currentView.type === 'get_user_playlists') loadView(currentView, false);
          }
        });

        copyShareUrlBtn.addEventListener('click', () => {
          navigator.clipboard.writeText(shareUrlInput.value).then(() => {
            copyShareUrlBtn.textContent = 'Copied!';
            setTimeout(() => copyShareUrlBtn.textContent = 'Copy', 2000);
          });
        });

        if (fullScanModalEl && fullScanIframe) {
          fullScanModalEl.addEventListener('show.bs.modal', () => fullScanIframe.src = '?action=full_scan');
          fullScanModalEl.addEventListener('hidden.bs.modal', () => {
            fullScanIframe.src = 'about:blank';
            loadView(currentView, false);
          });
        }

        window.addEventListener('beforeinstallprompt', e => {
          e.preventDefault();
          deferredInstallPrompt = e;
          installPwaBtn.classList.remove('d-none');
        });
        installPwaBtn.addEventListener('click', async e => {
          e.preventDefault();
          if (!deferredInstallPrompt) return;
          deferredInstallPrompt.prompt();
          await deferredInstallPrompt.userChoice;
          deferredInstallPrompt = null;
          installPwaBtn.classList.add('d-none');
        });

        function updateUIForAuthState() {
          const loggedIn = !!currentUser;
          document.body.classList.toggle('logged-in', loggedIn);
          document.body.classList.toggle('logged-out', !loggedIn);
          if (currentUser) {
            const nameInput = document.getElementById('display-name-input');
            if (nameInput) nameInput.value = currentUser.artist || '';
          }
        }

        async function checkSession() {
          const res = await fetchData('?action=get_session');
          currentUser = (res && res.status === 'loggedin') ? res.user : null;
          updateUIForAuthState();
        }

        const init = async () => {
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?pwa=sw').catch(() => {});
          }
          playerElements.prevBtn.forEach(b => { if (b) b.innerHTML = ICONS.prev; });
          playerElements.nextBtn.forEach(b => { if (b) b.innerHTML = ICONS.next; });
          playerElements.shuffleBtn.forEach(b => { if (b) b.innerHTML = ICONS.shuffle; });
          updatePlayPauseIcons();
          updateRepeatIcons();
          updateShuffleButtons();

          await checkSession();

          let startView = null;
          if (window.initialView) {
            startView = window.initialView;
          } else {
            const urlParams = new URLSearchParams(window.location.search);
            const viewParam = urlParams.get('view');
            const searchParam = urlParams.get('search');
            if (viewParam) {
              const sort = (viewParam === 'albums') ? 'album_asc' : (viewParam === 'artists') ? 'name_asc' : 'title_asc';
              const type = viewParam.startsWith('get_') ? viewParam : 'get_' + viewParam;
              startView = { type, param: '', sort };
            } else if (searchParam) {
              startView = { type: 'search', param: searchParam, sort: 'title_asc' };
            } else {
              const saved = localStorage.getItem('php_music_last_view');
              if (saved) {
                try { startView = JSON.parse(saved); } catch (e) {}
              }
            }
          }

          if (!startView) startView = { type: 'get_songs', param: '', sort: 'title_asc' };

          loadView(startView, false);
          history.replaceState(startView, '', buildViewUrl(startView));
        };

        init();
      });
    </script>
  </body>
</html>