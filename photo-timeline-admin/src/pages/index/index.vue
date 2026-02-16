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

      <view class="upload-card" v-if="isAdmin">
        <view class="field">
          <text class="label-text">时间</text>
          <!-- Using a simple input for H5 compatibility or picker -->
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
          <text class="label-text">照片</text>
          <view class="upload-area" @click="chooseImage">
             <template v-if="tempPhotoData">
                <image :src="tempPhotoData.src" mode="aspectFill" class="preview-img"></image>
                <view class="re-upload-tip">点击更换</view>
             </template>
             <template v-else>
                <view class="upload-placeholder">
                   <text class="upload-icon">📷</text>
                   <text>点击选择照片</text>
                </view>
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

      <view class="upload-card" v-else>
          <view class="upload-placeholder" style="padding: 40px 0;">
              <text style="font-size: 3rem; margin-bottom: 16px;">🔐</text>
              <text class="label-text" style="text-align: center; margin-bottom: 24px;">这是管理端，需要密钥才能操作</text>
              <button class="btn primary" @click="requestLogin">输入管理员密钥</button>
          </view>
      </view>
    </view>

    <view class="timeline-shell">
      <view class="timeline-header">
        <text class="h2">{{ appConfig.timelineTitle }}</text>
        <view class="nav">
          <button class="btn icon" @click="openSettings" v-if="isAdmin">⚙️</button>
        </view>
      </view>

      <!-- Scroll View for Timeline -->
      <scroll-view 
        class="timeline" 
        scroll-y="true" 
        scroll-with-animation="true"
      >
        <view class="timeline-content">
            <view class="axis" aria-hidden="true"></view>
            <view class="timeline-track">
              <view v-if="items.length === 0" class="empty">
                <text>{{ appConfig.emptyText }}</text>
              </view>
              
              <view 
                v-for="(item, index) in sortedItems" 
                :key="item.id" 
                class="timeline-item"
                :style="{ '--i': index }"
              >
                <view class="dot"></view>
                <view class="card">
                  <image class="photo" :src="item.src" mode="aspectFill"></image>
                  <view class="card-body">
                    <text class="date">{{ formatDate(item.date) }}</text>
                    <text class="title">{{ item.title || appConfig.defaultItemTitle }}</text>
                    <view class="delete-btn" @click="deleteItem(item.id)" v-if="isAdmin">
                      <text class="delete-text">删除</text>
                    </view>
                  </view>
                </view>
              </view>
            </view>
        </view>
      </scroll-view>
    </view>

    <!-- Settings Modal -->
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
            </view>
            <view class="modal-footer">
                <button class="btn primary" @click="saveSettings">保存配置</button>
            </view>
        </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { onLoad } from '@dcloudio/uni-app';

const STORAGE_KEY = 'photoTimelineItems';

// Config State
const appConfig = reactive({
    appTitle: "花生",
    kicker: "Peanut",
    mainTitle: "精彩时刻",
    subTitle: "记录属于你的花生时刻，支持横向滚动与本地保存。",
    timelineTitle: "时间轴",
    emptyText: "还没有照片，先上传几张吧。",
    defaultItemTitle: "未命名照片",
    unknownDateText: "未知时间"
});

const editConfig = reactive({...appConfig});
const showSettingsModal = ref(false);

// State
const dateValue = ref('');
const timeValue = ref('');
const captionValue = ref('');
const items = ref([]);
const tempPhotoData = ref(null); 
const adminKey = ref(''); // Store the API Key

// Dynamic API Base URL
const getApiBaseUrl = () => {
    return 'https://api.hetao.us';
};

const API_BASE = getApiBaseUrl();

// Helpers
const createId = () => 
  `id-${Date.now()}-${Math.random().toString(16).slice(2)}`;

const formatDate = (value) => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return appConfig.unknownDateText;
  return date.toLocaleString("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const isAdmin = computed(() => {
    return !!adminKey.value;
});

// Actions
const bindDateChange = (e) => {
  dateValue.value = e.detail.value;
};

const bindTimeChange = (e) => {
  timeValue.value = e.detail.value;
};

