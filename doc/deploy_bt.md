# 宝塔面板 (BT Panel) 部署指南（PHP 建站版）

本指南基于当前仓库代码（`photo-timeline-backend`、`photo-timeline-admin`、`photo-timeline-uniapp`）整理，适用于 Linux + 宝塔 + Nginx + PHP 环境。

说明：本版本按“宝塔面板可视化操作”编写，不依赖命令行。

## 项目结构

```text
project-root/
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
└── doc/
    └── deploy_bt.md
```

## 当前功能概览

- 图片/视频上传，支持批量。
- 上传时自动提取 EXIF（拍摄时间、GPS），并生成缩略图。
- 视频封面补生成功能采用单文件模式，避免额外生成冗余 `_thumb` 文件。
- GPS 可通过高德 API 自动解析地址。
- 时间轴分页加载、搜索、分组展示。
- 年/月两级折叠；默认仅展开当前月，当前月不足 5 条时自动补拉上一月数据（直到合计至少 5 条或无更多数据）。
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
- Node.js 18+（用于前端打包）

### 2. PHP 扩展

在宝塔 `PHP管理 -> 安装扩展` 确保启用：

- `sqlite3`
- `pdo_sqlite`
- `exif`
- `gd`
- `curl`

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
PEANUT_PRODUCTION=true
PEANUT_BASE_URL=https://api.example.com
PEANUT_CORS_ALLOWED_ORIGINS=https://www.example.com,https://admin.example.com
PEANUT_SSL_VERIFY=true
PEANUT_AMAP_KEY=你的高德Web服务Key
```

可选：

- `PEANUT_DB_FILE` 自定义 SQLite 路径
- `PEANUT_UPLOAD_DIR` 自定义上传目录
- `PEANUT_THUMB_MAX_WIDTH`、`PEANUT_THUMB_QUALITY` 调整缩略图策略

### 5. 配置 Nginx 伪静态（宝塔面板）

进入 `网站 -> api.example.com -> 设置 -> 伪静态`，选择 `Nginx` 并填入：

```nginx=

location / {
  if (!-e $request_filename){
    rewrite  ^(.*)$  /index.php?s=$1  last;   break;
  }
}
location ^~ /uploads/ {
  # 只允许你的前端域名
  if ($http_origin ~* "^https://(admin\.hetao\.us|hetao\.us)$") {
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

### 6. 设置权限（宝塔文件面板）

在 `文件` 面板中，重点检查：

- `uploads/` 目录可写
- `timeline.db`（首次请求后自动创建）可读写
- 站点目录属主建议为 `www`

推荐权限：

- 普通文件：`644`
- 普通目录：`755`
- `uploads/`：`775`（至少可写）

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

- `photo-timeline-admin/src/api.js`
- `photo-timeline-uniapp/src/api.js`

确保为：

```js
const API_BASE = 'https://api.example.com';
```

## 四、打包前端

说明：打包可在本地开发机完成后上传 `dist`，不强制在服务器命令行执行。

如果你在服务器上使用“终端”执行，也可参考：

```bash
# 管理端
cd /www/wwwroot/your-project/photo-timeline-admin
npm install
npm run buildh5

# 用户端
cd /www/wwwroot/your-project/photo-timeline-uniapp
npm install
npm run buildh5
```

说明：`buildh5` 为项目脚本，等价于 `npm run build:h5`。

产物目录：

- `photo-timeline-admin/dist/build/h5`
- `photo-timeline-uniapp/dist/build/h5`

## 五、部署前端站点

### 1. 用户端（`www.example.com`）

- 根目录指向 `photo-timeline-uniapp/dist/build/h5`
- 伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### 2. 管理端（`admin.example.com`）

- 根目录指向 `photo-timeline-admin/dist/build/h5`
- 伪静态：

```nginx
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

- `x-api-key: <PEANUT_API_SECRET>`

### 2. 主要接口

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

### 3. group_id 逻辑（已在后端兜底）

- 新建条目时，如果传入 `group_id` 为空，后端会自动生成。
- 生成格式：`毫秒时间戳-5位随机36进制`。
- 列表聚合按 `COALESCE(NULLIF(group_id, ''), id)` 分组，确保旧数据与空值也可稳定展示。
- 年/月计数接口同样按上述分组口径统计（统计“动态条数”，非媒体文件条数）。

### 4. 关键参数说明（后端接口）

- `GET /items`：`page`（可选，>=1）、`limit`（可选，>0）、`search`（可选）。
- `GET /items/counts`：`search`（可选，和 `/items` 同口径过滤）。
- `POST /items`（JSON）：`date`、`src` 必填；`description`、`thumb`、`group_id`、`latitude`、`longitude`、`taken_at` 可选。
- `PUT /items/{id}`（JSON）：可更新 `title`、`description`、`date`、`thumb`。
- `POST /upload`（form-data）：`file` 必填；`skip_thumb=1` 可选（跳过图片二次缩略图）；`exif_date`、`exif_lat`、`exif_lng` 可选。

## 八、EXIF 与地址解析策略

上传后提取顺序：

1. 服务端 `exif_read_data`（JPEG/TIFF）
2. 客户端上传的 EXIF 字段（`exif_date`/`exif_lat`/`exif_lng`）
3. 无 EXIF 时由前端侧兜底时间逻辑（项目内实现）

地址解析：

- 配置了 `PEANUT_AMAP_KEY`：经纬度自动转中文地址
- 未配置：展示经纬度

## 九、常见问题

### 1. 403 无权限

- 检查 `x-api-key` 是否正确。
- 检查后端 `PEANUT_API_SECRET` 是否与前端输入一致。

### 2. 上传失败 / 数据库只读

- 检查后端目录与 `uploads` 权限。
- 检查 SQLite 文件与目录所有者是否为 `www`。

### 3. 跨域失败

- 检查 `.env` 的 `PEANUT_CORS_ALLOWED_ORIGINS`。
- 检查 Nginx 是否拦截了 `OPTIONS` 请求。

### 4. 页面行为与预期不一致

- 前端更新后请强制刷新浏览器缓存（`Ctrl+F5`）。
- 若仍异常，确认站点根目录是否指向最新 `dist/build/h5`。
