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

      <!-- 未登录状态 -->
      <view class="login-card" v-if="!isAuthed">
          <view class="login-placeholder">
              <text style="font-size: 3rem; margin-bottom: 16px;">🔐</text>
              <text class="login-hint">请输入密钥查看时间轴</text>
              <button class="btn primary" @click="requestKey">输入密钥</button>
          </view>
      </view>
    </view>

    <view class="timeline-shell" v-if="isAuthed">
      <view class="timeline-header">
        <text class="h2">{{ appConfig.timelineTitle }}</text>
      </view>

      <!-- 搜索框 -->
      <view class="search-bar">
        <view class="search-icon">🔍</view>
        <input
          type="text"
          class="search-input"
          v-model="searchQuery"
          placeholder="搜索标题、描述或地点..."
          confirm-type="search"
          @confirm="onSearch"
          @input="onSearchInput"
        />
        <view class="search-clear" v-if="searchQuery" @click="clearSearch">✕</view>
        <view class="search-btn" @click="onSearch">搜索</view>
      </view>

      <!-- 时间轴滚动区域 -->
      <view class="timeline">
        <view class="timeline-content">
            <view id="timeline-top-anchor" class="timeline-top-anchor"></view>
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
                    <view class="card-body">
                      <text class="date">{{ formatDate(item.date, appConfig.unknownDateText) }}</text>
                      <text class="title" v-if="item.title">{{ item.title }}</text>
                      <text class="description" v-if="item.description">{{ item.description }}</text>
                    </view>

                    <view class="media-list" v-if="item.media && item.media.length > 0">
                      <view class="media-item" v-for="(m, mIndex) in item.media" :key="m.id || mIndex">
                        <video
                          v-if="isVideo(m.src)"
                          class="photo"
                          :src="m.src"
                          controls
                        ></video>
                        <image
                          v-else
                          class="photo"
                          :src="m.thumb || m.src"
                          mode="aspectFill"
                          lazy-load
                          @click="previewImage(m.src, allImageUrls)"
                        ></image>
                        <view class="card-body-meta" v-if="m.taken_at || m.address || hasCoord(m.latitude, m.longitude)">
                          <view class="meta-row" v-if="m.taken_at">
                            <text class="meta-text">{{ appConfig.takenAtLabel }} {{ m.taken_at }}</text>
                          </view>
                          <view class="meta-row location" v-if="m.address || hasCoord(m.latitude, m.longitude)" @click.stop="openMap(m.latitude, m.longitude)">
                            <text class="meta-text">{{ m.address || formatCoord(m.latitude, m.longitude) }}</text>
                          </view>
                        </view>
                      </view>
                    </view>
                  </view>
                </view>
              </template>
            </view>
            
            <!-- 加载状态 -->
            <view class="loading-more" v-if="items.length > 0">
                <template v-if="isLoading">
                    <text class="loading-text">{{ appConfig.loadingText }}</text>
                </template>
                <template v-else-if="hasMore">
                     <text class="loading-text">{{ appConfig.loadMoreText }}</text>
                </template>
                <template v-else>
                    <view class="no-more-data">
                        <view class="divider"></view>
                        <text class="no-more-text">{{ appConfig.endText }}</text>
                        <view class="divider"></view>
                    </view>
                </template>
            </view>
        </view>
      </view>
      <view
        class="back-top-btn"
        :class="{ 'is-visible': showBackTop }"
        :style="backTopInlineStyle"
        @click="handleBackTopClick"
        @touchstart.stop="onBackTopTouchStart"
        @touchmove.stop.prevent="onBackTopTouchMove"
        @touchend.stop="onBackTopTouchEnd"
        @touchcancel.stop="onBackTopTouchEnd"
      >
        <view class="back-top-icon">
          <view class="back-top-cap"></view>
          <view class="back-top-shaft"></view>
          <view class="back-top-head"></view>
        </view>
      </view>
    </view>

    <view class="auth-modal-overlay" v-if="showAuthModal">
      <view class="auth-modal-card">
        <view class="auth-modal-header">
          <text class="auth-modal-title">密钥验证</text>
          <view class="auth-close-btn" @click="closeAuthModal">✕</view>
        </view>
        <view class="auth-modal-body">
          <text class="auth-lock">🔐</text>
          <text class="auth-hint">请输入访问密钥</text>
          <input
            class="auth-input"
            v-model="authKeyInput"
            placeholder="请输入访问密钥"
            :password="true"
            confirm-type="done"
            @confirm="submitAuthKey"
          />
        </view>
        <view class="auth-modal-footer">
          <button class="btn ghost" @click="closeAuthModal" :disabled="authLoading">取消</button>
          <button class="btn primary" @click="submitAuthKey" :disabled="authLoading">
            {{ authLoading ? '验证中...' : '确认' }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { onLoad, onPageScroll, onReachBottom } from '@dcloudio/uni-app';
import { formatDate, isVideo, previewImage } from '../../utils.js';
import * as api from '../../api.js';

// 位置格式化
const hasCoord = (lat, lng) => (
    lat !== null && lat !== undefined && lat !== '' &&
    lng !== null && lng !== undefined && lng !== ''
);

const formatCoord = (lat, lng) => {
    if (!hasCoord(lat, lng)) return '';
    const latDir = lat >= 0 ? 'N' : 'S';
    const lngDir = lng >= 0 ? 'E' : 'W';
    return `${Math.abs(lat).toFixed(4)}°${latDir}, ${Math.abs(lng).toFixed(4)}°${lngDir}`;
};

const openMap = (lat, lng) => {
    if (!hasCoord(lat, lng)) return;
    // #ifdef H5
    window.open(`https://uri.amap.com/marker?position=${lng},${lat}`, '_blank');
    // #endif
    // #ifndef H5
    uni.openLocation({ latitude: parseFloat(lat), longitude: parseFloat(lng) });
    // #endif
};

// 配置状态
const appConfig = reactive({
    appTitle: "花生",
    kicker: "Peanut",
    mainTitle: "精彩时刻",
    subTitle: "记录属于你的花生时刻，支持横向滚动与本地保存。",
    timelineTitle: "时间轴",
    emptyText: "还没有照片，先上传几张吧。",
    defaultItemTitle: "未命名照片",
    unknownDateText: "未知时间",
    pageSize: 5,
    loadingText: "加载中...",
    loadMoreText: "上拉加载更多",
    endText: "THE END",
    takenAtLabel: "拍摄:"
});

// 密钥状态
const viewerKey = ref('');
const isAuthed = ref(false);
const urlKeyUsed = ref(false);
const showAuthModal = ref(false);
const authKeyInput = ref('');
const authLoading = ref(false);

// 搜索状态
const searchQuery = ref('');

// 数据状态
const items = ref([]);
const page = ref(1);
const hasMore = ref(true);
const isLoading = ref(false);
const showBackTop = ref(false);
const backTopLeft = ref(20);
const backTopTop = ref(0);
const backTopMoved = ref(false);
const backTopDrag = reactive({
    active: false,
    startX: 0,
    startY: 0,
    originLeft: 20,
    originTop: 0
});
const BACK_TOP_SIZE = 46;
const BACK_TOP_MARGIN = 12;
const backTopInlineStyle = computed(() => ({
    left: `${backTopLeft.value}px`,
    top: `${backTopTop.value}px`
}));

const getTouchXY = (e) => {
    const touch = (e && e.touches && e.touches[0]) || (e && e.changedTouches && e.changedTouches[0]);
    if (!touch) return { x: 0, y: 0 };
    return {
        x: Number(touch.clientX ?? touch.pageX ?? 0),
        y: Number(touch.clientY ?? touch.pageY ?? 0)
    };
};

const getWindowInfo = () => {
    try {
        const info = uni.getSystemInfoSync() || {};
        const safeBottom = Number((info.safeAreaInsets && info.safeAreaInsets.bottom) || 0);
        return {
            width: Number(info.windowWidth || 375),
            height: Number(info.windowHeight || 667),
            safeBottom
        };
    } catch (e) {
        return { width: 375, height: 667, safeBottom: 0 };
    }
};

const clampBackTopPosition = (left, top) => {
    const { width, height } = getWindowInfo();
    const maxLeft = Math.max(BACK_TOP_MARGIN, width - BACK_TOP_SIZE - BACK_TOP_MARGIN);
    const maxTop = Math.max(BACK_TOP_MARGIN, height - BACK_TOP_SIZE - BACK_TOP_MARGIN);
    return {
        left: Math.min(Math.max(left, BACK_TOP_MARGIN), maxLeft),
        top: Math.min(Math.max(top, BACK_TOP_MARGIN), maxTop)
    };
};

const initBackTopPosition = () => {
    const { height, safeBottom } = getWindowInfo();
    const targetTop = height - BACK_TOP_SIZE - safeBottom - 28;
    const pos = clampBackTopPosition(BACK_TOP_MARGIN, targetTop);
    backTopLeft.value = pos.left;
    backTopTop.value = pos.top;
};

const onBackTopTouchStart = (e) => {
    const point = getTouchXY(e);
    backTopDrag.active = true;
    backTopMoved.value = false;
    backTopDrag.startX = point.x;
    backTopDrag.startY = point.y;
    backTopDrag.originLeft = backTopLeft.value;
    backTopDrag.originTop = backTopTop.value;
};

const onBackTopTouchMove = (e) => {
    if (!backTopDrag.active) return;
    const point = getTouchXY(e);
    const dx = point.x - backTopDrag.startX;
    const dy = point.y - backTopDrag.startY;
    if (Math.abs(dx) + Math.abs(dy) > 4) {
        backTopMoved.value = true;
    }
    const pos = clampBackTopPosition(backTopDrag.originLeft + dx, backTopDrag.originTop + dy);
    backTopLeft.value = pos.left;
    backTopTop.value = pos.top;
};

const onBackTopTouchEnd = () => {
    backTopDrag.active = false;
};

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
    let urls = [];
    for (const item of items.value) {
        if (item.media) {
            urls.push(...item.media.filter(m => !isVideo(m.src)).map(m => m.src));
        } else if (item.src && !isVideo(item.src)) {
            urls.push(item.src);
        }
    }
    return urls;
});

