# 宝塔面板 (BT Panel) 部署指南

本指南指导您部署 **Photo Timeline（花生）** 的 PHP 后端和 Uni-app 前端。

---

## 项目结构

```
duanxinyu/
├── photo-timeline-backend/    # PHP 后端 API
│   ├── config.php             # ⚙️ 配置文件（密钥等，需手动创建）
│   ├── db.php                 # 数据库连接与迁移
│   ├── index.php              # API 路由控制器
│   ├── .htaccess              # Apache URL 重写
│   ├── timeline.db            # SQLite 数据库（自动生成）
│   └── uploads/               # 上传文件目录（自动创建）
├── photo-timeline-admin/      # 管理后台（UniApp H5）
│   └── src/
│       ├── api.js             # API 服务模块
│       ├── exif.js            # 客户端 EXIF 解析器（拍摄时间+GPS）
│       ├── utils.js           # 工具函数
│       └── pages/index/       # 主页面
├── photo-timeline-uniapp/     # 用户展示端（UniApp H5）
│   └── src/
│       ├── api.js             # API 服务模块
│       ├── utils.js           # 工具函数
│       └── pages/index/       # 主页面
└── doc/
    └── deploy_bt.md           # 本文档
```

---

## 功能概览

- 照片/视频上传，支持批量上传
- 自动提取照片 EXIF 信息（拍摄时间、GPS 坐标）
- **坐标自动转地址**：通过高德地图 API 将 GPS 坐标解析为可读地址（如「北京市朝阳区…」）
- 自动生成缩略图
- 时间轴展示，支持无限滚动
- 管理后台：上传、删除、全局配置
- 时间轴卡片展示拍摄时间、地址信息，点击地址可跳转高德地图

---

## 准备工作

1.  **服务器要求**:
    *   已安装宝塔面板
    *   已安装 **Nginx/Apache** 和 **PHP 7.4+**

2.  **PHP 扩展要求**:
    *   宝塔 **PHP管理 -> 安装扩展**，确保已安装：
        *   `sqlite3` — SQLite 数据库驱动
        *   `pdo_sqlite` — PDO SQLite 驱动
        *   `exif` — 照片 EXIF 信息提取（拍摄时间/GPS）
        *   `gd` — 缩略图生成
        *   `curl` — 高德地图逆地理编码（坐标转地址）

---

## 第一步：部署后端 (PHP)

### 1. 上传代码

将 `photo-timeline-backend` 文件夹内的内容上传到网站根目录：
- 例如 `/www/wwwroot/api.hetao.us/`
- 或者子目录 `/www/wwwroot/hetao.us/api`

### 2. 配置密钥

> **⚠️ 重要**: 后端使用 `config.php` 存储 API 密钥等敏感配置，该文件**不会**被 Git 提交。

在后端目录中创建 `config.php`，内容如下：

```php
<?php
return [
    // API 密钥（请修改为强密码！）
    'api_secret' => '你的密钥',

    // 生产环境设为 true（隐藏错误详情）
    'production' => true,

    // 数据库文件路径
    'db_file' => __DIR__ . '/timeline.db',

    // 上传目录路径
    'upload_dir' => __DIR__ . '/uploads',

    // 缩略图最大宽度
    'thumb_max_width' => 800,

    // 缩略图质量 (1-100)
    'thumb_quality' => 60,

    // 高德地图 Web Service API Key（用于坐标转地址，留空则显示坐标）
    // 免费申请: https://lbs.amap.com/ → 控制台 → 应用管理 → 创建新应用 → 添加 Key（服务平台选 Web服务）
    'amap_key' => '',
];
```

### 3. 配置高德地图 API Key（推荐）

配置后，上传带 GPS 信息的照片时会自动将坐标解析为地址（如「北京市海淀区中关村大街」），展示在时间轴卡片上。

**申请步骤**（免费，每日 5000 次调用额度）：

1. 打开 https://lbs.amap.com/ 注册并登录
2. 进入 **控制台 → 应用管理 → 创建新应用**
3. 点击 **添加 Key**，服务平台选择 **「Web服务」**
4. 将获得的 Key 填入 `config.php` 的 `amap_key` 字段

> 不配置也可正常使用，有 GPS 坐标时会显示坐标值作为兜底。

### 4. 创建站点

*   宝塔 **网站 -> 添加站点**
*   域名: `api.hetao.us`（或者直接用 IP）
*   PHP版本: 选择 7.4 或 8.0+

### 5. 伪静态配置 (Nginx)

> **⚠️ 必须配置**，否则 API 无法访问（报 404 或 CORS 错误）。

在宝塔 **伪静态** 中填入：

```nginx
location / {
    if (!-e $request_filename){
        rewrite  ^(.*)$  /index.php?s=$1  last;   break;
    }
}
```

### 6. 权限设置（非常重要！）

SQLite 数据库不仅需要文件可写，**所在的文件夹也必须可写**。

```bash
# 在宝塔终端执行（修改路径为你的实际路径）
chown -R www:www /www/wwwroot/api.hetao.us
chmod -R 775 /www/wwwroot/api.hetao.us
chmod -R 777 /www/wwwroot/api.hetao.us/uploads
```

### 7. 验证

访问 `https://你的域名/`，应看到：

```json
{"message": "Peanut Timeline Backend (PHP) is Running!"}
```

---

