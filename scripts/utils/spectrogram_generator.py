import os
import datetime
import numpy as np
import librosa
from matplotlib import pyplot as plt
import logging
from utils.helpers import get_settings

log = logging.getLogger('spectrogram_generator')

# Load configuration to get the customized EXTRACTED path if it exists
conf = get_settings()
_extracted_base = conf.get('EXTRACTED', os.path.expanduser('~/BirdSongs/Extracted'))
LDFCS_DIR = os.path.join(_extracted_base, 'LongSpectrograms')

FREQ_BINS = 1024
AUDIO_SR = 48000
HOP_LENGTH = 512

def _get_daily_memmap_path(date_str, type_str):
    if not os.path.exists(LDFCS_DIR):
        os.makedirs(LDFCS_DIR, exist_ok=True)
    return os.path.join(LDFCS_DIR, f'daily_{type_str}_{date_str}.dat')

def _get_daily_png_path(date_str, type_str):
    return os.path.join(LDFCS_DIR, f'daily_{type_str}_{date_str}.png')

def _get_total_cols(recording_length):
    # Number of segments in 24 hours
    return int((24 * 3600) / recording_length)

def _get_col_index(timestamp, recording_length):
    # Timestamp is a string like "2026-03-31 09:42:00" or similar, wait
    # In BirdNET-Pi, we can just use current time or parse it from file_date and file_time
    # file_date="2026-03-31", file_time="09:42:00" or similar?
    # Let's pass the actual parsed hours, minutes, seconds, or a datetime object
    total_seconds = timestamp.hour * 3600 + timestamp.minute * 60 + timestamp.second
    col_index = int(total_seconds / recording_length)
    return col_index

# Absolute dB reference: RMS of a full-scale sine wave at 24kHz, 16-bit: ~1.0 amplitude.
# Using ref=1.0 ensures dB values are absolute (not relative to each chunk's max),
# so different time segments remain visually comparable — following Towsey et al. (2018).
_DB_REF = 1.0
# Floor in dB: values below this are clamped (silences). 70 dB dynamic range.
_DB_RANGE = 70.0


def _mag_to_db(S):
    """Convert magnitude spectrogram to dB with fixed reference and dynamic range."""
    S_db = librosa.amplitude_to_db(S, ref=_DB_REF, amin=1e-6, top_db=_DB_RANGE)
    return S_db


def _alpha_from_amplitude(mean_mag_per_bin):
    """Compute the Towsey-style Alpha channel from per-bin mean amplitude.

    Following Towsey et al. (2018): alpha is proportional to overall energy,
    so silent bins are transparent (alpha≈0) and loud bins are opaque (alpha≈1).
    Values are normalised within the chunk to [0, 1].
    """
    alpha = (mean_mag_per_bin - np.min(mean_mag_per_bin)) / (
        np.ptp(mean_mag_per_bin) + 1e-8
    )
    return alpha


def calculate_acoustic_indices(S):
    """Return an RGBA column (FREQ_BINS, 4) of Acoustic-Index False-Color for one chunk.

    Channels:
      R = normalised mean amplitude (energy indicator)
      G = ACI  (Acoustic Complexity Index — high for structured birdsong)
      B = Temporal Entropy (high for unpredictable/broadband sound)
      A = alpha derived from mean amplitude (Towsey et al. 2018 convention)

    Args:
        S: magnitude spectrogram array of shape (FREQ_BINS, time_frames).
    """
    mean_mag = np.mean(S, axis=1)                                          # (FREQ_BINS,)

    # ACI per frequency bin: ratio of temporal variation to total energy
    aci = np.sum(np.abs(np.diff(S, axis=1)), axis=1) / (np.sum(S, axis=1) + 1e-8)

    # Shannon temporal entropy per frequency bin
    S_norm = S / (np.sum(S, axis=1, keepdims=True) + 1e-8)
    entropy = -np.sum(S_norm * np.log2(S_norm + 1e-8), axis=1)

    # Normalise each index to [0, 1] within the chunk
    mag_norm = (mean_mag - np.min(mean_mag)) / (np.ptp(mean_mag) + 1e-8)
    aci_norm = (aci - np.min(aci)) / (np.ptp(aci) + 1e-8)
    ent_norm = (entropy - np.min(entropy)) / (np.ptp(entropy) + 1e-8)

    alpha = _alpha_from_amplitude(mean_mag)

    rgba_col = np.stack([mag_norm, aci_norm, ent_norm, alpha], axis=-1)    # (FREQ_BINS, 4)
    return rgba_col


def calculate_standard_col(S):
    """Return a (FREQ_BINS, 2) column — [dB_value, alpha] — for one chunk.

    The dB value uses *absolute* reference (ref=1.0) so values are comparable
    across chunks recorded at different times of day, unlike per-chunk normalisation
    with ref=np.max which would make every segment look equally loud.

    The alpha channel follows Towsey et al. (2018): loud bins are opaque,
    silent bins are transparent.

    Args:
        S: magnitude spectrogram of shape (FREQ_BINS, time_frames).
    """
    S_db = _mag_to_db(S)                           # absolute dB, shape (FREQ_BINS, time_frames)
    mean_db = np.mean(S_db, axis=1)                # (FREQ_BINS,)
    mean_mag = np.mean(S, axis=1)                  # (FREQ_BINS,) — raw amplitude for alpha
    alpha = _alpha_from_amplitude(mean_mag)        # (FREQ_BINS,)
    return np.stack([mean_db, alpha], axis=-1)     # (FREQ_BINS, 2)