// 加载配置
const loadConfig = async () => {
    try {
        const data = await api.fetchConfig(viewerKey.value);
        Object.assign(appConfig, data);
        if (appConfig.pageSize) {
            appConfig.pageSize = Number(appConfig.pageSize);
        }
        uni.setNavigationBarTitle({ title: appConfig.appTitle });
    } catch (e) {
        console.error("获取配置失败", e);
    }
};

// 加载条目（支持分页）
const load = async (isRefresh = true) => {
    if (isLoading.value) return;

    if (isRefresh) {
        page.value = 1;
        hasMore.value = true;
        items.value = [];
        showBackTop.value = false;
    }

    if (!hasMore.value) return;
    isLoading.value = true;

    const limit = appConfig.pageSize && Number(appConfig.pageSize) > 0 ? Number(appConfig.pageSize) : 5;

    try {
        const data = await api.fetchItems(viewerKey.value, page.value, limit, searchQuery.value);
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
    }
};

const scrollToTop = () => {
    uni.pageScrollTo({
        scrollTop: 0,
        duration: 300
    });
};

const handleBackTopClick = () => {
    if (backTopMoved.value) {
        backTopMoved.value = false;
        return;
    }
    scrollToTop();
};

// 触底加载更多
const loadMore = () => {
    if (hasMore.value && !isLoading.value) {
        load(false);
    }
};

