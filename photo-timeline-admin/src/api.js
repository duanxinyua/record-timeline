/**
 * api.js - API 服务模块
 * 管理后台专用，包含所有后端通信逻辑
 */

// API 基础地址
const API_BASE = 'https://api.hetao.us';

/**
 * 获取 API 基础地址
 */
export const getApiBaseUrl = () => API_BASE;

/**
 * 验证 API Key 是否有效
 */
export const verifyKey = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/verify-key`,
            method: 'GET',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(true);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else {
                    reject(new Error('验证失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 获取应用配置
 */
export const fetchConfig = () => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/config`,
            method: 'GET',
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else {
                    reject(new Error('获取配置失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 保存应用配置
 */
export const saveConfig = (apiKey, configData) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/config`,
            method: 'POST',
            header: { 'x-api-key': apiKey },
            data: configData,
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else {
                    reject(new Error('保存失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 获取时间轴条目（分页）
 */
export const fetchItems = (page, limit) => {
    return new Promise((resolve, reject) => {
        const url = (page > 0 && limit > 0)
            ? `${API_BASE}/items/?page=${page}&limit=${limit}`
            : `${API_BASE}/items/`;

        uni.request({
            url,
            method: 'GET',
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else {
                    reject(new Error('加载失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 创建时间轴条目
 */
export const createItem = (apiKey, itemData) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/items/`,
            method: 'POST',
            header: { 'x-api-key': apiKey },
            data: itemData,
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else {
                    reject(new Error('创建失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 删除时间轴条目
 */
export const deleteItem = (apiKey, id) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/items/${id}`,
            method: 'DELETE',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else if (res.statusCode === 404) {
                    reject(new Error('条目不存在'));
                } else {
                    reject(new Error('删除失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 上传文件
 * @param {string} apiKey
 * @param {string} filePath
 * @param {File|null} fileObj
 * @param {{date?: string, latitude?: number, longitude?: number}|null} clientExif - 客户端提取的 EXIF
 */
export const uploadFile = (apiKey, filePath, fileObj = null, clientExif = null) => {
    // 将客户端 EXIF 数据作为 formData 字段发送
    const formData = {};
    if (clientExif) {
        if (clientExif.date) formData.exif_date = clientExif.date;
        if (clientExif.latitude != null) formData.exif_lat = String(clientExif.latitude);
        if (clientExif.longitude != null) formData.exif_lng = String(clientExif.longitude);
    }

    return new Promise((resolve, reject) => {
        uni.uploadFile({
            url: `${API_BASE}/upload`,
            filePath: filePath,
            file: fileObj,
            name: 'file',
            header: { 'x-api-key': apiKey },
            formData: formData,
            success: (uploadRes) => {
                if (uploadRes.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                    return;
                }
                try {
                    const data = JSON.parse(uploadRes.data);
                    if (data.error || data.detail) {
                        reject(new Error(data.detail || data.error));
                    } else {
                        resolve(data);
                    }
                } catch (e) {
                    reject(new Error('解析响应失败'));
                }
            },
            fail: () => reject(new Error('上传失败'))
        });
    });
};
