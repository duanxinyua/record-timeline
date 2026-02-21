# 宝塔面板 (BT Panel) 部署指南

本指南基于当前仓库代码（`photo-timeline-backend`、`photo-timeline-admin`、`photo-timeline-uniapp`）整理，适用于 Linux + 宝塔 + Nginx + PHP 环境。

## 项目结构

```text
hetao.us/
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

### 1. 上传后端代码

将 `photo-timeline-backend` 部署到站点目录，例如：

- `/www/wwwroot/api.hetao.us/`

### 2. 初始化配置文件

在后端目录执行（或手动复制）：

```bash
cd /www/wwwroot/api.hetao.us
cp config.example.php config.php
cp .env.example .env
```

说明：

- `config.php` 必须存在，后端通过 `require config.php` 加载配置。
- `config.php` 会读取 `.env`（如果存在），建议生产环境使用 `.env` 注入敏感配置。

### 3. 配置关键参数（至少这些）

编辑 `.env`：

```ini
PEANUT_API_SECRET=请设置强密钥
PEANUT_PRODUCTION=true
PEANUT_BASE_URL=https://api.hetao.us
PEANUT_CORS_ALLOWED_ORIGINS=https://hetao.us,https://admin.hetao.us
PEANUT_SSL_VERIFY=true
PEANUT_AMAP_KEY=你的高德Web服务Key
```

可选：

- `PEANUT_DB_FILE` 自定义 SQLite 路径
- `PEANUT_UPLOAD_DIR` 自定义上传目录
- `PEANUT_THUMB_MAX_WIDTH`、`PEANUT_THUMB_QUALITY` 调整缩略图策略

### 4. Nginx 伪静态

在后端站点伪静态中配置：

```nginx
location / {
    if (!-e $request_filename){
        rewrite  ^(.*)$  /index.php?s=$1  last;
        break;
    }
}
```

### 5. 权限

```bash
chown -R www:www /www/wwwroot/api.hetao.us
chmod -R 775 /www/wwwroot/api.hetao.us
chmod -R 777 /www/wwwroot/api.hetao.us/uploads
```

### 6. 后端连通性验证

健康检查：

```bash
curl -s https://api.hetao.us/
```

预期返回：

```json
{"message":"Peanut Timeline Backend (PHP refactored) is Running!"}
```

鉴权检查：

```bash
curl -i -H "x-api-key: 你的密钥" https://api.hetao.us/verify-key
```

## 三、前端 API 地址

两个前端项目都通过 `src/api.js` 中 `API_BASE` 指向后端地址：

- `photo-timeline-admin/src/api.js`
- `photo-timeline-uniapp/src/api.js`

确保为：

```js
const API_BASE = 'https://api.hetao.us';
```

## 四、打包前端

```bash
# 管理端
cd /www/wwwroot/hetao.us/photo-timeline-admin
npm ci
npm run build:h5

# 用户端
cd /www/wwwroot/hetao.us/photo-timeline-uniapp
npm ci
npm run build:h5
```

产物目录：

- `photo-timeline-admin/dist/build/h5`
- `photo-timeline-uniapp/dist/build/h5`

## 五、部署前端站点

### 1. 用户端（`hetao.us`）

- 根目录指向 `photo-timeline-uniapp/dist/build/h5`
- 伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### 2. 管理端（`admin.hetao.us`）

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
