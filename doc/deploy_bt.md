# 宝塔面板 (BT Panel) 部署指南

本指南指导您部署 **Photo Timeline** 的 PHP 后端和 Uni-app 前端。

---

## 准备工作

1.  **服务器要求**:
    *   已安装宝塔面板.
    *   已安装 **Nginx/Apache** 和 **PHP 7.4+**.

---

## 第一步：部署后端 (PHP)

1.  **上传代码**:
    *   将 `photo-timeline-backend` 文件夹内的内容上传到网站根目录 (例如 `/www/wwwroot/timeline.com/`，或者子目录 `/www/wwwroot/timeline.com/api`).
    
2.  **创建站点**:
    *   宝塔 **网站 -> 添加站点**。
    *   域名: `api.hetao.us` (或者直接用 IP).
    *   PHP版本: 选择 7.4 或 8.0+.
        *   **重要**: 必须配置伪静态，否则 API 无法访问 (报 404 或 CORS 错误)。
        *   您可以直接复制 `photo-timeline-backend/nginx.conf` 文件中的内容。
        *   或者手动填入：
        ```nginx
        location / {
            if (!-e $request_filename){
                rewrite  ^(.*)$  /index.php?s=$1  last;   break;
            }
        }
        ```
    *   **目录权限**:
        *   确保 `uploads` 目录和 `timeline.db` 文件有写入权限 (755 或 777, 用户 www)。

3.  **验证**:
    *   访问 `http://您的域名/` 或 `http://您的域名/index.php`。
    *   应看到 `{"message": "Peanut Timeline Backend (PHP) is Running!"}`.

---

## 第二步：前端修改

前端依然是 Uni-app H5，但如果后端地址变了，需要更新 `getApiBaseUrl`。

**文件**: `src/pages/index/index.vue` (两个项目都要改)。

```javascript
const getApiBaseUrl = () => {
    // 指向新的 PHP 站点地址
    return 'http://api.hetao.us'; 
    // 或者如果是本地测试 PHPStudy:
    // return 'http://localhost/test/duanxinyu/photo-timeline-backend';
};
```

---

## 第三步：打包前端 (同上)

打包步骤与之前相同 (HBuilderX 发行 H5)。

---

## 常见问题 (PHP)

1.  **SQLite 驱动缺失**:
    *   宝塔 **PHP管理 -> 安装扩展**，确保安装了 `sqlite3` 或 `pdo_sqlite`。
    
2.  **文件上传大小限制**:
    *   宝塔 **PHP管理 -> 配置修改**，调整 `upload_max_filesize` 和 `post_max_size` (建议 10M+)。

3.  **跨域 (CORS)**:
    *   `index.php` 已经内置了 CORS 头，一般不需要额外配置 Nginx 跨域，除非 Nginx 拦截了 OPTIONS 请求。
