# PHP-Music Lite

<img width="1280" height="720" alt="image" src="https://github.com/user-attachments/assets/15fa5561-43d3-45d8-b10b-2ed67672a646" />

**PHP-Music Lite** is a fast, ultra-lightweight, single-file music streaming server and web player. Inspired by the YouTube Music interface, it serves as the zero-dependency, portable alternative to [HirotakaDango/PHP-Music](https://github.com/HirotakaDango/PHP-Music).

Drop a single PHP file into your music folder, run the scanner, and stream your collection across desktop and mobile devices.

---

## Highlights

- **Single-File Architecture:** Frontend, backend API, and database migrations bundled into one file.
- **SQLite Database:** Zero database setup required; creates and maintains a local `music.db` with WAL mode enabled.
- **Fast Chunked Streaming:** Supports HTTP `206 Partial Content` byte-range seeking with unbuffered 32KB chunk streaming for smooth playback on low-bandwidth networks.
- **OPFS Client Caching:** Uses the browser's Origin Private File System (OPFS) to cache played tracks for instant replays.
- **PWA Ready:** Installable as a Progressive Web App on mobile and desktop with background media playback via the MediaSession API.
- **Modern Responsive UI:** YouTube Music-inspired dark theme, mobile fullscreen player modal, drag-and-drop playlist reordering, and metadata inspectors.

---

## Features

- **Audio Support:** Streams `.mp3`, `.m4a`, `.flac`, `.ogg`, and `.wav`.
- **Automatic Tag & Art Extraction:** Uses [getID3](https://github.com/JamesHeinrich/getID3) to extract titles, artists, albums, years, and bitrates, converting cover art to WebP (300×300) to save storage and bandwidth.
- **Library Views:** Browse by All Songs, Albums, Artists, Favorites, and Custom Playlists.
- **Playlist Management:**
  - Create, rename, and delete playlists.
  - Reorder songs using drag-and-drop (via SortableJS).
  - Export and import playlists to/from `.json` files.
- **User Authentication:** Multi-user support with session persistence (1-year cookie lifetime), password hashing (`PASSWORD_DEFAULT`), and user-specific favorites/playlists.
- **Deep Linking / Sharing:** Generates direct share links for individual songs, albums, artists, or playlists.

---

## Requirements

- **PHP 7.4+** or **PHP 8.x**
- PHP Extensions:
  - `pdo_sqlite`
  - `gd` (required for WebP cover art resizing)
- *(Recommended)* [getID3](https://github.com/JamesHeinrich/getID3) extracted into a `./getid3` folder next to the script for ID3 tag parsing.

---

## Quick Start

1. **Place the files in your music directory:**
   ```text
   /your-music-folder/
   ├── index.php          (rename player.txt/player.php to index.php)
   └── getid3/            (optional, recommended)
       └── getid3.php
   ```

2. **Start a local PHP test server:**
   ```bash
   php -S 0.0.0.0:8000 -t /your-music-folder/
   ```

3. **Open the web app:**
   - Navigate to `http://localhost:8000` in your browser.
   - Click **Scan All** in the sidebar to index your audio files into `music.db`.

4. **Default Credentials:**
   The initial scan creates a default library user:
   - **Email:** `musiclibrary@mail.com`
   - **Password:** `musiclibrary`

*(You can also register a new account directly through the modal).*

---

## Configuration

You can customize the following constants near the top of the file:

```php
define('MUSIC_DIR', __DIR__);             // Directory containing your audio files
define('DB_FILE', __DIR__ . '/music.db'); // Path to SQLite database file
define('PAGE_SIZE', 25);                  // Number of items per infinite scroll page
```

---

## Upstream Project

For the full-featured, modular version with extended multi-user administration, transcode management, and advanced features, visit the main repository:

👉 **[HirotakaDango/PHP-Music](https://github.com/HirotakaDango/PHP-Music)**
