# 记录时间轴

一个支持图片/视频发布、按时间轴浏览、EXIF 元数据提取与地理位置展示的三端项目。

- 用户端：输入密钥后浏览时间轴
- 管理端：上传、编辑、删除、回收站、全局配置
- 后端：PHP + SQLite API，负责鉴权、上传与数据聚合

## 仓库地址（GitHub）

- SSH：`git@github.com:duanxinyua/record-timeline.git`
- HTTPS：`https://github.com/duanxinyua/record-timeline.git`

## 项目结构

```text
project-root/
├── data/                         # SQLite 数据库目录（不放在后端 Web 根目录）
├── photo-timeline-backend/      # PHP 后端 API
├── photo-timeline-admin/        # 管理端（uni-app H5）
├── photo-timeline-uniapp/       # 用户端（uni-app H5）
├── CHANGELOG.md                 # 修改记录
└── doc/deploy_bt.md             # 宝塔部署文档
```

## 功能特性

- 图片/视频上传（支持批量）
- 大视频上传支持 5MB 分片、断点续传与服务端后台处理，降低网盘挂载目录上的长连接压力
- 自动提取 EXIF（拍摄时间、GPS）
- 自动生成图片缩略图；视频支持封面补生成功能
- 视频封面补生成功能采用单文件模式（避免生成冗余 `_thumb` 文件）
- 时间轴分页加载、关键词搜索
- 多媒体分组发布（同一组媒体聚合为一条动态）
- 年/月两级折叠；默认只展开最新月份，最新月份不足 5 条会自动补拉上一月到至少 5 条（或无更多数据）
- 年/月后缀显示动态总数（全量统计，不受当前分页加载数量影响）
- 图片预览支持左右滑动，并采用懒加载（只渲染当前与相邻图片）
- 用户端与管理端配置独立浏览器标签页图标，包含 32x32、64x64 与 Apple Touch Icon
- 管理端支持软删除、恢复、彻底删除、清空回收站
- 管理端回收站“彻底删除/清空”使用页面内确认弹窗，避免层级遮挡
- 全局文案与分页参数可在线配置（`/config`）

## 技术栈

- 前端：`uni-app` + `Vue 3` + `Vite`
- 后端：原生 `PHP`（无框架）+ `SQLite`
- 媒体处理：`EXIF`、`GD`、`cURL`、`FFmpeg`

## 运行环境要求

- PHP 7.4+（推荐 8.x）
- 扩展：`sqlite3`、`pdo_sqlite`、`exif`、`gd`、`curl`（建议开启 `fileinfo`）
- FFmpeg：视频转码需要
- PHP CLI：大视频分片后台处理需要
- Node.js 18+

## 快速开始（本地）

### 1. 克隆项目

```bash
git clone git@github.com:duanxinyua/record-timeline.git
cd record-timeline
```

### 2. 配置后端

```bash
cd photo-timeline-backend
cp config.example.php config.php
cp .env.example .env
```

编辑 `photo-timeline-backend/.env`，至少配置：

```ini
PEANUT_API_SECRET=your-strong-secret
PEANUT_ADMIN_SECRET=your-strong-admin-secret
PEANUT_PRODUCTION=false
PEANUT_DB_FILE=/path/to/project/data/timeline.db
PEANUT_BASE_URL=http://127.0.0.1:8000
PEANUT_CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
PEANUT_SSL_VERIFY=true
PEANUT_FFMPEG_BIN=/usr/bin/ffmpeg
PEANUT_PHP_CLI_BIN=/usr/bin/php
PEANUT_CHUNK_TMP_DIR=/tmp/peanut-timeline-chunks
PEANUT_PENDING_UPLOAD_DIR=/tmp/peanut-timeline-pending
PEANUT_VIDEO_TMP_DIR=/tmp/peanut-timeline-video
PEANUT_VIDEO_TRANSCODE_PRESET=superfast
PEANUT_VIDEO_TRANSCODE_CRF=24
PEANUT_VIDEO_TRANSCODE_THREADS=2
PEANUT_AMAP_KEY=
```

参数作用说明：

