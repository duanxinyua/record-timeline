# 宝塔面板 (BT Panel) 部署指南（PHP 建站版）

本指南基于当前仓库代码（`photo-timeline-backend`、`photo-timeline-admin`、`photo-timeline-uniapp`）整理，适用于 Linux + 宝塔 + Nginx + PHP 环境。

说明：本版本按“宝塔面板可视化操作”编写，不依赖命令行。

## 项目结构

```text
project-root/
├── data/                         # SQLite 数据库目录（不放在后端 Web 根目录）
├── photo-timeline-backend/      # PHP 后端 API
│   ├── index.php                # 统一入口与路由
│   ├── db.php                   # SQLite 初始化与迁移
│   ├── config.example.php       # 配置模板（复制为 config.php）
│   ├── .env.example             # 环境变量模板（可选）
│   ├── src/
│   │   ├── Controllers/         # 业务控制器
│   │   ├── Models/              # 数据模型
│   │   └── Utils/               # 工具函数
│   └── uploads/                 # 上传目录（自动创建）
├── photo-timeline-admin/        # 管理端（UniApp H5）
│   └── src/pages/index/index.vue
├── photo-timeline-uniapp/       # 用户端（UniApp H5）
│   └── src/pages/index/index.vue
├── CHANGELOG.md                 # 修改记录
└── doc/
    └── deploy_bt.md
```

## 当前功能概览

- 图片/视频上传，支持批量。
- 大视频上传支持 5MB 分片、断点续传与服务端后台处理，降低大文件直传和网盘挂载目录上的长连接压力。
- 上传时自动提取 EXIF（拍摄时间、GPS），并生成缩略图。
- 视频封面补生成功能采用单文件模式，避免额外生成冗余 `_thumb` 文件。
- GPS 可通过高德 API 自动解析地址。
- 时间轴分页加载、搜索、分组展示。
- 年/月两级折叠；默认仅展开最新月份，最新月份不足 5 条时自动补拉上一月数据（直到合计至少 5 条或无更多数据）。
- 年/月标题后缀显示全量动态计数（非当前分页已加载条数）。
- 管理端支持编辑、软删除、回收站恢复、彻底删除、清空回收站。
- 管理端回收站“彻底删除/清空”使用页面内确认弹窗，避免被回收站层遮挡。
- `group_id` 为空时后端会自动兜底生成（保证分组稳定）。
- 用户端和管理端均为单页滚动体验，顶部区域与时间轴一起滚动。
- 置顶按钮为悬浮球，支持拖拽移动位置。

## 一、环境要求

### 1. 服务器与运行时

- 宝塔面板
- Nginx
- PHP 7.4+（推荐 8.x）
- FFmpeg（用于视频转码）
- PHP CLI（用于大视频分片后台处理）
- Node.js 18+（用于前端打包）

### 2. PHP 扩展

在宝塔 `PHP管理 -> 安装扩展` 确保启用：

- `sqlite3`
- `pdo_sqlite`
- `exif`
- `gd`
- `curl`
- `fileinfo`（建议开启，用于识别缺少扩展名的上传文件）

## 二、部署后端（PHP）

### 1. 在宝塔创建后端站点

在宝塔面板中：

1. 进入 `网站` -> `添加站点`
2. 域名填写：`api.example.com`（按你的实际域名）
3. PHP 版本选择：`8.0` 或更高
4. 网站目录建议：`/www/wwwroot/api.example.com`
5. 提交创建

### 2. 上传后端代码

将 `photo-timeline-backend` 目录内文件上传到站点根目录（例如 `/www/wwwroot/api.example.com`）。

上传完成后应能看到：

- `index.php`
- `db.php`
- `config.example.php`
- `.env.example`
- `.user.ini`（PHP 上传大小、执行时间等站点级配置）
- `bin/process-upload-queue.php`（分片视频后台处理脚本）
- `src/`
- `uploads/`（没有也没关系，系统会创建）

### 3. 通过文件管理器初始化配置文件（不使用命令行）

在宝塔 `文件` 中进入后端目录：

1. 复制 `config.example.php` 并重命名为 `config.php`
2. 复制 `.env.example` 并重命名为 `.env`

然后用宝塔在线编辑器修改 `.env`。

说明：

- `config.php` 必须存在，后端通过 `require config.php` 加载配置。
- `config.php` 会读取 `.env`（如果存在），建议生产环境使用 `.env` 注入敏感配置。

### 4. 配置关键参数（至少这些）

编辑 `.env`：

