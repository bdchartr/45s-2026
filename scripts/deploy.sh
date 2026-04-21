#!/usr/bin/env zsh
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

# Collect files to upload
FILES=()
while IFS= read -r -d '' f; do
  FILES+=("$f")
done < <(find . -type f \
  -not -path "./.git/*" \
  -not -path "./vendor/*" \
  -not -path "./node_modules/*" \
  -not -name "config.php" \
  -not -name "*.ps1" \
  -print0)

echo "Deploying ${#FILES[@]} files to ${USER}@${SERVER}:${REMOTE_DIR} ..."

# Collect unique remote directories needed
typeset -A DIRS
for f in "${FILES[@]}"; do
  rel="${f#./}"
  remote_file="${REMOTE_DIR}/${rel}"
  remote_dir="${remote_file:h}"   # zsh :h modifier = dirname
  DIRS["$remote_dir"]=1
done

# Pre-create all remote directories via SSH
MKDIR_CMD="mkdir -p"
for d in "${(@k)DIRS}"; do
  MKDIR_CMD+=" \"$d\""
done
ssh -p "$PORT" "${USER}@${SERVER}" "eval $MKDIR_CMD"

# Build sftp batch and upload
BATCH=$(mktemp)
trap 'rm -f "$BATCH"' EXIT

for f in "${FILES[@]}"; do
  rel="${f#./}"
  echo "put $f ${REMOTE_DIR}/${rel}" >> "$BATCH"
done

sftp -b "$BATCH" -P "$PORT" "${USER}@${SERVER}"

echo "Deploy complete."
echo "URL: https://wkapp.com/45/"
