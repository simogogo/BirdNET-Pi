import csv
import glob
import json
import os
import re
import subprocess
from collections import OrderedDict
from configparser import ConfigParser
from itertools import chain

_settings = None
_v3_labels_cache = None
_v3_taxon_groups_cache = None
_language_cache = {}

V3_MODELS = (
    'BirdNET+_V3.0_Global_12K_FP16',
    'BirdNET+_V3.0_Global_12K_FP16_pruned',
)
V3_LABELS_CSV = 'BirdNET+_V3.0_Global_12K_Labels.csv'
V3_EXTRA_TAXON_GROUPS = (
    'Amphibia',
    'Insecta',
    'Mammalia',
    'Reptilia',
    'Actinopterygii',
    'Arachnida',
    'Anthropogenic',
    'Geophony',
    'Music',
)

BASE_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DB_PATH = os.path.join(BASE_PATH, 'scripts/birds.db')
MODEL_PATH = os.path.join(BASE_PATH, 'model')
FONT_DIR = os.path.join(BASE_PATH, 'homepage/static')
ANALYZING_NOW = os.path.expanduser('~/BirdSongs/StreamData/analyzing_now.txt')


def get_font():
    conf = get_settings()
    if conf['DATABASE_LANG'] == 'ar':
        ret = {'font.family': 'Noto Sans Arabic', 'path': os.path.join(FONT_DIR, 'NotoSansArabic-Regular.ttf')}
    elif conf['DATABASE_LANG'] in ['ja', 'zh_CN', 'zh_TW']:
        ret = {'font.family': 'Noto Sans JP', 'path': os.path.join(FONT_DIR, 'NotoSansJP-Regular.ttf')}
    elif conf['DATABASE_LANG'] == 'ko':
        ret = {'font.family': 'Noto Sans KR', 'path': os.path.join(FONT_DIR, 'NotoSansKR-Regular.ttf')}
    elif conf['DATABASE_LANG'] == 'th':
        ret = {'font.family': 'Noto Sans Thai', 'path': os.path.join(FONT_DIR, 'NotoSansThai-Regular.ttf')}
    else:
        ret = {'font.family': 'Roboto Flex', 'path': os.path.join(FONT_DIR, 'RobotoFlex-Regular.ttf')}
    return ret


class PHPConfigParser(ConfigParser):
    def get(self, section, option, *, raw=False, vars=None, fallback=None):
        value = super().get(section, option, raw=raw, vars=vars, fallback=fallback)
        if raw:
            return value
        else:
            return value.strip('"')


def _get_analyzing_now_path(conf):
    recs_dir = conf.get('RECS_DIR', os.path.expanduser('~/BirdSongs'))
    return os.path.join(recs_dir, 'StreamData/analyzing_now.txt')


def _ensure_dir_for_file(filepath):
    if filepath:
        os.makedirs(os.path.dirname(filepath), exist_ok=True)


def _load_settings(settings_path='/etc/birdnet/birdnet.conf', force_reload=False):
    global _settings
    if _settings is None or force_reload:
        # Development fallback: if system config missing, check project root
        actual_path = settings_path
        if not os.path.exists(actual_path):
            local_conf = os.path.join(BASE_PATH, 'birdnet.conf')
            if os.path.exists(local_conf):
                actual_path = local_conf
            else:
                # If both are missing, we might need a default config or throw a clearer error
                # For now, it will throw FileNotFoundError as before but we looked locally
                pass

        with open(actual_path) as f:
            parser = PHPConfigParser(interpolation=None)
            # preserve case
            parser.optionxform = lambda option: option
            lines = chain(("[top]",), f)
            parser.read_file(lines)
            _settings = parser['top']
    return _settings


def get_settings(settings_path='/etc/birdnet/birdnet.conf', force_reload=False):
    settings = _load_settings(settings_path, force_reload)
    return settings


def get_open_files_in_dir(dir_name):
    result = subprocess.run(['lsof', '-w', '-Fn', '+D', f'{dir_name}'], check=False, capture_output=True)
    ret = result.stdout.decode('utf-8')
    err = result.stderr.decode('utf-8')
    if err:
        raise RuntimeError(f'{ret}:\n {err}')
    names = [line.lstrip('n') for line in ret.splitlines() if line.startswith('n')]
    return names


