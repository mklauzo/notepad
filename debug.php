<?php
// debug.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

require_once 'config.php';

echo "<h1>Test Połączenia i Danych</h1>";

try {
    // 1. Sprawdzenie połączenia i wymuszenie UTF-8
    $pdo->exec("SET NAMES 'utf8mb4'");
    echo "<p style='color:green'>Połączenie z bazą OK.</p>";

    // 2. Pobranie surowych danych
    $stmt = $pdo->query("SELECT * FROM notes ORDER BY created_at DESC LIMIT 5");
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>Liczba notatek w bazie: " . count($notes) . "</h2>";

    if (count($notes) > 0) {
        echo "<pre style='background:#eee; padding:10px;'>";
        print_r($notes);
        echo "</pre>";

        // 3. Test konwersji na JSON (Kluczowy moment!)
        $json = json_encode($notes);
        if ($json === false) {
            echo "<h2 style='color:red'>BŁĄD JSON: " . json_last_error_msg() . "</h2>";
            echo "<p>To jest przyczyna! Masz polskie znaki, których PHP nie może przetworzyć na JSON.</p>";
        } else {
            echo "<h2 style='color:green'>JSON OK (długość: " . strlen($json) . ")</h2>";
        }
    } else {
        echo "<p style='color:orange'>Tabela jest pusta.</p>";
    }

} catch (PDOException $e) {
    echo "<h2 style='color:red'>Błąd SQL: " . $e->getMessage() . "</h2>";
}
?>