```ini
PEANUT_API_SECRET=请设置强密钥
PEANUT_ADMIN_SECRET=请设置单独的管理员强密钥
PEANUT_PRODUCTION=true
PEANUT_DB_FILE=/www/wwwroot/your-project/data/timeline.db
PEANUT_BASE_URL=https://api.example.com
PEANUT_CORS_ALLOWED_ORIGINS=https://www.example.com,https://admin.example.com
PEANUT_SSL_VERIFY=true
PEANUT_FFMPEG_BIN=/usr/bin/ffmpeg
PEANUT_PHP_CLI_BIN=/usr/bin/php
PEANUT_CHUNK_TMP_DIR=/tmp/peanut-timeline-chunks
PEANUT_PENDING_UPLOAD_DIR=/tmp/peanut-timeline-pending
PEANUT_VIDEO_TMP_DIR=/tmp/peanut-timeline-video
PEANUT_VIDEO_TRANSCODE_PRESET=superfast
PEANUT_VIDEO_TRANSCODE_CRF=24
PEANUT_VIDEO_TRANSCODE_THREADS=2
PEANUT_AMAP_KEY=你的高德Web服务Key
```

常用可选项：

- `PEANUT_DB_FILE` 自定义 SQLite 路径。生产环境建议放在后端 Web 根目录外，例如项目根目录的 `data/timeline.db`。
- `PEANUT_UPLOAD_DIR` 自定义上传目录
- `PEANUT_PHP_CLI_BIN` 指定 PHP CLI 路径，用于后台处理分片视频
- `PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR` 指定上传与视频处理临时目录，建议放本机系统盘
- `PEANUT_THUMB_MAX_WIDTH`、`PEANUT_THUMB_QUALITY` 调整缩略图策略
- `PEANUT_VIDEO_TRANSCODE_PRESET`、`PEANUT_VIDEO_TRANSCODE_CRF`、`PEANUT_VIDEO_TRANSCODE_THREADS` 调整视频转码速度/体积平衡

生产环境建议：

- `PEANUT_API_SECRET` 与 `PEANUT_ADMIN_SECRET` 必须使用不同值。前者只给用户端只读接口，后者只给管理端写操作接口。
- `PEANUT_AMAP_KEY` 可留空；留空时不走高德，会尝试 Nominatim/OpenStreetMap 兜底，解析失败时前端展示经纬度。
- 部署视频上传能力前，先在服务器安装 `ffmpeg`，并在 `.env` 中写入绝对路径，例如 `/usr/bin/ffmpeg`。
- 部署大视频分片上传能力前，确认服务器存在 PHP CLI，并在 `.env` 中写入绝对路径，例如 `/usr/bin/php`。
- PHP 8.0 FPM 若设置了 `disable_functions`，至少保留 `exec` 或 `proc_open` 中的一种。
- 当前项目自带的 `.user.ini` 已包含 `upload_max_filesize=1024M`、`post_max_size=1024M`、`max_execution_time=600` 等配置；复制到新站点后必须把其中的 `open_basedir` 改为实际目录，宝塔面板里的 PHP 配置也应保持不低于实际上传需求。

### 5. 配置 Nginx 伪静态（宝塔面板）

进入 `网站 -> api.example.com -> 设置 -> 伪静态`，选择 `Nginx` 并填入：

```nginx=
client_max_body_size 64m;
fastcgi_read_timeout 1800s;
fastcgi_send_timeout 1800s;

location ~* ^/(config\.php|db\.php|timeline\.db.*|.*\.(?:sqlite|sqlite3|db)(?:-wal|-shm|-journal)?)$ {
  return 404;
}

location ^~ /src/ {
  return 404;
}

location ^~ /bin/ {
  return 404;
}

location / {
  if (!-e $request_filename){
    rewrite  ^(.*)$  /index.php?s=$1  last;   break;
  }
}
location ^~ /uploads/ {
  # 只允许你的前端域名；按实际域名替换这里的 example.com
  if ($http_origin ~* "^https://(admin\.example\.com|www\.example\.com)$") {
    add_header Access-Control-Allow-Origin $http_origin always;
    add_header Vary Origin always;
    add_header Access-Control-Expose-Headers "Content-Length,Content-Range,Accept-Ranges" always;
  }

  # 可选：处理预检
  if ($request_method = OPTIONS) {
    add_header Access-Control-Allow-Origin $http_origin always;
    add_header Access-Control-Allow-Methods "GET,HEAD,OPTIONS" always;
    add_header Access-Control-Allow-Headers "Range,Content-Type" always;
    add_header Content-Length 0;
    add_header Content-Type text/plain;
    return 204;
  }

  try_files $uri =404;
}




```

