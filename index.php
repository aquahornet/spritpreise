<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- .env DATEI LADEN ---
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $pos = strpos($line, '=');
        if ($pos !== false) {
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1), " \t\n\r\0\x0B\"'");
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}
date_default_timezone_set('Europe/Berlin');

$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db_user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: null;
$db_pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: null;
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: null;

if ($db_user === null || $db_name === null) {
    die("Fehler: Datenbank-Konfiguration fehlt. Bitte stelle sicher, dass die .env-Datei auf dem Server existiert (Hinweis: Dein rsync-Befehl schließt .env-Dateien aus).");
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
$conn->query("SET time_zone = '" . date('P') . "'");

// --- MAPPING VON STATION-ID ZU NAME ---
// Diese Namen musst du manuell pflegen. Die IDs findest du in der Tankerkönig-Suche.
$stationNames = [
    'de4ab744-fb65-4998-8666-bbf110e42dcd' => 'ENI (Münchner Str. 121A/1)',
    'e9e805ea-8f6d-4450-9fcb-bacad82e4c7a' => 'ELAN (Münchner Str. 92)',
    '9826b023-5719-48c0-9571-e16641777879' => 'Bavaria Petrol (Grünwalder Weg 42)',
    '1cf17a0c-0091-4ea4-a12d-3ccf944c1947' => 'Bavaria Petrol (Unterhachinger Str. 28a)',
];

// --- DATEN ABFRAGEN ---
// Wir holen uns den jeweils aktuellsten Eintrag für jede Tankstelle
$sql = "SELECT 
            s.station_id,
            (SELECT isOpen FROM preise WHERE station_id = s.station_id ORDER BY timestamp DESC LIMIT 1) as isOpen,
            (SELECT timestamp FROM preise WHERE station_id = s.station_id ORDER BY timestamp DESC LIMIT 1) as timestamp,
            (SELECT diesel FROM preise WHERE station_id = s.station_id AND diesel IS NOT NULL ORDER BY timestamp DESC LIMIT 1) as diesel,
            (SELECT e5 FROM preise WHERE station_id = s.station_id AND e5 IS NOT NULL ORDER BY timestamp DESC LIMIT 1) as e5,
            (SELECT e10 FROM preise WHERE station_id = s.station_id AND e10 IS NOT NULL ORDER BY timestamp DESC LIMIT 1) as e10
        FROM (SELECT DISTINCT station_id FROM preise) s
        ORDER BY diesel ASC"; // Sortiert nach dem günstigsten Diesel

$result = $conn->query($sql);

// --- FAHRZEUGDATEN ABFRAGEN ---
$vehicles_stats = [];
$table_exists = false;
$check_table = $conn->query("SHOW TABLES LIKE 'tankdaten'");
if ($check_table && $check_table->num_rows > 0) {
    $table_exists = true;
}

if ($table_exists) {
    $sql_tank = "SELECT fahrzeug, km_stand, liter, preis FROM tankdaten ORDER BY fahrzeug, km_stand ASC";
    if ($result_tank = $conn->query($sql_tank)) {
        $raw_data = [];
        while ($row = $result_tank->fetch_assoc()) {
            $raw_data[$row['fahrzeug']][] = $row;
        }

        foreach ($raw_data as $vehicle => $entries) {
            $total_liters = 0;
            $total_cost = 0;
            $min_km = null;
            $max_km = null;
            $liters_for_consumption = 0;

            foreach ($entries as $index => $entry) {
                $liter = (float)$entry['liter'];
                $preis = (float)$entry['preis'];
                $km = (float)$entry['km_stand'];

                $total_liters += $liter;
                $total_cost += $liter * $preis;

                if ($min_km === null || $km < $min_km) $min_km = $km;
                if ($max_km === null || $km > $max_km) $max_km = $km;

                // Für den Verbrauch: erste Befüllung überspringen (Startbefüllung)
                if ($index > 0) {
                    $liters_for_consumption += $liter;
                }
            }

            $distance = ($max_km !== null && $min_km !== null) ? ($max_km - $min_km) : 0;
            $avg_consumption = 0;
            if ($distance > 0) {
                $avg_consumption = ($liters_for_consumption / $distance) * 100;
            }

            $vehicles_stats[] = [
                'name' => $vehicle,
                'total_liters' => $total_liters,
                'total_cost' => $total_cost,
                'distance' => $distance,
                'avg_consumption' => $avg_consumption
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spritpreise Ottobrunn</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f9; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #007bff; color: white; }
        tr:hover { background-color: #f1f1f1; }
        .price.stale {
            color: #999; /* Graut den Preis aus */
            font-style: italic;
        }
        .price { font-weight: bold; color: #d9534f; }
        .time { font-size: 0.8em; color: #666; }
        .vehicle-section-title {
            margin-top: 40px;
            color: #333;
            border-bottom: 2px solid #28a745;
            padding-bottom: 8px;
            font-size: 1.5em;
        }
        .vehicle-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-radius: 6px;
            overflow: hidden;
        }
        .vehicle-table th {
            background-color: #28a745;
            color: white;
            font-weight: bold;
            padding: 12px;
        }
        .vehicle-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            color: #444;
        }
        .vehicle-table tr:hover {
            background-color: #f9f9f9;
        }
        .badge-consumption {
            background-color: #e8f5e9;
            color: #1b5e20;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }
        .vehicle-bold {
            font-weight: bold;
            color: #2b2b2b;
        }
        .vehicle-link {
            text-decoration: none;
            color: #28a745;
            font-weight: bold;
        }
        .vehicle-link:hover {
            text-decoration: underline;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 20px;
            border-radius: 6px;
        }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 15px; }
            th, td { padding: 10px 8px; font-size: 0.85em; }
            .vehicle-table th, .vehicle-table td { padding: 10px 8px; font-size: 0.85em; }
        }
    </style>
    <style>
        /* Zusätzliche Stile für den klickbaren Link */
        .station-link {
            text-decoration: none;
            color: #0056b3;
            font-weight: bold;
        }
        .station-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <h1>Aktuelle Spritpreise</h1>
    
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Station</th>
                <th>Diesel</th>
                <th>E5</th>
                <th>E10</th>
                <th>Stand</th>
                <th>Diesel (55l)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                        $stationId = $row['station_id'];
                        // Zeige den Namen an, falls er im Array existiert, ansonsten die ID
                        $stationDisplayName = $stationNames[$stationId] ?? $stationId;
                    ?>
                    <tr>
                        <td>
                            <a href="details.php?id=<?php echo urlencode($stationId); ?>" class="station-link"><?php echo htmlspecialchars($stationDisplayName); ?></a>
                            <br>
                            <small style="color: <?php echo $row['isOpen'] ? 'green' : 'red'; ?>;">
                                <?php echo $row['isOpen'] ? 'Geöffnet' : 'Geschlossen'; ?>
                            </small>
                        </td>
                        <?php $priceClass = $row['isOpen'] ? 'price' : 'price stale'; ?>
                        <td class="<?php echo $priceClass; ?>"><?php echo $row['diesel'] !== null ? number_format($row['diesel'], 3, ',', '.') . ' €' : '-'; ?></td>
                        <td class="<?php echo $priceClass; ?>"><?php echo $row['e5'] !== null ? number_format($row['e5'], 3, ',', '.') . ' €' : '-'; ?></td>
                        <td class="<?php echo $priceClass; ?>"><?php echo $row['e10'] !== null ? number_format($row['e10'], 3, ',', '.') . ' €' : '-'; ?></td>
                        <td class="time"><?php echo date("H:i (d.m.)", strtotime($row['timestamp'])); ?></td>
                        <td class="<?php echo $priceClass; ?>">
                            <?php echo $row['diesel'] !== null ? number_format($row['diesel'] * 55, 2, ',', '.') . ' €' : '-'; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">Noch keine Daten vorhanden. Starte erst das fetch-Skript!</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <h2 class="vehicle-section-title">Fahrzeug-Statistiken</h2>
    <div class="table-responsive">
        <table class="vehicle-table">
            <thead>
            <tr>
                <th>Fahrzeug</th>
                <th>Gesamt getankt</th>
                <th>Gesamtkosten</th>
                <th>Durchschnittsverbrauch</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($vehicles_stats)): ?>
                <?php foreach ($vehicles_stats as $stat): ?>
                    <tr>
                        <td class="vehicle-bold">
                            <a href="vehicle_details.php?name=<?php echo urlencode($stat['name']); ?>" class="vehicle-link"><?php echo htmlspecialchars($stat['name']); ?></a>
                        </td>
                        <td><?php echo number_format($stat['total_liters'], 2, ',', '.') . ' l'; ?></td>
                        <td><?php echo number_format($stat['total_cost'], 2, ',', '.') . ' €'; ?></td>
                        <td>
                            <?php if ($stat['avg_consumption'] > 0): ?>
                                <span class="badge-consumption"><?php echo number_format($stat['avg_consumption'], 2, ',', '.') . ' l/100km'; ?></span>
                            <?php else: ?>
                                <span style="color: #999; font-style: italic;">Nicht genügend Daten</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: #999; font-style: italic; padding: 20px;">
                        Keine Fahrzeugdaten gefunden. Bitte starte das Sync-Skript!
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</body>
</html>

<?php $conn->close(); ?>