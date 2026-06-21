<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- VALIDATE INPUT ---
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Fehler: Keine Tankstellen-ID angegeben.");
}
$stationId = $_GET['id'];

// --- KONFIGURATION LADEN ---
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
$conn->query("SET time_zone = '" . date('P') . "'");

// --- HILFSFUNKTIONEN ---
function getExtremes($conn, $stationId, $fuel, $sort) {
    $allowedFuels = ['diesel', 'e5', 'e10'];
    $allowedSorts = ['ASC', 'DESC'];
    if (!in_array($fuel, $allowedFuels) || !in_array($sort, $allowedSorts)) return null;
    
    $sql = "SELECT $fuel as price, timestamp FROM preise WHERE station_id = ? AND isOpen = 1 AND $fuel IS NOT NULL ORDER BY $fuel $sort, timestamp DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $stationId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

function fmtP($val) { return $val !== null ? number_format((float)$val, 3, ',', '.') . ' €' : '-'; }
function fmtD($dateStr) { return $dateStr ? date("d.m.Y H:i", strtotime($dateStr)) : '-'; }

// --- MAPPING VON STATION-ID ZU NAME (wie in index.php) ---
// In einer größeren Anwendung würde man dies in eine Konfigurationsdatei auslagern.
$stationNames = [
    'de4ab744-fb65-4998-8666-bbf110e42dcd' => 'ENI (Münchner Str. 121A/1)',
    'e9e805ea-8f6d-4450-9fcb-bacad82e4c7a' => 'ELAN (Münchner Str. 92)',
    '9826b023-5719-48c0-9571-e16641777879' => 'Bavaria Petrol (Grünwalder Weg 42)',
    '1cf17a0c-0091-4ea4-a12d-3ccf944c1947' => 'Bavaria Petrol (Unterhachinger Str. 28a)'
];
$stationDisplayName = $stationNames[$stationId] ?? $stationId;

// --- DATEN FÜR DIAGRAMM ABFRAGEN (letzte 24 Stunden) ---
$stmt = $conn->prepare("
    SELECT timestamp, diesel, e5, e10 
    FROM preise 
    WHERE station_id = ? AND timestamp >= NOW() - INTERVAL 24 HOUR
    ORDER BY timestamp ASC
");
$stmt->bind_param("s", $stationId);
$stmt->execute();
$result = $stmt->get_result();

$labels = [];
$dieselPrices = [];
$e5Prices = [];
$e10Prices = [];

// --- LETZTE BEKANNTE PREISE VOR DEN 24 STUNDEN LADEN ---
// Damit das Diagramm nachts nicht leer startet, holen wir den letzten gültigen Preis vor dem 24h-Fenster.
$stmtLast = $conn->prepare("
    SELECT diesel, e5, e10 
    FROM preise 
    WHERE station_id = ? AND timestamp < NOW() - INTERVAL 24 HOUR AND isOpen = 1 
    ORDER BY timestamp DESC LIMIT 1
");
$stmtLast->bind_param("s", $stationId);
$stmtLast->execute();
$resLast = $stmtLast->get_result()->fetch_assoc();
$stmtLast->close();

$lastDiesel = $resLast ? $resLast['diesel'] : null;
$lastE5 = $resLast ? $resLast['e5'] : null;
$lastE10 = $resLast ? $resLast['e10'] : null;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Format timestamp for display in the chart
        $labels[] = date("H:i", strtotime($row['timestamp']));
        
        // Wenn die Tankstelle geschlossen ist (null), verwenden wir den letzten bekannten Preis weiter
        if ($row['diesel'] !== null) $lastDiesel = $row['diesel'];
        if ($row['e5'] !== null) $lastE5 = $row['e5'];
        if ($row['e10'] !== null) $lastE10 = $row['e10'];

        $dieselPrices[] = $lastDiesel;
        $e5Prices[] = $lastE5;
        $e10Prices[] = $lastE10;
    }
}

// --- DATEN FÜR BESTE TANKZEIT ABFRAGEN (Historischer Durchschnitt je Stunde) ---
$avgDieselPrices = array_fill(0, 24, null);
$avgE5Prices = array_fill(0, 24, null);
$avgE10Prices = array_fill(0, 24, null);
$hourLabels = array_map(function($h) { return sprintf("%02d:00", $h); }, range(0, 23));

$stmtStats = $conn->prepare("
    SELECT HOUR(timestamp) as h, AVG(diesel) as avg_d, AVG(e5) as avg_e5, AVG(e10) as avg_e10
    FROM preise 
    WHERE station_id = ? AND isOpen = 1 AND diesel IS NOT NULL
    GROUP BY HOUR(timestamp)
    ORDER BY h ASC
");
$stmtStats->bind_param("s", $stationId);
$stmtStats->execute();
$resStats = $stmtStats->get_result();

while ($row = $resStats->fetch_assoc()) {
    $h = (int)$row['h'];
    $avgDieselPrices[$h] = round($row['avg_d'], 3);
    $avgE5Prices[$h] = round($row['avg_e5'], 3);
    $avgE10Prices[$h] = round($row['avg_e10'], 3);
}

$stmtStats->close();

// --- DATEN FÜR BESTEN WOCHENTAG ABFRAGEN (Historischer Durchschnitt je Tag) ---
$avgDieselDays = array_fill(0, 7, null);
$avgE5Days = array_fill(0, 7, null);
$avgE10Days = array_fill(0, 7, null);
$dayLabels = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

$stmtDays = $conn->prepare("
    SELECT WEEKDAY(timestamp) as w, AVG(diesel) as avg_d, AVG(e5) as avg_e5, AVG(e10) as avg_e10
    FROM preise 
    WHERE station_id = ? AND isOpen = 1 AND diesel IS NOT NULL
    GROUP BY WEEKDAY(timestamp)
    ORDER BY w ASC
");
$stmtDays->bind_param("s", $stationId);
$stmtDays->execute();
$resDays = $stmtDays->get_result();

while ($row = $resDays->fetch_assoc()) {
    $w = (int)$row['w'];
    $avgDieselDays[$w] = round($row['avg_d'], 3);
    $avgE5Days[$w] = round($row['avg_e5'], 3);
    $avgE10Days[$w] = round($row['avg_e10'], 3);
}
$stmtDays->close();

// -- BERECHNE DIE ABSOLUTEN HIGHLIGHTS (Basierend auf Diesel als Hauptindikator) --
$bestHour = null;
$bestDay = null;

$validDieselHours = array_filter($avgDieselPrices, function($v) { return $v !== null; });
if (!empty($validDieselHours)) $bestHour = array_search(min($validDieselHours), $avgDieselPrices);

$validDieselDays = array_filter($avgDieselDays, function($v) { return $v !== null; });
if (!empty($validDieselDays)) $bestDay = array_search(min($validDieselDays), $avgDieselDays);

// --- NEUE STATISTIKEN ABFRAGEN ---
$stmtAvgs = $conn->prepare("
    SELECT 
        AVG(diesel) as avg_all_d, AVG(e5) as avg_all_e5, AVG(e10) as avg_all_e10,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 30 DAY THEN diesel ELSE NULL END) as avg_30_d,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 30 DAY THEN e5 ELSE NULL END) as avg_30_e5,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 30 DAY THEN e10 ELSE NULL END) as avg_30_e10,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 7 DAY THEN diesel ELSE NULL END) as avg_7_d,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 7 DAY THEN e5 ELSE NULL END) as avg_7_e5,
        AVG(CASE WHEN timestamp >= NOW() - INTERVAL 7 DAY THEN e10 ELSE NULL END) as avg_7_e10
    FROM preise
    WHERE station_id = ? AND isOpen = 1
