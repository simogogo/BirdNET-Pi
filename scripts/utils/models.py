import logging
import math
import operator
import os
import subprocess

import numpy as np

from .helpers import get_settings, get_model_labels, is_v3_model, BASE_PATH, MODEL_PATH

os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ['CUDA_VISIBLE_DEVICES'] = ''
np.set_printoptions(legacy="1.21")

try:
    import tflite_runtime.interpreter as tflite
except ImportError:
    try:
        import ai_edge_litert.interpreter as tflite
    except ImportError:
        from tensorflow import lite as tflite

try:
    import onnxruntime as ort
except ImportError:
    ort = None

log = logging.getLogger(__name__)


def get_model(model=None):
    conf = get_settings()
    if model is None:
        model = conf['MODEL']

    if model == 'BirdNET_6K_GLOBAL_MODEL':
        return BirdNetV1(conf.getfloat('SENSITIVITY'))
    elif model == 'BirdNET_GLOBAL_6K_V2.4_Model_FP16':
        return BirdNetV2_4(conf.getfloat('SENSITIVITY'))
    elif model == 'BirdNET+_V3.0_Global_12K_FP16':
        return BirdNetV3_0_FP16(conf.getfloat('SENSITIVITY'))
    elif model == 'BirdNET+_V3.0_Global_12K_FP16_pruned':
        return BirdNetV3_0_FP16_Pruned(conf.getfloat('SENSITIVITY'))
    elif model == 'Perch_v2':
        return Perch()
    elif model == 'BirdNET-Go_classifier_20250916':
        return BirdNETGo20250916(conf.getfloat('SENSITIVITY'))


def get_meta_model(model=None, version=None):
    conf = get_settings()
    if model is None:
        model = conf['MODEL']
    if version is None:
        version = conf.getint('DATA_MODEL_VERSION')

    if is_v3_model(model):
        return GeoModelV3(conf.getfloat('SF_THRESH'))

    if model not in ['BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET-Go_classifier_20250916']:
        return None

    if version == 1:
        return MDataModel1(conf.getfloat('SF_THRESH'))
    elif version == 2:
        return MDataModel2(conf.getfloat('SF_THRESH'))


class Basemodel:
    chunk_duration = None
    sample_rate = None
    model_name = None
    _input_layer = 0
    _output_layer = 0

    def __init__(self):
        model_path = os.path.join(MODEL_PATH, f'{self.model_name}.tflite')
        self.interpreter = tflite.Interpreter(model_path)
        self.interpreter.allocate_tensors()
        input_details = self.interpreter.get_input_details()
        output_details = self.interpreter.get_output_details()

        self._input_layer_idx = input_details[self._input_layer]['index']
        self._output_layer_idx = output_details[self._output_layer]['index']

        self.labels = get_model_labels(self.model_name)

    def label(self, logits):
        p_labels = dict(zip(self.labels, logits))
        return sorted(p_labels.items(), key=operator.itemgetter(1), reverse=True)

    def predict(self, chunk):
        raise NotImplementedError

    def set_meta_data(self, lat, lon, week):
        pass

    def get_species_list(self):
        return []

    def get_modeled_species(self):
        return set()


class BirdNet(Basemodel):
    chunk_duration = 3
    sample_rate = 48000

    def __init__(self, sens):
        super().__init__()

        self._mdata_model = self._set_meta_model()

        self._sensitivity = max(0.5, min(1.0 - (sens - 1.0), 1.5))

    def scale(self, logits):
        return 1 / (1.0 + np.exp(-self._sensitivity * logits))

    def _set_meta_model(self):
        return None


