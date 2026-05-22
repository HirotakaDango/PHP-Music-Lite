# PHP-Music-Lite

<img width="720" height="1442" alt="1" src="https://github.com/user-attachments/assets/970c73b7-4296-4cc6-8c5d-5f9d7c037d82" />


A lightweight, single-file web-based music player built using PHP, HTML5, CSS, and vanilla JavaScript. It scans a local directory for MP3 files and allows users to listen to music, create custom playlists, and export/import their playlist configurations.

## Features

- **Local Library Scanning**: Automatically scans the `./music` directory recursively for MP3 files using PHP.
- **Dynamic Loading**: Loads library tracks in batches of 25 (lazy loading) to ensure performance remains smooth with larger directories.
- **Search & Filter**: Real-time filtering for both the local library and custom playlists.
- **Custom Playlists**: Add or remove tracks from a personal playlist saved locally in your browser's `localStorage`.
- **Import & Export**: Save your customized playlist as a JSON file or import a previously exported one.
- **Responsive Web Player**: Mobile-friendly player interface with play, pause, skip, backward, track progress slider, and duration indicators.

## Requirements

- A web server running **PHP** (PHP 5.4+ recommended).
- Modern web browser with JavaScript enabled.

## Setup Instructions

1. **Clone or download** this repository to your web server's document root (e.g., `htdocs` or `var/www/html`).
2. Place the main script as `index.php`.
3. Create a directory named `music` in the same folder:
   ```bash
   mkdir music
