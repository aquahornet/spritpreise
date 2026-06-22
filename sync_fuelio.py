import os
import io
import zipfile
import csv
from datetime import datetime
import mysql.connector
from google.oauth2.service_account import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload
from dotenv import load_dotenv

# Ermittle den absoluten Pfad des Ordners, in dem dieses Skript liegt
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ENV_PATH = os.path.join(SCRIPT_DIR, '.env')

# Umgebungsvariablen explizit aus diesem Pfad laden
if os.path.exists(ENV_PATH):
    load_dotenv(ENV_PATH)
else:
    print(f"Warnung: .env Datei wurde unter {ENV_PATH} nicht gefunden!")

# Datenbank-Konfiguration exakt an deine .env angepasst
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'user': os.getenv('DB_USER'),
    'password': os.getenv('DB_PASS'),  # Angepasst an deine .env
    'database': os.getenv('DB_NAME')   # Angepasst an deine .env
}

# ----------------------------------------------------------------------
# Konfiguration für dein Google Drive und deine Fahrzeuge
FOLDER_ID = '1ocfUPOgds7OaWG4Cjm7H9hUtbBbO2v-S'

# Trage hier alle Fahrzeuge ein, die importiert werden sollen.
VEHICLES = {
    '2': 'Opel Insignia CT',
    '5': 'Honda CB1000R SC80'  # Falls die Honda eine andere ID hat (4 oder 5), hier anpassen!
}
# ----------------------------------------------------------------------

# 1. Google Drive API Verbindung herstellen
SCOPES = ['https://www.googleapis.com/auth/drive.readonly']
KEY_FILE = os.path.join(SCRIPT_DIR, 'google_key.json')

if not os.path.exists(KEY_FILE):
    print(f"Fehler: {KEY_FILE} wurde im Ordner nicht gefunden!")
    exit(1)

creds = Credentials.from_service_account_file(KEY_FILE, scopes=SCOPES)
service = build('drive', 'v3', credentials=creds)

# Verbindung zur MySQL-Datenbank herstellen
try:
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    print("Erfolgreich mit der MySQL-Datenbank verbunden.\n")
except mysql.connector.Error as err:
    print(f"Datenbankverbindungsfehler: {err}")
    exit(1)

# Schleife über alle konfigurierten Fahrzeuge
for vehicle_id, vehicle_name in VEHICLES.items():
    print(f"=== Starte Synchronisation für: {vehicle_name} (ID: {vehicle_id}) ===")
    
    # 2. Gegezielt nach der ZIP-Datei des aktuellen Fahrzeugs suchen
    query = f"'{FOLDER_ID}' in parents and name contains 'vehicle-{vehicle_id}-sync' and mimeType = 'application/zip'"
    
    try:
        results = service.files().list(
            q=query,
            orderBy="modifiedTime desc", 
            pageSize=1, 
            fields="files(id, name)"
        ).execute()
    except Exception as e:
        print(f"Fehler beim Aufruf der Google API für {vehicle_name}: {e}")
        continue

    items = results.get('files', [])

    if not items:
        print(f"Keine ZIP-Datei für 'vehicle-{vehicle_id}-sync' ({vehicle_name}) im Ordner gefunden! Überspringe...\n")
        continue

    file_id = items[0]['id']
    file_name = items[0]['name']
    print(f"Gefunden: {file_name} (ID: {file_id})")

    # 3. ZIP-Datei in den Arbeitsspeicher herunterladen
    print("Lade Datei von Google Drive herunter...")
    request = service.files().get_media(fileId=file_id)
    fh = io.BytesIO()
    downloader = MediaIoBaseDownload(fh, request)
    done = False
    while done is False:
        status, done = downloader.next_chunk()

    # 4. ZIP-Datei im Speicher öffnen und nach der CSV suchen
    fh.seek(0)
    try:
        with zipfile.ZipFile(fh) as z:
            csv_files = [f for f in z.namelist() if f.endswith('.csv')]
            if not csv_files:
                print(f"Fehler: Keine CSV-Datei in der ZIP für {vehicle_name} gefunden.\n")
                continue
            
            with z.open(csv_files[0]) as csv_file:
                file_content = csv_file.read().decode('utf-8').splitlines()
    except Exception as e:
        print(f"Fehler beim Entpacken der ZIP für {vehicle_name}: {e}\n")
        continue

    # 5. CSV-Inhalt nach ## Log filtern
    reader = csv.reader(file_content)
    in_log_section = False
    log_header = None
    log_rows = []

    for row in reader:
        if not row:
            continue
        if row[0].startswith('## Log'):
            in_log_section = True
            continue
        if in_log_section and row[0].startswith('##'):
            break
        if in_log_section:
            if log_header is None:
                log_header = row
            else:
                log_rows.append(row)

    print(f"Verarbeitung von {len(log_rows)} CSV-Einträgen gestartet...")

    inserted_count = 0
    skipped_count = 0

    # 6. Daten in MySQL eintragen (rückwärts, älteste zuerst)
    for row in reversed(log_rows):
        guid = row[19] # Eindeutige ID aus Fuelio

        # Prüfen, ob dieser Eintrag anhand der GUID schon existiert
        cursor.execute("SELECT 1 FROM tankdaten WHERE fuelio_guid = %s", (guid,))
        if cursor.fetchone():
            skipped_count += 1
            continue

        # Daten parsen & konvertieren
        raw_date = row[0] # Format: '2026-06-19 17:54'
        formatted_date = datetime.strptime(raw_date, '%Y-%m-%d %H:%M').strftime('%Y-%m-%d %H:%M:%S')

        km_stand = float(row[1]) 
        liter = float(row[2])    
        tankstelle = row[8] if row[8] else None
        preis_pro_liter = float(row[13])

        # SQL INSERT Befehl mit der Fahrzeug-Spalte
        sql = """
            INSERT INTO tankdaten (fahrzeug, datum, km_stand, liter, preis, tankstelle, fuelio_guid) 
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """
        values = (vehicle_name, formatted_date, km_stand, liter, preis_pro_liter, tankstelle, guid)
        
        try:
            cursor.execute(sql, values)
            inserted_count += 1
        except mysql.connector.Error as err:
            print(f"Fehler bei Eintrag für {vehicle_name} am {raw_date}: {err}")

    conn.commit()
    print(f"Ergebnis für {vehicle_name}: {inserted_count} importiert, {skipped_count} übersprungen.\n")

# Verbindung am Ende schließen
if 'conn' in locals() and conn.is_connected():
    cursor.close()
    conn.close()
    print("Alle Fahrzeuge verarbeitet. Datenbankverbindung sauber geschlossen.")