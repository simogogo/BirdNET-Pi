#!/usr/bin/env bash
set -u

log() {
  logger -t birdnet_disk_check -- "$1" 2>/dev/null || true
  echo "[disk_check] $1"
}

# Prevent overlapping runs from cron
exec 9>/tmp/birdnet_disk_check.lock
if ! flock -n 9; then
  exit 0
fi

if [ ! -f /etc/birdnet/birdnet.conf ]; then
  log "ERROR: /etc/birdnet/birdnet.conf not found"
  exit 1
fi
# shellcheck disable=SC1091
source /etc/birdnet/birdnet.conf

EXTRACTED="${EXTRACTED:-$HOME/BirdSongs/Extracted}"
PROCESSED="${PROCESSED:-$HOME/BirdSongs/Processed}"
RECS_DIR="${RECS_DIR:-$HOME/BirdSongs}"
FULL_DISK="${FULL_DISK:-purge}"
EXCLUDE_FILE="$HOME/BirdNET-Pi/scripts/disk_check_exclude.txt"
EMERGENCY_THRESHOLD=98

# Parse PURGE_THRESHOLD safely as integer in [20, 99]
raw_thresh="${PURGE_THRESHOLD:-95}"
raw_thresh="${raw_thresh//,/.}"
purge_threshold="${raw_thresh%%.*}"
if ! [[ "$purge_threshold" =~ ^[0-9]+$ ]]; then
  purge_threshold=95
fi
if [ "$purge_threshold" -lt 20 ]; then purge_threshold=20; fi
if [ "$purge_threshold" -gt 99 ]; then purge_threshold=99; fi

get_used_pct() {
  local target="$1"
  df -P --output=pcent "$target" 2>/dev/null | tail -n1 | tr -dc '0-9'
}

if [ ! -d "$EXTRACTED" ]; then
  log "ERROR: EXTRACTED directory '$EXTRACTED' does not exist"
  exit 1
fi

used="$(get_used_pct "$EXTRACTED")"
if ! [[ "$used" =~ ^[0-9]+$ ]]; then
  log "ERROR: Unable to determine disk usage for '$EXTRACTED'"
  exit 1
fi

if [ "$used" -lt "$purge_threshold" ]; then
  exit 0
fi

log "Disk usage at ${used}% (threshold ${purge_threshold}%, mode=${FULL_DISK})"

case "$FULL_DISK" in
  keep)
    log "FULL_DISK=keep: stopping core services"
    /usr/local/bin/stop_core_services.sh
    exit 0
    ;;
  purge)
    ;;
  *)
    log "WARNING: Unknown FULL_DISK value '$FULL_DISK', defaulting to purge"
    ;;
esac

# Best-effort refresh of auto-protected best-per-species recordings (non-fatal)
curl -fsS --max-time 15 "http://localhost/views.php?view=Species%20Stats" >/dev/null 2>&1 || true

# Ensure exclude file exists (never abort silently if missing)
if [ ! -f "$EXCLUDE_FILE" ]; then
  mkdir -p "$(dirname "$EXCLUDE_FILE")"
  printf "##start\n##end\n" > "$EXCLUDE_FILE" 2>/dev/null || true
  log "WARNING: Created missing $EXCLUDE_FILE"
fi

# Build sanitized non-empty exclude list (strip blanks and markers)
TMP_EXCLUDE="$(mktemp)"
trap 'rm -f "$TMP_EXCLUDE"' EXIT
if [ -r "$EXCLUDE_FILE" ]; then
  grep -vE '^\s*$|^\s*##(start|end)\s*$' "$EXCLUDE_FILE" > "$TMP_EXCLUDE" 2>/dev/null || true
fi

BY_DATE_DIR="${EXTRACTED}/By_Date"
deleted=0

if [ -d "$BY_DATE_DIR" ] && cd "$BY_DATE_DIR"; then
  # Count audio files and date folders safely (no glob expansion, no div-by-zero)
  total_audio="$(find . -mindepth 3 -maxdepth 3 -type f ! -name '*.png' 2>/dev/null | wc -l)"
  day_dirs="$(find . -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)"

  if [ "$total_audio" -gt 0 ]; then
    if [ "$day_dirs" -gt 0 ]; then
      target_delete=$(( total_audio / day_dirs ))
    else
      target_delete="$total_audio"
    fi
    if [ "$target_delete" -lt 50 ]; then
      target_delete=50
    fi

    log "Purging oldest unprotected recordings in $BY_DATE_DIR (target up to ${target_delete} audio files + spectrograms)"

    while IFS= read -r rel; do
      [ -z "$rel" ] && continue

      # Skip protected recordings (do NOT increment deleted counter)
      if [ -s "$TMP_EXCLUDE" ] && grep -qxFe "$rel" "$TMP_EXCLUDE"; then
        continue
      fi

      rm -f -- "$rel" "${rel}.png"
      ((deleted++))

      # Periodically re-check disk usage so we stop as soon as we drop below threshold
      if [ $((deleted % 50)) -eq 0 ]; then
        used="$(get_used_pct "$EXTRACTED")"
        if [[ "$used" =~ ^[0-9]+$ ]] && [ "$used" -lt "$purge_threshold" ]; then
          break
        fi
      fi

      if [ "$deleted" -ge "$target_delete" ]; then
        break
      fi
    done < <(find . -mindepth 3 -maxdepth 3 -type f ! -name '*.png' 2>/dev/null | sed 's|^\./||' | sort)

    # Remove orphan .png files whose audio clip no longer exists
    while IFS= read -r png; do
      [ -z "$png" ] && continue
      audio="${png%.png}"
      if [ ! -f "$audio" ]; then
        rm -f -- "$png"
      fi
    done < <(find . -mindepth 3 -maxdepth 3 -type f -name '*.png' 2>/dev/null | sed 's|^\./||')
  fi

  # Clean empty directories
  find "$BY_DATE_DIR" -mindepth 1 -type d -empty -delete 2>/dev/null || true
  find "$RECS_DIR" -type d -empty -mtime +90 -delete 2>/dev/null || true
else
  log "WARNING: $BY_DATE_DIR not found or inaccessible"
fi

# Re-evaluate disk usage after By_Date purge
used="$(get_used_pct "$EXTRACTED")"
log "Purge finished: deleted ${deleted} recordings, disk usage now ${used}%"

if [[ "$used" =~ ^[0-9]+$ ]] && [ "$used" -ge "$purge_threshold" ]; then
  if [ -d "$PROCESSED" ]; then
    log "Disk still at ${used}%: cleaning $PROCESSED"
    find "$PROCESSED" -mindepth 1 -delete 2>/dev/null || true
  fi

  # Also clean obsolete LDFCS .dat memmap files (not today's) if disk is still full
  LDFCS_DIR="${EXTRACTED}/LongSpectrograms"
  if [ -d "$LDFCS_DIR" ]; then
    today_str="$(date '+%Y-%m-%d')"
    find "$LDFCS_DIR" -maxdepth 1 -type f -name '*.dat' ! -name "*${today_str}*" -delete 2>/dev/null || true
  fi

  used="$(get_used_pct "$EXTRACTED")"
  if [[ "$used" =~ ^[0-9]+$ ]] && [ "$used" -ge "$EMERGENCY_THRESHOLD" ]; then
    log "CRITICAL: Disk usage still ${used}% (>= ${EMERGENCY_THRESHOLD}%) after purge! Stopping core services to prevent corruption."
    /usr/local/bin/stop_core_services.sh
  fi
fi
