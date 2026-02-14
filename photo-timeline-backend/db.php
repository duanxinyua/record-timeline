<?php
// db.php
// Handles SQLite connection and table initialization.

$dbFile = __DIR__ . '/timeline.db';
$uploadDir = __DIR__ . '/uploads';

// Ensure upload directory exists
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create Tables if not exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timelineitem (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            date TEXT NOT NULL,
            src TEXT NOT NULL,
            latitude REAL,
            longitude REAL,
            taken_at TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appconfig (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kicker TEXT DEFAULT 'Peanut',
            mainTitle TEXT DEFAULT '精彩时刻',
            subTitle TEXT DEFAULT '记录属于你的花生时刻，支持横向滚动与本地保存。',
            timelineTitle TEXT DEFAULT '时间轴',
            emptyText TEXT DEFAULT '还没有照片，先上传几张吧。',
            defaultItemTitle TEXT DEFAULT '未命名照片',
            unknownDateText TEXT DEFAULT '未知时间'
        )
    ");

    // Seed Config if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM appconfig");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO appconfig (id) VALUES (1)");
    }

} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