- `PEANUT_API_SECRET`：用户端只读密钥。用户端通过 `x-api-key` 传递，仅能访问 `GET /items`、`GET /config` 等只读接口。
- `PEANUT_ADMIN_SECRET`：管理员密钥。管理端专用，可访问上传、删除、配置修改等写操作接口。未配置时自动降级为与 `PEANUT_API_SECRET` 相同（向后兼容，生产环境强烈建议单独配置）。
- `PEANUT_PRODUCTION`：是否生产环境。`true` 时后端会隐藏详细错误，仅返回通用错误信息。
- `PEANUT_DB_FILE`：SQLite 数据库文件路径。建议放到后端 Web 根目录外，例如项目根目录的 `data/timeline.db`。
- `PEANUT_BASE_URL`：后端对外访问基准地址。用于生成上传文件的完整访问链接。
- `PEANUT_CORS_ALLOWED_ORIGINS`：允许跨域访问的前端域名白名单，多个域名用英文逗号分隔。
- `PEANUT_SSL_VERIFY`：后端请求外部 HTTPS 服务时是否校验证书，生产环境建议保持 `true`。
- `PEANUT_FFMPEG_BIN`：`ffmpeg` 可执行文件路径。视频自动转码依赖它。生产环境建议直接写绝对路径，例如 `/usr/bin/ffmpeg`，避免 PHP-FPM 的 `PATH` 与命令行环境不一致。
- `PEANUT_PHP_CLI_BIN`：PHP CLI 可执行文件路径。用于在分片上传完成后拉起后台处理进程，建议写绝对路径。
- `PEANUT_CHUNK_TMP_DIR`：分片上传临时目录。建议放本机系统盘，不要放到 Web 根目录或网盘挂载目录。
- `PEANUT_PENDING_UPLOAD_DIR`：已上传但尚未提交入库媒体的跟踪目录。前端取消发布或过期后会用于清理孤儿文件。
- `PEANUT_VIDEO_TMP_DIR`：视频合并、转码的本地临时目录。建议放本机系统盘，避免跨挂载移动大文件。
- `PEANUT_VIDEO_TRANSCODE_PRESET`：视频转码速度档位。未设置时默认 `veryfast`，低配服务器可用 `superfast` 降低单次处理压力。
- `PEANUT_VIDEO_TRANSCODE_CRF`：视频转码清晰度/体积平衡参数。未设置时默认 `23`，数值越大体积越小、清晰度越低。
- `PEANUT_VIDEO_TRANSCODE_THREADS`：视频转码线程数。低配机器建议 `1`，2 核机器可先用 `2`。
- `PEANUT_AMAP_KEY`：高德地图 Web Service Key。用于将 EXIF 坐标解析为中文地址；留空则不走高德解析，会尝试 Nominatim/OpenStreetMap 兜底。后端会在中国大陆范围内先将照片 EXIF 的 `WGS84` 坐标转换为高德使用的 `GCJ-02`，以减少地址偏移。

视频转码运行前提：

- 服务器需安装 `ffmpeg`，并保证 `PEANUT_FFMPEG_BIN` 指向可执行文件。
- PHP 运行环境至少要允许一种命令执行能力：`exec` 或 `proc_open`。若两者都被禁用，需转码的视频会返回明确错误。
- 大视频分片上传完成后会优先使用 PHP CLI 后台处理；若无法拉起后台进程，会先返回 `202 processing`，再在当前请求结束后兜底处理。
- `PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR` 必须对 PHP-FPM 运行用户可写。生产环境建议放本机系统盘，不放网盘挂载目录。
- 当前后端会尽量规避 `PATH` 为空、跨挂载 `rename` 失败或 `open_basedir` 导致的常见部署问题。

### 3. 启动管理端

```bash
cd photo-timeline-admin
npm install
npm run dev:h5
```

### 4. 启动用户端

```bash
cd photo-timeline-uniapp
npm install
npm run dev:h5
```

后端启动与部署请参考 `doc/deploy_bt.md`（宝塔面板方式，不使用 PHP 命令行部署）。

## 打包构建

```bash
# 管理端
cd photo-timeline-admin
npm ci
npm run build:h5

# 用户端
cd ../photo-timeline-uniapp
npm ci
npm run build:h5
```

说明：文档这里使用标准脚本名 `build:h5`。项目里也保留了 `buildh5` 别名，效果相同。

输出目录：

- `photo-timeline-admin/dist/build/h5`
- `photo-timeline-uniapp/dist/build/h5`

## 浏览器标签页图标

