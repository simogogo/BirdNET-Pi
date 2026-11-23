#!/usr/bin/env bash

source /etc/birdnet/birdnet.conf
cd /home/$BIRDNET_USER/BirdNET-Pi/scripts

if [[ "${MODEL:-}" == BirdNET+_V3.0_* ]] && [ ! -f "/home/$BIRDNET_USER/BirdNET-Pi/model/BirdNET+_V3.0_Global_12K_Labels.csv" ]; then
  ./download_v3_models.sh --labels-only || true
fi

python3 -c 'from utils.helpers import set_label_file; set_label_file()'

cd -