onPageScroll((e) => {
    const top = Number((e && e.scrollTop) || 0);
    showBackTop.value = top > 480;
});

onReachBottom(() => {
    if (!isAuthed.value) return;
    loadMore();
});

// 搜索输入事件（H5 端兼容）
const onSearchInput = (e) => {
    // uni-app H5 mode: e.detail.value; native: e.target.value
    if (e.detail && e.detail.value !== undefined) {
        searchQuery.value = e.detail.value;
    }
};

// 搜索操作
const onSearch = () => {
    load(true);
};

// 清除搜索
const clearSearch = () => {
    searchQuery.value = '';
    load(true);
};

// 输入密钥
const requestKey = () => {
    showAuthModal.value = true;
    authKeyInput.value = '';
};

const closeAuthModal = () => {
    if (authLoading.value) return;
    showAuthModal.value = false;
    authKeyInput.value = '';
};

const submitAuthKey = async () => {
    if (authLoading.value) return;
    const key = authKeyInput.value.trim();
    if (!key) {
        uni.showToast({ title: '请输入密钥', icon: 'none' });
        return;
    }

    authLoading.value = true;
    try {
        await api.verifyKey(key);
        viewerKey.value = key;
        uni.setStorageSync('peanut_viewer_key', key);
        isAuthed.value = true;
        showAuthModal.value = false;
        authKeyInput.value = '';
        loadConfig();
        load();
    } catch (e) {
        uni.showToast({ title: '密钥错误', icon: 'none' });
    } finally {
        authLoading.value = false;
    }
};

