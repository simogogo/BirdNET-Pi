import sqlite3
import requests
import os
import sys
import logging
from datetime import datetime, timedelta

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from helpers import DB_PATH, get_settings

logging.basicConfig(level=logging.INFO)
log = logging.getLogger('backfill')

def backfill_weather():
    conf = get_settings()
    lat = conf.get('LATITUDE', None)
    lon = conf.get('LONGITUDE', None)

    if not lat or not lon:
        log.error("Latitude or Longitude not set in settings.")
        return

    try:
        con = sqlite3.connect(DB_PATH)
        cur = con.cursor()
        
        cur.execute("SELECT MIN(Date), MAX(Date) FROM detections")
        dates = cur.fetchone()
        
        if not dates or not dates[0]:
            log.info("No detections found to backfill.")
            return

        start_date = dates[0]
        # Open-Meteo Archive API is usually updated as for 2 days ago
        # If max is today, cap to 2 days ago for archive, forecast handles the rest
        max_date_obj = datetime.strptime(dates[1], '%Y-%m-%d')
        yesterday_obj = datetime.today() - timedelta(days=2)
        
        end_date_obj = min(max_date_obj, yesterday_obj)
        end_date = end_date_obj.strftime('%Y-%m-%d')

        log.info(f"Populating weather from {start_date} to {end_date}...")

        url = f"https://archive-api.open-meteo.com/v1/archive?latitude={lat}&longitude={lon}&start_date={start_date}&end_date={end_date}&hourly=temperature_2m,weather_code,is_day,wind_speed_10m,wind_direction_10m&temperature_unit=fahrenheit&wind_speed_unit=mph&timezone=auto"
        
        resp = requests.get(url, timeout=30)
        resp.raise_for_status()
        data = resp.json()

        if 'hourly' not in data:
            log.error("Invalid response from Open-Meteo Archive API.")
            return

        times = data['hourly']['time']
        temps = data['hourly']['temperature_2m']
        codes = data['hourly']['weather_code']
        is_days = data['hourly']['is_day']
        winds = data['hourly']['wind_speed_10m']
        dirs = data['hourly']['wind_direction_10m']

        # Ensure Table Exists
        cur.execute('''
            CREATE TABLE IF NOT EXISTS weather (
                Date DATE,
                Hour INT,
                Temp FLOAT,
                ConditionCode INT,
                IsDay INT,
                WindSpeed FLOAT,
                WindDirection INT,
                PRIMARY KEY(Date, Hour)
            )
        ''')

        # Insert backwards
        inserted = 0
        for t, temp, code, is_day, wind, direction in zip(times, temps, codes, is_days, winds, dirs):
            if temp is None:
                continue
            dt = datetime.fromisoformat(t)
            date_str = dt.strftime('%Y-%m-%d')
            hour = dt.hour
            
            cur.execute("INSERT OR REPLACE INTO weather (Date, Hour, Temp, ConditionCode, IsDay, WindSpeed, WindDirection) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (date_str, hour, temp, code, is_day, wind, direction))
            inserted += 1

        con.commit()
        con.close()
        log.info(f"Successfully populated {inserted} hours of historical weather data.")

    except Exception as e:
        log.error(f"Error during backfill: {e}")

if __name__ == '__main__':
    backfill_weather()
