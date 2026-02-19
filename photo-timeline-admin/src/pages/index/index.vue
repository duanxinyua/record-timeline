<template>
  <view class="container">
    <view class="bg-noise" aria-hidden="true"></view>
    <view class="hero">
      <view class="hero-copy">
        <text class="kicker">{{ appConfig.kicker }}</text>
        <view class="h1">{{ appConfig.mainTitle }}</view>
        <view class="sub">
          <text>{{ appConfig.subTitle }}</text>
        </view>
      </view>

      <!-- 管理员上传区域 -->
      <view class="upload-card" v-if="isAdmin">
        <view class="field">
          <text class="label-text">时间</text>
          <view class="datetime-group">
            <picker mode="date" :value="dateValue" @change="bindDateChange">
              <view class="input-picker">
                {{ dateValue || '选择日期' }}
              </view>
            </picker>
            <picker mode="time" :value="timeValue" @change="bindTimeChange">
              <view class="input-picker">
                {{ timeValue || '选择时间' }}
              </view>
            </picker>
          </view>
        </view>
        <view class="field">
          <text class="label-text">标题（可选）</text>
          <input
            class="uni-input"
            v-model="captionValue"
            placeholder="例如：毕业旅行 / 项目发布"
          />
        </view>
        <view class="field">
          <text class="label-text">说点什么（可选）</text>
          <textarea
            class="uni-textarea"
            v-model="descriptionValue"
            placeholder="记录当时的心情..."
            :maxlength="500"
            auto-height
          />
        </view>
        <view class="field">
          <view class="field-header">
            <text class="label-text">照片/视频</text>
            <text class="clear-all-btn" v-if="batchList.length > 0" @click="clearSelection">清除全部({{ batchList.length }})</text>
          </view>
          <view class="upload-area" :class="{ 'has-items': batchList.length > 0 }">
            <!-- 已上传文件预览网格 -->
            <view class="preview-grid" v-if="batchList.length > 0">
              <view class="preview-item" v-for="(item, index) in batchList" :key="index">
                <video
                  v-if="isVideo(item.src)"
                  :src="item.src"
                  class="preview-media"
                  :controls="false"
                  :show-center-play-btn="false"
                ></video>
                <image
                  v-else
                  :src="item.thumb || item.src"
                  mode="aspectFill"
                  class="preview-media"
                  @click.stop="previewImage(item.src)"
                ></image>
                <view class="preview-remove" @click.stop="removeFromBatch(index)">✕</view>
                <view class="video-badge" v-if="isVideo(item.src)">
                  <text class="video-badge-text">VIDEO</text>
                </view>
              </view>
              <!-- 添加更多 -->
              <!-- #ifdef H5 -->
              <view class="preview-item add-more-card" @click="showAddMenu">
                <text class="add-icon">+</text>
                <text class="add-text">添加</text>
              </view>
              <!-- #endif -->
              <!-- #ifndef H5 -->
              <view class="preview-item add-more-card" @click="chooseMedia">
                <text class="add-icon">+</text>
                <text class="add-text">添加</text>
              </view>
              <!-- #endif -->
            </view>

            <!-- 空状态：初始上传触发 -->
            <template v-else>
              <!-- #ifdef H5 -->
              <view class="h5-triggers">
                <view class="trigger-btn" @click="triggerH5Input('camera', 'image')">
                  <text class="trigger-icon">📷</text>
                  <text>拍照片</text>
                </view>
                <view class="trigger-btn" @click="triggerH5Input('camera', 'video')">
                  <text class="trigger-icon">📹</text>
                  <text>拍视频</text>
                </view>
                <view class="trigger-btn" @click="triggerH5Input('album', 'image')">
                  <text class="trigger-icon">🖼️</text>
                  <text>传照片</text>
                </view>
                <view class="trigger-btn" @click="triggerH5Input('album', 'video')">
                  <text class="trigger-icon">🎞️</text>
                  <text>传视频</text>
                </view>
              </view>
              <!-- #endif -->
              <!-- #ifndef H5 -->
              <view class="upload-placeholder" @click="chooseMedia">
                <text class="upload-icon">📷/📹</text>
                <text>点击拍摄或选择</text>
              </view>
              <!-- #endif -->
            </template>
          </view>
        </view>
        <view class="actions">
          <button class="btn primary" @click="addItems">上传到时间轴</button>
          <button class="btn ghost" @click="logout">退出管理员</button>
        </view>
        <view class="hint">
          <text>退出后需重新访问链接登录。</text>
        </view>
      </view>

      <!-- 未登录状态 -->
      <view class="upload-card" v-else>
          <view class="upload-placeholder" style="padding: 40px 0;">
              <text style="font-size: 3rem; margin-bottom: 16px;">🔐</text>
              <text class="label-text" style="text-align: center; margin-bottom: 24px;">这是管理端，需要密钥才能操作</text>
              <button class="btn primary" @click="requestLogin">输入管理员密钥</button>
          </view>
      </view>
    </view>

    <!-- 时间轴 -->
    <view class="timeline-shell">
      <view class="timeline-header">
        <text class="h2">{{ appConfig.timelineTitle }}</text>
        <view class="nav">
          <button class="btn icon" @click="openSettings" v-if="isAdmin">⚙️</button>
        </view>
      </view>

      <scroll-view 
        class="timeline" 
        scroll-y="true" 
        scroll-with-animation="true"
        @scrolltolower="loadMore"
      >
        <view class="timeline-content">
            <view class="axis" aria-hidden="true"></view>
            <view class="timeline-track">
              <view v-if="items.length === 0 && !isLoading" class="empty">
                <text>{{ appConfig.emptyText }}</text>
              </view>

              <template v-for="group in groupedItems" :key="group.key">
                <view class="month-header">
                  <view class="month-dot"></view>
                  <text class="month-title">{{ group.key }}</text>
                </view>
                <view
                  v-for="item in group.items"
                  :key="item.id"
                  class="timeline-item"
                >
                  <view class="dot"></view>
                  <view class="card">
                    <video
                      v-if="isVideo(item.src) && !showSettingsModal && !showEditModal"
                      class="photo"
                      :src="item.src"
                      controls
                    ></video>
                    <view v-else-if="isVideo(item.src)" class="photo video-placeholder">
                      <text style="color: #fff;">📹</text>
                    </view>
                    <image
                      v-else
                      class="photo"
                      :src="item.thumb || item.src"
                      mode="aspectFill"
                      lazy-load
                      @click="previewImage(item.src, allImageUrls)"
                    ></image>
                    <view class="card-body">
                      <text class="date">{{ formatDate(item.date, appConfig.unknownDateText) }}</text>
                      <text class="title" v-if="item.title">{{ item.title }}</text>
                      <text class="description" v-if="item.description">{{ item.description }}</text>
                      <view class="meta-row" v-if="item.taken_at">
                        <text class="meta-text">拍摄: {{ item.taken_at }}</text>
                      </view>
                      <view class="meta-row location" v-if="item.address || (item.latitude && item.longitude)" @click.stop="openMap(item.latitude, item.longitude)">
                        <text class="meta-text">{{ item.address || formatCoord(item.latitude, item.longitude) }}</text>
                      </view>
                    </view>

                    <view class="card-actions" v-if="isAdmin">
                      <view class="action-btn" @click.stop="openEdit(item)">
                        <text class="action-icon">✏️</text>
                      </view>
                      <view class="action-btn danger" @click.stop="confirmDelete(item.id)">
                        <text class="action-icon">🗑️</text>
                      </view>
                    </view>
                  </view>
                </view>
              </template>
            </view>
            
            <!-- 加载状态 -->
            <view class="loading-more" v-if="items.length > 0">
                <template v-if="isLoading">
                    <text class="loading-text">加载中...</text>
                </template>
                <template v-else-if="hasMore">
                     <text class="loading-text">上拉加载更多</text>
                </template>
                <template v-else>
                    <view class="no-more-data">
                        <view class="divider"></view>
                        <text class="no-more-text">THE END</text>
                        <view class="divider"></view>
                    </view>
                </template>
            </view>
        </view>
      </scroll-view>
    </view>

    <!-- 设置弹窗 -->
    <view class="modal-overlay" v-if="showSettingsModal">
        <view class="modal-content">
            <view class="modal-header">
                <text class="modal-title">全局设置</text>
                <view class="close-btn" @click="closeSettings">✕</view>
            </view>
            <view class="modal-body">
                <view class="field">
                    <text class="label-text">导航栏标题</text>
                    <input class="uni-input" v-model="editConfig.appTitle" />
                </view>
                <view class="field">
                    <text class="label-text">顶部英文 (Kicker)</text>
                    <input class="uni-input" v-model="editConfig.kicker" />
                </view>
                <view class="field">
                    <text class="label-text">主标题 (H1)</text>
                    <input class="uni-input" v-model="editConfig.mainTitle" />
                </view>
                <view class="field">
                    <text class="label-text">副标题</text>
                    <input class="uni-input" v-model="editConfig.subTitle" />
                </view>
                <view class="field">
                    <text class="label-text">时间轴标题</text>
                    <input class="uni-input" v-model="editConfig.timelineTitle" />
                </view>
                <view class="field">
                    <text class="label-text">空状态提示</text>
                    <input class="uni-input" v-model="editConfig.emptyText" />
                </view>
                <view class="field">
                    <text class="label-text">默认照片标题</text>
                    <input class="uni-input" v-model="editConfig.defaultItemTitle" />
                </view>
                <view class="field">
                    <text class="label-text">未知时间提示</text>
                    <input class="uni-input" v-model="editConfig.unknownDateText" />
                </view>
                <view class="field">
                    <text class="label-text">每页加载数量</text>
                    <input class="uni-input" type="number" v-model.number="editConfig.pageSize" />
                </view>
            </view>
            <view class="modal-footer">
                <button class="btn primary" @click="handleSaveSettings">保存配置</button>
            </view>
        </view>
    </view>

    <!-- 编辑条目弹窗 -->
    <view class="modal-overlay" v-if="showEditModal">
        <view class="modal-content">
            <view class="modal-header">
                <text class="modal-title">编辑条目</text>
                <view class="close-btn" @click="closeEdit">✕</view>
            </view>
            <view class="modal-body">
                <view class="field">
                    <text class="label-text">标题</text>
                    <input class="uni-input" v-model="editItemData.title" placeholder="照片标题" />
                </view>
                <view class="field">
                    <text class="label-text">说点什么</text>
                    <textarea class="uni-textarea" v-model="editItemData.description" placeholder="记录当时的心情..." :maxlength="500" auto-height />
                </view>
                <view class="field">
                    <text class="label-text">日期时间</text>
                    <view class="datetime-group">
                        <picker mode="date" :value="editItemData.dateStr" @change="editItemDateChange">
                            <view class="input-picker">{{ editItemData.dateStr || '选择日期' }}</view>
                        </picker>
                        <picker mode="time" :value="editItemData.timeStr" @change="editItemTimeChange">
                            <view class="input-picker">{{ editItemData.timeStr || '选择时间' }}</view>
                        </picker>
                    </view>
                </view>
            </view>
            <view class="modal-footer">
                <button class="btn primary" @click="handleSaveEdit">保存</button>
            </view>
        </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { formatDate, isVideo, previewImage } from '../../utils.js';
