import logging
import os
import os.path
import re
import signal
import sys
import threading
from queue import Queue
from subprocess import CalledProcessError

import inotify.adapters
from inotify.constants import IN_CLOSE_WRITE

from utils.analysis import load_global_model, run_analysis
from utils.helpers import get_settings, get_wav_files, _get_analyzing_now_path, _ensure_dir_for_file
from utils.classes import ParseFileName
from utils.reporting import extract_detection, summary, write_to_file, write_to_db, apprise, bird_weather, heartbeat, \
    update_json_file

try:
    import utils.spectrogram_generator as spectrogram_generator
except ImportError as e:
    spectrogram_generator = None
    logging.error(f"Failed to import spectrogram_generator: {e}")

shutdown = False

log = logging.getLogger(__name__)


def sig_handler(sig_num, curr_stack_frame):
    global shutdown
    log.info('Caught shutdown signal %d', sig_num)
    shutdown = True


def main():
    load_global_model()
    conf = get_settings()
    stream_data_dir = os.path.join(conf['RECS_DIR'], 'StreamData')
    os.makedirs(stream_data_dir, exist_ok=True)
    i = inotify.adapters.Inotify()
    i.add_watch(stream_data_dir, mask=IN_CLOSE_WRITE)

    backlog = get_wav_files()

    report_queue = Queue()
    thread = threading.Thread(target=handle_reporting_queue, args=(report_queue, ))
    thread.start()

    log.info('backlog is %d', len(backlog))
    for file_name in backlog:
        process_file(file_name, report_queue)
        if shutdown:
            break
    log.info('backlog done')

    empty_count = 0
    for event in i.event_gen():
        if shutdown:
            break

        if event is None:
            if empty_count > (conf.getint('RECORDING_LENGTH') * 2 + 30):
                log.error('no more notifications: restarting...')
                break
            empty_count += 1
            continue

        (_, type_names, path, file_name) = event
        if re.search('.wav$', file_name) is None:
            continue
        log.debug("PATH=[%s] FILENAME=[%s] EVENT_TYPES=%s", path, file_name, type_names)

        file_path = os.path.join(path, file_name)
        if file_path in backlog:
            # if we're very lucky, the first event could be for the file in the backlog that finished
            # while running get_wav_files()
            backlog = []
            continue

        process_file(file_path, report_queue)
        empty_count = 0

    # we're all done
    report_queue.put(None)
    thread.join()
    report_queue.join()


def process_file(file_name, report_queue):
    try:
        if os.path.getsize(file_name) == 0:
            os.remove(file_name)
            return
        log.info('Analyzing %s', file_name)
        conf = get_settings()
        analyzing_now = _get_analyzing_now_path(conf)
        _ensure_dir_for_file(analyzing_now)
        with open(analyzing_now, 'w') as analyzing:
            analyzing.write(file_name)
        file = ParseFileName(file_name)
        detections = run_analysis(file)
        # we join() to make sure te reporting queue does not get behind
        if not report_queue.empty():
            log.warning('reporting queue not yet empty')
        report_queue.join()
        report_queue.put((file, detections))
    except BaseException as e:
        stderr = e.stderr.decode('utf-8') if isinstance(e, CalledProcessError) else ""
        log.exception(f'Unexpected error: {stderr}', exc_info=e)


def handle_reporting_queue(queue):
    process_count = 0
    last_day = None
    while True:
        msg = queue.get()
        # check for signal that we are done
        if msg is None:
            break

        file, detections = msg
        try:
            update_json_file(file, detections)
            for detection in detections:
                detection.file_name_extr = extract_detection(file, detection)
                log.info('%s;%s', summary(file, detection), os.path.basename(detection.file_name_extr))
                write_to_file(file, detection)
                write_to_db(file, detection)
            apprise(file, detections)
            bird_weather(file, detections)
            heartbeat()
            
            conf = get_settings()
            current_day = file.file_date.strftime('%Y-%m-%d')
            
            # --- LDFCS Generation ---
            if spectrogram_generator is not None:
                try:
                    # Finalize previous day if day changed
                    if last_day is not None and current_day != last_day:
                        log.info(f"LDFCS: Day change detected from {last_day} to {current_day}. Finalizing spectrogram for {last_day}.")
                        spectrogram_generator.render_daily_image(conf, last_day)
                        spectrogram_generator.cleanup_ldfcs_memmaps(conf)
                    
                    last_day = current_day
                    
                    spectrogram_generator.update_daily_spectrogram(file.file_name, conf)
                    process_count += 1
                    
                    # Call render roughly every 10 minutes 
                    # (Assuming recording_length is between 15s and 60s, checking every 40 processations is safe)
                    if process_count % 40 == 0:
                        spectrogram_generator.render_daily_image(conf, current_day)
                    
                    if process_count % 100 == 0:
                        spectrogram_generator.cleanup_ldfcs_memmaps(conf)
                except Exception as e:
                    log.error(f"LDFCS Error: {e}")
            # --- End LDFCS ---
            
            os.remove(file.file_name)
        except BaseException as e:
            stderr = e.stderr.decode('utf-8') if isinstance(e, CalledProcessError) else ""
            log.exception(f'Unexpected error: {stderr}', exc_info=e)

        queue.task_done()

    # mark the 'None' signal as processed
    queue.task_done()
    log.info('handle_reporting_queue done')


def setup_logging():
    logger = logging.getLogger()
    formatter = logging.Formatter("[%(name)s][%(levelname)s] %(message)s")
    handler = logging.StreamHandler(stream=sys.stdout)
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    global log
    log = logging.getLogger('birdnet_analysis')


if __name__ == '__main__':
    signal.signal(signal.SIGINT, sig_handler)
    signal.signal(signal.SIGTERM, sig_handler)

    setup_logging()

    main()
