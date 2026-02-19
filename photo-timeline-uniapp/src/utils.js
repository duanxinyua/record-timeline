/**
 * utils.js - 共享工具函数
 */

/**
 * 格式化日期为中文格式
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
 */
export const isVideo = (url) => {
    if (!url) return false;
    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    return ['mp4', 'mov', 'webm', 'avi', 'm4v', '3gp'].includes(ext);
};

/**
 * 预览图片
 */
export const previewImage = (url) => {
    uni.previewImage({ urls: [url] });
};