import * as api from '../../api.js';
import { extractMetadata } from '../../exif.js';

// ==================== 状态 ====================

const appConfig = reactive({
    appTitle: "花生",
    kicker: "Peanut",
    mainTitle: "精彩时刻",
    subTitle: "记录属于你的花生时刻，支持横向滚动与本地保存。",
    timelineTitle: "时间轴",
    emptyText: "还没有照片，先上传几张吧。",
    defaultItemTitle: "未命名照片",
    unknownDateText: "未知时间",
    pageSize: 5
});

const editConfig = reactive({...appConfig});
const showSettingsModal = ref(false);

const dateValue = ref('');
const timeValue = ref('');
const captionValue = ref('');
const descriptionValue = ref('');
const items = ref([]);
const batchList = ref([]);
const adminKey = ref('');

// 分页状态
const page = ref(1);
const hasMore = ref(true);
const isLoading = ref(false);

const isAdmin = computed(() => !!adminKey.value);

// 按年月分组
const groupedItems = computed(() => {
    const groups = [];
    let currentKey = '';
    for (const item of items.value) {
        const date = new Date(item.date);
        const key = isNaN(date.getTime()) ? '未知时间' : `${date.getFullYear()}年${date.getMonth() + 1}月`;
        if (key !== currentKey) {
            groups.push({ key, items: [item] });
            currentKey = key;
        } else {
            groups[groups.length - 1].items.push(item);
        }
    }
    return groups;
});

