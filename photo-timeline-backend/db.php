<?php
// db.php
// 数据库连接与表初始化

// 加载外部配置
$config = require __DIR__ . '/config.php';

$dbFile = $config['db_file'];
$uploadDir = $config['upload_dir'];

// 确保上传目录存在
if (!file_exists($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => '无法创建上传目录，请检查目录权限']);
        exit;
    }
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 启用 WAL 模式提升并发性能
    $pdo->exec("PRAGMA journal_mode=WAL");

    // 创建表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timelineitem (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            date TEXT NOT NULL,
            src TEXT NOT NULL,
            thumb TEXT,
            latitude REAL,
            longitude REAL,
            taken_at TEXT,
            group_id TEXT
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

    // 先补充旧库可能缺少的 group_id 列（必须在建索引之前）
    try { $pdo->exec("ALTER TABLE timelineitem ADD COLUMN group_id TEXT"); } catch (PDOException $e) {}

    // 创建索引（提升排序和分组查询性能）
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_timelineitem_date ON timelineitem(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_timelineitem_group ON timelineitem(group_id)");

    // 数据库迁移：使用版本标记避免每次请求都检查
    $migrationFile = __DIR__ . '/.db_migrated';
    if (!file_exists($migrationFile)) {
        // 检查 appconfig 表的列
        $cols = $pdo->query("PRAGMA table_info(appconfig)")->fetchAll();
        $existingCols = array_column($cols, 'name');

        if (!in_array('appTitle', $existingCols)) {
            $pdo->exec("ALTER TABLE appconfig ADD COLUMN appTitle TEXT DEFAULT '花生'");
        }
        if (!in_array('pageSize', $existingCols)) {
            $pdo->exec("ALTER TABLE appconfig ADD COLUMN pageSize INTEGER DEFAULT 5");
        }

        // 检查 timelineitem 表的列
        $itemCols = $pdo->query("PRAGMA table_info(timelineitem)")->fetchAll();
        $existingItemCols = array_column($itemCols, 'name');

        if (!in_array('thumb', $existingItemCols)) {
            $pdo->exec("ALTER TABLE timelineitem ADD COLUMN thumb TEXT");
        }

        // 标记迁移完成
        file_put_contents($migrationFile, date('Y-m-d H:i:s'));
    }

    // 初始化配置（仅首次）
    $stmt = $pdo->query("SELECT COUNT(*) FROM appconfig");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO appconfig (id) VALUES (1)");
    }

    // 新增列迁移（幂等，已存在则忽略）
    try { $pdo->exec("ALTER TABLE timelineitem ADD COLUMN address TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE timelineitem ADD COLUMN description TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE timelineitem ADD COLUMN deleted_at TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE appconfig ADD COLUMN loadingText TEXT DEFAULT '加载中...'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE appconfig ADD COLUMN loadMoreText TEXT DEFAULT '上拉加载更多'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE appconfig ADD COLUMN endText TEXT DEFAULT 'THE END'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE appconfig ADD COLUMN takenAtLabel TEXT DEFAULT '拍摄:'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE timelineitem ADD COLUMN group_id TEXT"); } catch (PDOException $e) {}

} catch (PDOException $e) {
    http_response_code(500);
    if (!empty($config['production'])) {
        echo json_encode(['error' => '数据库连接失败，请联系管理员']);
        error_log("数据库错误: " . $e->getMessage());
    } else {
        echo json_encode(['error' => '数据库连接失败: ' . $e->getMessage()]);
    }
    exit;
}