");
$stmtAvgs->bind_param("s", $stationId);
$stmtAvgs->execute();
$avgs = $stmtAvgs->get_result()->fetch_assoc();
$stmtAvgs->close();

$minD = getExtremes($conn, $stationId, 'diesel', 'ASC');
$maxD = getExtremes($conn, $stationId, 'diesel', 'DESC');
$minE5 = getExtremes($conn, $stationId, 'e5', 'ASC');
$maxE5 = getExtremes($conn, $stationId, 'e5', 'DESC');
$minE10 = getExtremes($conn, $stationId, 'e10', 'ASC');
$maxE10 = getExtremes($conn, $stationId, 'e10', 'DESC');

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preisentwicklung für <?php echo htmlspecialchars($stationDisplayName); ?></title>
    <!-- Chart.js from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f9; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { text-align: center; color: #333; }
        h2 { font-weight: normal; margin-top: -10px; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .chart-wrapper { margin-bottom: 40px; }
        .highlight-container { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; margin-bottom: 30px; }
        .highlight-card { background: #e8f5e9; border: 1px solid #c8e6c9; padding: 20px; border-radius: 8px; text-align: center; flex: 1; min-width: 200px; max-width: 300px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .highlight-card h3 { margin: 0 0 10px 0; font-size: 1.2em; color: #2e7d32; }
        .highlight-card .value { font-size: 2.2em; font-weight: bold; color: #1b5e20; margin-bottom: 5px; }
        .highlight-card .subtext { font-size: 0.85em; color: #4caf50; }
        h3 { color: #555; margin-bottom: 10px; }
        table.stats-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        table.stats-table th, table.stats-table td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        table.stats-table th { background-color: #e9ecef; color: #333; font-weight: bold; }
        table.stats-table tr:hover { background-color: #f8f9fa; }
        .date-small { font-size: 0.85em; color: #666; display: block; margin-top: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h1>Preisentwicklung der letzten 24h</h1>
    <h2><?php echo htmlspecialchars($stationDisplayName); ?></h2>
    
    <div class="chart-wrapper">
        <?php if (count($labels) > 1): ?>
            <canvas id="priceChart"></canvas>
        <?php else: ?>
            <p style="text-align: center;">Nicht genügend Daten für eine Preisanzeige der letzten 24 Stunden vorhanden.</p>
        <?php endif; ?>
    </div>

    <hr style="border: 0; border-top: 1px solid #ddd; margin: 30px 0;">

    <h1>Statistiken & Rekorde</h1>
    
    <h3>Durchschnittspreise</h3>
    <div style="overflow-x:auto;">
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Zeitraum</th>
                    <th>Diesel</th>
                    <th>E5</th>
                    <th>E10</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Letzte 7 Tage</td>
                    <td><?php echo fmtP($avgs['avg_7_d']); ?></td>
                    <td><?php echo fmtP($avgs['avg_7_e5']); ?></td>
                    <td><?php echo fmtP($avgs['avg_7_e10']); ?></td>
                </tr>
                <tr>
                    <td>Letzte 30 Tage</td>
                    <td><?php echo fmtP($avgs['avg_30_d']); ?></td>
                    <td><?php echo fmtP($avgs['avg_30_e5']); ?></td>
                    <td><?php echo fmtP($avgs['avg_30_e10']); ?></td>
                </tr>
                <tr>
                    <td>Seit Datenerhebung</td>
                    <td><?php echo fmtP($avgs['avg_all_d']); ?></td>
                    <td><?php echo fmtP($avgs['avg_all_e5']); ?></td>
                    <td><?php echo fmtP($avgs['avg_all_e10']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3>Historische Extremwerte</h3>
    <div style="overflow-x:auto;">
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Rekord</th>
                    <th>Diesel</th>
                    <th>E5</th>
                    <th>E10</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Allzeit-Tief</td>
                    <td>
                        <?php echo $minD ? fmtP($minD['price']) : '-'; ?>
                        <?php if($minD): ?><span class="date-small"><?php echo fmtD($minD['timestamp']); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php echo $minE5 ? fmtP($minE5['price']) : '-'; ?>
                        <?php if($minE5): ?><span class="date-small"><?php echo fmtD($minE5['timestamp']); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php echo $minE10 ? fmtP($minE10['price']) : '-'; ?>
                        <?php if($minE10): ?><span class="date-small"><?php echo fmtD($minE10['timestamp']); ?></span><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Allzeit-Hoch</td>
                    <td>
                        <?php echo $maxD ? fmtP($maxD['price']) : '-'; ?>
                        <?php if($maxD): ?><span class="date-small"><?php echo fmtD($maxD['timestamp']); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php echo $maxE5 ? fmtP($maxE5['price']) : '-'; ?>
                        <?php if($maxE5): ?><span class="date-small"><?php echo fmtD($maxE5['timestamp']); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php echo $maxE10 ? fmtP($maxE10['price']) : '-'; ?>
                        <?php if($maxE10): ?><span class="date-small"><?php echo fmtD($maxE10['timestamp']); ?></span><?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <hr style="border: 0; border-top: 1px solid #ddd; margin: 30px 0;">

    <h1>Historische Empfehlungen</h1>
    
    <?php if ($bestHour !== null || $bestDay !== null): ?>
    <div class="highlight-container">
        <?php if ($bestHour !== null): ?>
        <div class="highlight-card">
            <h3>Beste Uhrzeit</h3>
            <div class="value"><?php echo sprintf("%02d:00", $bestHour); ?></div>
            <div class="subtext">Historischer Durchschnitt</div>
        </div>
        <?php endif; ?>
        <?php if ($bestDay !== null): ?>
        <div class="highlight-card">
            <h3>Bester Wochentag</h3>
            <div class="value"><?php echo $dayLabels[$bestDay]; ?></div>
            <div class="subtext">Historischer Durchschnitt</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2>Durchschnitt nach Uhrzeit</h2>
    <div class="chart-wrapper">
        <canvas id="bestTimeChart"></canvas>
    </div>

    <h2>Durchschnitt nach Wochentag</h2>
    <div class="chart-wrapper">
        <canvas id="bestDayChart"></canvas>
    </div>

    <p style="text-align: center; margin-top: 20px;">
        <a href="index.php">Zurück zur Übersicht</a>
    </p>
</div>

<script>
    // Only run script if there is data to display
    if (<?php echo json_encode(count($labels) > 1); ?>) {
        const ctx = document.getElementById('priceChart').getContext('2d');
        const priceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [
                    {
                        label: 'Diesel',
                        data: <?php echo json_encode($dieselPrices); ?>,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        tension: 0.1,
                        spanGaps: true // Connects points over null values
                    },
                    {
                        label: 'E5',
                        data: <?php echo json_encode($e5Prices); ?>,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        tension: 0.1,
                        spanGaps: true
                    },
                    {
                        label: 'E10',
                        data: <?php echo json_encode($e10Prices); ?>,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        tension: 0.1,
                        spanGaps: true
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false, // Don't start y-axis at 0
                        ticks: {
                            // Format y-axis labels as currency
                            callback: function(value) {
                                return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Helper function to highlight the lowest value in a dataset
    function getHighlightColors(data, defaultColor) {
        const validData = data.filter(v => v !== null);
        if(validData.length === 0) return data.map(() => defaultColor);
        const minVal = Math.min(...validData);
        return data.map(v => v === minVal ? 'rgba(76, 175, 80, 0.8)' : defaultColor);
    }

    // Chart for the best time to refuel (Historical Averages)
    const hasHistoryData = <?php echo json_encode(count(array_filter($avgDieselPrices)) > 0); ?>;
    if (hasHistoryData) {
        const ctxBestTime = document.getElementById('bestTimeChart').getContext('2d');
        const bestTimeChart = new Chart(ctxBestTime, {
            type: 'bar', // Balkendiagramm eignet sich gut für durchschnittliche Stunden
            data: {
                labels: <?php echo json_encode($hourLabels); ?>,
                datasets: [
                    {
                        label: 'Ø Diesel',
                        data: <?php echo json_encode($avgDieselPrices); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgDieselPrices); ?>, 'rgba(255, 99, 132, 0.4)'),
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Ø E5',
                        data: <?php echo json_encode($avgE5Prices); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgE5Prices); ?>, 'rgba(54, 162, 235, 0.4)'),
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Ø E10',
                        data: <?php echo json_encode($avgE10Prices); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgE10Prices); ?>, 'rgba(75, 192, 192, 0.4)'),
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    } else {
        document.getElementById('bestTimeChart').outerHTML = '<p style="text-align: center;">Noch nicht genug historische Daten für einen Durchschnitt vorhanden.</p>';
    }

    // Chart for the best day to refuel (Historical Averages)
    const hasDayData = <?php echo json_encode(count(array_filter($avgDieselDays)) > 0); ?>;
    if (hasDayData) {
        const ctxBestDay = document.getElementById('bestDayChart').getContext('2d');
        const bestDayChart = new Chart(ctxBestDay, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dayLabels); ?>,
                datasets: [
                    {
                        label: 'Ø Diesel',
                        data: <?php echo json_encode($avgDieselDays); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgDieselDays); ?>, 'rgba(255, 99, 132, 0.4)'),
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Ø E5',
                        data: <?php echo json_encode($avgE5Days); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgE5Days); ?>, 'rgba(54, 162, 235, 0.4)'),
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Ø E10',
                        data: <?php echo json_encode($avgE10Days); ?>,
                        backgroundColor: getHighlightColors(<?php echo json_encode($avgE10Days); ?>, 'rgba(75, 192, 192, 0.4)'),
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);
                            }
                        }
                    }
                }
                // Tooltips omitted for brevity, it inherits defaults
            }
        });
    } else {
        document.getElementById('bestDayChart').outerHTML = '<p style="text-align: center;">Noch nicht genug historische Daten für Wochentage vorhanden.</p>';
    }
</script>

</body>
</html>