// 所有图片 URL（用于全屏滑动浏览）
const allImageUrls = computed(() => {
    return items.value.filter(item => !isVideo(item.src)).map(item => item.src);
});

// ==================== 日期选择 ====================

const bindDateChange = (e) => { dateValue.value = e.detail.value; };
const bindTimeChange = (e) => { timeValue.value = e.detail.value; };

// ==================== 位置信息 ====================

const formatCoord = (lat, lng) => {
    if (!lat || !lng) return '';
    const latDir = lat >= 0 ? 'N' : 'S';
    const lngDir = lng >= 0 ? 'E' : 'W';
    return `${Math.abs(lat).toFixed(4)}°${latDir}, ${Math.abs(lng).toFixed(4)}°${lngDir}`;
};

const openMap = (lat, lng) => {
    // #ifdef H5
    window.open(`https://uri.amap.com/marker?position=${lng},${lat}`, '_blank');
    // #endif
    // #ifndef H5
    uni.openLocation({ latitude: parseFloat(lat), longitude: parseFloat(lng) });
    // #endif
};

// ==================== 文件上传 ====================

const clearSelection = () => {
    batchList.value = [];
};

const removeFromBatch = (index) => {
    batchList.value.splice(index, 1);
};

const showAddMenu = () => {
    uni.showActionSheet({
        itemList: ['拍照片', '拍视频', '传照片', '传视频'],
        success: (res) => {
            const actions = [
                () => triggerH5Input('camera', 'image'),
                () => triggerH5Input('camera', 'video'),
                () => triggerH5Input('album', 'image'),
                () => triggerH5Input('album', 'video'),
            ];
            actions[res.tapIndex]();
        }
    });
};

