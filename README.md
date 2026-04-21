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
├── photo-timeline-backend/      # PHP 后端 API
├── photo-timeline-admin/        # 管理端（uni-app H5）
├── photo-timeline-uniapp/       # 用户端（uni-app H5）
└── doc/deploy_bt.md             # 宝塔部署文档
```

## 功能特性

- 图片/视频上传（支持批量）
- 自动提取 EXIF（拍摄时间、GPS）
- 自动生成图片缩略图；视频支持封面补生成功能
- 视频封面补生成功能采用单文件模式（避免生成冗余 `_thumb` 文件）
- 时间轴分页加载、关键词搜索
- 多媒体分组发布（同一组媒体聚合为一条动态）
- 年/月两级折叠；默认只展开当前月，当前月不足 5 条会自动补拉上一月到至少 5 条（或无更多数据）
- 年/月后缀显示动态总数（全量统计，不受当前分页加载数量影响）
- 图片预览支持左右滑动，并采用懒加载（只渲染当前与相邻图片）
- 管理端支持软删除、恢复、彻底删除、清空回收站
- 管理端回收站“彻底删除/清空”使用页面内确认弹窗，避免层级遮挡
- 全局文案与分页参数可在线配置（`/config`）

## 技术栈

- 前端：`uni-app` + `Vue 3` + `Vite`
- 后端：原生 `PHP`（无框架）+ `SQLite`
- 媒体处理：`EXIF`、`GD`、`cURL`

## 运行环境要求

- PHP 7.4+（推荐 8.x）
- 扩展：`sqlite3`、`pdo_sqlite`、`exif`、`gd`、`curl`
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
PEANUT_BASE_URL=http://127.0.0.1:8000
PEANUT_CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
PEANUT_SSL_VERIFY=true
PEANUT_FFMPEG_BIN=/usr/bin/ffmpeg
PEANUT_AMAP_KEY=
```

参数作用说明：

- `PEANUT_API_SECRET`：用户端只读密钥。用户端通过 `x-api-key` 传递，仅能访问 `GET /items`、`GET /config` 等只读接口。
- `PEANUT_ADMIN_SECRET`：管理员密钥。管理端专用，可访问上传、删除、配置修改等写操作接口。未配置时自动降级为与 `PEANUT_API_SECRET` 相同（向后兼容，生产环境强烈建议单独配置）。
- `PEANUT_PRODUCTION`：是否生产环境。`true` 时后端会隐藏详细错误，仅返回通用错误信息。
- `PEANUT_BASE_URL`：后端对外访问基准地址。用于生成上传文件的完整访问链接。
- `PEANUT_CORS_ALLOWED_ORIGINS`：允许跨域访问的前端域名白名单，多个域名用英文逗号分隔。
- `PEANUT_SSL_VERIFY`：后端请求外部 HTTPS 服务时是否校验证书，生产环境建议保持 `true`。
- `PEANUT_FFMPEG_BIN`：`ffmpeg` 可执行文件路径。视频自动转码依赖它。生产环境建议直接写绝对路径，例如 `/usr/bin/ffmpeg`，避免 PHP-FPM 的 `PATH` 与命令行环境不一致。
- `PEANUT_VIDEO_TRANSCODE_PRESET`：视频转码速度档位，默认 `veryfast`。
- `PEANUT_VIDEO_TRANSCODE_CRF`：视频转码清晰度/体积平衡参数，默认 `23`。
- `PEANUT_AMAP_KEY`：高德地图 Web Service Key。用于将 EXIF 坐标解析为中文地址；留空则不走高德解析。后端会在中国大陆范围内先将照片 EXIF 的 `WGS84` 坐标转换为高德使用的 `GCJ-02`，以减少地址偏移。

视频转码运行前提：

- 服务器需安装 `ffmpeg`，并保证 `PEANUT_FFMPEG_BIN` 指向可执行文件。
- PHP 运行环境至少要允许一种命令执行能力：`proc_open` 或 `exec`。若两者都被禁用，需转码的视频会返回明确错误。
- 当前后端会优先使用 `proc_open`，其次回退到 `exec`，并尽量规避 `PATH` 为空或 `open_basedir` 导致的常见部署问题。

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
npm install
npm run buildh5

# 用户端
cd ../photo-timeline-uniapp
npm install
npm run buildh5
```

说明：`buildh5` 已在两个前端 `package.json` 中提供，等价于 `npm run build:h5`。

输出目录：

- `photo-timeline-admin/dist/build/h5`
- `photo-timeline-uniapp/dist/build/h5`

## 前端 API 地址

两个前端当前通过源码中的 `API_BASE` 访问后端：

- `photo-timeline-admin/src/api.js`
- `photo-timeline-uniapp/src/api.js`

请根据部署环境修改为你的后端地址（例如 `https://api.example.com`）。

## 主要接口

- `GET /verify-key`
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
- `POST /clear-addresses`
- `POST /refresh-addresses`

说明：除根路径健康检查外，业务接口都需要请求头 `x-api-key`。只读接口（`GET /items`、`GET /items/counts`、`GET /config`、`GET /verify-key`）接受用户密钥或管理员密钥；写操作接口仅接受管理员密钥（`PEANUT_ADMIN_SECRET`）。

接口参数（关键）：

- `GET /items`：`page`（可选，>=1）、`limit`（可选，>0）、`search`（可选，标题/备注/地址模糊搜索）。
- `GET /items/counts`：`search`（可选，和 `/items` 同口径过滤）。
- `POST /items`（JSON）：`date`、`src` 必填；`description`、`thumb`、`group_id`、`latitude`、`longitude`、`taken_at` 可选。
- `PUT /items/{id}`（JSON）：可更新 `title`、`description`、`date`、`thumb`。
- `POST /upload`（form-data）：`file` 必填；`skip_thumb=1` 可选（跳过图片二次缩略图生成）；`exif_date`、`exif_lat`、`exif_lng` 可选。
- `POST /clear-addresses`：清空当前已缓存的地址文本，不改经纬度。
- `POST /refresh-addresses`：按当前坐标系规则重新解析所有带坐标条目的地址，并直接回写数据库。
- 视频上传说明：后端会尽量统一输出为 `MP4 (H.264/AVC + AAC)`，以提升手机和 PC 浏览器兼容性；未安装 `ffmpeg`、`PEANUT_FFMPEG_BIN` 配置错误，或 PHP 同时禁用了 `proc_open`/`exec` 时，需转码的视频会上传失败并返回明确错误。

## 地址与坐标说明

- 照片 EXIF 中的 GPS 坐标通常是 `WGS84`。
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

## 安全建议

- 使用强密钥并定期轮换 `PEANUT_API_SECRET` 和 `PEANUT_ADMIN_SECRET`，两者务必设置不同的值
- 严格配置 `PEANUT_CORS_ALLOWED_ORIGINS`
- 不要提交以下文件到 GitHub（已在 `.gitignore` 处理）：

```text
.env
photo-timeline-backend/config.php
photo-timeline-backend/timeline.db*
photo-timeline-backend/uploads/
```

## 开发说明

- 代码采用“前后端分仓内聚”结构，前端为两个独立 uni-app 项目
- 后端为轻量 MVC 风格（`Controllers` / `Models` / `Utils`）
- 当前仓库未内置自动化测试，可先通过接口联调与页面回归进行验证
