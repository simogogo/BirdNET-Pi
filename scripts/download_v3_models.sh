#!/usr/bin/env bash
# Download BirdNET+ V3.0 acoustic models, taxonomy labels, and Geomodel V3.0.4
# from official upstream releases (Zenodo + birdnet-team/geomodel) with SHA-256 verification.
#
# Usage:
#   download_v3_models.sh [--pruned | --full | --all | --model <NAME> | --labels-only | --geomodel-only]
#
# Optional mirror overrides:
#   BIRDNET_V3_BASE_URL       (default: https://zenodo.org/records/24362980/files)
#   BIRDNET_V3_URL_SUFFIX     (default: ?download=1 for Zenodo; set empty for GitHub Releases)
#   BIRDNET_GEOMODEL_BASE_URL (default: https://github.com/birdnet-team/geomodel/releases/download/v3.0.4)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODEL_DIR="${BIRDNET_MODEL_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)/model}"
LOCK_FILE="/tmp/birdnet_v3_models.lock"

V3_BASE_URL="${BIRDNET_V3_BASE_URL:-https://zenodo.org/records/24362980/files}"
V3_URL_SUFFIX="${BIRDNET_V3_URL_SUFFIX-?download=1}"
GEOMODEL_BASE_URL="${BIRDNET_GEOMODEL_BASE_URL:-https://github.com/birdnet-team/geomodel/releases/download/v3.0.4}"

# Official filenames, URL-encoded names, and SHA-256 checksums
V3_FULL_FILE="BirdNET+_V3.0_Global_12K_FP16.tflite"
V3_FULL_ENC="BirdNET%2B_V3.0_Global_12K_FP16.tflite"
V3_FULL_SHA="5f6d77917dc461414469b3d6ef78df2ec3651c4268c384592eb47760d7354106"

V3_PRUNED_FILE="BirdNET+_V3.0_Global_12K_FP16_pruned.tflite"
V3_PRUNED_ENC="BirdNET%2B_V3.0_Global_12K_FP16_pruned.tflite"
V3_PRUNED_SHA="26eaf4c1fabfed48dfb849e460ae67a3856af5b82c989e55c806d479c3a771ba"

V3_LABELS_FILE="BirdNET+_V3.0_Global_12K_Labels.csv"
V3_LABELS_ENC="BirdNET%2B_V3.0_Global_12K_Labels.csv"
V3_LABELS_SHA="88d976477fac81127ac8ac4c215c37749d0a6537944112ea606d321317cb7640"

GEO_ONNX_FILE="BirdNET+_Geomodel_V3.0.4_Global_14K_FP16.onnx"
GEO_ONNX_ENC="BirdNET%2B_Geomodel_V3.0.4_Global_14K_FP16.onnx"
GEO_ONNX_SHA="80e410f811bf4455e6cb1a2d48e394737d3828fe943a444646778495b67e7bab"

GEO_LABELS_FILE="BirdNET+_Geomodel_V3.0.4_Global_14K_Labels.txt"
GEO_LABELS_ENC="BirdNET%2B_Geomodel_V3.0.4_Global_14K_Labels.txt"
GEO_LABELS_SHA="8250b457e45d43fc3e77b5cbd06a1d311baf585ab9c51ed8d42e011d98534835"

WANT_PRUNED=0
WANT_FULL=0
WANT_LABELS=0
WANT_GEOMODEL=0