const captureVideoFrame = (file) => {
    // #ifdef H5
    return new Promise((resolve) => {
        const video = document.createElement('video');
        video.preload = 'auto';
        video.muted = true;
        video.playsInline = true;
        const url = URL.createObjectURL(file);
        video.src = url;
        const cleanup = () => URL.revokeObjectURL(url);
        let resolved = false;
        const done = (result) => { if (!resolved) { resolved = true; cleanup(); resolve(result); } };
        video.onloadeddata = () => { video.currentTime = Math.min(1, video.duration * 0.1); };
        video.onseeked = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = Math.min(video.videoWidth, 800);
                canvas.height = Math.round(canvas.width * video.videoHeight / video.videoWidth);
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => {
                    done(blob ? new File([blob], 'thumb.jpg', { type: 'image/jpeg' }) : null);
                }, 'image/jpeg', 0.7);
            } catch (e) { done(null); }
        };
        video.onerror = () => done(null);
        setTimeout(() => done(null), 8000);
    });
    // #endif
    // #ifndef H5
    return Promise.resolve(null);
    // #endif
};

const uploadOneFile = async (item) => {
    const filePath = typeof item === 'string' ? item : (item.path || item.src);
    const fileObj = typeof item === 'string' ? null : item.file;
    const clientExif = (typeof item === 'object') ? item.clientExif : null;

    try {
        const data = await api.uploadFile(adminKey.value, filePath, fileObj, clientExif);
        return data;
    } catch (error) {
        if (error.message === 'AUTH_FAILED') {
            uni.showToast({ title: '密钥失效', icon: 'none' });
            adminKey.value = '';
            uni.removeStorageSync('peanut_api_key');
        }
        throw error;
    }
};

const handleBatchUpload = async (filePaths) => {
    if (!filePaths || filePaths.length === 0) return;

    uni.showLoading({ title: `正在上传 0/${filePaths.length}` });

    try {
        for (let i = 0; i < filePaths.length; i++) {
            uni.showLoading({ title: `正在上传 ${i+1}/${filePaths.length}` });
            const data = await uploadOneFile(filePaths[i]);

            // 视频无缩略图时，客户端截帧并上传
            if (isVideo(data.url) && !data.thumb) {
                const fileObj = (typeof filePaths[i] === 'object') ? filePaths[i].file : null;
                if (fileObj) {
                    const frameFile = await captureVideoFrame(fileObj);
                    if (frameFile) {
                        try {
                            const thumbData = await api.uploadFile(adminKey.value, URL.createObjectURL(frameFile), frameFile);
                            data.thumb = thumbData.url;
                        } catch (e) { /* 截帧上传失败不影响主流程 */ }
                    }
                }
            }

            batchList.value.push({
                src: data.url,
                thumb: data.thumb,
                name: data.filename || 'media',
                exif: data.exif
            });
        }

        if (batchList.value.length > 0) {
            uni.showToast({ title: '上传完成', icon: 'success' });
        }
    } catch (e) {
        uni.showToast({ title: e.message || '上传出错', icon: 'none' });
    } finally {
        uni.hideLoading();
    }
};

const triggerH5Input = (sourceType, mediaType) => {
    // #ifdef H5
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.accept = mediaType === 'video' ? 'video/*' : 'image/*';

    if (sourceType === 'camera') {
        input.setAttribute('capture', 'environment');
    }

    input.onchange = async (event) => {
        const files = event.target.files;
        if (files && files.length > 0) {
            const items = [];
            for (const file of Array.from(files)) {
                // 在上传前从原始文件提取元数据（图片 EXIF / 视频 MP4 moov / lastModified 兜底）
                let clientExif = await extractMetadata(file);
                items.push({
                    file: file,
                    path: URL.createObjectURL(file),
                    type: mediaType,
                    clientExif: clientExif
                });
            }
            handleBatchUpload(items);
        }
    };
    input.click();
    // #endif
};

