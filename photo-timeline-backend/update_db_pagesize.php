<?php
require 'db.php';

// Check if 'pageSize' column exists in 'appconfig'
$cols = $pdo->query("PRAGMA table_info(appconfig)")->fetchAll(PDO::FETCH_ASSOC);
$hasPageSize = false;
foreach ($cols as $col) {
    if ($col['name'] === 'pageSize') {
        $hasPageSize = true;
        break;
    }
}

if (!$hasPageSize) {
    try {
        $pdo->exec("ALTER TABLE appconfig ADD COLUMN pageSize INTEGER DEFAULT 5");
        echo "Successfully added 'pageSize' column to 'appconfig' table.\n";
    } catch (PDOException $e) {
        echo "Error adding column: " . $e->getMessage() . "\n";
    }
} else {
    echo "'pageSize' column already exists.\n";
}
?>
