<?php
// db.php
// Handles SQLite connection and table initialization.

$dbFile = __DIR__ . '/timeline.db';
$uploadDir = __DIR__ . '/uploads';

// Ensure upload directory exists
if (!file_exists($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => "Failed to create upload directory. Check permissions for " . __DIR__]);
        exit;
    }
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
            appTitle TEXT DEFAULT '花生',
            kicker TEXT DEFAULT 'Peanut',
            mainTitle TEXT DEFAULT '精彩时刻',
            subTitle TEXT DEFAULT '记录属于你的花生时刻，支持横向滚动与本地保存。',
            timelineTitle TEXT DEFAULT '时间轴',
            emptyText TEXT DEFAULT '还没有照片，先上传几张吧。',
            defaultItemTitle TEXT DEFAULT '未命名照片',
            unknownDateText TEXT DEFAULT '未知时间',
            pageSize INTEGER DEFAULT 5
        )
    ");

    // Migration: Check if appTitle exists (for existing dbs)
    $cols = $pdo->query("PRAGMA table_info(appconfig)")->fetchAll();
    $hasAppTitle = false;
    $hasPageSize = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'appTitle') {
            $hasAppTitle = true;
        }
        if ($col['name'] === 'pageSize') {
            $hasPageSize = true;
        }
    }
    if (!$hasAppTitle) {
        $pdo->exec("ALTER TABLE appconfig ADD COLUMN appTitle TEXT DEFAULT '花生'");
    }
    if (!$hasPageSize) {
        $pdo->exec("ALTER TABLE appconfig ADD COLUMN pageSize INTEGER DEFAULT 5");
    }

    // Migration: Check if thumb exists in timelineitem
    $itemCols = $pdo->query("PRAGMA table_info(timelineitem)")->fetchAll();
    $hasThumb = false;
    foreach ($itemCols as $col) {
        if ($col['name'] === 'thumb') {
            $hasThumb = true;
            break;
        }
    }
    if (!$hasThumb) {
        $pdo->exec("ALTER TABLE timelineitem ADD COLUMN thumb TEXT");
    }

    // Seed Config if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM appconfig");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO appconfig (id) VALUES (1)");
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => "数据库连接失败 (Database Connection Failed): " . $e->getMessage()]);
    exit;
}
