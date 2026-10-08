#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

APP_ID="nddownloader"

# Extract version from appinfo/info.xml
VERSION=$(grep -oPm1 "(?<=<version>)[^<]+" "${ROOT_DIR}/appinfo/info.xml" || echo "1.0.0")
echo "Building release package for ${APP_ID} v${VERSION}..."

BUILD_DIR="${ROOT_DIR}/build"
RELEASE_DIR="${BUILD_DIR}/${APP_ID}"
ARCHIVE_NAME="${APP_ID}-${VERSION}.tar.gz"
GENERIC_ARCHIVE="${APP_ID}.tar.gz"

rm -rf "${BUILD_DIR}"
mkdir -p "${RELEASE_DIR}"

# Include required app directories and files
echo "Copying application assets..."
cp -r "${ROOT_DIR}/appinfo" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/lib" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/templates" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/js" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/css" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/img" "${RELEASE_DIR}/"
cp -r "${ROOT_DIR}/l10n" "${RELEASE_DIR}/"
cp "${ROOT_DIR}/LICENSE" "${RELEASE_DIR}/"
cp "${ROOT_DIR}/THIRD_PARTY_NOTICES.md" "${RELEASE_DIR}/"
cp "${ROOT_DIR}/CHANGELOG.md" "${RELEASE_DIR}/"
cp "${ROOT_DIR}/README.md" "${RELEASE_DIR}/"

# Clean up any development or temporary files
find "${RELEASE_DIR}" -type f -name "*.map" -delete || true
find "${RELEASE_DIR}" -type f -name ".DS_Store" -delete || true

echo "Creating tarball..."
cd "${BUILD_DIR}"
tar -czf "${ARCHIVE_NAME}" "${APP_ID}"
cp "${ARCHIVE_NAME}" "${GENERIC_ARCHIVE}"

echo "Release archive created successfully:"
echo " - ${BUILD_DIR}/${ARCHIVE_NAME}"
echo " - ${BUILD_DIR}/${GENERIC_ARCHIVE}"
