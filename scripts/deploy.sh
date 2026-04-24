#!/usr/bin/env bash
set -euo pipefail

SERVER="wkapp.com"
USER="chartb"
PORT="2222"
REMOTE_DIR="/home/chartb/public_html/45"

cd "$(dirname "$0")/.."

if [[ ! -f composer.json ]]; then
  echo "ERROR: Run from the 45s project root." >&2
  exit 1
fi

echo "Deploying to ${USER}@${SERVER}:${REMOTE_DIR} ..."

rsync -az --checksum --delete \
  -e "ssh -p ${PORT}" \
  --exclude='.git/' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='server/config.php' \
  --exclude='*.ps1' \
  --exclude='.phpunit.cache/' \
  . "${USER}@${SERVER}:${REMOTE_DIR}"

echo "Deploy complete."
echo "URL: https://wkapp.com/45/"
