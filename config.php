<?php
// Ustawienia bazy danych
define('DB_HOST', 'localhost');
define('DB_NAME', 'notatki_db');
define('DB_USER', 'user_notatki'); // Zmień na swoje dane
define('DB_PASS', 'pa$$word123'); // Zmień na swoje dane
define('DB_CHARSET', 'utf8mb4');

// Ścieżka do przechowywania załączników na serwerze
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Upewnij się, że katalog do uploadów istnieje
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Błąd połączenia z bazą danych: " . $e->getMessage());
}

/**
 * Generuje unikalny ID dla notatki (zgodnie z JS)
 * @return string
 */
function uid() {
    return date('U') . '-' . substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 5)), 0, 8);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        // Dodaj te opcje dla większej stabilności PDO
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // WYMUSZENIE BRAKU EMULACJI (zwiększa stabilność dla zapytań z dużymi danymi)
        PDO::ATTR_EMULATE_PREPARES   => false, 
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Ustawienie kodowania na poziomie sesji, na wypadek problemów z DSN
    $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'"); 
    
} catch (PDOException $e) {
    die("Błąd połączenia z bazą danych: " . $e->getMessage());
}