两个 H5 项目的浏览器标签页图标资产分别放在：

- `photo-timeline-admin/src/static/favicon-32.png`
- `photo-timeline-admin/src/static/favicon-64.png`
- `photo-timeline-admin/src/static/apple-touch-icon.png`
- `photo-timeline-uniapp/src/static/favicon-32.png`
- `photo-timeline-uniapp/src/static/favicon-64.png`
- `photo-timeline-uniapp/src/static/apple-touch-icon.png`

两个项目的 `index.html` 使用相同引用：

```html
<link rel="icon" type="image/png" sizes="32x32" href="./static/favicon-32.png?v=20260515r2" />
<link rel="icon" type="image/png" sizes="64x64" href="./static/favicon-64.png?v=20260515r2" />
<link rel="shortcut icon" type="image/png" href="./static/favicon-32.png?v=20260515r2" />
<link rel="apple-touch-icon" sizes="180x180" href="./static/apple-touch-icon.png?v=20260515r2" />
```

图标文件为圆角 RGBA PNG。替换图标时只提交 `src/static` 下的小尺寸图标文件，不提交仓库根目录导出的高清源图、SVG 或临时图片。

## 前端 API 地址

两个前端当前通过源码中的 `API_BASE` 访问后端：

- `photo-timeline-admin/src/api.js`：开发环境走 `/api`，由 `photo-timeline-admin/vite.config.js` 代理到 `https://api.hetao.us`；生产环境默认 `https://api.hetao.us`。
- `photo-timeline-uniapp/src/api.js`：默认 `https://api.hetao.us`。

请根据部署环境把这些地址或代理目标改为你的后端地址（例如 `https://api.example.com`）：

- 管理端生产地址：`photo-timeline-admin/src/api.js` 中的 `https://api.hetao.us`。
- 管理端开发代理：`photo-timeline-admin/vite.config.js` 中的 `proxy['/api'].target`。
- 用户端地址：`photo-timeline-uniapp/src/api.js` 中的 `https://api.hetao.us`。

## 主要接口

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

说明：除根路径健康检查外，业务接口都需要请求头 `x-api-key`。只读接口（`GET /items`、`GET /items/counts`、`GET /config`、`GET /verify-key`）接受用户密钥或管理员密钥；`GET /verify-admin-key` 与写操作接口仅接受管理员密钥（`PEANUT_ADMIN_SECRET`）。

接口参数（关键）：

- `GET /items`：`page`（可选，>=1）、`limit`（可选，>0，后端最多取 100）、`search`（可选，标题/备注/地址模糊搜索）。
- `GET /items/counts`：`search`（可选，和 `/items` 同口径过滤）。
- `POST /items`（JSON）：`date`、`src` 必填；`title`、`description`、`thumb`、`group_id`、`latitude`、`longitude`、`taken_at`、`address` 可选。
- `PUT /items/{id}`（JSON）：可更新 `title`、`description`、`date`、`thumb`。有 `group_id` 的动态会把 `title`、`description`、`date` 同步到整组，`thumb` 只更新当前媒体。
- `POST /upload`（form-data）：`file` 必填；`skip_thumb=1` 可选（跳过图片二次缩略图生成）；`exif_date`、`exif_lat`、`exif_lng` 可选。
- 大视频分片上传：管理端 H5 会对 50MB 及以上视频自动使用 5MB 分片，流程为 `POST /upload/init` 初始化、`POST /upload/chunk` 上传分片、`POST /upload/complete` 提交合并转码、`GET /upload/status` 轮询状态。前端本地保存断点信息，7 天内重新选择同一文件可继续上传。
- `POST /upload/cleanup`（JSON）：`urls` 数组。用于删除已上传但未入库的媒体文件；已被时间轴引用的文件不会被删除。
- `POST /clear-addresses`：清空当前已缓存的地址文本，不改经纬度。
- `POST /refresh-addresses`：按当前坐标系规则重新解析所有带坐标条目的地址，并直接回写数据库。
- 视频上传说明：后端会尽量统一输出为 `MP4 (H.264/AVC + AAC)`，以提升手机和 PC 浏览器兼容性；未安装 `ffmpeg`、`PEANUT_FFMPEG_BIN` 配置错误，或 PHP 同时禁用了 `proc_open`/`exec` 时，需转码的视频会上传失败并返回明确错误。

