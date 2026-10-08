# Nextcloud App Store Release & Code Signing Guide

This guide describes the exact steps required to register **ND Downloader** (`nddownloader`) on the [Nextcloud App Store](https://apps.nextcloud.com), generate and submit your code signing certificate, configure release automation, and publish releases.

---

> [!CAUTION]
> **CRITICAL SECURITY REQUIREMENT**
> Your private key (`nddownloader.key`) proves ownership of your application.
> - **NEVER** commit `nddownloader.key` to git or publish it publicly.
> - Generate the key **only on your local machine**.
> - If you ever leak or lose your private key, you must immediately revoke the certificate and re-register.

---

## Prerequisites

1. An account on [apps.nextcloud.com](https://apps.nextcloud.com).
2. OpenSSL installed locally (`openssl version`).
3. Your GitHub account profile showing a verified public email address (`niowork477@gmail.com`).

---

## Step 1: Generate Private Key and CSR Locally

On your local machine (Linux/macOS terminal or Windows PowerShell with OpenSSL):

```bash
# 1. Create a secure local directory
mkdir -p ~/.nextcloud/certificates/
cd ~/.nextcloud/certificates/

# 2. Generate an RSA 4096-bit private key and Certificate Signing Request (CSR)
openssl req -nodes -newkey rsa:4096 \
  -keyout nddownloader.key \
  -out nddownloader.csr \
  -subj "/CN=nddownloader"
```

Files produced:
- `nddownloader.key` — **Your private key.** Keep this strictly secret.
- `nddownloader.csr` — **Your Certificate Signing Request.** This will be sent to Nextcloud.

---

## Step 2: Submit CSR to Nextcloud

1. Fork the official Nextcloud certificate requests repository:
   [https://github.com/nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
2. In your fork, create a new directory named `nddownloader/`.
3. Place `nddownloader.csr` inside `nddownloader/`:
   ```
   nddownloader/
     nddownloader.csr
   ```
4. Commit and open a Pull Request against the upstream repository:
   - PR Title: `Add certificate request for nddownloader`
   - Description: Briefly describe the app and note that you are the author (`NotUrNio`).
5. Ensure your GitHub profile displays your email `niowork477@gmail.com`. The Nextcloud security team will review and approve the request.

---

## Step 3: Save Your Signed Certificate

Once the Nextcloud team approves your PR, they will commit a signed public certificate `nddownloader.crt` in your folder.

1. Download or copy `nddownloader.crt`.
2. Save it locally into your certificates folder:
   ```bash
   ~/.nextcloud/certificates/nddownloader.crt
   ```

---

## Step 4: Register the App on apps.nextcloud.com

1. Log in to [apps.nextcloud.com](https://apps.nextcloud.com).
2. Navigate to [Register new app](https://apps.nextcloud.com/developer/apps/new).
3. Fill in the form:
   - **App ID:** `nddownloader`
   - **Certificate:** Paste the entire contents of `~/.nextcloud/certificates/nddownloader.crt`.
   - **Signature:** Generate a signature over your app ID by running:
     ```bash
     echo -n "nddownloader" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/nddownloader.key | openssl base64
     ```
     Copy the resulting base64 string and paste it into the **Signature** field.
4. Click **Register App**.

---

## Step 5: Configure GitHub Secrets for Automated Releases

To let GitHub Actions automatically package and sign your releases on every version tag:

1. Go to your GitHub repository: `https://github.com/NotUrNio/ND-Nextcloud`.
2. Navigate to **Settings** &rarr; **Secrets and variables** &rarr; **Actions**.
3. Click **New repository secret**:
   - **Name:** `APP_PRIVATE_KEY`
   - **Secret:** Paste the entire contents of your private key `nddownloader.key` (including `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----`).
4. (Optional) If using automated store upload:
   - Go to [apps.nextcloud.com/account/token](https://apps.nextcloud.com/account/token) and generate an API Token.
   - Add secret `APPSTORE_TOKEN` with the generated token.

---

## Step 6: Creating a Release

### Method A: Automated via Git Tag (Recommended)

1. Verify `version` in `appinfo/info.xml` and update `CHANGELOG.md`:
   ```xml
   <version>1.0.0</version>
   ```
2. Commit your changes:
   ```bash
   git commit -am "chore(release): prepare v1.0.0"
   ```
3. Tag and push:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```
4. GitHub Actions will automatically:
   - Run linter and PHPUnit unit tests.
   - Package `nddownloader.tar.gz` with top-level `nddownloader/` folder.
   - Sign the tarball with `APP_PRIVATE_KEY` and output `nddownloader.tar.gz.sig`.
   - Create a GitHub Release with assets attached.

### Method B: Manual Local Packaging & Signing

If you prefer to build and sign on your local machine:

```bash
# 1. Build the tarball
make build
# (or: bash scripts/build-release.sh)

# 2. Sign the archive
openssl dgst -sha512 -sign ~/.nextcloud/certificates/nddownloader.key build/nddownloader.tar.gz | openssl base64 > build/nddownloader.tar.gz.sig

# Print signature
cat build/nddownloader.tar.gz.sig
```

---

## Step 7: Publish Release to the App Store

1. Go to [Upload App Release](https://apps.nextcloud.com/developer/apps/releases/new).
2. Enter the details:
   - **Download URL:** The direct link to `nddownloader.tar.gz` attached to your GitHub release, e.g.:
     `https://github.com/NotUrNio/ND-Nextcloud/releases/download/v1.0.0/nddownloader.tar.gz`
   - **Signature:** The base64 signature string from `nddownloader.tar.gz.sig` (either from GitHub Release assets or generated manually via OpenSSL).
   - **Nightly:** Leave unchecked.
3. Click **Add release**.
4. The App Store will download the archive, verify the signature against your public certificate, validate `appinfo/info.xml`, and publish the release immediately!

---

## Step 8: Certificate Renewal & Troubleshooting

### Lost or Compromised Key
If your private key is ever compromised:
1. Submit a PR to `nextcloud/app-certificate-requests` replacing `nddownloader.csr` with a newly generated CSR.
2. The Nextcloud team will revoke the old certificate and issue a new one.
3. Re-register on the App Store (`apps.nextcloud.com/developer/apps/new`).

### Archive Validation Errors
- The App Store requires **strictly one top-level directory** in the tarball, matching the app ID (`nddownloader/`).
- `appinfo/info.xml` must strictly conform to `info.xsd` (`<php>` before `<nextcloud>`, valid `<licence>`, description wrapped in CDATA).