说明：

- 管理端 H5 对 50MB 及以上视频会自动启用 5MB 分片上传，但 `client_max_body_size` 仍需大于单个分片和普通上传文件大小，建议先设为 `64m`。
- 如果宝塔“伪静态”面板不接受 `client_max_body_size` 或 `fastcgi_*_timeout`，请放到该站点的 Nginx 配置 `server {}` 内。

### 6. 设置权限（宝塔文件面板）

在 `文件` 面板中，重点检查：

- `uploads/` 目录可写
- `data/` 目录可写，`timeline.db`（首次请求后自动创建）可读写
- `.env` 中的 `PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR` 目录可写
- 站点目录属主建议为 PHP-FPM 运行用户（宝塔常见为 `www`）

推荐权限：

- 普通文件：`644`
- 普通目录：`755`
- `uploads/`：`775`（至少可写）
- `data/`：`775`（至少可写，SQLite WAL 会在同目录创建 `-wal` / `-shm` 文件）
- 分片/视频临时目录：`775`（至少可写，且建议位于本机系统盘）

关键点：

- `data/` 目录和 `timeline.db` 必须属于 PHP-FPM 运行用户，或至少让该用户可写。宝塔常见用户是 `www`。
- 只让数据库文件可写不够；SQLite WAL 模式还需要在数据库同目录创建 `timeline.db-wal` 和 `timeline.db-shm`。
- 如果管理端登录提示“密钥无效”，但 `.env` 中的 `PEANUT_ADMIN_SECRET` 确认正确，请先检查 `/verify-admin-key` 是否实际返回 `500`。返回 `500` 且内容包含“数据库目录不可写”时，就是 `data/` 权限问题。

命令行修复示例：

```bash
chown -R www:www /www/wwwroot/your-project/data /www/wwwroot/your-project/photo-timeline-backend/uploads
chmod 775 /www/wwwroot/your-project/data /www/wwwroot/your-project/photo-timeline-backend/uploads
chmod 664 /www/wwwroot/your-project/data/timeline.db
```

如果 PHP 站点启用了 `open_basedir`，还需要把 `data/` 目录加入允许路径。以当前项目结构为例：

```ini
open_basedir=/www/wwwroot/your-project/photo-timeline-backend/:/www/wwwroot/your-project/data/:/tmp/
```

如果临时目录不在 `/tmp` 下，也要把这些目录加入 `open_basedir`。

### 7. 后端连通性验证（无需命令行）

健康检查（浏览器直接访问）：

- `https://api.example.com/`

预期返回 JSON：

```json
{"message":"Peanut Timeline Backend (PHP refactored) is Running!"}
```

鉴权检查（推荐用 Apifox / Postman）：

- URL：`https://api.example.com/verify-key`
- Method：`GET`
- Header：`x-api-key: 你的密钥`

返回 `200` 即正常。

## 三、前端 API 地址

两个前端项目都通过 `src/api.js` 中 `API_BASE` 指向后端地址：

- `photo-timeline-admin/src/api.js`：开发环境走 `/api`，由 `photo-timeline-admin/vite.config.js` 代理到后端；生产环境默认写死后端地址。
- `photo-timeline-uniapp/src/api.js`：默认写死后端地址。

部署到你的域名时，按下面位置调整：

- 管理端生产地址：`photo-timeline-admin/src/api.js` 中的 `https://api.hetao.us` 改为 `https://api.example.com`。
- 管理端开发代理：`photo-timeline-admin/vite.config.js` 中的 `proxy['/api'].target` 改为 `https://api.example.com`。
- 用户端地址：`photo-timeline-uniapp/src/api.js` 中的 `https://api.hetao.us` 改为 `https://api.example.com`。

## 四、打包前端

说明：打包可在本地开发机完成后上传 `dist`，不强制在服务器命令行执行。

如果你在服务器上使用“终端”执行，也可参考：

```bash
# 管理端
cd /www/wwwroot/your-project/photo-timeline-admin
npm ci
npm run build:h5

# 用户端
cd /www/wwwroot/your-project/photo-timeline-uniapp
npm ci
npm run build:h5
```

说明：文档这里使用标准脚本名 `build:h5`。项目里也保留了 `buildh5` 别名，效果相同。

产物目录：

- `photo-timeline-admin/dist/build/h5`
- `photo-timeline-uniapp/dist/build/h5`