const chooseImage = () => {
  if (!isAdmin.value) return;

  uni.chooseImage({
    count: 1,
    sizeType: ['original', 'compressed'],
    sourceType: ['album', 'camera'],
    success: async (res) => {
      const tempFilePaths = res.tempFilePaths;
      
      uni.uploadFile({
        url: `${API_BASE}/upload`, 
        filePath: tempFilePaths[0],
        name: 'file',
        header: {
            'x-api-key': adminKey.value
        },
        formData: {
          'user': 'test'
        },
        success: (uploadFileRes) => {
          if (uploadFileRes.statusCode === 403) {
             uni.showToast({ title: '密钥失效', icon: 'none' });
             adminKey.value = ''; 
             uni.removeStorageSync('peanut_api_key');
             return;
          }
          try {
            const data = JSON.parse(uploadFileRes.data);
            tempPhotoData.value = {
               src: data.url,
               name: data.filename || 'photo.jpg'
            };
            uni.showToast({ title: '上传成功', icon: 'success' });
          } catch (e) {
            console.error(e);
            uni.showToast({ title: '解析失败', icon: 'none' });
          }
        },
        fail: (err) => {
          console.error(err);
          uni.showToast({ title: '上传失败', icon: 'none' });
        }
      });
    }
  });
};

const addItems = () => {
  if (!tempPhotoData.value) {
    uni.showToast({ title: '请先选择照片', icon: 'none' });
    return;
  }
  
  if (!isAdmin.value) return;

  // Combine Date and Time
  let finalDateStr = '';
  if (dateValue.value) {
      finalDateStr = dateValue.value;
      if (timeValue.value) {
          finalDateStr += 'T' + timeValue.value;
      } else {
          finalDateStr += 'T00:00';
      }
  } else {
      finalDateStr = new Date().toISOString();
  }
  
  const date = new Date(finalDateStr);
  
  const newItem = {
    // id will be generated by backend
    date: date.toISOString(),
    title: captionValue.value || tempPhotoData.value.name.replace(/\.[^/.]+$/, ""),
    src: tempPhotoData.value.src
  };

  uni.request({
    url: `${API_BASE}/items/`,
    method: 'POST',
    header: {
        'x-api-key': adminKey.value
    },
    data: newItem,
    success: (res) => {
        if (res.statusCode === 200) {
            items.value.push(res.data);
            uni.showToast({ title: '添加成功', icon: 'success' });
             // Reset form
            captionValue.value = '';
            tempPhotoData.value = null;
        } else if (res.statusCode === 403) {
             uni.showToast({ title: '密钥失效', icon: 'none' });
             adminKey.value = '';
             uni.removeStorageSync('peanut_api_key');
        } else {
            uni.showToast({ title: '保存失败', icon: 'none' });
        }
    },
    fail: () => {
         uni.showToast({ title: '请求失败', icon: 'none' });
    }
  });
};

const deleteItem = (id) => {
  uni.showModal({
    title: '确认',
    content: '确定要删除这张照片吗？',
    success: (res) => {
      if (res.confirm) {
        if (!isAdmin.value) return;

        uni.request({
            url: `${API_BASE}/items/${id}`,
            method: 'DELETE',
            header: {
                'x-api-key': adminKey.value
            },
            success: (delRes) => {
                if (delRes.statusCode === 200) {
                     if (Array.isArray(items.value)) {
                        items.value = items.value.filter(item => item.id !== id);
                     } else {
                        // Fallback reload if state is inconsistent
                        load();
                     }
                     uni.showToast({ title: '删除成功', icon: 'none' });
                } else if (delRes.statusCode === 403) {
                     uni.showToast({ title: '密钥失效', icon: 'none' });
                     adminKey.value = '';
                     uni.removeStorageSync('peanut_api_key');
                }
            }
        });
      }
    }
  });
};

// Config Actions
const fetchConfig = () => {
    uni.request({
        url: `${API_BASE}/config`,
        method: 'GET',
        success: (res) => {
            if (res.statusCode === 200) {
                Object.assign(appConfig, res.data);
                uni.setNavigationBarTitle({
                    title: appConfig.appTitle
                });
            }
        }
    });
};

const openSettings = () => {
    Object.assign(editConfig, appConfig);
    showSettingsModal.value = true;
};

const closeSettings = () => {
    showSettingsModal.value = false;
};

const saveSettings = () => {
    uni.request({
        url: `${API_BASE}/config`,
        method: 'POST',
        header: {
            'x-api-key': adminKey.value
        },
        data: editConfig,
        success: (res) => {
            if (res.statusCode === 200) {
                Object.assign(appConfig, res.data);
                showSettingsModal.value = false;
                uni.showToast({ title: '配置已更新', icon: 'success' });
                uni.setNavigationBarTitle({
                    title: appConfig.appTitle
                });
            } else if (res.statusCode === 403) {
                uni.showToast({ title: '无权限', icon: 'none' });
            } else {
                uni.showToast({ title: '保存失败', icon: 'none' });
            }
        }
    });
};

