/**
 * api.js - API 服务模块
 * 管理后台专用，包含所有后端通信逻辑
 */

// API 基础地址（开发环境走本地代理，避免跨域）
const API_BASE = import.meta.env.DEV ? '/api' : 'https://api.hetao.us';
const LARGE_VIDEO_CHUNK_SIZE = 5 * 1024 * 1024;
const CHUNK_UPLOAD_MAX_RETRIES = 3;
const CHUNK_COMPLETE_MAX_RETRIES = 2;
const CHUNK_RETRY_BASE_DELAY_MS = 800;
const CHUNK_STATUS_POLL_INTERVAL_MS = 1500;
const CHUNK_STATUS_POLL_TIMEOUT_MS = 30 * 60 * 1000;
const CHUNK_RESUME_STORAGE_KEY = 'peanut_chunk_upload_resume_map';
const CHUNK_RESUME_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * 获取 API 基础地址
 */
export const getApiBaseUrl = () => API_BASE;

const applyUploadFormData = (formData, clientExif = null, options = null) => {
    if (clientExif) {
        if (clientExif.date) formData.append('exif_date', clientExif.date);
        if (clientExif.latitude != null) formData.append('exif_lat', String(clientExif.latitude));
        if (clientExif.longitude != null) formData.append('exif_lng', String(clientExif.longitude));
    }
    if (options && options.skipThumb) {
        formData.append('skip_thumb', '1');
    }
    return formData;
};

const createApiError = (message, statusCode = 0, code = 'REQUEST_FAILED') => {
    const error = new Error(message);
    error.statusCode = Number(statusCode || 0);
    error.code = code;
    return error;
};

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const isRetryableUploadError = (error) => {
    const statusCode = Number(error && error.statusCode);
    const code = error && error.code;

    if (!statusCode) {
        return code === 'NETWORK_ERROR' || code === 'REQUEST_FAILED';
    }

    return statusCode >= 500 || statusCode === 408 || statusCode === 409 || statusCode === 425 || statusCode === 429;
};

const withRetries = async (runner, {
    attempts = 1,
    shouldRetry = () => false,
    onRetry = null
} = {}) => {
    let lastError = null;

    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            return await runner(attempt);
        } catch (error) {
            lastError = error;
            if (attempt >= attempts || !shouldRetry(error)) {
                throw error;
            }

            if (typeof onRetry === 'function') {
                onRetry({ attempt, nextAttempt: attempt + 1, error });
            }

            const waitMs = Math.min(4000, CHUNK_RETRY_BASE_DELAY_MS * Math.pow(2, attempt - 1));
            await delay(waitMs);
        }
    }

    throw lastError || createApiError('上传失败');
};

const parseJsonResponse = async (response) => {
    const rawText = await response.text();
    let data = null;
    try {
        data = rawText ? JSON.parse(rawText) : null;
    } catch (e) {
        data = null;
    }
    return { data, rawText };
};

const postJson = async (url, apiKey, payload, options = {}) => {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'x-api-key': apiKey
            },
            body: JSON.stringify(payload || {}),
            keepalive: !!options.keepalive
        });
    } catch (error) {
        throw createApiError('网络异常，请求失败', 0, 'NETWORK_ERROR');
    }

    const { data, rawText } = await parseJsonResponse(response);

    if (response.status === 403) {
        throw createApiError('AUTH_FAILED', 403, 'AUTH_FAILED');
    }

    if (!response.ok || (data && (data.error || data.detail))) {
        throw createApiError((data && (data.detail || data.error)) || rawText || '请求失败', response.status, 'REQUEST_FAILED');
    }

    return data || {};
};

const postFormDataJson = async (url, apiKey, formData) => {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'x-api-key': apiKey },
            body: formData
        });
    } catch (error) {
        throw createApiError('网络异常，上传失败', 0, 'NETWORK_ERROR');
    }

    const { data, rawText } = await parseJsonResponse(response);

    if (response.status === 403) {
        throw createApiError('AUTH_FAILED', 403, 'AUTH_FAILED');
    }

    if (!response.ok || (data && (data.error || data.detail))) {
        if (response.status === 413) {
            throw createApiError('分片仍然超过服务器限制，请调大站点上传上限', 413, 'PAYLOAD_TOO_LARGE');
        }
        throw createApiError((data && (data.detail || data.error)) || rawText || '上传失败', response.status, 'REQUEST_FAILED');
    }

    return data || {};
};

