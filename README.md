# Motrix for Nextcloud

A Nextcloud app that sends downloads (HTTP/HTTPS, FTP, magnet links, torrents) to a
[Motrix](https://motrix.app) Server and saves the finished files straight into Nextcloud storage.

> **Status:** planning — no code yet.

## Idea

The official [Motrix Extension](https://github.com/motrixapp/motrix-extension) bridges a *browser*
and Motrix. This project does the same for *Nextcloud*: paste a link in Nextcloud, Motrix downloads
it, and the file shows up in your Files.

```
Nextcloud (this app) --MDXP JSON-RPC--> Motrix Server (Docker) --writes--> shared folder --> Nextcloud Files
```

## How it talks to Motrix

Motrix 2 exposes **MDXP** (Motrix Download eXchange Protocol) on port `16801`:

- `POST /mdxp` — JSON-RPC 2.0 calls (add task, list, pause, resume, remove, stats)
- `GET /mdxp/events` — live progress stream
- `/mdxp/pair/*` — one-time device-code pairing that issues a bearer token

References: [CLI manual](https://motrix.app/manual/cli/) · [Docker / Server manual](https://motrix.app/manual/docker/)

## Planned features

- **Downloads page** — paste URL / magnet, choose destination folder, live progress, pause/resume/remove
- **Files integration** — "Download URL into this folder", "Add .torrent to Motrix"
- **Admin settings** — Motrix Server address, pairing, storage mode
- **Background job** — import finished files, rescan storage, send Nextcloud notifications
- **Per-user isolation** — users only see their own downloads

## Target environment

- Nextcloud running in a Pterodactyl (Wings) container on Ubuntu
- Motrix Server running as a separate Docker container on the same host,
  with its `/downloads` mounted into the Nextcloud volume (UID/GID `988` to match Pterodactyl)

## Tech stack

- Backend: PHP (Nextcloud app framework, `OCP\` API)
- Frontend: Vue + `@nextcloud/vue`
