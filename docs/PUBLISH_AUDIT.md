# Nextcloud App Store Publication Audit: ND Downloader (`nddownloader`)

**Audit Date:** October 2026  
**Target Nextcloud Versions:** Nextcloud 28.x – Nextcloud 31.x  
**Target Repository:** [NotUrNio/ND-Nextcloud](https://github.com/NotUrNio/ND-Nextcloud)  
**App ID:** `nddownloader`  
**Audit Purpose:** Comprehensive readiness inspection for publishing on the official Nextcloud App Store ([apps.nextcloud.com](https://apps.nextcloud.com/)) in compliance with official Nextcloud developer guidelines, security standards, and App Store automated validation rules.

---

## 1. App Store Rules & `appinfo/info.xml` Schema Compliance

Nextcloud enforces an XML schema validation check ([`info.xsd`](https://apps.nextcloud.com/schema/apps/info.xsd)) upon tarball upload. Any schema failure or missing mandatory metadata immediately rejects the release.

### 1.1 Field-by-Field Schema Inspection (`appinfo/info.xml`)

| Field | Current Value | App Store / Schema Status | Required Action for Phase 1 |
|---|---|---|---|
| `<id>` | `nddownloader` | **Valid** (matches folder name and lowercase format). | Keep as `nddownloader`. |
| `<name>` | `ND Downloader` | **Valid**. | Keep. |
| `<summary>` | Short summary | **Valid**, but could be more descriptive. | Refine to clearly explain it connects to an external download server. |
| `<description>` | Plain text description | **Partial**. Must be wrapped in `<![CDATA[...]]>` or clean HTML `<p>` tags for rich rendering on apps.nextcloud.com. | Format properly with formatted feature list in CDATA. |
| `<version>` | `1.0.0` | **Valid** SemVer format. | Keep `1.0.0` for initial store release. |
| `<licence>` | `<licence>agpl</licence>` | **FAILED / INVALID**. Nextcloud requires a valid SPDX license identifier. `agpl` is deprecated/rejected by the schema. | Update to `<licence>AGPL-3.0-or-later</licence>` (and add `LICENSE` file). |
| `<author>` | `<author>ND Downloader Team</author>` | **FAILED**. Schema requires `mail` attribute: `<author mail="..." homepage="...">Name</author>`. | Add `mail` attribute and author name. |
| `<category>` | `multimedia`, `tools` | **Valid**. Must match allowed categories in schema (`tools`, `multimedia`, `integration`). | Keep `tools` and `integration`. |
| `<bugs>` | `https://github.com/NotUrNio/ND-Nextcloud/issues` | **Valid** HTTPS issue tracker URL. | Keep. |
| `<repository>` | `https://github.com/NotUrNio/ND-Nextcloud` | **Partial**. Schema requires attribute `type="git"`: `<repository type="git">...</repository>`. | Add `type="git"` attribute. |
| `<screenshot>` | **MISSING**. | **FAILED**. Nextcloud App Store requires at least one `<screenshot>` entry (recommended 2–3 screenshots showing UI and settings). | Add `<screenshot>` URLs/paths pointing to valid images. |
| `<dependencies>` | `<nextcloud min-version="28" max-version="36"/>` | **FAILED**. `max-version="36"` is an invalid future version rejected by store linting. Max version cannot exceed current Nextcloud release + 1 (currently NC 31). | Change to `<nextcloud min-version="28" max-version="31"/>`. |
| `<dependencies>` | Missing `<php>` tag | **FAILED**. Schema requires `<php min-version="..." max-version="..."/>`. | Add `<php min-version="8.1" max-version="8.4"/>`. |
| `<namespace>` | `<namespace>NdDownloader</namespace>` | **Legacy / Deprecated**. PSR-4 `OCA\NdDownloader` is resolved automatically via composer/Application in NC 28+. | Can be kept or omitted safely. |

### 1.2 Store Rules & Asset Requirements

1. **Missing `LICENSE` File:** The repo currently lacks an AGPL-3.0-or-later `LICENSE` file in the root. App Store publication strictly requires the license file to be present and match `<licence>`. Also, an attribution note acknowledging the original MIT-licensed Motrix project must be retained.
2. **Missing Icons (`img/app.svg`, `img/app-dark.svg`):** The Nextcloud app menu and App Store catalog require `img/app.svg` and `img/app-dark.svg`. Currently only `img/nddownloader.svg` exists.
3. **Missing `CHANGELOG.md`:** Release automation and App Store changelog parsing expect a structured `CHANGELOG.md`.
4. **Localization (`l10n/`):** While hardcoded English exists in JS/templates, an `l10n/` folder with base English translations (`l10n/en.json`) is standard for official apps.

---

## 2. Leftover Legacy Strings & Environment Hardcodings

A scan of the codebase reveals lingering environment-specific paths (Pterodactyl container paths, developer file paths), legacy "motrix" strings, and hardcoded tokens:

### 2.1 Hardcoded Tokens & Credentials
* **`lib/Service/NdDownloaderClient.php`:**
  Previously contained a fallback secret token constant `DEFAULT_TOKEN = '6wiYws5ONfV1fg3DAwP1tXiFlOmIc1QWW8RuLKY0tbE'` and automatically rewrote app config on 401.
* **`lib/Migration/Version1000Date20261006000000.php`:**
  Previously seeded `6wiYws5...` if no token existed.

> [!WARNING]
> **Token Compromise Notice:** The previously hardcoded fallback token (`6wiYws5...`) has been completely removed from the code, migrations, and runtime auto-heal routines. Because this repository has historical commits, this token must be treated as compromised. The server administrator must generate and rotate to a new secret RPC Bearer token in the download daemon and update it in Nextcloud Admin Settings. If no token is configured, the app cleanly reports "not configured" / empty and never mutates configuration or uses fallback secrets.

### 2.2 Host / Pterodactyl Environment Hardcodings
* **`lib/Controller/ApiController.php` (Lines 510–511):**
  ```php
  @touch('/home/container/nd_start_trigger');
  @touch('/home/container/motrix_start_trigger');
  ```
  Touches paths inside `/home/container/`. This is exclusive to Pterodactyl server environments and will fail or be a no-op on standard Docker, bare metal, or Kubernetes installations.
* **`lib/Service/StorageSyncService.php` (Lines 33, 56, 85, 162):**
  ```php
  $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', '/home/container/nextcloud/data'), '/');
  foreach (['/var/www/html/data', '/home/container/nextcloud/data'] as $altData) { ... }
  ```
  Hardcoded fallbacks assuming Pterodactyl mount paths.
* **`lib/Service/NdDownloaderClient.php` (Lines 67–73):**
  ```php
  '/home/container/nextcloud/data/bridge/endpoint.json',
  '/home/container/nextcloud/data/bridge/pairing.json',
  '/home/container/nddownloader/bridge/endpoint.json',
  '/home/container/nddownloader/bridge/pairing.json',
  '/home/container/nd/bridge/endpoint.json',
  '/home/container/nd/bridge/pairing.json',
  '/home/container/motrix/bridge/endpoint.json',
  ```
  Probes specific host filesystem directories for pairing files.
* **`README.md` (Lines 32–33):**
  Mentions "Nextcloud running on Docker / Pterodactyl container". Needs standard Docker / Docker Compose instructions.

### 2.3 Legacy & Environment Cleanup (Step D)
* **Resolved in Step D:**
  - Removed `/apps/motrix` URL fallback in `js/app.js`.
  - Removed `motrix_endpoint`, `motrix_token`, and `motrix_save_dir` fallbacks across `NdDownloaderClient.php`, `AdminSettings.php`, and `Version1000Date20261006000000.php`.
  - Removed `http://motrix-server:16801` hardcoded endpoint probes.
  - Removed `motrix` app config check in `UrlValidator.php`.
  - Removed hardcoded `@touch('/home/container/...')` calls from `ApiController.php`.
  - Updated admin settings labels and README to accurately document the backend: **ND / Motrix download server exposing `/mdxp` JSON-RPC 2.0**.

---

## 3. Security Audit

### 3.1 CSRF & Request Token Protection
* **Current State:** Controllers extend `OCP\AppFramework\Controller`. In Nextcloud, controllers require a valid CSRF token by default unless decorated with `#[NoCSRFRequired]`.
* **Frontend:** Both `js/app.js` and `js/files-menu.js` transmit `'requesttoken': getRequestToken()` in their request headers.
* **Finding:** CSRF protection is properly active on state-changing endpoints.

### 3.2 Authentication & Privilege Escalation Vulnerabilities
> [!NOTE]
> **Nextcloud Authorization Model:** In Nextcloud, controller methods require administrator privileges by default unless explicitly decorated with `#[NoAdminRequired]`. There is no `#[AdminRequired]` attribute. Furthermore, `#[NoAdminRequired]` restricts endpoints to authenticated logged-in users; only `#[PublicPage]` permits unauthenticated public/guest access. Because no routes use `#[PublicPage]`, unauthenticated guests were never admitted.

1. **Privilege Escalation in `startEngine()` (`lib/Controller/ApiController.php`):**
   * Previously tagged with `#[NoAdminRequired]`.
   * Any authenticated standard user could trigger host watchdog files and mutate global application settings (`CONFIG_ENDPOINT`, `CONFIG_TOKEN`).
   * **Resolution (Step B):** Removed `#[NoAdminRequired]` (making it admin-only by default) and added explicit admin role checks. Removed hardcoded `@touch('/home/container/...')` calls, replacing them with an optional admin-configured trigger path. Added `#[UserRateLimit(limit: 10, period: 60)]`.
2. **Access Control on `getStatus()` and `getStats()`:**
   * Both previously carried `#[NoAdminRequired]`.
   * **Resolution (Step B):** `getStatus()` is not needed by regular users and has had `#[NoAdminRequired]` removed (now admin-only). `getStats()` is used by the frontend speed indicator and retains `#[NoAdminRequired]`, but now explicitly verifies an active logged-in user session.
3. **Missing Rate Limiting on Administrative & Resource-Intensive Actions:**
   * **Resolution (Step B):** Native Nextcloud `#[UserRateLimit]` attribute applied to `startEngine`, `testConnection`, `syncTask`, `deleteTask`, and `deleteTaskFallback`.

### 3.3 Server-Side Request Forgery (SSRF) & DNS Rebinding
* **Current State:** `UrlValidator.php` parses URLs and checks against private IP ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`, `fc00::/7`, `fe80::/10`).
* **Vulnerabilities:**
  1. **No Configurable Allow/Deny Lists:** Administrators have no UI or config options to restrict allowed download domains (e.g., only allowing specific intranet hosts or blocking known malicious sites).
  2. **DNS Rebinding Vulnerability:** `UrlValidator::validateUrl()` checks the IP at validation time via `gethostbynamel()`. However, the URL is later sent to the external download daemon (`NdDownloaderClient::addUrl`). A malicious domain with low TTL can return a benign public IP during Nextcloud validation, then resolve to an internal loopback/metadata IP (e.g. `169.254.169.254` or `127.0.0.1:16801`) when fetched by the daemon.
  3. **Allow Private Network Default:** If the admin enables private networking, any user can target internal infrastructure via the download server.

### 3.4 Destination Folder Path Traversal & File Abstraction
* **Current State in `StorageSyncService.php`:**
  ```php
  $cleanFolder = trim(str_replace(['..', '\\'], '', (string)$targetFolder), '/');
  $cleanFilename = basename((string)$filename);
  $targetDir = $dataDir . '/' . $userId . '/files/' . $cleanFolder;
  ```
* **Vulnerabilities & Issues:**
  1. **Incomplete Sanitization:** Simple non-recursive string replacement of `..` can be circumvented by sequences such as `....//` (which after replacing `..` leaves `../`).
  2. **Bypassing Nextcloud Virtual Filesystem (`OCP\Files\`):** `StorageSyncService` operates directly on disk paths via PHP `rename()` and `copy()`, completely bypassing Nextcloud's `IUserFolder` and `IRootFolder` node system.
  3. **Broken on Object Storage (S3 / MinIO):** If a Nextcloud instance uses Primary Object Storage (S3/Swift), files do not exist at `$dataDir/$userId/files/`. Direct disk file manipulation fails catastrophically on cloud/object storage installations.
  4. **File Locking & Trashbin:** Direct disk operations bypass Nextcloud's file locking (`ILockingProvider`), activity logs, and quota enforcement until an explicit `scanFile()` is called.

### 3.5 Token Storage Security & Encryption Decision (Step C)
* **Architecture Decision:** We retain compatibility with Nextcloud 28 (`min-version="28"`) and encrypt the secret RPC Bearer token using Nextcloud's core `\OCP\Security\ICrypto` service.
* **Rationale:** While Nextcloud 29 introduced `IAppConfig::TYPE_SENSITIVE`, using it would force dropping support for Nextcloud 28. `\OCP\Security\ICrypto` has been a stable, core cryptographic interface across Nextcloud 28 through 31. It encrypts the token at rest using authenticated AES encryption keyed by the Nextcloud server's unique secret (`config.php`).
* **Implementation:**
  - `NdDownloaderClient::setToken()` automatically encrypts tokens using `ICrypto::encrypt()` before saving to `oc_appconfig`.
  - `NdDownloaderClient::getToken()` decrypts the ciphertext using `ICrypto::decrypt()`, falling back gracefully to plaintext if decrypting an unmigrated legacy value.
  - Added migration `Version1001Date20261008000000.php` to scan for any existing plaintext `nddownloader_token` and encrypt it in place upon app update.

### 3.6 Output Escaping & XSS Review
* **Templates:** `templates/admin.php` properly uses `<?php p($_['endpoint']); ?>` and `<?php p($_['saveDir']); ?>`. `p()` escapes HTML entities.
* **JavaScript:** `files-menu.js` and `app.js` use `escapeHtml()` and safe DOM creation (`document.createElement`, `textContent`).
* **Finding:** No stored or reflected XSS vulnerabilities identified in the view layer.

### 3.7 Rate Limiting
* **Current State:** In `ApiController.php`, only `addTask()` has a rate limit: `#[UserRateLimit(limit: 30, period: 60)]`.
* **Vulnerability:** Endpoints such as `testConnection()`, `startEngine()`, `syncTask()`, and `deleteTask()` have no rate limiting. A user can flood the backend download server with connection test or synchronization requests.

---

## 4. Deprecated & Removed APIs (NC 28 – NC 31)

1. **`OCP\Util::addScript` & `OCP\Util::addStyle`:**
   * Used in `PageController.php`, `AdminSettings.php`, and `FilesLoadAdditionalScriptsListener.php`.
   * **Status:** Deprecated since Nextcloud 19. In modern Nextcloud, assets should be attached directly to the `TemplateResponse` via `$response->addScript(...)` and `$response->addStyle(...)`.
2. **`OCA\Files\Event\LoadAdditionalScriptsEvent`:**
   * Used in `Application.php` and `FilesLoadAdditionalScriptsListener.php` to inject `files-menu.js`.
   * **Status:** Deprecated in Nextcloud 28+ following the Vue 3 rewrite of the Files app. While retained for backward compatibility in NC 28, Nextcloud 30+ deprecates script injection into Files in favor of `@nextcloud/files` app integration. For NC 28–31 compatibility, the listener continues to function, but code should handle missing events gracefully.
3. **`OCP\IConfig::getSystemValue('datadirectory')`:**
   * Used in `StorageSyncService.php`.
   * **Status:** Discouraged for app code. Apps should use `\OCP\Files\IRootFolder::getUserFolder($userId)` to ensure full compatibility with external storages and S3.
4. **PHP Template Engines (`TemplateResponse`):**
   * Nextcloud 28+ encourages modern Vue frontend components over `.php` templates in `templates/`. Traditional PHP templates are still supported for backward compatibility, but produce deprecation notices in `nextcloud.log` on newer versions.

---

## 5. Protocol & Architecture Reconciliation

### 5.1 Protocol Clarification: Motrix/mdxp JSON-RPC vs Native Aria2 JSON-RPC
A critical architectural inconsistency was identified between the UI/documentation and the underlying network protocol:

* **What the UI & README claim:** The settings label states `"Aria2 RPC server:"`, and the README mentions `"talks to an Aria2 RPC server"`.
* **What the code actually executes (`lib/Service/NdDownloaderClient.php`):**
  * The client connects to endpoint `POST /mdxp` (falling back to `/jsonrpc` only if explicitly appended).
  * The JSON-RPC methods sent are:
    * `engine/status`
    * `stats/get`
    * `task/list`
    * `task/get`
    * `download/add` (with parameters `kind: url|magnet|torrent`, `saveDir`, `uris`)
    * `task/pause`, `task/resume`, `task/remove`
* **Analysis:** These are **NOT native aria2 methods**. Native aria2 RPC methods use prefixes like `aria2.addUri`, `aria2.tellStatus`, `aria2.tellActive`, `aria2.pause`, `aria2.getGlobalStat`.  
  Pointing ND Downloader at a standard vanilla `aria2c --enable-rpc` daemon will result in `-32601 Method not found` errors.
  The server expected by ND Downloader is the **Motrix / Motrix-Server backend** (an RPC bridge running the Motrix download core exposing `/mdxp`).
* **Resolution for Phase 1:**
  1. Clearly document in the README and admin settings that the backend is a Motrix-compatible daemon / ND Downloader server container.
  2. Provide a clean, standalone `docker-compose.yml` showing how to launch this container with mounted shared storage on standard Docker.
  3. Ensure settings labels accurately reflect: `"ND / Aria2 RPC Server URL"` with an explanatory hint indicating the `/mdxp` JSON-RPC specification.

### 5.2 Nextcloud Files "+ New" Integration Status
* The user specifically requested: *"Check whether 'Download with ND' still appears in the Files app; follow the README or code, but tell me which, since I wanted the header button next to '+ New' removed."*
* **Code Audit of `js/files-menu.js`:**
  * **Result:** The header button directly adjacent to "+ New" **has been removed**.
  * **Current behavior:** `files-menu.js` registers an entry inside the `+ New` dropdown menu:
    ```javascript
    menu.registerEntry({
        id: 'nd-download',
        displayName: 'Download with ND',
        iconSvgInline: ND_SVG_ICON,
        order: 35,
        category: 1, // CreateNew
        handler: function(destination) { ... }
    });
    ```
  * Additionally, it attaches a global window drag-and-drop listener that opens the download modal when a URL or magnet link is dropped anywhere in the Files view.
  * **Conclusion:** The code matches the user's intent: the header button is gone, and only the "+ New" menu item and drag-and-drop integration remain.

---

## 6. Phase 1 Implementation Plan & Fix Checklist

Upon user approval of this audit, Phase 1 will implement the following structured fixes:

### 1. `appinfo/info.xml` & Repository Metadata (Step E - Completed)
- [x] Fix `<licence>` to `AGPL-3.0-or-later`.
- [x] Add `mail` and name to `<author mail="niowork477@gmail.com">NotUrNio</author>`.
- [x] Fix dependencies: `<php min-version="8.1" max-version="8.4"/>` and `<nextcloud min-version="28" max-version="31"/>`.
- [x] Add `<repository type="git">https://github.com/NotUrNio/ND-Nextcloud</repository>`.
- [x] Wrap description in `<![CDATA[...]]>`.
- [x] Add `<screenshot>` definitions (validated against official Nextcloud info.xsd).
- [x] Create root `LICENSE` (AGPL-3.0-or-later).
- [x] Create `THIRD_PARTY_NOTICES.md` with Motrix MIT copyright and license.
- [x] Create structured `CHANGELOG.md`.
- [x] Add `img/app.svg` and `img/app-dark.svg`.
- [x] Capture/generate UI screenshots and store in `docs/screenshots/` with README guide.

### 2. Environment Hardcoding & Security Remediation
- [x] **Step A (Complete):** Removed hardcoded fallback token `6wiYws5...` from `NdDownloaderClient.php`, `ApiController.php`, and migrations. Removed 401 config-mutating auto-heal. Added compromise notice.
- [x] **Step B (Complete):** Access control hardening & rate limiting. Removed `#[NoAdminRequired]` from `startEngine()` and `getStatus()`. Added `#[UserRateLimit]` to `startEngine`, `testConnection`, `syncTask`, `deleteTask`, and `deleteTaskFallback`. Removed hardcoded host triggers from `startEngine()`.
- [x] **Step C (Complete):** Token storage encryption. Implemented `\OCP\Security\ICrypto` encryption/decryption in `NdDownloaderClient` and created migration `Version1001Date20261008000000.php` to encrypt existing tokens in place. Retained NC 28 compatibility.
- [x] **Step D (Complete):** Remove Motrix & Pterodactyl leftovers. Purged `motrix_*` config fallbacks, `http://motrix-server:16801` probe, `/apps/motrix` frontend route, and `/home/container/` touch commands. Documented `/mdxp` backend protocol in README and settings.
- [ ] Refactor `StorageSyncService.php` to use Nextcloud's `IRootFolder` / `IUserFolder` APIs so it works seamlessly on standard storage and S3 Object Storage, with recursive traversal protection.
- [ ] Implement Admin Domain/IP Allowlist and Denylist in `UrlValidator.php` to prevent SSRF and DNS rebinding attacks.

### 3. Server Deployment Documentation & Consistency
- [ ] Provide a tested `docker-compose.yml` for running Nextcloud + ND Downloader server with a shared volume.
- [ ] Document Docker setup as primary, and Pterodactyl as an optional sub-section.
- [ ] Ensure the UI status label never hangs on "Engine: Checking..." by implementing explicit error/offline states on timeouts.

### 4. Tests & Linting
- [ ] Set up basic PHPUnit test suite for `UrlValidator`, `TaskOwnershipService`, and client request building.
- [ ] Add `psalm.xml` or PHP linter configuration.
