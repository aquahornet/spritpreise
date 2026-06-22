<?php
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
    die("Fehler: Datenbank-Konfiguration fehlt. Bitte stelle sicher, dass die .env-Datei auf dem Server existiert.");
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
$conn->query("SET time_zone = '" . date('P') . "'");

// --- VALIDIERUNG INPUT ---
if (!isset($_GET['name']) || empty($_GET['name'])) {
    die("Fehler: Kein Fahrzeugname angegeben.");
}
$vehicle_name = $_GET['name'];

// --- DATEN ABFRAGEN ---
$stmt = $conn->prepare("SELECT datum, km_stand, liter, preis, tankstelle FROM tankdaten WHERE fahrzeug = ? ORDER BY km_stand ASC");
$stmt->bind_param("s", $vehicle_name);
$stmt->execute();
$result = $stmt->get_result();

$entries = [];
$prev_km = null;
$total_liters_all = 0;
$total_cost_all = 0;
$liters_for_consumption = 0;

while ($row = $result->fetch_assoc()) {
    $km = (float)$row['km_stand'];
    $liter = (float)$row['liter'];
    $preis = (float)$row['preis'];
    
    $cost = $liter * $preis;
    $distance = 0;
    $consumption = 0;
    
    $total_liters_all += $liter;
    $total_cost_all += $cost;
    
    if ($prev_km !== null) {
        $distance = $km - $prev_km;
        if ($distance > 0) {
            $consumption = ($liter / $distance) * 100;
        }
        $liters_for_consumption += $liter;
    }
    
    $entries[] = [
        'datum' => $row['datum'],
        'km_stand' => $km,
        'liter' => $liter,
        'preis' => $preis,
        'cost' => $cost,
        'distance' => $distance,
        'consumption' => $consumption,
        'tankstelle' => $row['tankstelle']
    ];
    
    $prev_km = $km;
}
$stmt->close();

// Statistiken berechnen
$total_entries = count($entries);
$total_distance_tracked = 0;
$overall_avg_consumption = 0;

if ($total_entries > 1) {
    $first_km = $entries[0]['km_stand'];
    $last_km = $entries[$total_entries - 1]['km_stand'];
    $total_distance_tracked = $last_km - $first_km;
    
    if ($total_distance_tracked > 0) {
        $overall_avg_consumption = ($liters_for_consumption / $total_distance_tracked) * 100;
    }
}

