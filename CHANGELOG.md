# 修改记录

本文件记录当前项目的实现与文档变更，日期按实际维护日期填写。

## 2026-04-24

### 上传与媒体处理

- 管理端 H5 对 50MB 及以上视频启用 5MB 分片上传，单片大小为 5MB。
- 分片上传支持本地断点续传、失败重试，以及通过 `/upload/status` 轮询服务端处理状态。
- 新增 `/upload/init`、`/upload/chunk`、`/upload/complete`、`/upload/status`、`/upload/cleanup` 接口。
- 大视频分片合并与转码支持 PHP CLI 后台处理；后台进程无法启动时返回 `202 processing` 并在当前请求结束后兜底处理。
- 视频处理新增本地临时目录、转码线程数与跨挂载安全写入逻辑，降低网盘挂载目录上的大文件处理压力。
- 管理端会跟踪已上传但未发布的媒体，取消、退出、刷新或发布失败时尝试清理孤儿文件。

### 数据库与配置

- SQLite 默认迁移到项目级 `data/timeline.db`，避免数据库文件暴露在后端 Web 根目录。
- 数据库初始化会确保 `data/` 目录存在且可写，并启用 WAL、`synchronous=NORMAL`、`busy_timeout=5000`。
- 数据库迁移逻辑改为幂等执行，补充时间轴查询所需索引。
- 新增或补充 `PEANUT_DB_FILE`、`PEANUT_ADMIN_SECRET`、`PEANUT_PHP_CLI_BIN`、`PEANUT_CHUNK_TMP_DIR`、`PEANUT_PENDING_UPLOAD_DIR`、`PEANUT_VIDEO_TMP_DIR`、`PEANUT_VIDEO_TRANSCODE_PRESET`、`PEANUT_VIDEO_TRANSCODE_CRF`、`PEANUT_VIDEO_TRANSCODE_THREADS` 等配置说明。
- `.gitignore` 覆盖 `data/timeline.db*`，避免提交 SQLite 数据库和 WAL 相关文件。

### 接口与权限

- 只读接口接受用户密钥或管理员密钥；写操作和 `GET /verify-admin-key` 仅接受管理员密钥。
- `GET /items` 分页参数做非负归一化，并限制 `limit` 最大为 100。
- `POST /items` 与 `PUT /items/{id}` 会认领上传跟踪文件，避免已入库媒体被清理任务误删。
- `PUT /items/{id}` 在条目存在 `group_id` 时同步组级标题、备注和日期，封面只更新当前媒体。
- 时间轴列表与计数按 `COALESCE(NULLIF(group_id, ''), id)` 聚合，兼容旧数据和空 `group_id`。

### 前端与体验

- 年/月默认展开逻辑按“最新月份”处理；最新月份不足 5 条时自动补拉上一月数据。
- 管理端上传大视频时显示服务端处理状态，处理完成后自动加入待发布列表。
- 管理端阻止服务端处理中的媒体被提前发布，避免写入未完成资源。
- 前端 API 地址说明对齐当前源码：管理端开发走 `/api` 代理，管理端生产和用户端默认指向 `https://api.hetao.us`。
- 地址解析说明对齐后端逻辑：高德 Key 为空时尝试 Nominatim/OpenStreetMap 兜底，失败后展示经纬度。

### 部署文档

- 宝塔部署文档补充 FFmpeg、PHP CLI、`fileinfo`、`.user.ini`、`bin/process-upload-queue.php`、Nginx 上传限制、`open_basedir`、临时目录权限和前端静态资源缓存策略。
- README 补充大视频分片上传、数据库外置、管理员密钥、地址解析兜底、API 参数限制与核心数据字段说明。