class BirdNetV1(BirdNet):
    model_name = 'BirdNET_6K_GLOBAL_MODEL'

    def __init__(self, sens):
        super().__init__(sens)
        self._mdata = None
        self._mdata_params = None

    def _set_meta_model(self):
        input_details = self.interpreter.get_input_details()
        return input_details[1]['index']

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])
        self.interpreter.set_tensor(self._mdata_model, np.array(self._mdata, dtype='float32'))

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        return self.label(self.scale(logits))

    def _convert_metadata(self, m):
        # Convert week to cosine
        if 1 <= m[2] <= 48:
            m[2] = math.cos(math.radians(m[2] * 7.5)) + 1
        else:
            m[2] = -1

        # Add binary mask
        mask = np.ones((3,))
        if m[0] == -1 or m[1] == -1:
            mask = np.zeros((3,))
        if m[2] == -1:
            mask[2] = 0.0

        return np.concatenate([m, mask])

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != [lat, lon, week]:
            self._mdata_params = [lat, lon, week]
            # Convert and prepare metadata
            mdata = self._convert_metadata(np.array([lat, lon, week]))
            self._mdata = np.expand_dims(mdata, 0)


class BirdNetV2_4(BirdNet):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16'

    def _set_meta_model(self):
        return get_meta_model()

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        return self.label(self.scale(logits))

    def set_meta_data(self, lat, lon, week):
        self._mdata_model.set_meta_data(lat, lon, week)

    def get_species_list(self):
        return self._mdata_model.get_species_list(self.labels)


class Perch(Basemodel):
    chunk_duration = 5
    sample_rate = 32000
    model_name = 'Perch_v2'
    _output_layer = 3

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        exp_x = np.exp(logits - np.max(logits))  # Stabilizing to prevent overflow
        return self.label(exp_x / np.sum(exp_x))


class BirdNETGo20250916(BirdNetV2_4):
    model_name = 'BirdNET-Go_classifier_20250916'


def _ensure_v3_assets(*args):
    """Run scripts/download_v3_models.sh on-demand if a required V3 asset is missing."""
    downloader = os.path.join(BASE_PATH, 'scripts', 'download_v3_models.sh')
    if not os.path.isfile(downloader):
        log.warning('V3 model downloader not found at %s', downloader)
        return False
    log.info('Missing BirdNET+ V3.0 asset(s); downloading via %s %s', downloader, ' '.join(args))
    try:
        res = subprocess.run(['bash', downloader, *args], check=False, capture_output=True, text=True)
        if res.stdout:
            for line in res.stdout.strip().splitlines():
                log.info('[download_v3_models] %s', line)
        if res.returncode != 0:
            log.error('Failed to download V3 assets (rc=%d): %s', res.returncode, res.stderr.strip())
            return False
        return True
    except Exception as exc:
        log.error('Error running V3 model downloader: %s', exc)
        return False