onLoad(() => {
    let key = '';
    let fromUrl = false;
    let queryKeyDetected = false;

    // #ifdef H5
    if (window.location.search) {
        const queryParams = new URLSearchParams(window.location.search);
        if (queryParams.has('key')) {
            queryKeyDetected = true;
            const url = new URL(window.location.href);
            url.searchParams.delete('key');
            const query = url.searchParams.toString();
            const cleanUrl = url.pathname + (query ? `?${query}` : '') + url.hash;
            window.history.replaceState({}, '', cleanUrl);
        }
    }

    if (window.location.hash) {
        const hash = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : window.location.hash;
        const hashParams = new URLSearchParams(hash);
        if (hashParams.has('key')) {
            key = (hashParams.get('key') || '').trim();
            fromUrl = !!key;
        }
    }
    // #endif

    if (key) {
        viewerKey.value = key;
        urlKeyUsed.value = fromUrl;
    } else if (!queryKeyDetected) {
        const stored = uni.getStorageSync('peanut_viewer_key');
        if (stored) {
            viewerKey.value = stored;
        }
    }
});

const clearUrlKey = () => {
    // #ifdef H5
    const url = new URL(window.location.href);
    let changed = false;

    if (url.searchParams.has('key')) {
        url.searchParams.delete('key');
        changed = true;
    }

    if (url.hash) {
        const hash = url.hash.startsWith('#') ? url.hash.slice(1) : url.hash;
        const hashParams = new URLSearchParams(hash);
        if (hashParams.has('key')) {
            hashParams.delete('key');
            url.hash = hashParams.toString() ? `#${hashParams.toString()}` : '';
            changed = true;
        }
    }

    if (changed) {
        const query = url.searchParams.toString();
        const cleanUrl = url.pathname + (query ? `?${query}` : '') + url.hash;
        window.history.replaceState({}, '', cleanUrl);
    }
    // #endif
};

// 生命周期
onMounted(async () => {
    uni.setNavigationBarTitle({ title: appConfig.appTitle });
    initBackTopPosition();
    if (viewerKey.value) {
        try {
            await api.verifyKey(viewerKey.value);
            isAuthed.value = true;
            if (urlKeyUsed.value) {
                clearUrlKey();
            }
            loadConfig();
            load();
        } catch (e) {
            viewerKey.value = '';
            uni.removeStorageSync('peanut_viewer_key');
        }
    }
});
</script>

<style>
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

.timeline-shell {
  position: relative;
  z-index: 1;
  padding: 0 6vw 20px;
}

.timeline-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
}

.search-bar {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
  position: relative;
  z-index: 10;
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(230, 213, 196, 0.5);
  border-radius: 16px;
  padding: 8px 8px 8px 16px;
  box-shadow: 0 4px 16px rgba(93, 64, 55, 0.06);
  transition: box-shadow 0.3s, border-color 0.3s;
  touch-action: manipulation;
  -webkit-touch-callout: none;
  overflow: visible;
}

.search-bar:focus-within {
  border-color: var(--accent);
  box-shadow: 0 4px 20px rgba(230, 180, 117, 0.2);
}

.search-icon {
  font-size: 16px;
  margin-right: 8px;
  flex-shrink: 0;
  opacity: 0.5;
}

