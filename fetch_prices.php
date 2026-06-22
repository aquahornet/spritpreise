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
    die("Fehler: Datenbank-Konfiguration fehlt. Bitte stelle sicher, dass die .env-Datei auf dem Server existiert (Hinweis: Dein rsync-Befehl schließt .env-Dateien aus)." . PHP_EOL);
}

// --- VERBINDUNG ZUR DATENBANK ---
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    // Auch Fehlermeldungen brauchen einen Zeilenumbruch für das Log
    die("Verbindung fehlgeschlagen: " . $conn->connect_error . PHP_EOL);
}
$conn->query("SET time_zone = '" . date('P') . "'");

// --- DATEN VON API ABRUFEN ---
$url = "https://creativecommons.tankerkoenig.de/json/prices.php?ids=" . urlencode($_ENV['STATION_IDS'] ?? '') . "&apikey=" . urlencode($_ENV['TANKERKOENIG_API_KEY'] ?? '');

$response = file_get_contents($url);
$data = json_decode($response, true);

if ($data && $data['ok']) {
    foreach ($data['prices'] as $id => $prices) {
        $status = $prices['status'];

        if ($status == "open") {
            $isOpen = 1;
            $e5 = $prices['e5'];
            $e10 = $prices['e10'];
            $diesel = $prices['diesel'];
        } else { 
            $isOpen = 0;
            $e5 = null;
            $e10 = null;
            $diesel = null;
        }

        $stmt = $conn->prepare("INSERT INTO preise (station_id, diesel, e5, e10, isOpen) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sdddi", $id, $diesel, $e5, $e10, $isOpen);
        $stmt->execute();
        $stmt->close();
    }
    
    // HIER IST DIE ENTSCHEIDENDE ÄNDERUNG:
    // PHP_EOL sorgt für den Zeilenumbruch im Linux-Dateisystem
    echo "Daten erfolgreich aktualisiert: " . date("d.m.Y H:i:s") . PHP_EOL;

} else {
    echo "Fehler beim Abrufen der API-Daten am " . date("d.m.Y H:i:s") . PHP_EOL;
}

$conn->close();
?>