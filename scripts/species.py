import argparse
import datetime
import os

from utils.helpers import (
    get_settings,
    is_v3_model,
    get_v3_taxon_groups,
    get_v3_enabled_extra_classes,
    MODEL_PATH,
)
from utils.models import MDataModel1, MDataModel2, GeoModelV3

if __name__ == '__main__':
    parser = argparse.ArgumentParser(
        description='Get list of species for a given location with BirdNET. Sorted by occurrence frequency.'
    )
    parser.add_argument('--threshold', type=float, default=0.05,
                        help='Occurrence frequency threshold. Defaults to 0.05.')
    args = parser.parse_args()

    conf = get_settings()
    lat = conf.getfloat('LATITUDE')
    lon = conf.getfloat('LONGITUDE')
    week = datetime.datetime.today().isocalendar()[1]
    model_name = conf.get('MODEL', '')
    v3_active = is_v3_model(model_name)

    print(f'Getting species list for {lat}/{lon}, Week {week} (Model: {model_name})...', flush=True)
    labels_path = os.path.join(MODEL_PATH, 'labels.txt')
    with open(labels_path, 'r', encoding='utf-8') as lfile:
        labels = [line.strip() for line in lfile if line.strip()]

    if v3_active:
        taxon_groups = get_v3_taxon_groups()
        enabled_extra = get_v3_enabled_extra_classes(conf)
        labels = [
            lbl for lbl in labels
            if taxon_groups.get(lbl.split('_')[0], 'Aves') == 'Aves'
            or taxon_groups.get(lbl.split('_')[0], 'Aves') in enabled_extra
        ]
        model = GeoModelV3(args.threshold)
    else:
        model = MDataModel1(args.threshold) if conf.getint('DATA_MODEL_VERSION') == 1 else MDataModel2(args.threshold)

    model.set_meta_data(lat, lon, week)
    species_list = model.get_species_list_details(labels)

    for species in species_list:
        print(f'{species[1]} - {species[0]:.4f}')

    print(f'\nTotal species: {len(species_list)}')
    print("""
The above species list describes all the species that the model will attempt to detect.
If you don't see a species you want detected on this list, decrease your threshold.

NOTE: no actual changes to your BirdNET-Pi species list were made by running this command.
To set your desired frequency threshold, do it through the BirdNET-Pi web interface (Tools -> Settings -> Model)
""")
