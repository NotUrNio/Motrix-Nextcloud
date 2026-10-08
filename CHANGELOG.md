# Changelog

All notable changes to **ND Downloader** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-08

### Added
- Initial release for the official Nextcloud App Store.
- Support for HTTP, HTTPS, FTP, Magnet links, and BitTorrent downloads.
- Direct integration into Nextcloud Files app ("+ New" menu and drag-and-drop link support).
- Real-time download progress tracking, transfer speeds, and ETA indicators.
- Per-user task ownership isolation.
- Automatic storage sync importing finished downloads into Nextcloud storage.
- Encrypted storage for download server RPC Bearer tokens using `\OCP\Security\ICrypto`.
- Admin settings interface for configuring backend server connection parameters.
- Compatibility with Nextcloud 28, 29, 30, and 31 on PHP 8.1 through 8.4.