## 第二步：修改前端 API 地址

如果后端域名/地址发生变化，需要更新两个前端项目的 API 地址。

**文件**: `src/api.js`（两个项目各一个）

```javascript
// 修改为你的后端地址
const API_BASE = 'https://api.hetao.us';

// 本地开发时可改为：
// const API_BASE = 'http://localhost/test/duanxinyu/photo-timeline-backend';
```

---

## 第三步：打包前端

使用 HBuilderX 或命令行打包 H5：

```bash
# 管理后台
cd photo-timeline-admin
npm install
npm run build:h5

# 用户展示端
cd photo-timeline-uniapp
npm install
npm run build:h5
```

打包后的文件在 `dist/build/h5` 目录。

---

## 第四步：部署用户端

1.  **创建站点**:
    *   宝塔 **网站 -> 添加站点**
    *   域名: `hetao.us`（用户访问的主域名）
    *   根目录: `.../photo-timeline-uniapp/dist/build/h5`

2.  **伪静态配置 (Nginx)**:
    ```nginx
    location / {
        try_files $uri $uri/ /index.html;
    }
    ```

---

## 第五步：部署管理端 (Admin)

1.  **创建站点**:
    *   宝塔 **网站 -> 添加站点**
    *   域名: `admin.hetao.us`
    *   根目录: `.../photo-timeline-admin/dist/build/h5`

2.  **伪静态配置 (Nginx)**:
    ```nginx
    location / {
        try_files $uri $uri/ /index.html;
    }
    ```

3.  **管理员登录方式**:
    *   方式一：访问 `https://admin.hetao.us?key=你的密钥`（Magic Link 自动登录）
    *   方式二：打开页面后点击「输入管理员密钥」手动输入

---

## EXIF 信息提取说明

上传照片时，系统通过以下方式自动提取拍摄时间和 GPS 位置：

### 提取策略（多层兜底）

| 优先级 | 方式 | 说明 |
|--------|------|------|
| 1 | PHP `exif_read_data` | 服务端读取 JPEG/TIFF 的 EXIF 数据 |
| 2 | 前端 JS EXIF 解析 | 客户端直接解析 JPEG 原始字节，不依赖 PHP 扩展 |
| 3 | `File.lastModified` | 使用文件修改时间兜底（适用于 HEIC、PNG 等无 EXIF 格式） |

### 拍摄时间优先级

`DateTimeOriginal` > `DateTimeDigitized` > `DateTime` > `File.lastModified`

### 地址解析

当照片包含 GPS 坐标时：
- **已配置高德 Key** → 自动解析为可读地址（如「广东省深圳市南山区…」），点击可打开高德地图
- **未配置高德 Key** → 显示坐标值（如「22.5431°N, 114.0579°E」），点击同样可打开地图

---

## 常见问题

### 1. SQLite 驱动缺失

宝塔 **PHP管理 -> 安装扩展**，安装 `sqlite3`、`pdo_sqlite`。

### 2. 文件上传大小限制

宝塔 **PHP管理 -> 配置修改**，调整以下参数（建议 50M+）：

```ini
upload_max_filesize = 50M
post_max_size = 50M
```

### 3. 上传/数据库报错 (Read-only / Permission denied)

**原因**: Web 用户 `www` 没有写入权限。

**解决**:
- 在宝塔 **文件** 中找到后端目录
- 点击 **权限**，设为 `755` 或 `777`，所有者设为 `www`
- 确保 `uploads/` 目录权限为 `777`
- 确保 `timeline.db` 及其 WAL 文件可写

### 4. 跨域 (CORS)

`index.php` 已内置 CORS 头，一般不需要额外配置。如 Nginx 拦截了 OPTIONS 请求，可在 Nginx 配置中添加：

```nginx
location / {
    if ($request_method = 'OPTIONS') {
        add_header Access-Control-Allow-Origin *;
        add_header Access-Control-Allow-Methods 'GET, POST, DELETE, OPTIONS';
        add_header Access-Control-Allow-Headers 'Content-Type, x-api-key';
        return 204;
    }
}
```

### 5. 数据库迁移

系统首次运行会自动检查并创建所需的数据表和列。迁移完成后会在后端目录生成 `.db_migrated` 标记文件。新增的 `address` 列通过幂等迁移自动添加，无需手动干预。

### 6. config.php 丢失

如果访问 API 报 500 错误，请检查后端目录是否存在 `config.php` 文件。该文件不会随 Git 提交，部署时需手动创建。

### 7. EXIF 信息获取不到

*   **拍摄时间为空**: 确保 PHP 安装了 `exif` 扩展。即使未安装，前端 JS 解析器和 `File.lastModified` 会自动兜底。
*   **GPS 坐标为空**: 照片必须包含 GPS 信息（手机相机需开启定位权限）。截图、网络下载的图片通常不含 GPS 数据。
*   **地址不显示**: 检查 `config.php` 中的 `amap_key` 是否已配置。未配置时只显示坐标。

### 8. 高德地图 API 调用失败

*   确认 Key 的服务平台为 **「Web服务」**（不是 JS API 或其他）
*   确认 PHP 已安装 `curl` 扩展（宝塔 PHP管理 -> 安装扩展）
*   确认服务器能访问外网（`curl https://restapi.amap.com` 测试）
*   免费额度为每日 5000 次，超出后需升级或等待次日重置
