<?php
if (isset($_GET['action']) && $_GET['action'] === 'get_songs') {
  header('Content-Type: application/json'); $songs = [];
  if (is_dir('./music')) {
    $dir = new RecursiveDirectoryIterator('./music', RecursiveDirectoryIterator::SKIP_DOTS);
    foreach (new RecursiveIteratorIterator($dir) as $file) {
      if (strtolower($file->getExtension()) === 'mp3') {
        $songs[] = ['name' => $file->getBasename('.mp3'), 'path' => $file->getPathname()];
      }
    }
  }
  echo json_encode($songs); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Music Player</title>
    <style>
      body {
        font-family: sans-serif;
        background: #121212;
        color: #fff;
        padding: 10px;
        margin: 0 0 160px;
        box-sizing: border-box;
      }
      .tab-header {
        display: flex;
        border-bottom: 2px solid #333;
        margin-bottom: 15px;
      }
      .tab-btn {
        flex: 1;
        background: none;
        border: none;
        color: #b3b3b3;
        padding: 12px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        text-align: center;
        border-bottom: 3px solid transparent;
      }
      .tab-btn.active {
        color: #1db954;
        border-bottom-color: #1db954;
      }
      .tab-content {
        display: none;
      }
      .tab-content.active {
        display: block;
      }
      .search-container {
        margin-bottom: 15px;
      }
      .search-input {
        width: 100%;
        background: #1e1e1e;
        border: 1px solid #333;
        color: #fff;
        padding: 10px 14px;
        border-radius: 20px;
        font-size: 14px;
        box-sizing: border-box;
        outline: none;
      }
      .search-input:focus {
        border-color: #1db954;
      }
      .list-container {
        max-height: 400px;
        overflow-y: auto;
        background: #1e1e1e;
        margin-bottom: 15px;
        padding: 5px;
        border-radius: 8px;
      }
      .song-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 8px;
        border-bottom: 1px solid #333;
        cursor: pointer;
      }
      .song-title {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-right: 10px;
        font-size: 14px;
      }
      .btn {
        background: #1db954;
        color: #fff;
        border: none;
        padding: 6px 12px;
        cursor: pointer;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
      }
      .btn-remove {
        background: #ff4444;
      }
      .action-row {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
      }
      .action-btn {
        background: #333;
        color: #fff;
        border: 1px solid #444;
        padding: 8px 16px;
        cursor: pointer;
        border-radius: 4px;
        font-size: 13px;
        font-weight: bold;
      }
      .action-btn:hover {
        background: #444;
      }
      .player {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #181818;
        padding: 15px;
        text-align: center;
        border-top: 1px solid #333;
        box-shadow: 0 -4px 10px rgba(0,0,0,0.5);
      }
      .controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 20px;
        margin-bottom: 10px;
      }
      .control-btn {
        background: none;
        border: none;
        color: #fff;
        cursor: pointer;
        padding: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .control-btn:hover {
        color: #1db954;
      }
      .control-btn svg {
        fill: currentColor;
      }
      .slider-container {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        font-size: 12px;
        color: #b3b3b3;
      }
      .slider {
        width: 100%;
        max-width: 500px;
        height: 5px;
        accent-color: #1db954;
      }
    </style>
  </head>
  <body>
    <div class="tab-header">
      <button class="tab-btn active" onclick="switchTab('library-tab', this)">Library</button>
      <button class="tab-btn" onclick="switchTab('playlist-tab', this)">My Playlist</button>
    </div>

    <div id="library-tab" class="tab-content active">
      <div class="search-container">
        <input type="text" id="librarySearch" class="search-input" placeholder="Search songs..." oninput="filterLibrary()">
      </div>
      <div class="list-container" id="libraryContainer">
        <div id="libraryList"></div>
      </div>
    </div>

    <div id="playlist-tab" class="tab-content">
      <div class="action-row">
        <button class="action-btn" onclick="exportPlaylist()">Export Playlist</button>
        <button class="action-btn" onclick="document.getElementById('importInput').click()">Import Playlist</button>
        <input type="file" id="importInput" style="display: none;" accept=".json" onchange="importPlaylist(event)">
      </div>
      <div class="search-container">
        <input type="text" id="playlistSearch" class="search-input" placeholder="Search playlist..." oninput="filterPlaylist()">
      </div>
      <div class="list-container">
        <div id="playlistList"></div>
      </div>
    </div>

    <div class="player">
      <div id="nowPlaying" style="color: #1db954; margin-bottom: 8px; font-weight: bold; font-size: 14px;">No song selected</div>
      <div class="controls">
        <button class="control-btn" onclick="changeTrack(-1)">
          <svg width="24" height="24" viewBox="0 0 24 24">
            <path d="M6 6h2v12H6zm3.5 6L18 6v12z"/>
          </svg>
        </button>
        <button class="control-btn" id="playBtn" onclick="togglePlay()">
          <svg width="32" height="32" viewBox="0 0 24 24" id="playIcon">
            <path d="M8 5v14l11-7z"/>
          </svg>
        </button>
        <button class="control-btn" onclick="changeTrack(1)">
          <svg width="24" height="24" viewBox="0 0 24 24">
            <path d="M16 6h2v12h-2zm-10 12l8.5-6L6 6z"/>
          </svg>
        </button>
      </div>
      <div class="slider-container">
        <span id="curTime">0:00</span>
        <input type="range" id="progress" class="slider" min="0" max="100" value="0" oninput="seek()">
        <span id="durTime">0:00</span>
      </div>
    </div>
    <audio id="audio" ontimeupdate="updateProgress()" onloadedmetadata="initDuration()" onended="changeTrack(1)"></audio>

    <script>
      let allSongs = [], playlist = [], loadedIndex = 0, currentIdx = -1, currentMode = 'library';
      let filteredLibrarySongs = [];
      const audio = document.getElementById('audio'), playBtn = document.getElementById('playBtn'), progress = document.getElementById('progress');

      window.onload = () => {
        const savedPlaylist = localStorage.getItem('user_playlist');
        if (savedPlaylist) {
          try {
            playlist = JSON.parse(savedPlaylist);
          } catch (e) {}
        }
        fetch('?action=get_songs').then(res => res.json()).then(data => {
          allSongs = data;
          filteredLibrarySongs = [...allSongs];
          load25Songs();
          renderPlaylist();
        });
        document.getElementById('libraryContainer').addEventListener('scroll', function() {
          if (this.scrollTop + this.clientHeight >= this.scrollHeight - 5) {
            load25Songs();
          }
        });
      };

      function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
      }

      function savePlaylistState() {
        localStorage.setItem('user_playlist', JSON.stringify(playlist));
      }

      function exportPlaylist() {
        const formatted = {
          name: "Most Listened",
          songs: playlist.map(song => {
            return {
              title: song.name,
              artist: "Unknown",
              filename: song.path.split(/[\\/]/).pop()
            };
          })
        };
        const blob = new Blob([JSON.stringify(formatted, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'mostlistened.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      }

      function importPlaylist(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
          try {
            const data = JSON.parse(e.target.result);
            if (data && Array.isArray(data.songs)) {
              playlist = data.songs.map(song => {
                const matched = allSongs.find(s => s.name === song.title || s.path.toLowerCase().endsWith(song.filename.toLowerCase()));
                return {
                  name: matched ? matched.name : song.title,
                  path: matched ? matched.path : "./music/" + song.filename
                };
              });
              savePlaylistState();
              renderPlaylist();
            }
          } catch (err) {}
        };
        reader.readAsText(file);
        event.target.value = '';
      }

      function filterLibrary() {
        const q = document.getElementById('librarySearch').value.toLowerCase();
        filteredLibrarySongs = allSongs.filter(song => song.name.toLowerCase().includes(q));
        document.getElementById('libraryList').innerHTML = '';
        loadedIndex = 0;
        load25Songs();
      }

      function load25Songs() {
        const list = document.getElementById('libraryList'), end = Math.min(loadedIndex + 25, filteredLibrarySongs.length);
        for (let i = loadedIndex; i < end; i++) {
          let div = document.createElement('div');
          div.className = 'song-item';
          const songIndexInAllSongs = allSongs.findIndex(s => s.path === filteredLibrarySongs[i].path);
          div.onclick = (e) => {
            if (e.target.tagName !== 'BUTTON') {
              playSong(songIndexInAllSongs, 'library');
            }
          };
          div.innerHTML = `<div class="song-title">${i + 1}. ${filteredLibrarySongs[i].name}</div><button class="btn" onclick="event.stopPropagation(); addToPlaylist(${songIndexInAllSongs})">+ Add</button>`;
          list.appendChild(div);
        }
        loadedIndex = end;
      }

      function filterPlaylist() {
        renderPlaylist();
      }

      function addToPlaylist(idx) {
        const song = allSongs[idx];
        if (playlist.some(p => p.path === song.path)) return;
        playlist.push(song);
        savePlaylistState();
        renderPlaylist();
      }

      function removeFromPlaylist(idx) {
        playlist.splice(idx, 1);
        savePlaylistState();
        if (currentMode === 'playlist' && currentIdx === idx) changeTrack(0);
        renderPlaylist();
      }

      function renderPlaylist() {
        const list = document.getElementById('playlistList');
        list.innerHTML = '';
        const q = document.getElementById('playlistSearch').value.toLowerCase();
        playlist.forEach((song, i) => {
          if (q && !song.name.toLowerCase().includes(q)) return;
          let div = document.createElement('div');
          div.className = 'song-item';
          div.onclick = (e) => {
            if (e.target.tagName !== 'BUTTON') {
              playSong(i, 'playlist');
            }
          };
          div.innerHTML = `<div class="song-title">${i + 1}. ${song.name}</div><button class="btn btn-remove" onclick="event.stopPropagation(); removeFromPlaylist(${i})">✕</button>`;
          list.appendChild(div);
        });
      }

      function playSong(idx, mode) {
        if (idx < 0 || (mode === 'library' && idx >= allSongs.length) || (mode === 'playlist' && idx >= playlist.length)) return;
        currentIdx = idx;
        currentMode = mode;
        let song = (mode === 'library') ? allSongs[idx] : playlist[idx];
        if (!song) return;
        audio.src = song.path;
        document.getElementById('nowPlaying').innerText = song.name;
        audio.play().then(() => {
          updatePlayIcon(true);
        }).catch(() => {
          updatePlayIcon(false);
        });
      }

      function togglePlay() {
        if (!audio.src) {
          if (playlist.length > 0) {
            return playSong(0, 'playlist');
          } else {
            return playSong(0, 'library');
          }
        }
        if (audio.paused) {
          audio.play();
          updatePlayIcon(true);
        } else {
          audio.pause();
          updatePlayIcon(false);
        }
      }

      function updatePlayIcon(isPlaying) {
        const playIcon = document.getElementById('playIcon');
        if (isPlaying) {
          playIcon.setAttribute('viewBox', '0 0 24 24');
          playIcon.innerHTML = '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>';
        } else {
          playIcon.setAttribute('viewBox', '0 0 24 24');
          playIcon.innerHTML = '<path d="M8 5v14l11-7z"/>';
        }
      }

      function changeTrack(direction) {
        let list = (currentMode === 'library') ? allSongs : playlist;
        if (list.length === 0) return;
        currentIdx += direction;
        if (currentIdx >= list.length) currentIdx = 0;
        if (currentIdx < 0) currentIdx = list.length - 1;
        playSong(currentIdx, currentMode);
      }

      function updateProgress() {
        if (!isNaN(audio.duration)) {
          progress.value = (audio.currentTime / audio.duration) * 100;
          document.getElementById('curTime').innerText = formatTime(audio.currentTime);
        }
      }

      function initDuration() {
        document.getElementById('durTime').innerText = formatTime(audio.duration);
      }

      function seek() {
        if (!isNaN(audio.duration)) audio.currentTime = (progress.value / 100) * audio.duration;
      }

      function formatTime(secs) {
        let m = Math.floor(secs / 60), s = Math.floor(secs % 60);
        return m + ":" + (s < 10 ? '0' : '') + s;
      }
    </script>
  </body>
</html>