// Umgekehrt sortieren für die Anzeige (neueste oben)
$display_entries = array_reverse($entries);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tankverlauf für <?php echo htmlspecialchars($vehicle_name); ?></title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f9; padding: 20px; }
        .container { max-width: 950px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #28a745;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link:hover { text-decoration: underline; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 0.85em;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-value {
            margin: 8px 0 0 0;
            font-size: 1.6em;
            font-weight: bold;
            color: #2b2b2b;
        }
        .stat-card .stat-value.highlight {
            color: #1b5e20;
        }
        
        /* Table Styling */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #28a745; color: white; }
        tr:hover { background-color: #f8f9fa; }
        
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
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }
            .stats-grid .stat-card:last-child {
                grid-column: span 2;
            }
            .stat-card {
                padding: 10px;
            }
            .stat-card .stat-value {
                font-size: 1.3em;
            }
            th, td {
                padding: 10px 8px;
                font-size: 0.8em;
            }
        }
        
        /* Sortierbare Spaltenköpfe */
        th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 25px; /* Platz für das Icon */
        }
        th.sortable:hover {
            background-color: #218838;
        }
        th.sortable::after {
            content: ' ↕';
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.5);
            position: absolute;
            right: 8px;
        }
        th.sortable.asc::after {
            content: ' ▲';
            color: white;
        }
        th.sortable.desc::after {
            content: ' ▼';
            color: white;
        }
        
        .badge-consumption {
            background-color: #e8f5e9;
            color: #1b5e20;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }
        .text-muted { color: #888; font-style: italic; }
        .text-bold { font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" class="back-link">&larr; Zurück zur Übersicht</a>
    
    <h1>Tankverlauf: <?php echo htmlspecialchars($vehicle_name); ?></h1>
    
    <!-- KPI Dashboard -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Betankungen</h3>
            <p class="stat-value"><?php echo $total_entries; ?></p>
        </div>
        <div class="stat-card">
            <h3>Strecke</h3>
            <p class="stat-value"><?php echo number_format($total_distance_tracked, 0, ',', '.') . ' km'; ?></p>
        </div>
        <div class="stat-card">
            <h3>Gesamtmenge</h3>
            <p class="stat-value"><?php echo number_format($total_liters_all, 1, ',', '.') . ' l'; ?></p>
        </div>
        <div class="stat-card">
            <h3>Gesamtkosten</h3>
            <p class="stat-value"><?php echo number_format($total_cost_all, 2, ',', '.') . ' €'; ?></p>
        </div>
        <div class="stat-card">
            <h3>Ø Verbrauch</h3>
            <p class="stat-value highlight"><?php echo $overall_avg_consumption > 0 ? number_format($overall_avg_consumption, 2, ',', '.') . ' l' : '-'; ?></p>
        </div>
    </div>
    
    <!-- Details Table -->
    <div class="table-responsive">
        <table id="detailsTable">
            <thead>
                <tr>
                <th class="sortable" data-sort-type="date">Datum</th>
                <th class="sortable" data-sort-type="number">Kilometerstand</th>
                <th class="sortable" data-sort-type="number">Menge</th>
                <th class="sortable" data-sort-type="number">Preis/l</th>
                <th class="sortable" data-sort-type="number">Kosten</th>
                <th class="sortable" data-sort-type="number">Distanz</th>
                <th class="sortable" data-sort-type="number">Verbrauch</th>
                <th class="sortable" data-sort-type="string">Tankstelle</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($display_entries)): ?>
                <?php foreach ($display_entries as $entry): ?>
                    <tr>
                        <td><?php echo date("d.m.Y H:i", strtotime($entry['datum'])); ?></td>
                        <td class="text-bold"><?php echo number_format($entry['km_stand'], 0, ',', '.') . ' km'; ?></td>
                        <td><?php echo number_format($entry['liter'], 2, ',', '.') . ' l'; ?></td>
                        <td><?php echo number_format($entry['preis'], 3, ',', '.') . ' €'; ?></td>
                        <td class="text-bold"><?php echo number_format($entry['cost'], 2, ',', '.') . ' €'; ?></td>
                        <td>
                            <?php echo $entry['distance'] > 0 ? '+' . number_format($entry['distance'], 0, ',', '.') . ' km' : '<span class="text-muted">Startwert</span>'; ?>
                        </td>
                        <td>
                            <?php if ($entry['consumption'] > 0): ?>
                                <span class="badge-consumption"><?php echo number_format($entry['consumption'], 2, ',', '.') . ' l/100km'; ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($entry['tankstelle'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 20px;" class="text-muted">
                        Keine Einträge für dieses Fahrzeug vorhanden.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function getCellValue(row, index, type) {
    const cell = row.cells[index];
    let text = cell.textContent || cell.innerText;
    text = text.trim();
    
    if (type === 'number') {
        let clean = text.replace(/[+lkm€/]/g, '').trim();
        clean = clean.replace(/\./g, '');
        clean = clean.replace(/,/g, '.');
        const num = parseFloat(clean);
        return isNaN(num) ? -1 : num;
    }
    
    if (type === 'date') {
        const parts = text.split(/[\s.:]+/);
        if (parts.length >= 5) {
            const day = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10) - 1;
            const year = parseInt(parts[2], 10);
            const hour = parseInt(parts[3], 10);
            const minute = parseInt(parts[4], 10);
            return new Date(year, month, day, hour, minute).getTime();
        }
        return 0;
    }
    
    return text.toLowerCase();
}

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('detailsTable');
    if (!table) return;
    
    const headers = table.querySelectorAll('th.sortable');
    const tbody = table.querySelector('tbody');
    
    headers.forEach((header, index) => {
        header.addEventListener('click', () => {
            const type = header.getAttribute('data-sort-type');
            const isAsc = header.classList.contains('asc');
            
            headers.forEach(h => h.classList.remove('asc', 'desc'));
            
            if (isAsc) {
                header.classList.add('desc');
            } else {
                header.classList.add('asc');
            }
            
            const rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length <= 1 && rows[0] && rows[0].querySelector('td[colspan]')) {
                return;
            }
            
            rows.sort((rowA, rowB) => {
                const valA = getCellValue(rowA, index, type);
                const valB = getCellValue(rowB, index, type);
                
                if (valA === valB) return 0;
                
                if (isAsc) {
                    return valA < valB ? 1 : -1;
                } else {
                    return valA > valB ? 1 : -1;
                }
            });
            
            rows.forEach(row => tbody.appendChild(row));
        });
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>