def get_wav_files():
    conf = get_settings()
    files = (glob.glob(os.path.join(conf['RECS_DIR'], '*/*/*.wav')) +
             glob.glob(os.path.join(conf['RECS_DIR'], 'StreamData/*.wav')))
    files.sort()
    files = [os.path.join(conf['RECS_DIR'], file) for file in files]
    rec_dir = os.path.join(conf['RECS_DIR'], 'StreamData')
    open_recs = get_open_files_in_dir(rec_dir)
    files = [file for file in files if file not in open_recs]
    return files


def is_v3_model(model=None):
    if model is None:
        model = get_settings().get('MODEL', '')
    return model in V3_MODELS


def load_v3_labels_rows():
    global _v3_labels_cache
    if _v3_labels_cache is None:
        csv_path = os.path.join(MODEL_PATH, V3_LABELS_CSV)
        with open(csv_path, encoding='utf-8', newline='') as f:
            rows = list(csv.DictReader(f))
        rows.sort(key=lambda r: int(r['ix']))
        _v3_labels_cache = rows
    return _v3_labels_cache


def get_v3_taxon_groups():
    global _v3_taxon_groups_cache
    if _v3_taxon_groups_cache is None:
        _v3_taxon_groups_cache = {
            row['scientific_name']: row.get('taxon_group', 'Aves')
            for row in load_v3_labels_rows()
        }
    return _v3_taxon_groups_cache


def get_v3_enabled_extra_classes(conf=None):
    if conf is None:
        conf = get_settings()
    raw = conf.get('V3_EXTRA_CLASSES', '') or ''
    return {item.strip() for item in raw.split(',') if item.strip()}


def _load_l18n_json(language):
    candidates = [language]
    if language == 'zh':
        candidates.append('zh_CN')
    for code in candidates:
        file_name = os.path.join(MODEL_PATH, f'l18n/labels_{code}.json')
        if os.path.isfile(file_name):
            with open(file_name, encoding='utf-8') as f:
                return json.loads(f.read())
    return {}


def get_language(language=None, model=None):
    if language is None:
        language = get_settings()['DATABASE_LANG']
    if model is None:
        model = get_settings().get('MODEL', '')

    v3_flag = is_v3_model(model)
    cache_key = (language, v3_flag)
    if cache_key in _language_cache:
        return _language_cache[cache_key]

    l18n_map = _load_l18n_json(language)

    if v3_flag:
        # Map BirdNET-Pi language codes to V3.0 CSV column names
        if language == 'zh_CN':
            csv_cols = ['common_name_zh-CN', 'common_name_zh']
        elif language == 'zh_TW':
            csv_cols = ['common_name_zh', 'common_name_zh-CN']
        else:
            csv_cols = [f'common_name_{language}']

        ret = dict(l18n_map)
        for row in load_v3_labels_rows():
            sci = row['scientific_name']
            com = ''
            for col in csv_cols:
                val = (row.get(col) or '').strip()
                if val:
                    com = val
                    break
            if not com:
                com = (l18n_map.get(sci) or '').strip()
            if not com:
                com = (row.get('common_name') or row.get('common_name_en') or sci).strip()
            ret[sci] = com
        _language_cache[cache_key] = ret
        return ret

    ret = l18n_map if l18n_map else _load_l18n_json('en')
    _language_cache[cache_key] = ret
    return ret


def save_language(labels, language):
    file_name = os.path.join(MODEL_PATH, f'l18n/labels_{language}.json')
    with open(file_name, 'w', encoding='utf-8') as f:
        f.write(json.dumps(OrderedDict(sorted(labels.items())), indent=2, ensure_ascii=False))
    _language_cache.clear()


def get_model_labels(model=None):
    if model is None:
        model = get_settings()['MODEL']
    if is_v3_model(model):
        return [row['scientific_name'] for row in load_v3_labels_rows()]
    file_name = os.path.join(MODEL_PATH, f'{model}_Labels.txt')
    with open(file_name, encoding='utf-8') as f:
        labels = [line.strip() for line in f.readlines()]
    if labels and labels[0].count('_') == 1:
        labels = [re.sub(r'_.+$', '', label) for label in labels]
    return labels


def set_label_file():
    conf = get_settings(force_reload=True)
    model = conf['MODEL']
    lang = get_language(conf['DATABASE_LANG'], model=model)
    labels = [f'{label}_{lang.get(label, label)}\n' for label in get_model_labels(model)]
    file_name = os.path.join(MODEL_PATH, 'labels.txt')
    tmp_file = os.path.join(MODEL_PATH, 'labels.txt.tmp')
    if os.path.islink(file_name):
        os.remove(file_name)
    with open(tmp_file, 'w', encoding='utf-8') as f:
        f.writelines(labels)
    os.replace(tmp_file, file_name)