## 地址与坐标说明

- 照片 EXIF 中的 GPS 坐标通常是 `WGS84`。管理端 H5 上传前还会解析 JPEG EXIF、MP4/MOV 创建时间与 GPS，解析失败时用 `File.lastModified` 兜底。
- 高德地图逆地理和 H5 地图落点使用的是 `GCJ-02`。
- 后端在调用高德逆地理前，会自动将中国大陆范围内的 `WGS84` 转成 `GCJ-02`；中国大陆以外则保持原坐标不变。
- 前端点击“查看地图”时，也会按相同规则转换，避免“地址文本正确但地图落点仍偏移”。

如果你已经在旧版本中缓存过地址：

- 新上传的图片/视频会直接使用修正后的地址解析逻辑。
- 历史记录不会自动重算，需要手动刷新。
- 管理端时间轴右上角有一个 `📍` 按钮，会调用 `POST /refresh-addresses` 重新生成历史地址。
- 如果你只想删除地址缓存而不立即重建，可以调用 `POST /clear-addresses`。

## 数据说明（核心）

`timelineitem` 主要字段：

- `title`：标题（兼容旧数据，当前前端默认写空）
- `description`：内容备注（当前前端主字段）
- `date`：时间轴排序时间（ISO 字符串）
- `src`：原图/视频地址
- `thumb`：缩略图/封面地址
- `latitude` / `longitude`：经纬度
- `address`：按经纬度解析出的地址文本
- `taken_at`：拍摄时间（EXIF）
- `group_id`：多媒体分组 ID（同组聚合为一条动态）
- `deleted_at`：软删除时间（回收站）

分组计数说明：

- `GET /items/counts` 返回按动态分组后的年/月统计。
- 计数口径与列表一致：按 `group_id`（为空时回退 `id`）计动态条数，而非媒体文件条数。

## 部署

生产部署可参考：

- `doc/deploy_bt.md`

该文档包含宝塔 + Nginx + PHP 的完整配置步骤（伪静态、权限、前后端部署），并提供“纯宝塔面板操作”流程，不依赖命令行。

## 故障排查

### 管理端提示“密钥无效”

管理端登录会请求 `GET /verify-admin-key`。如果前端提示“密钥无效”，先区分响应状态：

- `403`：管理员密钥不匹配，检查 `.env` 的 `PEANUT_ADMIN_SECRET`。
- `500`：后端运行错误，前端也可能显示为“密钥无效”。常见原因是 SQLite 数据目录不可写。

SQLite 使用 WAL 时会在数据库同目录创建 `timeline.db-wal` 和 `timeline.db-shm`，所以 `PEANUT_DB_FILE` 指向的目录必须对 PHP-FPM 运行用户可写。宝塔常见 PHP-FPM 用户为 `www`，修复示例：

```bash
chown -R www:www /www/wwwroot/your-project/data /www/wwwroot/your-project/photo-timeline-backend/uploads
chmod 775 /www/wwwroot/your-project/data /www/wwwroot/your-project/photo-timeline-backend/uploads
chmod 664 /www/wwwroot/your-project/data/timeline.db
```

修复后可用接口验证：

```bash
curl -k -sS -H "x-api-key: 管理员密钥" https://api.example.com/verify-admin-key
```

预期返回：

```json
{"ok":true}
```

## 修改记录

- 完整记录见 [CHANGELOG.md](CHANGELOG.md)。
- 最近一次记录为 `2026-05-15`：用户端与管理端新增圆角浏览器标签页图标。

## 安全建议

- 使用强密钥并定期轮换 `PEANUT_API_SECRET` 和 `PEANUT_ADMIN_SECRET`，两者务必设置不同的值
- 严格配置 `PEANUT_CORS_ALLOWED_ORIGINS`
- 不要提交以下文件到 GitHub（已在 `.gitignore` 处理）：

```text
.env
photo-timeline-backend/config.php
data/timeline.db*
photo-timeline-backend/uploads/
image_GPT_yuan.svg
/*.png
```

## 开发说明

- 代码采用“前后端分仓内聚”结构，前端为两个独立 uni-app 项目
- 后端为轻量 MVC 风格（`Controllers` / `Models` / `Utils`）
- 当前仓库未内置自动化测试，可先通过接口联调与页面回归进行验证