const getJson = async (url, apiKey) => {
    let response;
    try {
        response = await fetch(url, {
            method: 'GET',
            headers: { 'x-api-key': apiKey }
        });
    } catch (error) {
        throw createApiError('网络异常，状态查询失败', 0, 'NETWORK_ERROR');
    }

    const { data, rawText } = await parseJsonResponse(response);

    if (response.status === 403) {
        throw createApiError('AUTH_FAILED', 403, 'AUTH_FAILED');
    }

    if (!response.ok || (data && (data.error || data.detail))) {
        throw createApiError((data && (data.detail || data.error)) || rawText || '状态查询失败', response.status, 'REQUEST_FAILED');
    }

    return data || {};
};

const getChunkResumeStore = () => {
    try {
        const stored = uni.getStorageSync(CHUNK_RESUME_STORAGE_KEY);
        if (!stored || typeof stored !== 'object' || Array.isArray(stored)) {
            return {};
        }

        const now = Date.now();
        const normalized = {};
        Object.keys(stored).forEach((key) => {
            const item = stored[key];
            if (!item || typeof item !== 'object') {
                return;
            }

            const uploadId = String(item.uploadId || '').trim();
            const updatedAt = Number(item.updatedAt || 0);
            if (!uploadId) {
                return;
            }
            if (updatedAt > 0 && (now - updatedAt) > CHUNK_RESUME_MAX_AGE_MS) {
                return;
            }

            normalized[key] = {
                uploadId,
                totalChunks: Number(item.totalChunks || 0),
                updatedAt
            };
        });

        return normalized;
    } catch (error) {
        return {};
    }
};

const saveChunkResumeStore = (store) => {
    try {
        if (!store || typeof store !== 'object' || Object.keys(store).length === 0) {
            uni.removeStorageSync(CHUNK_RESUME_STORAGE_KEY);
            return;
        }
        uni.setStorageSync(CHUNK_RESUME_STORAGE_KEY, store);
    } catch (error) {
        // ignore storage failures
    }
};

const buildChunkResumeFingerprint = (fileObj, clientExif = null, options = null) => {
    const exifDate = clientExif && clientExif.date ? String(clientExif.date) : '';
    const exifLat = clientExif && clientExif.latitude != null ? String(clientExif.latitude) : '';
    const exifLng = clientExif && clientExif.longitude != null ? String(clientExif.longitude) : '';

    return [
        String(fileObj && fileObj.name || ''),
        String(fileObj && fileObj.size || 0),
        String(fileObj && fileObj.lastModified || 0),
        String(fileObj && fileObj.type || ''),
        options && options.skipThumb ? '1' : '0',
        exifDate,
        exifLat,
        exifLng
    ].join('::');
};

const getChunkResumeEntry = (fingerprint) => {
    const store = getChunkResumeStore();
    return fingerprint && store[fingerprint] ? store[fingerprint] : null;
};

const saveChunkResumeEntry = (fingerprint, uploadId, totalChunks) => {
    if (!fingerprint || !uploadId) {
        return;
    }

    const store = getChunkResumeStore();
    store[fingerprint] = {
        uploadId: String(uploadId),
        totalChunks: Number(totalChunks || 0),
        updatedAt: Date.now()
    };
    saveChunkResumeStore(store);
};

const removeChunkResumeEntry = (fingerprint) => {
    if (!fingerprint) {
        return;
    }

    const store = getChunkResumeStore();
    if (!Object.prototype.hasOwnProperty.call(store, fingerprint)) {
        return;
    }

    delete store[fingerprint];
    saveChunkResumeStore(store);
};

const buildUploadedChunkIndexSet = (statusData, totalChunks) => {
    const uploaded = new Set();
    const limit = Math.max(0, Number(totalChunks || 0));
    const rawIndexes = Array.isArray(statusData && statusData.uploaded_chunk_indexes)
        ? statusData.uploaded_chunk_indexes
        : [];

    if (rawIndexes.length > 0) {
        rawIndexes.forEach((chunkIndex) => {
            const normalized = Number(chunkIndex);
            if (!Number.isInteger(normalized) || normalized < 0) {
                return;
            }
            if (limit > 0 && normalized >= limit) {
                return;
            }
            uploaded.add(normalized);
        });
        return uploaded;
    }

    const uploadedChunks = Math.max(0, Math.min(limit, Number(statusData && statusData.uploaded_chunks || 0)));
    for (let chunkIndex = 0; chunkIndex < uploadedChunks; chunkIndex++) {
        uploaded.add(chunkIndex);
    }

    return uploaded;
};

