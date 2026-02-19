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

## 准备工作

1.  **服务器要求**:
    *   已安装宝塔面板
    *   已安装 **Nginx/Apache** 和 **PHP 7.4+**

2.  **PHP 扩展要求**:
    *   宝塔 **PHP管理 -> 安装扩展**，确保已安装：
        *   `sqlite3` — SQLite 数据库驱动
        *   `pdo_sqlite` — PDO SQLite 驱动
        *   `exif` — 照片 EXIF 信息提取（时间/GPS）
        *   `gd` — 缩略图生成

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
];
```

### 3. 创建站点

*   宝塔 **网站 -> 添加站点**
*   域名: `api.hetao.us`（或者直接用 IP）
*   PHP版本: 选择 7.4 或 8.0+

### 4. 伪静态配置 (Nginx)

> **⚠️ 必须配置**，否则 API 无法访问（报 404 或 CORS 错误）。

在宝塔 **伪静态** 中填入：

```nginx
location / {
    if (!-e $request_filename){
        rewrite  ^(.*)$  /index.php?s=$1  last;   break;
    }
}
```

### 5. 权限设置（非常重要！）

SQLite 数据库不仅需要文件可写，**所在的文件夹也必须可写**。

```bash
# 在宝塔终端执行（修改路径为你的实际路径）
chown -R www:www /www/wwwroot/api.hetao.us
chmod -R 775 /www/wwwroot/api.hetao.us
chmod -R 777 /www/wwwroot/api.hetao.us/uploads
```

### 6. 验证

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

系统首次运行会自动检查并创建所需的数据表和列。迁移完成后会在后端目录生成 `.db_migrated` 标记文件。如需重新执行迁移（如手动修改了数据库结构），删除该文件即可。

### 6. config.php 丢失

如果访问 API 报 500 错误，请检查后端目录是否存在 `config.php` 文件。该文件不会随 Git 提交，部署时需手动创建。