const requestLogin = () => {
    uni.showModal({
        title: '管理员登录',
        editable: true,
        placeholderText: '请输入 API 密钥',
        success: (res) => {
            if (res.confirm && res.content) {
                const key = res.content.trim();
                if (key) {
                    uni.setStorageSync('peanut_api_key', key);
                    adminKey.value = key;
                    uni.showToast({ title: '登录成功', icon: 'success' });
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

// Removed save() as it is now handled by API calls

const load = () => {
  uni.request({
      url: `${API_BASE}/items/`,
      method: 'GET',
      success: (res) => {
          if (res.statusCode === 200) {
              items.value = res.data;
          }
      },
      fail: (e) => {
          console.error(e);
          uni.showToast({ title: '加载失败', icon: 'none' });
      }
  });
};

// Computed
const sortedItems = computed(() => {
  return [...items.value].sort((a, b) => new Date(b.date) - new Date(a.date)); // NEWEST FIRST
});

// Lifecycle
onLoad((options) => {
    // Magic Link Auth
    if (options && options.key) {
        uni.setStorageSync('peanut_api_key', options.key);
        adminKey.value = options.key;
        uni.showToast({ title: '管理员模式已激活', icon: 'none' });
    } else {
        // Try load existing key
        const stored = uni.getStorageSync('peanut_api_key');
        if (stored) {
            adminKey.value = stored;
        }
    }
});

onMounted(() => {
  const now = new Date();
  // Format YYYY-MM-DD
  const y = now.getFullYear();
  const m = String(now.getMonth()+1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  dateValue.value = `${y}-${m}-${d}`;
  
  // Format HH:MM
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  timeValue.value = `${hh}:${mm}`;
  
  fetchConfig();
  load();
});


</script>

<style>
/* Page specific styles */

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
  height: 180px;
  background: rgba(255, 255, 255, 0.5);
  border: 2px dashed var(--line);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
  cursor: pointer;
  transition: all 0.3s ease;
}

.upload-area:hover {
  background: rgba(255, 255, 255, 0.8);
  border-color: var(--accent);
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

.preview-img {
  width: 100%;
  height: 100%;
}

.re-upload-tip {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  font-size: 0.8rem;
  padding: 6px;
  text-align: center;
  backdrop-filter: blur(4px);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 0.95rem;
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

.actions {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-top: 8px;
}

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
  background: #fff;
  border: 1px solid var(--line);
  width: 48px;
  height: 48px;
  border-radius: 50%;
  color: var(--accent);
  box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}

.hint {
  font-size: 0.8rem;
  color: var(--muted);
  text-align: center;
  margin-top: 8px;
}

.timeline-shell {
  position: relative;
  z-index: 1;
  padding: 0 6vw 80px; /* Reduced top padding */
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
  height: 70vh; /* Fixed height for vertical scroll */
  white-space: normal; /* Allow wrap */
}

.timeline-content {
    position: relative;
    padding: 20px 0;
    max-width: 600px;
    margin: 0 auto;
}

.timeline::-webkit-scrollbar {
  display: none;
}

.nav .btn.icon {
  /* Ensure nav buttons are visible and nice */
  background: rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(4px);
}

.axis {
  position: absolute;
  left: 26px; /* Align with dots */
  top: 0;
  bottom: 0;
  width: 2px;
  background: linear-gradient(180deg, transparent, var(--line) 5%, var(--line) 95%, transparent);
}

.timeline-track {
  display: flex;
  flex-direction: column; /* Vertical */
  gap: 32px;
  padding-left: 0;
}

.empty {
  padding: 40px 32px;
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.4);
  color: var(--muted);
  border: 2px dashed var(--line);
  text-align: center;
}

.empty text {
  font-size: 0.9rem;
  opacity: 0.8;
}


.timeline-item {
  position: relative;
  width: 100%;
  padding-left: 60px; /* Space for axis and dot */
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

.card {
  position: relative; /* For absolute delete btn */
  background: #fff;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 10px 20px rgba(93, 64, 55, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.6);
  transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
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

.delete-btn {
  position: absolute;
  top: 12px;
  right: 12px;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(4px);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 8px rgba(0,0,0,0.1);
  opacity: 0;
  transform: scale(0.8);
  transition: all 0.2s ease;
}

.card:hover .delete-btn, .card:active .delete-btn {
  opacity: 1;
  transform: scale(1);
}

.delete-text {
  font-size: 0; /* Hide text */
}
.delete-text::before {
  content: "✕";
  font-size: 14px;
  color: var(--muted);
  font-weight: bold;
}



</style>
