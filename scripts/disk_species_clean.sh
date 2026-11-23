#!/usr/bin/env bash
# KEEP ONLY THE NUMBER OF FILES PER SPECIES DEFINED IN THE OPTIONS
set -u

log() {
  logger -t birdnet_disk_species_clean -- "$1" 2>/dev/null || true
  echo "[disk_species_clean] $1"
}

if [ ! -f /etc/birdnet/birdnet.conf ]; then
  log "ERROR: /etc/birdnet/birdnet.conf not found"
  exit 1
fi
# shellcheck disable=SC1091
source /etc/birdnet/birdnet.conf

EXTRACTED="${EXTRACTED:-$HOME/BirdSongs/Extracted}"
base_dir="${EXTRACTED}/By_Date"
raw_max="${MAX_FILES_SPECIES:-1000}"
raw_max="${raw_max//,/.}"
max_files_species="${raw_max%%.*}"

# If max_files_species is not a valid integer >= 1, exit
if ! [[ "$max_files_species" =~ ^[0-9]+$ ]] || [ "$max_files_species" -lt 1 ]; then
  exit 0
fi

if ! cd "$base_dir" 2>/dev/null; then
  log "ERROR: Cannot access $base_dir"
  exit 1
fi

EXCLUDE_FILE="$HOME/BirdNET-Pi/scripts/disk_check_exclude.txt"
TMP_EXCLUDE="$(mktemp)"
trap 'rm -f "$TMP_EXCLUDE"' EXIT
if [ -r "$EXCLUDE_FILE" ]; then
  # Filter out blank lines and markers so grep -vFf never matches everything
  grep -vE '^\s*$|^\s*##(start|end)\s*$' "$EXCLUDE_FILE" > "$TMP_EXCLUDE" 2>/dev/null || true
fi

# Get unique species from database
bird_names=$(
  sqlite3 -readonly "$HOME/BirdNET-Pi/scripts/birds.db" <<EOF
.mode list
.headers off
SELECT DISTINCT Com_Name FROM detections;
.quit
EOF
)

# Sanitize the bird names (remove single quotes and replace spaces with underscores)
sanitized_names="$(printf '%s\n' "$bird_names" | tr ' ' '_' | tr -d "'" | grep '[[:alnum:]]' | sed 's/_*$//' | sort -u)"

# Define how date works (GNU vs BusyBox)
dateformat=""
if date -d "-7 days" '+%Y-%m-%d' >/dev/null 2>&1; then
  dateformat=" days"
fi

# Precompute last 8 dates (today and past 7 days)
d0="$(date '+%Y-%m-%d')"
d1="$(date -d "-1$dateformat" '+%Y-%m-%d')"
d2="$(date -d "-2$dateformat" '+%Y-%m-%d')"
d3="$(date -d "-3$dateformat" '+%Y-%m-%d')"
d4="$(date -d "-4$dateformat" '+%Y-%m-%d')"
d5="$(date -d "-5$dateformat" '+%Y-%m-%d')"
d6="$(date -d "-6$dateformat" '+%Y-%m-%d')"
d7="$(date -d "-7$dateformat" '+%Y-%m-%d')"

total_deleted=0

while IFS= read -r species; do
  [ -z "$species" ] && continue

  # Replace ALL hyphens in species name with '=' so '-' field indexing works even with multi-hyphen names
  species_san="${species//-/=}"

  candidates="$(
    find . -mindepth 3 -maxdepth 3 -path "./*/${species}/*" -type f \
      -name "*[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*.*" \
      ! -name "*.png" \
      ! -name "*${d7}*" \
      ! -name "*${d6}*" \
      ! -name "*${d5}*" \
      ! -name "*${d4}*" \
      ! -name "*${d3}*" \
      ! -name "*${d2}*" \
      ! -name "*${d1}*" \
      ! -name "*${d0}*" 2>/dev/null | sed 's|^\./||'
  )"

  [ -z "$candidates" ] && continue

  if [ -s "$TMP_EXCLUDE" ]; then
    candidates="$(printf '%s\n' "$candidates" | grep -vxFf "$TMP_EXCLUDE" || true)"
  fi

  [ -z "$candidates" ] && continue

  to_delete="$(
    printf '%s\n' "$candidates" |
      awk -v s="$species" -v r="$species_san" '
        {
          if (s != r) {
            out = ""
            rest = $0
            while ((i = index(rest, s)) > 0) {
              out = out substr(rest, 1, i - 1) r
              rest = substr(rest, i + length(s))
            }
            $0 = out rest
          }
          print
        }' |
      sort -t'-' -k4,4nr -k1,1nr -k2,2nr -k3,3nr |
      tail -n +"$((max_files_species + 1))" |
      awk -v s="$species_san" -v r="$species" '
        {
          if (s != r) {
            out = ""
            rest = $0
            while ((i = index(rest, s)) > 0) {
              out = out substr(rest, 1, i - 1) r
              rest = substr(rest, i + length(s))
            }
            $0 = out rest
          }
          print
        }'
  )"

  [ -z "$to_delete" ] && continue

  sp_deleted=0
  while IFS= read -r rel; do
    [ -z "$rel" ] && continue
    rm -f -- "$rel" "${rel}.png"
    ((sp_deleted++))
    ((total_deleted++))
  done <<< "$to_delete"

  if [ "$sp_deleted" -gt 0 ]; then
    echo "$species : deleted $sp_deleted"
  fi
done <<< "$sanitized_names"

# Clean empty directories created by removals
find "$base_dir" -mindepth 1 -type d -empty -delete 2>/dev/null || true

log "Completed species cleanup (max_files_species=${max_files_species}, deleted=${total_deleted})"
