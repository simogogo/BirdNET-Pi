#!/usr/bin/env bash
# Stops core BirdNET-Pi services and removes unprocessed audio for today
set -u

if [ -f /etc/birdnet/birdnet.conf ]; then
  # shellcheck disable=SC1091
  source /etc/birdnet/birdnet.conf
fi

logger -t birdnet_stop_core_services -- "Stopping core BirdNET-Pi services" 2>/dev/null || true

services=(
  birdnet_recording.service
  custom_recording.service
  birdnet_analysis.service
  chart_viewer.service
  spectrogram_viewer.service
)

for i in "${services[@]}"; do
  sudo systemctl stop "${i}"
done

if [ -n "${RECS_DIR:-}" ]; then
  today_dir="${RECS_DIR}/$(date +%B-%Y/%d-%A)"
  if [ -d "$today_dir" ]; then
    sudo rm -rf "${today_dir:?}"/*
  fi
fi