## 五、部署前端站点

### 1. 用户端（`www.example.com`）

- 根目录指向 `photo-timeline-uniapp/dist/build/h5`
- 伪静态：

```nginx
location = /index.html {
    expires -1;
    add_header Cache-Control "no-store, no-cache, must-revalidate" always;
}

location ^~ /assets/ {
    expires 365d;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    try_files $uri =404;
    access_log off;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

### 2. 管理端（`admin.example.com`）

- 根目录指向 `photo-timeline-admin/dist/build/h5`
- 伪静态：

```nginx
location = /index.html {
    expires -1;
    add_header Cache-Control "no-store, no-cache, must-revalidate" always;
}

location ^~ /assets/ {
    expires 365d;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    try_files $uri =404;
    access_log off;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

## 六、认证与登录行为（当前实现）

### 1. 管理端登录

- 通过页面按钮 `输入管理员密钥` 打开验证弹窗登录。
- 登录成功后密钥缓存到本地 `peanut_api_key`。
- 退出登录会清理本地密钥。

注意：

- `?key=xxx` 查询参数不会再直接激活管理员模式，会被前端自动清理，避免 URL 泄漏密钥风险。

### 2. 用户端访问

- 通过 `输入密钥` 验证后进入时间轴。
- 本地缓存键名为 `peanut_viewer_key`。

## 七、数据与接口说明

### 1. 鉴权

除根路径健康检查外，业务接口统一要求请求头：

- `x-api-key: <密钥>`

只读接口接受 `PEANUT_API_SECRET` 或 `PEANUT_ADMIN_SECRET`；上传、删除、配置修改等写操作只接受 `PEANUT_ADMIN_SECRET`。

### 2. 主要接口

- `GET /verify-key`
- `GET /verify-admin-key`
- `GET /config`
- `POST /config`
- `GET /items?page=1&limit=5&search=关键词`
- `GET /items/counts?search=关键词`
- `POST /items`
- `PUT /items/{id}`
- `DELETE /items/{id}`
- `POST /items/{id}/restore`
- `DELETE /items/{id}/permanent`
- `GET /trash`
- `POST /empty-trash`
- `POST /upload`
- `POST /upload/init`
- `POST /upload/chunk`
- `POST /upload/complete`
- `GET /upload/status?upload_id=...`
- `POST /upload/cleanup`
- `POST /clear-addresses`
- `POST /refresh-addresses`

### 3. group_id 逻辑（已在后端兜底）

- 新建条目时，如果传入 `group_id` 为空，后端会自动生成。
- 生成格式：`毫秒时间戳-5位随机36进制`。
- 列表聚合按 `COALESCE(NULLIF(group_id, ''), id)` 分组，确保旧数据与空值也可稳定展示。
- 年/月计数接口同样按上述分组口径统计（统计“动态条数”，非媒体文件条数）。

### 4. 关键参数说明（后端接口）

- `GET /items`：`page`（可选，>=1）、`limit`（可选，>0，后端最多取 100）、`search`（可选）。
- `GET /items/counts`：`search`（可选，和 `/items` 同口径过滤）。
- `POST /items`（JSON）：`date`、`src` 必填；`title`、`description`、`thumb`、`group_id`、`latitude`、`longitude`、`taken_at`、`address` 可选。
- `PUT /items/{id}`（JSON）：可更新 `title`、`description`、`date`、`thumb`。有 `group_id` 的动态会把 `title`、`description`、`date` 同步到整组，`thumb` 只更新当前媒体。
- `POST /upload`（form-data）：`file` 必填；`skip_thumb=1` 可选（跳过图片二次缩略图）；`exif_date`、`exif_lat`、`exif_lng` 可选。
- 大视频分片上传：管理端 H5 对 50MB 及以上视频自动使用 5MB 分片，依次调用 `/upload/init`、`/upload/chunk`、`/upload/complete`，再通过 `/upload/status` 轮询后台处理结果。前端会在本地保存 7 天断点信息。
- `POST /upload/cleanup`（JSON）：`urls` 数组。用于删除已上传但未入库的媒体文件；已被时间轴引用的文件不会被删除。

## 八、EXIF 与地址解析策略

上传后提取顺序：

1. 管理端 H5 上传前解析 JPEG EXIF、MP4/MOV 创建时间与 GPS，失败时用 `File.lastModified` 兜底。
2. 服务端保存图片时再尝试 `exif_read_data`（JPEG/TIFF）。
3. 服务端 EXIF 不存在时使用客户端上传的字段（`exif_date`/`exif_lat`/`exif_lng`）。

地址解析：

- 配置了 `PEANUT_AMAP_KEY`：经纬度自动转中文地址
- 未配置：尝试 Nominatim/OpenStreetMap 兜底，解析失败时展示经纬度

## 九、修改记录

- 完整修改记录见根目录 [CHANGELOG.md](../CHANGELOG.md)。
- `2026-04-27` 文档补充：管理端登录“密钥无效”可能是后端 `500` 权限错误导致，需检查 `PEANUT_DB_FILE` 所在 `data/` 目录和 SQLite 文件是否对 PHP-FPM 用户可写。
- `2026-04-24` 部署相关变更：SQLite 数据库外置到 `data/timeline.db`，管理端大视频改为分片上传和 PHP CLI 后台处理，新增分片/待发布/视频临时目录配置，补充 FFmpeg、PHP CLI、`fileinfo`、`.user.ini`、`open_basedir`、Nginx 上传限制与前端静态资源缓存要求。
- 部署时需重点确认 `PEANUT_DB_FILE`、`PEANUT_ADMIN_SECRET`、`PEANUT_PHP_CLI_BIN`、`PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR` 与前端 `API_BASE` 已按实际域名和目录修改。

## 十、常见问题

### 1. 403 无权限

- 检查 `x-api-key` 是否正确。
- 用户端只读访问检查 `PEANUT_API_SECRET` 或 `PEANUT_ADMIN_SECRET` 是否与输入一致。
- 管理端登录、上传、删除、配置修改检查 `PEANUT_ADMIN_SECRET` 是否与输入一致。

### 2. 管理端提示“密钥无效”，但密钥确认正确

- 管理端登录接口是 `GET /verify-admin-key`。前端当前会把验证异常统一提示为“密钥无效”，所以需要看后端真实状态码。
- `403` 才是真正的管理员密钥不匹配。
- `500` 是后端运行错误。若响应内容包含“数据库目录不可写”，检查 `.env` 的 `PEANUT_DB_FILE`、`data/` 目录和 `timeline.db` 权限。
- 确认 `data/` 目录所有者是 PHP-FPM 用户（宝塔常见为 `www`），或至少对该用户可写。SQLite WAL 会在同目录创建 `timeline.db-wal` 和 `timeline.db-shm`。
- 修复权限后，用 Apifox/Postman 请求 `GET https://api.example.com/verify-admin-key`，Header 带 `x-api-key: 管理员密钥`，预期返回 `{"ok":true}`。

### 3. 上传失败 / 数据库只读

- 检查后端目录与 `uploads` 权限。
- 检查 `.env` 的 `PEANUT_DB_FILE` 是否指向可写目录，建议使用后端 Web 根目录外的 `data/timeline.db`。
- 检查 SQLite 文件与 `data/` 目录所有者是否为 PHP-FPM 运行用户（宝塔常见为 `www`）。
- 如果启用了 `open_basedir`，确认其中包含 `data/` 目录。
- 若只有视频上传失败，先执行 `ffmpeg -version` 确认系统已安装 FFmpeg。
- 若命令行里有 `ffmpeg`，但后台仍提示未安装，请把 `.env` 的 `PEANUT_FFMPEG_BIN` 改成绝对路径，例如 `/usr/bin/ffmpeg`。
- 若报命令执行相关错误，检查 PHP 8.0 的 `disable_functions`，确认 `proc_open` 或 `exec` 至少启用一个。
- 若大视频一直显示“服务端处理中”，检查 `.env` 的 `PEANUT_PHP_CLI_BIN` 是否指向可执行 PHP CLI，例如 `/usr/bin/php`。
- 检查 `PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR` 是否可写，并尽量放在本机系统盘；不要放到网盘挂载目录。
- 检查 Nginx `client_max_body_size`、PHP `upload_max_filesize`、`post_max_size` 是否大于 5MB 分片和普通上传文件大小。
- 宝塔/PHP-FPM 环境下如果日志出现 `server reached max_children setting`，说明并发已顶满，需要适当上调对应 PHP 版本 FPM 的 `pm.max_children`。

### 4. 跨域失败

- 检查 `.env` 的 `PEANUT_CORS_ALLOWED_ORIGINS`。
- 检查 Nginx 是否拦截了 `OPTIONS` 请求。

### 5. 页面行为与预期不一致

- 前端更新后请强制刷新浏览器缓存（`Ctrl+F5`）。
- 若仍异常，确认站点根目录是否指向最新 `dist/build/h5`。
