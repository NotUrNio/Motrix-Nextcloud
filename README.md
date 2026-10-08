# ND Downloader for Nextcloud

A Nextcloud app that sends downloads (HTTP/HTTPS, FTP, magnet links, torrents) to an Aria2 RPC server and saves the finished files straight into Nextcloud storage.

*Compatibility note: works with ND/aria2.*

## Overview

ND Downloader integrates download management directly into Nextcloud: paste a link or drop a torrent in Nextcloud, the download server downloads it, and the file shows up in your Files.

```
Nextcloud (ND Downloader) --JSON-RPC--> Download Server (Docker) --writes--> shared folder --> Nextcloud Files
```

## How it talks to the server

The server exposes JSON-RPC 2.0 endpoints:

- `POST /mdxp` — JSON-RPC 2.0 calls (add task, list, pause, resume, remove, stats)
- `/api/*` — REST endpoints for the Nextcloud UI

## Features

- **Downloads page** — paste URL / magnet / torrent, choose destination folder, live progress, pause/resume/remove
- **Files integration** — "Download with ND" in the Files new file menu
- **Admin settings** — Aria2 RPC server address, token authentication, storage directory
- **Automatic Storage Sync** — moves completed files into Nextcloud user storage and rescans paths
- **Per-user isolation** — users only see and manage their own downloads

## Target environment

- Nextcloud running on Docker / Pterodactyl container
- Aria2 RPC server running as a container on the host with download directory mounted to Nextcloud data volume

## Tech stack

- Backend: PHP (Nextcloud app framework, `OCP\` API, min NC 28)
- Frontend: Vanilla JS & CSS injected into Nextcloud
