/**
 * api.js - API 服务模块
 * 用户端精简版
 */

const API_BASE = 'https://api.hetao.us';

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
                } else {
                    reject(new Error('AUTH_FAILED'));
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
 * 获取时间轴条目（支持分页）
 */
export const fetchItems = (apiKey, page = 0, limit = 0) => {
    return new Promise((resolve, reject) => {
        const url = (page > 0 && limit > 0)
            ? `${API_BASE}/items/?page=${page}&limit=${limit}`
            : `${API_BASE}/items/`;

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
