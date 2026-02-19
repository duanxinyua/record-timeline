/**
 * utils.js - 共享工具函数
 * 管理后台与用户端共用
 */

/**
 * 格式化日期为中文格式
 * @param {string} value - ISO 日期字符串
 * @param {string} fallback - 无效时间时的默认文本
 * @returns {string}
 */
export const formatDate = (value, fallback = '未知时间') => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return date.toLocaleString("zh-CN", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
};

/**
 * 判断 URL 是否为视频文件
 * @param {string} url
 * @returns {boolean}
 */
export const isVideo = (url) => {
    if (!url) return false;
    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    return ['mp4', 'mov', 'webm', 'avi', 'm4v', '3gp'].includes(ext);
};

/**
 * 预览图片
 * @param {string} url - 图片 URL
 */
export const previewImage = (url, allUrls = []) => {
    const urls = allUrls.length > 0 ? allUrls : [url];
    uni.previewImage({ current: url, urls });
};