const chooseMedia = () => {
    if (!isAdmin.value) return;

    // #ifndef H5
    uni.chooseMedia({
        count: 9,
        mediaType: ['image', 'video'],
        sourceType: ['album', 'camera'],
        success: (res) => {
            const urls = res.tempFiles.map(f => f.tempFilePath);
            handleBatchUpload(urls);
        }
    });
    // #endif
};

// ==================== 条目管理 ====================

const addItems = async () => {
    if (batchList.value.length === 0) {
        uni.showToast({ title: '请先选择照片', icon: 'none' });
        return;
    }
    if (!isAdmin.value) return;

    // 构建日期
    let finalDateStr = '';
    if (dateValue.value) {
        finalDateStr = dateValue.value + (timeValue.value ? 'T' + timeValue.value : 'T00:00');
    } else {
        finalDateStr = new Date().toISOString();
    }

    const baseDate = new Date(finalDateStr);
    uni.showLoading({ title: '正在发布...' });

    try {
        for (let i = 0; i < batchList.value.length; i++) {
            const item = batchList.value[i];

            // 日期优先级：EXIF > 用户选择
            let itemDate = baseDate;
            if (item.exif && item.exif.date) {
                const parts = item.exif.date.split(/[: ]/);
                if (parts.length >= 6) {
                    itemDate = new Date(parts[0], parts[1]-1, parts[2], parts[3], parts[4], parts[5]);
                }
            } else {
                // 无 EXIF 时加秒确保排序
                itemDate = new Date(baseDate.getTime() + i * 1000);
            }

            const newItem = {
                date: itemDate.toISOString(),
                title: (i === 0) ? (captionValue.value || item.name.replace(/\.[^/.]+$/, "")) : '',
                description: (i === 0) ? (descriptionValue.value || null) : null,
                src: item.src,
                thumb: item.thumb,
                latitude: item.exif ? item.exif.latitude : null,
                longitude: item.exif ? item.exif.longitude : null,
                taken_at: (item.exif && item.exif.date) ? item.exif.date : null
            };

            const savedItem = await api.createItem(adminKey.value, newItem);
            items.value.unshift(savedItem);
        }

        uni.showToast({ title: '全部发布成功', icon: 'success' });
        captionValue.value = '';
        descriptionValue.value = '';
        batchList.value = [];
    } catch (e) {
        uni.showToast({ title: '发布过程中出错', icon: 'none' });
    } finally {
        uni.hideLoading();
    }
};

const confirmDelete = (id) => {
    uni.showModal({
        title: '确认',
        content: '确定要删除这张照片吗？',
        success: async (res) => {
            if (res.confirm) {
                if (!isAdmin.value) return;
                try {
                    await api.deleteItem(adminKey.value, id);
                    items.value = items.value.filter(item => item.id !== id);
                    uni.showToast({ title: '删除成功', icon: 'none' });
                } catch (error) {
                    if (error.message === 'AUTH_FAILED') {
                        uni.showToast({ title: '密钥失效', icon: 'none' });
                        adminKey.value = '';
                        uni.removeStorageSync('peanut_api_key');
                    } else {
                        uni.showToast({ title: '删除失败', icon: 'none' });
                    }
                }
            }
        }
    });
};

// ==================== 编辑条目 ====================

const showEditModal = ref(false);
const editItemData = reactive({ id: null, title: '', description: '', dateStr: '', timeStr: '' });