if [ $# -eq 0 ]; then
  WANT_PRUNED=1
  WANT_LABELS=1
  WANT_GEOMODEL=1
fi

while [ $# -gt 0 ]; do
  case "$1" in
    --pruned)
      WANT_PRUNED=1
      WANT_LABELS=1
      WANT_GEOMODEL=1
      ;;
    --full)
      WANT_FULL=1
      WANT_LABELS=1
      WANT_GEOMODEL=1
      ;;
    --all)
      WANT_PRUNED=1
      WANT_FULL=1
      WANT_LABELS=1
      WANT_GEOMODEL=1
      ;;
    --labels-only)
      WANT_LABELS=1
      ;;
    --geomodel-only)
      WANT_GEOMODEL=1
      ;;
    --model)
      shift
      if [ $# -eq 0 ]; then
        echo "Error: --model requires a model name" >&2
        exit 1
      fi
      case "$1" in
        BirdNET+_V3.0_Global_12K_FP16)
          WANT_FULL=1
          WANT_LABELS=1
          WANT_GEOMODEL=1
          ;;
        BirdNET+_V3.0_Global_12K_FP16_pruned)
          WANT_PRUNED=1
          WANT_LABELS=1
          WANT_GEOMODEL=1
          ;;
        *)
          echo "Model '$1' does not require BirdNET+ V3.0 external assets."
          exit 0
          ;;
      esac
      ;;
    -h|--help)
      echo "Usage: $0 [--pruned | --full | --all | --model <NAME> | --labels-only | --geomodel-only]"
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
  shift
done

mkdir -p "${MODEL_DIR}"

verify_sha256() {
  local filepath="$1"
  local expected="$2"
  local actual=""
  if command -v sha256sum >/dev/null 2>&1; then
    actual="$(sha256sum "${filepath}" | awk '{print $1}')"
  elif command -v shasum >/dev/null 2>&1; then
    actual="$(shasum -a 256 "${filepath}" | awk '{print $1}')"
  else
    return 0
  fi
  [ "${actual}" = "${expected}" ]
}

download_file() {
  local filename="$1"
  local url="$2"
  local expected_sha="$3"
  local dest="${MODEL_DIR}/${filename}"
  local tmp="${dest}.tmp.$$"

  if [ -f "${dest}" ] && verify_sha256 "${dest}" "${expected_sha}"; then
    echo "[OK] ${filename} already present and verified."
    return 0
  fi

  echo "[DL] Downloading ${filename}..."
  rm -f "${tmp}"
  trap 'rm -f "${tmp}"' RETURN

  if ! curl -fL --retry 3 --retry-delay 3 --connect-timeout 20 -o "${tmp}" "${url}"; then
    echo "[ERR] Failed to download ${filename} from ${url}" >&2
    rm -f "${tmp}"
    return 1
  fi

  if ! verify_sha256 "${tmp}" "${expected_sha}"; then
    echo "[ERR] SHA-256 mismatch for ${filename}! Discarding corrupted download." >&2
    rm -f "${tmp}"
    return 1
  fi

  mv -f "${tmp}" "${dest}"
  chmod 664 "${dest}" 2>/dev/null || true
  trap - RETURN
  echo "[OK] ${filename} downloaded and verified."
}

run_downloads() {
  if [ "${WANT_LABELS}" -eq 1 ]; then
    download_file "${V3_LABELS_FILE}" "${V3_BASE_URL}/${V3_LABELS_ENC}${V3_URL_SUFFIX}" "${V3_LABELS_SHA}"
  fi
  if [ "${WANT_GEOMODEL}" -eq 1 ]; then
    download_file "${GEO_ONNX_FILE}" "${GEOMODEL_BASE_URL}/${GEO_ONNX_ENC}" "${GEO_ONNX_SHA}"
    download_file "${GEO_LABELS_FILE}" "${GEOMODEL_BASE_URL}/${GEO_LABELS_ENC}" "${GEO_LABELS_SHA}"
  fi
  if [ "${WANT_PRUNED}" -eq 1 ]; then
    download_file "${V3_PRUNED_FILE}" "${V3_BASE_URL}/${V3_PRUNED_ENC}${V3_URL_SUFFIX}" "${V3_PRUNED_SHA}"
  fi
  if [ "${WANT_FULL}" -eq 1 ]; then
    download_file "${V3_FULL_FILE}" "${V3_BASE_URL}/${V3_FULL_ENC}${V3_URL_SUFFIX}" "${V3_FULL_SHA}"
  fi
}

if command -v flock >/dev/null 2>&1; then
  exec 9>"${LOCK_FILE}"
  flock -x 9
  run_downloads
  flock -u 9
else
  run_downloads
fi