def update_daily_spectrogram(audio_path, conf):
    try:
        generate_standard = int(conf.get('GENERATE_LDFCS_STANDARD', '1'))
        generate_indices = int(conf.get('GENERATE_LDFCS_INDICES', '1'))
        
        if generate_standard == 0 and generate_indices == 0:
            return

        recording_length = float(conf.get('RECORDING_LENGTH', 15))
        
        # We need the timestamp of the file. BirdNET-Pi filenames usually: 
        # "BirdNET_Pi_2026-03-31_09_42_00.wav" or similar, or just use modification time of the file
        # It's usually better to just use datetime.datetime.now() since files are processed instantly
        # Or parse the exact time from the filename "YYYY-MM-DD-birdnet-HH:MM:SS.wav"
        basename = os.path.basename(audio_path)
        
        # Example: 2024-02-24-birdnet-16:19:37.wav
        import re
        match = re.search(r'(\d{4}-\d{2}-\d{2})-.*?(\d{2}:\d{2}:\d{2})', basename)
        if match:
            date_str = match.group(1)
            time_str = match.group(2)
            timestamp = datetime.datetime.strptime(f"{date_str} {time_str}", "%Y-%m-%d %H:%M:%S")
        else:
            # Fallback
            timestamp = datetime.datetime.now()
            date_str = timestamp.strftime('%Y-%m-%d')
            
        col_index = _get_col_index(timestamp, recording_length)
        total_cols = _get_total_cols(recording_length)
        
        if col_index >= total_cols:
            col_index = total_cols - 1

        # Load audio chunk
        y, sr = librosa.load(audio_path, sr=AUDIO_SR, mono=True)
        # Generate spectrogram
        S = np.abs(librosa.stft(y, n_fft=FREQ_BINS*2-1, hop_length=HOP_LENGTH))
        
        # Trim S to exactly FREQ_BINS if needed
        S = S[:FREQ_BINS, :]

        if generate_standard == 1:
            # shape: (FREQ_BINS, 2) — channel 0 = dB, channel 1 = alpha
            std_col = calculate_standard_col(S)
            mem_path = _get_daily_memmap_path(date_str, 'standard')

            if not os.path.exists(mem_path):
                arr = np.memmap(mem_path, dtype='float32', mode='w+', shape=(FREQ_BINS, total_cols, 2))
                arr[:] = np.nan  # NaN marks unvisited columns
            else:
                arr = np.memmap(mem_path, dtype='float32', mode='r+', shape=(FREQ_BINS, total_cols, 2))

            arr[:, col_index, :] = std_col
            arr.flush()
            del arr

        if generate_indices == 1:
            # shape: (FREQ_BINS, 4) — RGBA channels
            idx_col = calculate_acoustic_indices(S)
            mem_path = _get_daily_memmap_path(date_str, 'indices')

            # RGBA shape (FREQ_BINS, total_cols, 4)
            if not os.path.exists(mem_path):
                arr = np.memmap(mem_path, dtype='float32', mode='w+', shape=(FREQ_BINS, total_cols, 4))
                arr[:] = 0.0  # fully transparent black background
            else:
                arr = np.memmap(mem_path, dtype='float32', mode='r+', shape=(FREQ_BINS, total_cols, 4))

            arr[:, col_index, :] = idx_col
            arr.flush()
            del arr
            
    except Exception as e:
        log.exception(f"LDFCS Error: {e}")

def render_daily_image(conf):
    try:
        generate_standard = int(conf.get('GENERATE_LDFCS_STANDARD', '1'))
        generate_indices = int(conf.get('GENERATE_LDFCS_INDICES', '1'))
        
        if generate_standard == 0 and generate_indices == 0:
            return
            
        # We render today's date
        date_str = datetime.datetime.now().strftime('%Y-%m-%d')
        recording_length = float(conf.get('RECORDING_LENGTH', 15))
        total_cols = _get_total_cols(recording_length)
        
        if generate_standard == 1:
            mem_path = _get_daily_memmap_path(date_str, 'standard')
            if os.path.exists(mem_path):
                arr = np.memmap(mem_path, dtype='float32', mode='r', shape=(FREQ_BINS, total_cols, 2))

                # channel 0: dB values; channel 1: alpha
                db_plane = arr[:, :, 0]   # (FREQ_BINS, total_cols)
                alpha_plane = arr[:, :, 1]

                # Replace unvisited NaN columns with silence floor
                db_plane = np.nan_to_num(db_plane, nan=-_DB_RANGE)
                alpha_plane = np.nan_to_num(alpha_plane, nan=0.0)

                # Normalise dB plane to [0,1] for the colourmap
                db_norm = (db_plane - (-_DB_RANGE)) / _DB_RANGE
                db_norm = np.clip(db_norm, 0.0, 1.0)

                # Apply 'magma' colormap → (FREQ_BINS, total_cols, 4) RGBA
                cmap = plt.get_cmap('magma')
                rgba = cmap(db_norm)                    # float64 RGBA [0,1]

                # Override the alpha channel with our Towsey-style alpha
                rgba[:, :, 3] = np.clip(alpha_plane, 0.0, 1.0)

                # Flip: low frequencies at bottom
                rgba = np.flipud(rgba)

                png_path = _get_daily_png_path(date_str, 'standard')
                plt.imsave(png_path, rgba)
                del arr

        if generate_indices == 1:
            mem_path = _get_daily_memmap_path(date_str, 'indices')
            if os.path.exists(mem_path):
                arr = np.memmap(mem_path, dtype='float32', mode='r', shape=(FREQ_BINS, total_cols, 4))

                # RGBA — clip to valid range and flip vertically
                plot_arr = np.clip(arr, 0.0, 1.0)
                plot_arr = np.flipud(plot_arr)

                png_path = _get_daily_png_path(date_str, 'indices')
                plt.imsave(png_path, plot_arr)
                del arr
                
    except Exception as e:
        log.exception(f"LDFCS Render Error: {e}")