class BirdNetV3(BirdNet):
    chunk_duration = 3
    sample_rate = 32000

    def __init__(self, sens):
        conf = get_settings()
        try:
            configured_chunk = int(float(conf.get('V3_CHUNK_DURATION', '3') or '3'))
        except ValueError:
            configured_chunk = 3
        self.chunk_duration = max(3, min(5, configured_chunk))

        max_cpus = os.cpu_count() or 1
        try:
            configured_threads = int(conf.get('V3_THREADS', '2') or '2')
        except ValueError:
            configured_threads = 2
        num_threads = max(1, min(max_cpus, configured_threads))

        model_path = os.path.join(MODEL_PATH, f'{self.model_name}.tflite')
        labels_csv = os.path.join(MODEL_PATH, 'BirdNET+_V3.0_Global_12K_Labels.csv')
        if not os.path.isfile(model_path) or not os.path.isfile(labels_csv):
            _ensure_v3_assets('--model', self.model_name)
        log.info('Loading %s (chunk=%ss, threads=%d)', self.model_name, self.chunk_duration, num_threads)
        self.interpreter = tflite.Interpreter(model_path=model_path, num_threads=num_threads)

        input_details = self.interpreter.get_input_details()
        self._input_layer_idx = input_details[self._input_layer]['index']
        expected_samples = int(self.sample_rate * self.chunk_duration)
        self.interpreter.resize_tensor_input(self._input_layer_idx, [1, expected_samples])
        self.interpreter.allocate_tensors()

        self.labels = get_model_labels(self.model_name)
        output_details = self.interpreter.get_output_details()
        pred_outputs = [o for o in output_details if int(o['shape'][-1]) == len(self.labels)]
        if pred_outputs:
            self._output_layer_idx = pred_outputs[0]['index']
        else:
            self._output_layer_idx = output_details[self._output_layer]['index']

        self._mdata_model = self._set_meta_model()
        self._sensitivity = max(0.5, min(1.0 - (sens - 1.0), 1.5))

    def _set_meta_model(self):
        return get_meta_model(self.model_name)

    def scale(self, probs):
        # BirdNET+ V3.0 already applies sigmoid inside the TFLite graph.
        # Avoid double-sigmoid; only adjust slope in logit space when SENSITIVITY != 1.0.
        if abs(self._sensitivity - 1.0) < 1e-6:
            return probs
        p = np.clip(probs, 1e-7, 1.0 - 1e-7)
        logits = np.log(p / (1.0 - p))
        return 1.0 / (1.0 + np.exp(-self._sensitivity * logits))

    def _top_k_labels(self, probs):
        # Fast top-K extraction via argpartition (~17x faster than sorting all 11,725 items in Python).
        # Because scale() is strictly monotonic, selecting top-K before scaling yields exact results.
        conf = get_settings()
        try:
            priv_thresh = conf.getfloat('PRIVACY_THRESHOLD')
        except (ValueError, KeyError):
            priv_thresh = 0.0
        n = len(probs)
        human_cutoff = max(10, int(n * priv_thresh / 100.0))
        k = min(n, max(20, human_cutoff))
        if k >= n:
            top_idx = np.argsort(probs)[::-1]
        else:
            part = np.argpartition(probs, -k)[-k:]
            top_idx = part[np.argsort(probs[part])[::-1]]
        top_probs = self.scale(probs[top_idx])
        return [(self.labels[i], float(p)) for i, p in zip(top_idx, top_probs)]

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])
        self.interpreter.invoke()
        probs = self.interpreter.get_tensor(self._output_layer_idx)[0]
        return self._top_k_labels(probs)

    def set_meta_data(self, lat, lon, week):
        if self._mdata_model is not None:
            self._mdata_model.set_meta_data(lat, lon, week)

    def get_species_list(self):
        if self._mdata_model is None:
            return []
        return self._mdata_model.get_species_list(self.labels)

    def get_modeled_species(self):
        if self._mdata_model is None:
            return set()
        return self._mdata_model.modeled_species


class BirdNetV3_0_FP16(BirdNetV3):
    model_name = 'BirdNET+_V3.0_Global_12K_FP16'


class BirdNetV3_0_FP16_Pruned(BirdNetV3):
    model_name = 'BirdNET+_V3.0_Global_12K_FP16_pruned'


