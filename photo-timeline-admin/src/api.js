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
export const fetchConfig = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/config`,
            method: 'GET',
            header: { 'x-api-key': apiKey },
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
export const fetchItems = (apiKey, page, limit, search = '') => {
    return new Promise((resolve, reject) => {
        let url = (page > 0 && limit > 0)
            ? `${API_BASE}/items/?page=${page}&limit=${limit}`
            : `${API_BASE}/items/`;

        if (search) {
            const separator = url.includes('?') ? '&' : '?';
            url += `${separator}search=${encodeURIComponent(search)}`;
        }

        uni.request({
            url,
            method: 'GET',
            header: { 'x-api-key': apiKey },
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
 * 获取按 年/月 聚合后的动态总数（不受分页影响）
 */
export const fetchItemCounts = (apiKey, search = '') => {
    return new Promise((resolve, reject) => {
        let url = `${API_BASE}/items/counts`;
        if (search) {
            url += `?search=${encodeURIComponent(search)}`;
        }

        uni.request({
            url,
            method: 'GET',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
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
 * 更新时间轴条目
 */
export const updateItem = (apiKey, id, itemData) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/items/${id}`,
            method: 'PUT',
            header: { 'x-api-key': apiKey, 'Content-Type': 'application/json' },
            data: itemData,
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else {
                    reject(new Error('更新失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 删除时间轴条目（软删除，可从回收站恢复）
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
 * 恢复已软删除的条目
 */
export const restoreItem = (apiKey, id) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/items/${id}/restore`,
            method: 'POST',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 404) {
                    reject(new Error('条目不在回收站中'));
                } else {
                    reject(new Error('恢复失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 彻底删除条目（不可恢复，连同文件一起删除）
 */
export const permanentDeleteItem = (apiKey, id) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/items/${id}/permanent`,
            method: 'DELETE',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
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
 * 获取回收站列表
 */
export const fetchTrash = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/trash`,
            method: 'GET',
            header: { 'x-api-key': apiKey },
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
 * 清空回收站（彻底删除所有已软删条目及文件）
 */
export const emptyTrash = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/empty-trash`,
            method: 'POST',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else {
                    reject(new Error('清空失败'));
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
 * @param {{skipThumb?: boolean}|null} options - 上传选项
 */
export const uploadFile = (apiKey, filePath, fileObj = null, clientExif = null, options = null) => {
    // 将客户端 EXIF 数据作为 formData 字段发送
    const formData = {};
    if (clientExif) {
        if (clientExif.date) formData.exif_date = clientExif.date;
        if (clientExif.latitude != null) formData.exif_lat = String(clientExif.latitude);
        if (clientExif.longitude != null) formData.exif_lng = String(clientExif.longitude);
    }
    if (options && options.skipThumb) {
        formData.skip_thumb = '1';
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
