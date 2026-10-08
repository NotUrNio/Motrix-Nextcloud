# ND Downloader for Nextcloud

A Nextcloud app that sends downloads (HTTP/HTTPS, FTP, magnet links, torrents) to an external ND / Motrix download server and automatically saves finished downloads into Nextcloud storage.

*Compatibility note: works with ND/aria2.*

## Backend Architecture & Protocol

ND Downloader communicates with a dedicated **ND / Motrix download server** via its **`/mdxp` JSON-RPC 2.0** endpoint:
- `POST /mdxp` — JSON-RPC 2.0 protocol managing tasks (`download/add`, `task/list`, `task/get`, `task/pause`, `task/resume`, `task/remove`, `stats/get`, `engine/status`).
- *Note:* This backend protocol is based on the Motrix download daemon specification (`/mdxp`), rather than raw standalone `aria2c` RPC. The backend runs as a container alongside Nextcloud.

```
Nextcloud (ND Downloader) --JSON-RPC (/mdxp)--> ND Server Container --downloads--> Nextcloud Storage
```

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
