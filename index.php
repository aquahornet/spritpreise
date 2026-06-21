<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- KONFIGURATION LADEN ---
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}

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
    
    <table>
        <thead>
            <tr>
                <th>Station</th>
                <th>Diesel</th>
                <th>E5</th>
                <th>E10</th>
                <th>Stand</th>
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
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5">Noch keine Daten vorhanden. Starte erst das fetch-Skript!</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>

<?php $conn->close(); ?>