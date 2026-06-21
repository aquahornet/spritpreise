<?php
// --- KONFIGURATION LADEN ---
require_once __DIR__ . '/config.php';

// --- VERBINDUNG ZUR DATENBANK ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Auch Fehlermeldungen brauchen einen Zeilenumbruch für das Log
    die("Verbindung fehlgeschlagen: " . $conn->connect_error . PHP_EOL);
}
$conn->query("SET time_zone = '" . date('P') . "'");

// --- DATEN VON API ABRUFEN ---
$url = "https://creativecommons.tankerkoenig.de/json/prices.php?ids=" . urlencode(STATION_IDS) . "&apikey=" . urlencode(TANKERKOENIG_API_KEY);

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