.search-input {
  flex: 1;
  background: transparent;
  border: none;
  outline: none;
  padding: 10px 4px;
  font-size: 15px;
  color: var(--ink);
  height: 40px;
  min-height: 40px;
  line-height: 20px;
  box-sizing: border-box;
  -webkit-appearance: none;
  appearance: none;
  touch-action: manipulation;
  pointer-events: auto;
  overflow: visible;
}

.search-clear {
  width: 28px;
  height: 28px;
  line-height: 28px;
  text-align: center;
  color: #999;
  font-size: 13px;
  flex-shrink: 0;
  cursor: pointer;
  border-radius: 50%;
  transition: background 0.2s;
}

.search-clear:active {
  background: rgba(0, 0, 0, 0.06);
}

.search-btn {
  flex-shrink: 0;
  padding: 0 18px;
  height: 36px;
  line-height: 36px;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  border: none;
  cursor: pointer;
  transition: opacity 0.2s, transform 0.15s;
  text-align: center;
  touch-action: manipulation;
}

.search-btn:active {
  opacity: 0.85;
  transform: scale(0.96);
}

.timeline {
  width: 100%;
  white-space: normal;
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

.empty {
  padding: 40px 32px;
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.4);
  color: var(--muted);
  border: 2px dashed var(--line);
  text-align: center;
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

.card {
  position: relative; 
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

.description {
  font-size: 0.9rem;
  color: var(--muted);
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
}

.media-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-bottom: 20px;
}

.card-body-meta {
  padding: 10px 20px 0;
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

/* 加载状态 */
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

.timeline-top-anchor {
    width: 100%;
    height: 0;
}

.login-card {
    margin-top: 32px;
    background: rgba(255,255,255,0.06);
    border-radius: 20px;
    border: 1px dashed rgba(255,255,255,0.15);
    backdrop-filter: blur(12px);
}

.login-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 0;
}

.login-hint {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.6);
    text-align: center;
    margin-bottom: 24px;
}

.auth-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 12000;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
}

.auth-modal-card {
    width: min(88vw, 420px);
    background: #fff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.auth-modal-header {
    padding: 18px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--line);
}

.auth-modal-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--ink);
}

.auth-close-btn {
    padding: 6px;
    line-height: 1;
    color: var(--muted);
}

.auth-modal-body {
    padding: 22px 20px 18px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.auth-lock {
    font-size: 2rem;
}

.auth-hint {
    font-size: 0.88rem;
    color: var(--muted);
}

.auth-input {
    width: 100%;
    height: 42px;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 0 12px;
    font-size: 15px;
    color: var(--ink);
    background: rgba(255, 255, 255, 0.8);
}

.auth-modal-footer {
    padding: 16px 20px 20px;
    display: flex;
    gap: 10px;
}

.auth-modal-footer .btn {
    flex: 1;
}

.back-top-btn {
    position: fixed;
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: rgba(93, 64, 55, 0.9);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 22px rgba(93, 64, 55, 0.25);
    z-index: 50;
    opacity: 0;
    transform: translateY(12px) scale(0.92);
    pointer-events: none;
    transition: opacity 0.22s ease, transform 0.22s ease, background 0.2s ease;
    touch-action: none;
}

.back-top-btn.is-visible {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}

.back-top-btn:active {
    transform: scale(0.94);
    background: rgba(93, 64, 55, 1);
}

.back-top-icon {
    width: 18px;
    height: 18px;
    position: relative;
}

.back-top-cap {
    position: absolute;
    top: 1px;
    left: 2px;
    width: 14px;
    height: 2px;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.95);
}

.back-top-shaft {
    position: absolute;
    top: 5px;
    left: 8px;
    width: 2px;
    height: 10px;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.95);
}

.back-top-head {
    position: absolute;
    top: 4px;
    left: 8px;
    width: 8px;
    height: 8px;
    border-left: 2px solid rgba(255, 255, 255, 0.95);
    border-top: 2px solid rgba(255, 255, 255, 0.95);
    transform: translateX(-3px) rotate(45deg);
}
</style>