const openEdit = (item) => {
    editItemData.id = item.id;
    editItemData.title = item.title || '';
    editItemData.description = item.description || '';
    const d = new Date(item.date);
    if (!isNaN(d.getTime())) {
        editItemData.dateStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        editItemData.timeStr = `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    } else {
        editItemData.dateStr = '';
        editItemData.timeStr = '';
    }
    showEditModal.value = true;
};

const closeEdit = () => { showEditModal.value = false; };
const editItemDateChange = (e) => { editItemData.dateStr = e.detail.value; };
const editItemTimeChange = (e) => { editItemData.timeStr = e.detail.value; };

const handleSaveEdit = async () => {
    const updateData = { title: editItemData.title, description: editItemData.description || null };
    if (editItemData.dateStr) {
        const dateStr = editItemData.dateStr + (editItemData.timeStr ? 'T' + editItemData.timeStr : 'T00:00');
        updateData.date = new Date(dateStr).toISOString();
    }
    try {
        const updated = await api.updateItem(adminKey.value, editItemData.id, updateData);
        const idx = items.value.findIndex(i => String(i.id) === String(updated.id));
        if (idx >= 0) {
            items.value[idx] = updated;
        }
        showEditModal.value = false;
        uni.showToast({ title: '已更新', icon: 'success' });
    } catch (e) {
        if (e.message === 'AUTH_FAILED') {
            uni.showToast({ title: '密钥失效', icon: 'none' });
            adminKey.value = '';
            uni.removeStorageSync('peanut_api_key');
        } else {
            uni.showToast({ title: '更新失败', icon: 'none' });
        }
    }
};

// ==================== 配置管理 ====================

const loadConfig = async () => {
    try {
        const data = await api.fetchConfig();
        Object.assign(appConfig, data);
        if (appConfig.pageSize) {
            appConfig.pageSize = Number(appConfig.pageSize);
        }
        uni.setNavigationBarTitle({ title: appConfig.appTitle });
    } catch (e) {
        console.error("获取配置失败", e);
    }
};

const openSettings = () => {
    Object.assign(editConfig, appConfig);
    showSettingsModal.value = true;
};

const closeSettings = () => {
    showSettingsModal.value = false;
};

const handleSaveSettings = async () => {
    try {
        const data = await api.saveConfig(adminKey.value, editConfig);
        Object.assign(appConfig, data);
        showSettingsModal.value = false;
        uni.showToast({ title: '配置已更新', icon: 'success' });
        uni.setNavigationBarTitle({ title: appConfig.appTitle });
    } catch (error) {
        if (error.message === 'AUTH_FAILED') {
            uni.showToast({ title: '无权限', icon: 'none' });
        } else {
            uni.showToast({ title: '保存失败', icon: 'none' });
        }
    }
};

// ==================== 认证 ====================

const requestLogin = () => {
    uni.showModal({
        title: '管理员登录',
        editable: true,
        placeholderText: '请输入 API 密钥',
        success: async (res) => {
            if (res.confirm && res.content) {
                const key = res.content.trim();
                if (!key) return;
                uni.showLoading({ title: '验证中...' });
                try {
                    await api.verifyKey(key);
                    uni.setStorageSync('peanut_api_key', key);
                    adminKey.value = key;
                    uni.showToast({ title: '登录成功', icon: 'success' });
                } catch (e) {
                    uni.showToast({ title: '密钥无效，请重新输入', icon: 'none' });
                } finally {
                    uni.hideLoading();
                }
            }
        }
    });
};

const logout = () => {
    uni.showModal({
        title: '确认退出',
        content: '退出后将无法上传和删除，确定吗？',
        success: (res) => {
            if (res.confirm) {
                adminKey.value = '';
                uni.removeStorageSync('peanut_api_key');
                uni.showToast({ title: '已退出管理员模式', icon: 'none' });
            }
        }
    });
};

// ==================== 数据加载 ====================

const load = async (isRefresh = true) => {
    if (isLoading.value) return;

    if (isRefresh) {
        page.value = 1;
        hasMore.value = true;
        items.value = [];
    }

    if (!hasMore.value) return;
    isLoading.value = true;

    const limit = appConfig.pageSize && Number(appConfig.pageSize) > 0 ? Number(appConfig.pageSize) : 5;

    try {
        const data = await api.fetchItems(page.value, limit);
        // 后端分页返回 { items, total, page, limit }
        const newItems = data.items || data;
        if (Array.isArray(newItems)) {
            if (newItems.length < limit) {
                hasMore.value = false;
            }
            items.value = [...items.value, ...newItems];
            page.value++;
        }
    } catch (e) {
        uni.showToast({ title: '加载失败', icon: 'none' });
    } finally {
        isLoading.value = false;
        if (isRefresh) {
            uni.stopPullDownRefresh();
        }
    }
};

const loadMore = () => {
    if (hasMore.value && !isLoading.value) {
        load(false);
    }
};

// ==================== 生命周期 ====================

onLoad((options) => {
    let key = options && options.key ? options.key : '';

    // #ifdef H5
    if (!key && window.location.search) {
        const params = new URLSearchParams(window.location.search);
        if (params.has('key')) {
            key = params.get('key');
        }
    }
    // #endif

    const tryKey = async (k) => {
        try {
            await api.verifyKey(k);
            uni.setStorageSync('peanut_api_key', k);
            adminKey.value = k;
            uni.showToast({ title: '管理员模式已激活', icon: 'none' });
        } catch (e) {
            uni.removeStorageSync('peanut_api_key');
            adminKey.value = '';
            uni.showToast({ title: '密钥无效，请重新登录', icon: 'none' });
        }
    };

    if (key) {
        tryKey(key);
    } else {
        const stored = uni.getStorageSync('peanut_api_key');
        if (stored) {
            tryKey(stored);
        }
    }
});

onMounted(() => {
    const now = new Date();
    dateValue.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    timeValue.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    loadConfig();
    load();
});
</script>

<style>
/* ==================== 排版 ==================== */

.h1 {
  font-size: clamp(2.4rem, 4vw, 3.4rem);
  margin: 0 0 12px;
  letter-spacing: -0.03em;
  font-weight: bold;
}

.h2 {
  font-size: 1.6rem;
  font-weight: bold;
}

/* ==================== 主视觉区 ==================== */

.hero {
  position: relative;
  z-index: 1;
  padding: 48px 6vw 32px;
}

@media (min-width: 768px) {
  .hero {
     display: grid;
     gap: 32px;
     grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
     align-items: center;
  }
}

.hero-copy {
  margin-bottom: 24px;
}

.hero-copy .kicker {
  font-family: "JetBrains Mono", monospace;
  text-transform: uppercase;
  letter-spacing: 0.15em;
  font-size: 0.85rem;
  color: var(--accent);
  margin: 0 0 16px;
  display: block;
  font-weight: 600;
}

.hero-copy .sub {
  margin: 0;
  color: var(--muted);
  max-width: 35ch;
  display: block;
  line-height: 1.6;
}

/* ==================== 上传卡片 ==================== */

.upload-card {
  background: var(--card);
  backdrop-filter: blur(20px);
  padding: 32px;
  border-radius: 32px;
  box-shadow: var(--shadow);
  border: 1px solid rgba(255, 255, 255, 0.6);
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.datetime-group {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 12px;
}

.upload-area {
  width: 100%;
  min-height: 160px;
  background: rgba(255, 255, 255, 0.5);
  border: 2px dashed var(--line);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
  transition: all 0.3s ease;
}

.upload-area.has-items {
  min-height: auto;
  padding: 12px;
  align-items: flex-start;
  border-style: solid;
  background: rgba(255, 255, 255, 0.3);
}

.upload-area:hover {
  background: rgba(255, 255, 255, 0.8);
  border-color: var(--accent);
}

/* 预览网格 */
.preview-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  width: 100%;
}

.preview-item {
  position: relative;
  aspect-ratio: 1;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--line);
}

.preview-media {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-remove {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 24px;
  height: 24px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  z-index: 3;
  cursor: pointer;
  line-height: 1;
}

.video-badge {
  position: absolute;
  bottom: 6px;
  left: 6px;
  background: rgba(0, 0, 0, 0.55);
  border-radius: 6px;
  padding: 2px 8px;
}

.video-badge-text {
  font-size: 10px;
  color: #fff;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.add-more-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: rgba(255, 255, 255, 0.6);
  border: 2px dashed var(--line);
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s;
}

.add-more-card:active {
  background: rgba(255, 255, 255, 0.9);
  border-color: var(--accent);
}

.add-icon {
  font-size: 2rem;
  color: var(--muted);
  line-height: 1;
}

.add-text {
  font-size: 0.75rem;
  color: var(--muted);
}

.h5-triggers {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    width: 100%;
    height: 100%;
}

.trigger-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--muted);
    font-size: 0.85rem;
    transition: background 0.2s;
    border: 1px solid rgba(0,0,0,0.03);
}

.trigger-btn:active {
    background: rgba(0,0,0,0.05);
}

.trigger-icon {
    font-size: 1.5rem;
    margin-bottom: 4px;
}

.upload-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 0.9rem;
}

.upload-icon {
  font-size: 2rem;
}

/* ==================== 表单元素 ==================== */

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 0.95rem;
}

.field-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.clear-all-btn {
  font-size: 0.8rem;
  color: var(--accent);
  cursor: pointer;
}

.label-text {
  color: var(--muted);
  font-size: 0.9rem;
  margin-left: 4px;
}

.uni-input, .input-picker {
  border: 1px solid var(--line);
  padding: 12px 16px;
  border-radius: 99px;
  font-size: 0.95rem;
  font-family: inherit;
  background: rgba(255, 255, 255, 0.5);
  min-height: 48px;
  color: var(--ink);
}

.uni-textarea {
  border: 1px solid var(--line);
  padding: 12px 16px;
  border-radius: 16px;
  font-size: 0.95rem;
  font-family: inherit;
  background: rgba(255, 255, 255, 0.5);
  min-height: 80px;
  width: 100%;
  color: var(--ink);
  line-height: 1.6;
  box-sizing: border-box;
}

.actions {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-top: 8px;
}

.hint {
  font-size: 0.8rem;
  color: var(--muted);
  text-align: center;
  margin-top: 8px;
}

/* ==================== 按钮 ==================== */

.btn {
  border: none;
  font-family: inherit;
  cursor: pointer;
  padding: 12px 24px;
  border-radius: 99px;
  font-size: 15px;
  font-weight: 500;
  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.btn.primary {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 10px 20px rgba(230, 180, 117, 0.3);
}

.btn.ghost {
  background: transparent;
  border: 1px solid var(--line);
  color: var(--muted);
}

.btn.icon {
  background: rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(4px);
  border: 1px solid var(--line);
  width: 48px;
  height: 48px;
  border-radius: 50%;
  color: var(--accent);
  box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}

/* ==================== 时间轴 ==================== */

.timeline-shell {
  position: relative;
  z-index: 1;
  padding: 0 6vw 80px;
}

.timeline-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 24px;
}

.timeline {
  width: 100%;
  height: 70vh;
  white-space: normal;
}

.timeline::-webkit-scrollbar {
  display: none;
}

.timeline-content {
    position: relative;
    padding: 20px 0;
    max-width: 600px;
    margin: 0 auto;
}

.axis {
  position: absolute;
  left: 26px;
  top: 0;
  bottom: 0;
  width: 2px;
  background: linear-gradient(180deg, transparent, var(--line) 5%, var(--line) 95%, transparent);
}

.timeline-track {
  display: flex;
  flex-direction: column;
  gap: 32px;
  padding-left: 0;
}

.month-header {
  position: relative;
  padding-left: 60px;
  display: flex;
  align-items: center;
}

.month-dot {
  position: absolute;
  left: 17px;
  width: 20px;
  height: 20px;
  background: var(--accent);
  border-radius: 50%;
  border: 3px solid #fff;
  box-shadow: 0 2px 8px rgba(230, 180, 117, 0.5);
  z-index: 2;
}

.month-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--ink);
  letter-spacing: 0.02em;
}

.timeline-item {
  position: relative;
  width: 100%;
  padding-left: 60px;
  display: block;
}

.timeline-item + .timeline-item {
  margin-left: 0;
}

.dot {
  position: absolute;
  top: 24px;
  left: 20px;
  width: 14px;
  height: 14px;
  background: #fff;
  border: 4px solid var(--accent);
  border-radius: 50%;
  box-shadow: 0 2px 8px rgba(230, 180, 117, 0.4);
  z-index: 2;
}

/* ==================== 卡片 ==================== */

.card {
  position: relative;
  background: rgba(255, 255, 255, 0.9);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
  transition: transform 0.2s;
  border: 1px solid rgba(255, 255, 255, 0.6);
  width: 100%;
}

.card:active {
  transform: scale(0.99);
}

@media (hover: hover) {
  .card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(93, 64, 55, 0.12);
  }
}

.photo {
  width: 100%;
  height: auto;
  aspect-ratio: 4/3;
  display: block;
  object-fit: cover;
}

.video-placeholder {
  background: #000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.card-body {
  padding: 16px 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.date {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.7rem;
  color: var(--muted);
  letter-spacing: 0.05em;
  background: var(--bg-strong);
  padding: 4px 10px;
  border-radius: 12px;
  align-self: flex-start;
  font-weight: 500;
}

.title {
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--ink);
  line-height: 1.4;
  margin-top: 4px;
}

.description {
  font-size: 0.9rem;
  color: var(--muted);
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
}

.meta-row {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
}

.meta-row.location {
  cursor: pointer;
}

.meta-text {
  font-size: 0.75rem;
  color: var(--muted);
  letter-spacing: 0.02em;
}

.card-actions {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    gap: 6px;
    z-index: 10;
}

.action-btn {
    background: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    backdrop-filter: blur(4px);
}

.action-icon {
    font-size: 14px;
}

/* ==================== 加载状态 ==================== */

.loading-more {
  padding: 30px 0;
  text-align: center;
}

.loading-text {
    font-size: 14px;
    color: #999;
}

.no-more-data {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.divider {
    width: 30px;
    height: 1px;
    background-color: #ddd;
}

.no-more-text {
    font-size: 12px;
    color: #aaa;
    letter-spacing: 1px;
    font-weight: 500;
}

.empty {
  padding: 40px 32px;
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.4);
  color: var(--muted);
  border: 2px dashed var(--line);
  text-align: center;
}

/* ==================== 弹窗 ==================== */

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
}

.modal-content {
    background: #fff;
    width: 90%;
    max-width: 480px;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
    max-height: 80vh;
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 1.2rem;
    font-weight: bold;
}

.close-btn {
    font-size: 1.5rem;
    padding: 8px;
    cursor: pointer;
    line-height: 1;
    color: var(--muted);
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid var(--line);
    text-align: right;
}
</style>