class GeoModelV3:
    model_name = 'BirdNET+_Geomodel_V3.0.4_Global_14K_FP16'
    labels_file = 'BirdNET+_Geomodel_V3.0.4_Global_14K_Labels.txt'

    def __init__(self, sf_thresh):
        self._sf_thresh = float(sf_thresh)
        self._mdata_params = None
        self._mdata = None
        self._probs_by_sci = None
        self.modeled_species = set()
        self.session = None
        self._input_name = None
        self._geo_sci_names = []

        onnx_path = os.path.join(MODEL_PATH, f'{self.model_name}.onnx')
        labels_path = os.path.join(MODEL_PATH, self.labels_file)

        if ort is None:
            log.warning('onnxruntime is not installed; Geomodel V3.0 species range filtering disabled.')
            return
        if not os.path.isfile(onnx_path) or not os.path.isfile(labels_path):
            _ensure_v3_assets('--geomodel-only')
        if not os.path.isfile(onnx_path) or not os.path.isfile(labels_path):
            log.warning('Geomodel V3.0 files not found in %s; species range filtering disabled.', MODEL_PATH)
            return

        with open(labels_path, encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                parts = line.split('\t')
                sci = parts[1] if len(parts) >= 2 else parts[0].split('_')[0]
                self._geo_sci_names.append(sci)
        self.modeled_species = set(self._geo_sci_names)

        sess_opts = ort.SessionOptions()
        sess_opts.intra_op_num_threads = min(2, os.cpu_count() or 1)
        self.session = ort.InferenceSession(onnx_path, sess_options=sess_opts, providers=['CPUExecutionProvider'])
        self._input_name = self.session.get_inputs()[0].name

    @staticmethod
    def _iso_week_to_48(week):
        if week is None or week <= 0:
            return 0
        return max(1, min(48, int(round(week * 48.0 / 52.0))))

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != (lat, lon, week):
            self._mdata = None
            self._probs_by_sci = None
        self._mdata_params = (lat, lon, week)

    def _run_inference(self):
        if self._probs_by_sci is not None:
            return self._probs_by_sci
        if self.session is None or self._mdata_params is None:
            self._probs_by_sci = {}
            return self._probs_by_sci

        lat, lon, week = self._mdata_params
        if lat == -1 or lon == -1:
            self._probs_by_sci = {}
            return self._probs_by_sci

        week48 = self._iso_week_to_48(week)
        if week48 == 0:
            weeks = np.arange(1, 49, dtype=np.float32)
            batch = np.stack([
                np.full((48,), lat, dtype=np.float32),
                np.full((48,), lon, dtype=np.float32),
                weeks,
            ], axis=1)
            out = self.session.run(None, {self._input_name: batch})[0]
            probs = np.max(out, axis=0)
        else:
            sample = np.array([[lat, lon, week48]], dtype=np.float32)
            probs = self.session.run(None, {self._input_name: sample})[0][0]

        self._probs_by_sci = dict(zip(self._geo_sci_names, probs.tolist()))
        return self._probs_by_sci

    def get_species_list_details(self, labels):
        if self._mdata is None:
            probs_by_sci = self._run_inference()
            if not probs_by_sci:
                self._mdata = []
                return self._mdata

            results = []
            for label in labels:
                sci = label.split('_')[0]
                prob = probs_by_sci.get(sci, 0.0)
                if prob >= self._sf_thresh:
                    results.append((prob, label))
            results.sort(key=lambda x: x[0], reverse=True)
            self._mdata = results
        return self._mdata

    def get_species_list(self, labels):
        l_filter = self.get_species_list_details(labels)
        return [s[1].split('_')[0] for s in l_filter]


class MDataModel:
    model_name = None

    def __init__(self, sf_thresh):
        model_path = os.path.join(MODEL_PATH, f'{self.model_name}.tflite')
        self.interpreter = tflite.Interpreter(model_path)
        self.interpreter.allocate_tensors()
        input_details = self.interpreter.get_input_details()
        output_details = self.interpreter.get_output_details()

        self._input_layer_idx = input_details[0]['index']
        self._output_layer_idx = output_details[0]['index']
        self._sf_thresh = sf_thresh

        self._mdata_params = None
        self._mdata = None

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != (lat, lon, week):
            self._mdata = None
        self._mdata_params = (lat, lon, week)

    def get_species_list_details(self, labels):
        if self._mdata is None:
            lat, lon, week = self._mdata_params
            sample = np.expand_dims(np.array([lat, lon, week], dtype='float32'), 0)

            # Run inference
            self.interpreter.set_tensor(self._input_layer_idx, sample)
            self.interpreter.invoke()

            l_filter = self.interpreter.get_tensor(self._output_layer_idx)[0]

            # Apply threshold
            l_filter = np.where(l_filter >= float(self._sf_thresh), l_filter, 0)

            # Zip with labels
            l_filter = list(zip(l_filter, labels))

            # Sort by filter value
            l_filter = sorted(l_filter, key=lambda x: x[0], reverse=True)

            self._mdata = [s for s in l_filter if s[0] >= self._sf_thresh]

        return self._mdata

    def get_species_list(self, labels):
        l_filter = self.get_species_list_details(labels)
        return [s[1].split('_')[0] for s in l_filter]


class MDataModel1(MDataModel):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_MData_Model_FP16'


class MDataModel2(MDataModel):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_MData_Model_V2_FP16'
