import os
import datetime
import numpy as np
import librosa
from matplotlib import pyplot as plt
import logging
from utils.helpers import get_settings

log = logging.getLogger('spectrogram_generator')

def _get_ldfcs_dir(conf):
    # Try EXTRACTED first, then fallback to RECS_DIR/Extracted
    extracted_base = conf.get('EXTRACTED')
    if not extracted_base:
        recs_dir = conf.get('RECS_DIR', os.path.expanduser('~/BirdSongs'))
        extracted_base = os.path.join(recs_dir, 'Extracted')
    
    ldfcs_dir = os.path.join(extracted_base, 'LongSpectrograms')
    os.makedirs(ldfcs_dir, exist_ok=True)
    return ldfcs_dir

FREQ_BINS = 512
AUDIO_SR = 24000
HOP_LENGTH = 512

def _get_daily_memmap_path(date_str, type_str, conf):
    ldfcs_dir = _get_ldfcs_dir(conf)
    return os.path.join(ldfcs_dir, f'daily_{type_str}_{date_str}.dat')

def _get_daily_png_path(date_str, type_str, conf):
    ldfcs_dir = _get_ldfcs_dir(conf)
    return os.path.join(ldfcs_dir, f'daily_{type_str}_{date_str}.png')

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


def _alpha_from_db(db_val):
    """Compute the Towsey-style Alpha channel from absolute dB.
    
    Instead of per-column normalization (which hides dim signals in noise),
    we use an absolute dB range:
    - Below -70 dB: fully transparent (alpha=0.0)
    - Above -35 dB: fully opaque (alpha=1.0)
    """
    alpha = (db_val - (-_DB_RANGE)) / (_DB_RANGE / 2.0)
    return np.clip(alpha, 0.0, 1.0)


def calculate_acoustic_indices(S):
    """Return an RGBA column (FREQ_BINS, 4) of Acoustic-Index False-Color for one chunk.

    Towsey-style Mapping (standard for identifying acoustic niches):
      R = ACI (Acoustic Complexity — captures bird songs)
      G = ENT (Temporal Entropy — captures wide-band noise like wind)
      B = BGN (Background Noise — captures persistent steady noise)
      
    Modified: All channels are modulated by Intensitiy (dB level) to avoid
    "cyan fog" saturation in silent regions.

    Args:
        S: magnitude spectrogram array of shape (FREQ_BINS, time_frames).
    """
    # 1. Global Intensity for scaling brightness
    S_db = _mag_to_db(S)
    mean_db = np.mean(S_db, axis=1)                                       # (FREQ_BINS,)
    
    # Scaling factor for brightness: -70dB (black) to -25dB (full color)
    # Using a -25dB ceiling makes interesting bird sounds vivid.
    intensity = (mean_db - (-_DB_RANGE)) / 45.0
    intensity = np.clip(intensity, 0.0, 1.0)

    # 2. ACI (Acoustic Complexity Index) per frequency bin
    # Sum of frame-to-frame changes normalized by total magnitude.
    # Added a small floor to prevent noise-floor saturation.
    sum_S = np.sum(S, axis=1)
    aci = np.sum(np.abs(np.diff(S, axis=1)), axis=1) / (sum_S + 1e-4)
    # Birds typically produce ACI between 0.2 and 0.5.
    aci_norm = np.clip(aci / 0.5, 0.0, 1.0)

    # 3. ENT (Temporal Entropy) per frequency bin
    # Shannon entropy of the temporal energy distribution.
    # Low values mean impulsive sounds; high values mean steady background noise (wind/rain).
    S_norm = S / (sum_S[:, None] + 1e-12)
    entropy = -np.sum(S_norm * np.log2(S_norm + 1e-12), axis=1)
    # Normalise against maximum possible entropy (log2 of time frames)
    max_ent = np.log2(max(2, S.shape[1]))
    ent_norm = np.clip(entropy / max_ent, 0.0, 1.0)

    # 4. BGN (Background Noise — Blue channel)
    # Approximate background level using the minimum magnitude in the segment.
    bgn_db = librosa.amplitude_to_db(np.min(S, axis=1), ref=_DB_REF, amin=1e-8, top_db=_DB_RANGE)
    bgn_norm = np.clip((bgn_db - (-_DB_RANGE)) / _DB_RANGE, 0.0, 1.0)

    # Apply Intensity modulation to all channels
    # This prevents colorful "grain" in silence.
    r = aci_norm * intensity
    g = ent_norm * intensity
    b = bgn_norm * intensity

    alpha = _alpha_from_db(mean_db)

    rgba_col = np.stack([r, g, b, alpha], axis=-1)    # (FREQ_BINS, 4)
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
    S_db = _mag_to_db(S)
    # Combined aggregation: favors peaks (birds) while maintaining noise background
    mean_db = np.mean(S_db, axis=1)
    max_db = np.max(S_db, axis=1)
    
    # 50/50 mix helps highlighting birds without losing the wind/noise floor
    final_db = 0.5 * mean_db + 0.5 * max_db
    
    alpha = _alpha_from_db(final_db)
    return np.stack([final_db, alpha], axis=-1)     # (FREQ_BINS, 2)

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
        
        # Example: 2024-02-24-birdnet-16:19:37.wav or 2024-02-24-birdnet-RTSP_1-16:19:37.wav
        import re
        match = re.search(r'(\d{4}-\d{2}-\d{2}).*?(\d{2}[:_]\d{2}[:_]\d{2})', basename)
        if match:
            date_str = match.group(1)
            time_str = match.group(2).replace('_', ':')
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
        # Standard n_fft for 512 bins is 1024
        # This gives exactly 513 bins from librosa.stft, we take first 512.
        S = np.abs(librosa.stft(y, n_fft=1024, hop_length=HOP_LENGTH))
        S = S[:FREQ_BINS, :]

        if generate_standard == 1:
            # shape: (FREQ_BINS, 2) — channel 0 = dB, channel 1 = alpha
            std_col = calculate_standard_col(S)
            mem_path = _get_daily_memmap_path(date_str, 'standard', conf)

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
            mem_path = _get_daily_memmap_path(date_str, 'indices', conf)

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