/**
 * 验证管理员密钥是否有效（仅管理员密钥通过）
 */
export const verifyKey = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/verify-admin-key`,
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
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
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
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
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
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
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
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else {
                    reject(new Error('清空失败'));
                }
            },
            fail: (e) => reject(e)
        });
    });
};

/**
 * 使用当前坐标系规则重建所有地址缓存
 */
export const refreshAddresses = (apiKey) => {
    return new Promise((resolve, reject) => {
        uni.request({
            url: `${API_BASE}/refresh-addresses`,
            method: 'POST',
            header: { 'x-api-key': apiKey },
            success: (res) => {
                if (res.statusCode === 200) {
                    resolve(res.data);
                } else if (res.statusCode === 403) {
                    reject(new Error('AUTH_FAILED'));
                } else {
                    reject(new Error('刷新地址失败'));
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
                    reject(createApiError('AUTH_FAILED', 403, 'AUTH_FAILED'));
                    return;
                }
                if (uploadRes.statusCode === 413) {
                    reject(createApiError('文件超过服务器允许大小', 413, 'PAYLOAD_TOO_LARGE'));
                    return;
                }
                try {
                    const data = JSON.parse(uploadRes.data);
                    if (data.error || data.detail) {
                        reject(createApiError(data.detail || data.error, uploadRes.statusCode, 'REQUEST_FAILED'));
                    } else {
                        resolve(data);
                    }
                } catch (e) {
                    reject(createApiError('解析响应失败', uploadRes.statusCode, 'REQUEST_FAILED'));
                }
            },
            fail: () => reject(createApiError('上传失败', 0, 'NETWORK_ERROR'))
        });
    });
};

const fetchChunkUploadStatus = (apiKey, uploadId) => {
    return getJson(`${API_BASE}/upload/status?upload_id=${encodeURIComponent(uploadId)}`, apiKey);
};

const waitForChunkUploadReady = async (apiKey, uploadId, totalChunks, onProgress = null) => {
    const deadline = Date.now() + CHUNK_STATUS_POLL_TIMEOUT_MS;

    while (Date.now() < deadline) {
        const statusData = await withRetries(
            () => fetchChunkUploadStatus(apiKey, uploadId),
            {
                attempts: CHUNK_COMPLETE_MAX_RETRIES,
                shouldRetry: isRetryableUploadError
            }
        );

        if (typeof onProgress === 'function') {
            onProgress({
                phase: statusData.status || 'processing',
                uploadedChunks: Number(statusData.uploaded_chunks || totalChunks || 0),
                totalChunks: Number(statusData.total_chunks || totalChunks || 0),
                message: statusData.status === 'ready' ? '服务端处理完成' : '服务端处理中'
            });
        }

        if (statusData.status === 'ready' && statusData.response) {
            return statusData.response;
        }

        if (statusData.status === 'failed') {
            throw createApiError(statusData.detail || '上传失败，请重新上传', 500, 'REQUEST_FAILED');
        }

        await delay(CHUNK_STATUS_POLL_INTERVAL_MS);
    }

    throw createApiError('服务端处理超时，请稍后查看上传结果', 504, 'REQUEST_FAILED');
};

export const waitForLargeUploadProcessing = (apiKey, uploadId, totalChunks, onProgress = null) => {
    return waitForChunkUploadReady(apiKey, uploadId, totalChunks, onProgress);
};

const resolveChunkUploadSession = async (apiKey, fileObj, totalSize, totalChunks, clientExif = null, options = null, onProgress = null) => {
    const fingerprint = buildChunkResumeFingerprint(fileObj, clientExif, options);
    const resumeEntry = getChunkResumeEntry(fingerprint);

    if (typeof onProgress === 'function') {
        onProgress({
            phase: 'checking',
            uploadedChunks: 0,
            totalChunks,
            progress: 0,
            message: '检查断点'
        });
    }

    if (resumeEntry && resumeEntry.uploadId) {
        try {
            const statusData = await withRetries(
                () => fetchChunkUploadStatus(apiKey, resumeEntry.uploadId),
                {
                    attempts: CHUNK_COMPLETE_MAX_RETRIES,
                    shouldRetry: isRetryableUploadError
                }
            );
            const status = String(statusData && statusData.status || '');
            const remoteTotalChunks = Number(statusData && statusData.total_chunks || 0);

            if (status === 'ready' && statusData.response) {
                removeChunkResumeEntry(fingerprint);
                return {
                    fingerprint,
                    uploadId: resumeEntry.uploadId,
                    completedResponse: statusData.response,
                    statusData,
                    uploadedChunkIndexes: buildUploadedChunkIndexSet(statusData, totalChunks)
                };
            }

            if ((status === 'uploading' || status === 'processing') && (!remoteTotalChunks || remoteTotalChunks === totalChunks)) {
                saveChunkResumeEntry(fingerprint, resumeEntry.uploadId, totalChunks);
                return {
                    fingerprint,
                    uploadId: resumeEntry.uploadId,
                    statusData,
                    uploadedChunkIndexes: buildUploadedChunkIndexSet(statusData, totalChunks)
                };
            }

            removeChunkResumeEntry(fingerprint);
        } catch (error) {
            if (Number(error && error.statusCode) === 404) {
                removeChunkResumeEntry(fingerprint);
            } else {
                throw error;
            }
        }
    }

    const initFormData = applyUploadFormData(new FormData(), clientExif, options);
    initFormData.append('filename', fileObj.name || 'video.mp4');
    initFormData.append('filesize', String(totalSize));
    initFormData.append('total_chunks', String(totalChunks));
    initFormData.append('mime_type', fileObj.type || '');

    const initData = await withRetries(
        () => postFormDataJson(`${API_BASE}/upload/init`, apiKey, initFormData),
        {
            attempts: CHUNK_COMPLETE_MAX_RETRIES,
            shouldRetry: isRetryableUploadError
        }
    );
    const uploadId = initData && initData.upload_id;

    if (!uploadId) {
        throw createApiError('初始化分片上传失败', 500, 'REQUEST_FAILED');
    }

    saveChunkResumeEntry(fingerprint, uploadId, totalChunks);

    return {
        fingerprint,
        uploadId,
        statusData: null,
        uploadedChunkIndexes: new Set()
    };
};

export const uploadLargeVideoInChunks = async (apiKey, fileObj, clientExif = null, options = null, onProgress = null) => {
    const totalSize = Number(fileObj && fileObj.size);
    if (!fileObj || !totalSize || totalSize <= 0) {
        throw createApiError('视频文件无效', 400, 'REQUEST_FAILED');
    }

    const awaitProcessing = !(options && options.awaitProcessing === false);
    const totalChunks = Math.max(1, Math.ceil(totalSize / LARGE_VIDEO_CHUNK_SIZE));
    const session = await resolveChunkUploadSession(apiKey, fileObj, totalSize, totalChunks, clientExif, options, onProgress);
    const fingerprint = session && session.fingerprint;
    const uploadId = session && session.uploadId;

    if (!uploadId) {
        throw createApiError('初始化分片上传失败', 500, 'REQUEST_FAILED');
    }

    if (session && session.completedResponse) {
        return session.completedResponse;
    }

    if (session && session.statusData && session.statusData.status === 'processing') {
        if (!awaitProcessing) {
            return {
                processing: true,
                status: 'processing',
                uploadId,
                totalChunks,
                fingerprint
            };
        }

        const response = await waitForChunkUploadReady(apiKey, uploadId, totalChunks, onProgress);
        removeChunkResumeEntry(fingerprint);
        return response;
    }

    const uploadedChunkIndexes = session && session.uploadedChunkIndexes instanceof Set
        ? session.uploadedChunkIndexes
        : new Set();

    if (typeof onProgress === 'function' && uploadedChunkIndexes.size > 0) {
        onProgress({
            phase: 'uploading',
            uploadedChunks: uploadedChunkIndexes.size,
            totalChunks,
            progress: uploadedChunkIndexes.size / totalChunks,
            message: `继续上传 ${uploadedChunkIndexes.size}/${totalChunks}`
        });
    }

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        if (uploadedChunkIndexes.has(chunkIndex)) {
            continue;
        }

        const start = chunkIndex * LARGE_VIDEO_CHUNK_SIZE;
        const end = Math.min(totalSize, start + LARGE_VIDEO_CHUNK_SIZE);
        const chunkBlob = fileObj.slice(start, end);
        await withRetries(
            () => {
                const chunkFormData = new FormData();
                chunkFormData.append('upload_id', uploadId);
                chunkFormData.append('chunk_index', String(chunkIndex));
                chunkFormData.append('file', chunkBlob, fileObj.name || `chunk-${chunkIndex}.part`);
                return postFormDataJson(`${API_BASE}/upload/chunk`, apiKey, chunkFormData);
            },
            {
                attempts: CHUNK_UPLOAD_MAX_RETRIES,
                shouldRetry: isRetryableUploadError,
                onRetry: ({ nextAttempt }) => {
                    if (typeof onProgress === 'function') {
                        onProgress({
                            phase: 'retry',
                            uploadedChunks: chunkIndex,
                            totalChunks,
                            message: `第 ${chunkIndex + 1} 片重试 ${nextAttempt}/${CHUNK_UPLOAD_MAX_RETRIES}`
                        });
                    }
                }
            }
        );

        uploadedChunkIndexes.add(chunkIndex);
        saveChunkResumeEntry(fingerprint, uploadId, totalChunks);

        if (typeof onProgress === 'function') {
            onProgress({
                phase: 'uploading',
                uploadedChunks: uploadedChunkIndexes.size,
                totalChunks,
                progress: uploadedChunkIndexes.size / totalChunks,
                message: `${Math.max(1, Math.min(100, Math.round((uploadedChunkIndexes.size / totalChunks) * 100)))}%`
            });
        }
    }

    const completeFormData = new FormData();
    completeFormData.append('upload_id', uploadId);

    const completeData = await withRetries(
        () => postFormDataJson(`${API_BASE}/upload/complete`, apiKey, completeFormData),
        {
            attempts: CHUNK_COMPLETE_MAX_RETRIES,
            shouldRetry: isRetryableUploadError
        }
    );

    if (completeData && completeData.status === 'processing' && completeData.upload_id) {
        if (!awaitProcessing) {
            return {
                processing: true,
                status: 'processing',
                uploadId: completeData.upload_id,
                totalChunks,
                fingerprint
            };
        }

        if (typeof onProgress === 'function') {
            onProgress({
                phase: 'processing',
                uploadedChunks: totalChunks,
                totalChunks,
                progress: 1,
                message: '服务端处理中'
            });
        }
        const response = await waitForChunkUploadReady(apiKey, completeData.upload_id, totalChunks, onProgress);
        removeChunkResumeEntry(fingerprint);
        return response;
    }

    removeChunkResumeEntry(fingerprint);
    return completeData;
};

const normalizeCleanupUrls = (urls) => {
    if (!Array.isArray(urls)) return [];

    return Array.from(new Set(
        urls
            .map((url) => (typeof url === 'string' ? url.trim() : ''))
            .filter(Boolean)
    ));
};

export const cleanupUploadedFiles = async (apiKey, urls, options = {}) => {
    const normalizedUrls = normalizeCleanupUrls(urls);
    if (!apiKey || normalizedUrls.length === 0) {
        return {
            requested: normalizedUrls.length,
            deleted: 0,
            missing: 0,
            referenced: 0,
            requested_urls: normalizedUrls,
            referenced_urls: []
        };
    }

    const chunkSize = 200;
    const summary = {
        requested: normalizedUrls.length,
        deleted: 0,
        missing: 0,
        referenced: 0,
        requested_urls: normalizedUrls,
        referenced_urls: []
    };

    for (let i = 0; i < normalizedUrls.length; i += chunkSize) {
        const chunk = normalizedUrls.slice(i, i + chunkSize);
        const result = await postJson(`${API_BASE}/upload/cleanup`, apiKey, { urls: chunk }, options);
        summary.deleted += Number(result.deleted || 0);
        summary.missing += Number(result.missing || 0);
        summary.referenced += Number(result.referenced || 0);
        if (Array.isArray(result.referenced_urls) && result.referenced_urls.length > 0) {
            summary.referenced_urls.push(...result.referenced_urls);
        }
    }

    summary.referenced_urls = normalizeCleanupUrls(summary.referenced_urls);
    return summary;
};
