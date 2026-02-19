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
        <input
          class="search-input"
          v-model="searchQuery"
          placeholder="搜索标题、描述或地点..."
          @confirm="onSearch"
        />
        <view class="search-clear" v-if="searchQuery" @click="clearSearch">✕</view>
        <button class="search-btn" @click="onSearch">搜索</button>
      </view>

      <!-- 时间轴滚动区域 -->
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
                      v-if="isVideo(item.src)"
                      class="photo"
                      :src="item.src"
                      controls
                    ></video>
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
                        <text class="meta-text">{{ appConfig.takenAtLabel }} {{ item.taken_at }}</text>
                      </view>
                      <view class="meta-row location" v-if="item.address || (item.latitude && item.longitude)" @click.stop="openMap(item.latitude, item.longitude)">
                        <text class="meta-text">{{ item.address || formatCoord(item.latitude, item.longitude) }}</text>
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
      </scroll-view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { formatDate, isVideo, previewImage } from '../../utils.js';
import * as api from '../../api.js';

// 位置格式化
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
const viewerKey = ref(uni.getStorageSync('peanut_viewer_key') || '');
const isAuthed = ref(false);

// 搜索状态
const searchQuery = ref('');

// 数据状态
const items = ref([]);
const page = ref(1);
const hasMore = ref(true);
const isLoading = ref(false);

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

// 触底加载更多
const loadMore = () => {
    if (hasMore.value && !isLoading.value) {
        load(false);
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
    uni.showModal({
        title: '输入密钥',
        editable: true,
        placeholderText: '请输入访问密钥',
        success: async (res) => {
            if (res.confirm && res.content) {
                try {
                    await api.verifyKey(res.content);
                    viewerKey.value = res.content;
                    uni.setStorageSync('peanut_viewer_key', res.content);
                    isAuthed.value = true;
                    loadConfig();
                    load();
                } catch (e) {
                    uni.showToast({ title: '密钥错误', icon: 'none' });
                }
            }
        }
    });
};

// 生命周期
onMounted(async () => {
    uni.setNavigationBarTitle({ title: appConfig.appTitle });
    if (viewerKey.value) {
        try {
            await api.verifyKey(viewerKey.value);
            isAuthed.value = true;
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
  padding: 0 6vw 80px;
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
  margin-bottom: 24px;
  position: relative;
}

.search-input {
  flex: 1;
  background: rgba(255, 255, 255, 0.6);
  border: 1px solid var(--line);
  padding: 10px 36px 10px 16px;
  border-radius: 20px;
  font-size: 0.95rem;
  transition: border-color 0.2s;
}

.search-input:focus {
  border-color: var(--accent);
}

.search-clear {
  position: absolute;
  right: 80px;
  top: 50%;
  transform: translateY(-50%);
  width: 24px;
  height: 24px;
  line-height: 24px;
  text-align: center;
  color: #999;
  font-size: 14px;
  z-index: 2;
  cursor: pointer;
}

.search-btn {
  margin-left: 10px;
  padding: 0 16px;
  height: 40px;
  line-height: 40px;
  border-radius: 20px;
  background: var(--accent);
  color: #fff;
  font-size: 0.95rem;
  border: none;
}

.timeline {
  width: 100%;
  height: 70vh;
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
</style>
