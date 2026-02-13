# 宝塔面板 (BT Panel) 部署指南

本指南将指导您如何在宝塔面板中各部署 **后端API** 和 **两个前端应用 (展示端 & 管理端)**。

---

## 准备工作

1.  **服务器要求**:
    *   CentOS 7+ / Ubuntu 20.04+ Recommended.
    *   已安装宝塔面板 (建议版本 8.0+).
2.  **宝塔软件商店安装**:
    *   **Nginx** (任意稳定版本，如 1.22).
    *   **Python项目管理器** (推荐 2.0+ 版本，界面友好).
3.  **开放端口**:
    *   请在 **宝塔面板 -> 安全** 和 **云服务商防火墙 (阿里云/腾讯云)** 中放行以下端口：
        *   `8000`: 后端 API 端口.
        *   `80`: 前端 H5 默认端口 (如果您使用默认 Web 服务).

---

## 第一步：部署后端 (Python FastAPI)

1.  **上传代码**:
    *   在宝塔面板 **文件** 中，进入 `/www/wwwroot/`。
    *   创建一个新文件夹，例如 `photo-timeline-backend`。
    *   将本地项目中的 `photo-timeline-backend` 文件夹内的所有文件上传到该目录。
    *   确保目录包含 `main.py` 和 `requirements.txt`。

2.  **创建 Python 项目**:
    *   打开 **Python项目管理器**。
    *   点击 **添加项目**。
    *   **项目名称**: `photo-timeline` (任意).
    *   **路径**: 选择刚才上传的 `/www/wwwroot/photo-timeline-backend`。
    *   **Python版本**: 推荐选择 `Python 3.8` 或更高 (如果没有，去版本管理里安装一个)。
    *   **框架**: `FastAPI` (如果没有选项，选 `Other` 或手动启动)。
    *   **启动方式**: `Gunicorn` 或 `Uvicorn`。
        *   建议使用自定义启动命令: `uvicorn main:app --host 0.0.0.0 --port 8000`
    *   **端口**: `8000`.
    *   勾选 **安装依赖** (它会自动识别 requirements.txt)。
    *   点击 **确定**。

3.  **验证**:
    *   在浏览器访问 `http://服务器IP:8000/`。
    *   如果看到 `{"message": "Peanut Timeline Backend..."}`，说明后端部署成功！

---

## 第二步：打包前端 (Uni-app H5)

您需要在本地电脑上将两个 Uni-app 项目打包成 H5 静态文件。

### 1. 修改接口地址 (重要！)
**展示端 (`photo-timeline-uniapp`) & 管理端 (`photo-timeline-admin`)**:

打开 `src/pages/index/index.vue`，找到 `getApiBaseUrl` 函数。

**方法 A (简单 - 使用端口)**:
如果您保持使用 8000 端口：
```javascript
const getApiBaseUrl = () => {
    // 假设您前端和后端部署在同一服务器
    return 'http://您的服务器IP:8000'; 
    // 或者如果配合域名：
    // return 'https://api.yourdomain.com';
};
```
*注意：如果前端配置了 HTTPS，后端也必须配置 SSL 证书，否则会报错。*

**方法 B (推荐 - Nginx 反向代理)**:
如果您想用 `/api` 代理 (避免暴露 8000 端口)：
```javascript
const getApiBaseUrl = () => {
    return '/api'; 
};
```
*(选择方法 B 需要在 Nginx 配置文件中添加 proxy_pass，见后文高级设置)*

### 2. 执行打包
在 HBuilderX 中：
*   点击菜单 **发行 -> 网站-PC Web或手机H5 (仅uniapp)**。
*   **网站标题**: 花生时间轴。
*   **网站域名**: 可以留空，或填 `/`。
*   点击 **发行**。

等待编译完成，控制台会输出路径，通常在 `unpackage/dist/build/h5`。

**请分别打包**:
*   `photo-timeline-uniapp` -> 得到 H5 文件包 A。
*   `photo-timeline-admin` -> 得到 H5 文件包 B。

---

## 第三步：部署前端 (Nginx)

您可以选择部署为两个不同的网站，或者两个子目录。这里推荐 **子目录** 或 **不同端口** 方案。

### 方案 A：单域名 + 子目录 (推荐)
假设您的域名是 `timeline.com`。

1.  **上传文件**:
    *   后端根目录 `/www/wwwroot/photo_site`。
    *   创建 `viewer` 文件夹，上传 **文件包 A** (展示端) 的内容。
    *   创建 `admin` 文件夹，上传 **文件包 B** (管理端) 的内容。

2.  **创建站点**:
    *   宝塔 **网站 -> 添加站点**。
    *   域名: `timeline.com`.
    *   根目录: `/www/wwwroot/photo_site`.

3.  **配置 Nginx (伪静态)**:
    由于 Uni-app 是单页应用 (SPA)，如果使用 `history` 模式 (默认是 hash 模式 `/#/`，如果是 hash 模式则不需要此步)，需要配置伪静态。如果您的链接里有 `#` 号 (如 `domain.com/#/pages/index/index`)，则跳过此步，直接访问即可。

    *   访问展示端: `http://timeline.com/viewer`
    *   访问管理端: `http://timeline.com/admin`

    *(注：如果 H5 发行时配置了 `运行的基础路径` 为 ./，则可以直接放子目录运行)*

### 方案 B：双端口 (简单粗暴)
如果不配置域名，直接用 IP。

1.  **展示端网站**:
    *   添加站点，IP: `80` (默认)。
    *   目录指向 **文件包 A**。
2.  **管理端网站**:
    *   添加站点，IP: `8080` (需要放行端口 8080)。
    *   目录指向 **文件包 B**。

---

## 高级设置：Nginx 反代后端 (可选)

为了安全和解决跨域/HTTPS 问题，建议在 Nginx 中配置反向代理，而不是直接访问 8000 端口。

在宝塔网站设置 -> **配置文件** 或 **反向代理** 中添加：

```nginx
location /api/ {
    proxy_pass http://127.0.0.1:8000/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

*   **注意**: 如果这样配置，前端代码里的 `API_BASE` 应该改为 `'/api'` (或者 `window.location.origin + '/api'`)。
*   且后端 `main.py` 不需要修改，因为 `/api/config` 会被代理到 `http://127.0.0.1:8000/config`。

---

## 常见问题

1.  **上传图片失败 403**:
    *   检查密钥 (Magic Key) 是否过期。
    *   检查服务器 `uploads` 目录是否有写入权限 (宝塔里给 `755` 或 `777` 权限，用户 `www`)。

2.  **无法连接后端**:
    *   检查后端是否在运行 (`ps -ef | grep python`)。
    *   检查 8000 端口是否放行。
    *   检查前端 API 地址是否正确指向服务器 IP。

3.  **页面刷新 404**:
    *   这是单页应用常见问题。在宝塔网站设置 -> 配置文件中添加：
    ```nginx
    location / {
      try_files $uri $uri/ /index.html;
    }
    ```