def render_daily_image(conf, date_str=None):
    try:
        generate_standard = int(conf.get('GENERATE_LDFCS_STANDARD', '1'))
        generate_indices = int(conf.get('GENERATE_LDFCS_INDICES', '1'))
        
        if generate_standard == 0 and generate_indices == 0:
            return
            
        if date_str is None:
            date_str = datetime.datetime.now().strftime('%Y-%m-%d')
        recording_length = float(conf.get('RECORDING_LENGTH', 15))
        total_cols = _get_total_cols(recording_length)
        
        if generate_standard == 1:
            mem_path = _get_daily_memmap_path(date_str, 'standard', conf)
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

                png_path = _get_daily_png_path(date_str, 'standard', conf)
                plt.imsave(png_path, rgba)
                del arr

        if generate_indices == 1:
            mem_path = _get_daily_memmap_path(date_str, 'indices', conf)
            if os.path.exists(mem_path):
                arr = np.memmap(mem_path, dtype='float32', mode='r', shape=(FREQ_BINS, total_cols, 4))

                # RGBA — clip to valid range and flip vertically
                plot_arr = np.clip(arr, 0.0, 1.0)
                plot_arr = np.flipud(plot_arr)

                png_path = _get_daily_png_path(date_str, 'indices', conf)
                plt.imsave(png_path, plot_arr)
                del arr
                
    except Exception as e:
        log.exception(f"LDFCS Render Error: {e}")

def cleanup_ldfcs_memmaps(conf):
    """Delete all .dat files in the LDFCS directory that are not for today.
    
    This prevents the LongSpectrograms folder from filling up with large
    binary buffers after the daily PNGs have been generated.
    """
    try:
        ldfcs_dir = _get_ldfcs_dir(conf)
        today_str = datetime.datetime.now().strftime('%Y-%m-%d')
        
        count = 0
        for filename in os.listdir(ldfcs_dir):
            if filename.endswith('.dat') and today_str not in filename:
                file_path = os.path.join(ldfcs_dir, filename)
                try:
                    os.remove(file_path)
                    count += 1
                except Exception as ex:
                    log.error(f"Failed to delete {filename}: {ex}")
        
        if count > 0:
            log.info(f"Cleaned up {count} obsolete LDFCS buffer files.")
            
    except Exception as e:
        log.error(f"LDFCS Cleanup Error: {e}")
