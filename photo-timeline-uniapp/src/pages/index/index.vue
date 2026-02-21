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

              <template v-for="yearGroup in groupedItems" :key="yearGroup.key">
                <view class="year-group">
                  <view class="year-header collapse-header" @click="toggleYearCollapse(yearGroup.key)">
                    <view class="year-dot"></view>
                    <text class="year-title">{{ yearGroup.label }}</text>
                    <text class="group-count">{{ yearGroup.displayCount }}</text>
                    <text class="collapse-arrow">{{ isYearCollapsed(yearGroup.key) ? '▸' : '▾' }}</text>
                  </view>

                  <view class="year-body" v-if="!isYearCollapsed(yearGroup.key)">
                    <template v-for="monthGroup in yearGroup.months" :key="monthGroup.collapseKey">
                      <view class="month-group">
                        <view class="month-header collapse-header" @click="toggleMonthCollapse(monthGroup.collapseKey)">
                          <view class="month-dot"></view>
                          <text class="month-title">{{ monthGroup.label }}</text>
                          <text class="group-count">{{ monthGroup.displayCount }}</text>
                          <text class="collapse-arrow">{{ isMonthCollapsed(monthGroup.collapseKey) ? '▸' : '▾' }}</text>
                        </view>

                        <view class="month-body" v-if="!isMonthCollapsed(monthGroup.collapseKey)">
                          <view
                            v-for="item in monthGroup.items"
                            :key="item.id"
                            class="timeline-item"
                          >
                            <view class="dot"></view>
                            <view class="card">
                              <view class="card-body">
                                <text class="date">{{ formatDate(item.date, appConfig.unknownDateText) }}</text>
                                <text class="description" v-if="getItemDescription(item)">{{ getItemDescription(item) }}</text>
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
                                    @click="openImagePreview(m.src)"
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
                        </view>
                      </view>
                    </template>
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

    <view class="image-preview-overlay" v-if="showImagePreview">
      <swiper
        class="image-preview-swiper"
        :current="previewCurrent"
        circular
        @change="onPreviewChange"
      >
        <swiper-item v-for="(url, index) in previewList" :key="`${url}-${index}`">
          <view class="image-preview-item">
            <image
              v-if="shouldRenderPreviewImage(index)"
              class="image-preview-image"
              :src="url"
              mode="aspectFit"
              lazy-load
            ></image>
          </view>
        </swiper-item>
      </swiper>
      <view class="image-preview-close" @click="closeImagePreview">✕</view>
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

const getItemDescription = (item) => {
    if (!item || typeof item !== 'object') return '';
    const description = (item.description ?? '').toString().trim();
    if (description) return description;
    const title = (item.title ?? '').toString().trim();
    if (title) return title;
    return '';
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
const showImagePreview = ref(false);
const previewCurrent = ref(0);
const previewList = ref([]);
const collapsedYears = ref({});
const collapsedMonths = ref({});
const yearCountMap = ref({});
const monthCountMap = ref({});

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

// 按 年 -> 月 分组（支持折叠）
const groupedItems = computed(() => {
    const yearGroups = [];
    const yearMap = new Map();

    for (const item of items.value) {
        const date = new Date(item.date);
        const isValid = !isNaN(date.getTime());
        const yearKey = isValid ? String(date.getFullYear()) : 'unknown';
        const yearLabel = isValid ? `${date.getFullYear()}年` : '未知年份';
        const monthValue = isValid ? date.getMonth() + 1 : 0;
        const monthKey = isValid
            ? `${date.getFullYear()}-${String(monthValue).padStart(2, '0')}`
            : 'unknown-month';
        const monthLabel = isValid ? `${monthValue}月` : '未知时间';

        let yearGroup = yearMap.get(yearKey);
        if (!yearGroup) {
            yearGroup = { key: yearKey, label: yearLabel, count: 0, displayCount: 0, months: [] };
            yearMap.set(yearKey, yearGroup);
            yearGroups.push(yearGroup);
        }
        yearGroup.count += 1;

        const lastMonth = yearGroup.months[yearGroup.months.length - 1];
        if (!lastMonth || lastMonth.key !== monthKey) {
            yearGroup.months.push({
                key: monthKey,
                collapseKey: `${yearKey}:${monthKey}`,
                label: monthLabel,
                count: 1,
                displayCount: 1,
                items: [item]
            });
        } else {
            lastMonth.items.push(item);
            lastMonth.count += 1;
        }
    }

    for (const yearGroup of yearGroups) {
        const yearRaw = yearCountMap.value[yearGroup.key];
        const yearTotal = Number(yearRaw);
        yearGroup.displayCount = Number.isFinite(yearTotal) ? yearTotal : yearGroup.count;

        for (const monthGroup of yearGroup.months) {
            const monthRaw = monthCountMap.value[monthGroup.collapseKey];
            const monthTotal = Number(monthRaw);
            monthGroup.displayCount = Number.isFinite(monthTotal) ? monthTotal : monthGroup.count;
        }
    }

    return yearGroups;
});

const MIN_EXPANDED_ITEMS = 5;

const getItemMonthMeta = (item) => {
    if (!item || !item.date) {
        return { yearKey: 'unknown', monthKey: 'unknown-month', collapseKey: 'unknown:unknown-month', year: 0, month: 0, valid: false };
    }
    const date = new Date(item.date);
    if (isNaN(date.getTime())) {
        return { yearKey: 'unknown', monthKey: 'unknown-month', collapseKey: 'unknown:unknown-month', year: 0, month: 0, valid: false };
    }

    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const yearKey = String(year);
    const monthKey = `${yearKey}-${String(month).padStart(2, '0')}`;
    return {
        yearKey,
        monthKey,
        collapseKey: `${yearKey}:${monthKey}`,
        year,
        month,
        valid: true
    };
};

const getPrevMonthMeta = (year, month) => {
    if (!year || !month) return null;
    let targetYear = year;
    let targetMonth = month - 1;
    if (targetMonth <= 0) {
        targetYear -= 1;
        targetMonth = 12;
    }
    const yearKey = String(targetYear);
    const monthKey = `${yearKey}-${String(targetMonth).padStart(2, '0')}`;
    return {
        yearKey,
        monthKey,
        collapseKey: `${yearKey}:${monthKey}`
    };
};

const getInitialMonthStats = () => {
    if (!items.value.length) return null;

    const current = getItemMonthMeta(items.value[0]);
    const prev = current.valid ? getPrevMonthMeta(current.year, current.month) : null;
    let currentCount = 0;
    let prevCount = 0;

    for (const item of items.value) {
        const meta = getItemMonthMeta(item);
        if (meta.monthKey === current.monthKey) {
            currentCount += 1;
        } else if (prev && meta.monthKey === prev.monthKey) {
            prevCount += 1;
        }
    }

    return { current, prev, currentCount, prevCount };
};

const applyDefaultCollapseState = () => {
    const years = {};
    const months = {};
    for (const yearGroup of groupedItems.value) {
        years[yearGroup.key] = true;
        for (const monthGroup of yearGroup.months) {
            months[monthGroup.collapseKey] = true;
        }
    }

    const stats = getInitialMonthStats();
    if (stats) {
        years[stats.current.yearKey] = false;
        months[stats.current.collapseKey] = false;

        if (stats.currentCount < MIN_EXPANDED_ITEMS && stats.prev && stats.prevCount > 0) {
            years[stats.prev.yearKey] = false;
            months[stats.prev.collapseKey] = false;
        }
    }

    collapsedYears.value = years;
    collapsedMonths.value = months;
};

const syncCollapseStateForNewGroups = () => {
    let yearsChanged = false;
    let monthsChanged = false;
    const years = { ...collapsedYears.value };
    const months = { ...collapsedMonths.value };

    for (const yearGroup of groupedItems.value) {
        if (!(yearGroup.key in years)) {
            years[yearGroup.key] = true;
            yearsChanged = true;
        }
        for (const monthGroup of yearGroup.months) {
            if (!(monthGroup.collapseKey in months)) {
                months[monthGroup.collapseKey] = true;
                monthsChanged = true;
            }
        }
    }

    if (yearsChanged) collapsedYears.value = years;
    if (monthsChanged) collapsedMonths.value = months;
};

const resetGroupCountMaps = () => {
    yearCountMap.value = {};
    monthCountMap.value = {};
};

const isYearCollapsed = (yearKey) => !!collapsedYears.value[yearKey];
const isMonthCollapsed = (monthKey) => !!collapsedMonths.value[monthKey];

const toggleYearCollapse = (yearKey) => {
    collapsedYears.value = {
        ...collapsedYears.value,
        [yearKey]: !isYearCollapsed(yearKey)
    };
};

const toggleMonthCollapse = (monthKey) => {
    collapsedMonths.value = {
        ...collapsedMonths.value,
        [monthKey]: !isMonthCollapsed(monthKey)
    };
};

const allImageUrls = computed(() => {
    const urls = [];
    for (const item of items.value) {
        if (Array.isArray(item.media)) {
            urls.push(...item.media.filter(m => !isVideo(m.src)).map(m => m.src));
        } else if (item.src && !isVideo(item.src)) {
            urls.push(item.src);
        }
    }
    return urls;
});

const openImagePreview = (url) => {
    const urls = allImageUrls.value;
    const currentIndex = urls.indexOf(url);

    if (currentIndex < 0) {
        previewImage(url);
        return;
    }

    previewList.value = urls;
    previewCurrent.value = currentIndex;
    showImagePreview.value = true;
};

const closeImagePreview = () => {
    showImagePreview.value = false;
    previewList.value = [];
    previewCurrent.value = 0;
};

const onPreviewChange = (e) => {
    const index = Number((e && e.detail && e.detail.current) || 0);
    previewCurrent.value = Number.isNaN(index) ? 0 : index;
};

const shouldRenderPreviewImage = (index) => {
    const total = previewList.value.length;
    const current = previewCurrent.value;
    if (total <= 1) return true;

    if (Math.abs(index - current) <= 1) return true;
    if (current === 0 && index === total - 1) return true;
    if (current === total - 1 && index === 0) return true;

    return false;
};

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
const loadGroupCounts = async () => {
    try {
        const data = await api.fetchItemCounts(viewerKey.value, searchQuery.value);
        yearCountMap.value = (data && data.year_counts) || {};
        monthCountMap.value = (data && data.month_counts) || {};
    } catch (e) {
        resetGroupCountMaps();
    }
};

const appendNextPage = async (limit) => {
    const data = await api.fetchItems(viewerKey.value, page.value, limit, searchQuery.value);
    const newItems = data.items || data;
    if (!Array.isArray(newItems)) {
        hasMore.value = false;
        return 0;
    }

    if (newItems.length < limit) {
        hasMore.value = false;
    }

    if (newItems.length > 0) {
        items.value = [...items.value, ...newItems];
        page.value += 1;
    }

    return newItems.length;
};

const ensureMonthQuotaForDefaultView = async (limit) => {
    while (hasMore.value) {
        const stats = getInitialMonthStats();
        if (!stats) break;

        // 当前月不足 5 条时，继续拉取直到“当前月 + 上一月”至少 5 条
        if (stats.currentCount >= MIN_EXPANDED_ITEMS) break;
        if ((stats.currentCount + stats.prevCount) >= MIN_EXPANDED_ITEMS) break;

        const loaded = await appendNextPage(limit);
        if (loaded <= 0) break;
    }
};

const load = async (isRefresh = true) => {
    if (isLoading.value) return;

    if (isRefresh) {
        page.value = 1;
        hasMore.value = true;
        items.value = [];
        showBackTop.value = false;
        resetGroupCountMaps();
    }

    if (!hasMore.value) return;
    isLoading.value = true;

    const limit = appConfig.pageSize && Number(appConfig.pageSize) > 0 ? Number(appConfig.pageSize) : 5;

    try {
        if (isRefresh) {
            await loadGroupCounts();
        }

        await appendNextPage(limit);

        if (isRefresh) {
            await ensureMonthQuotaForDefaultView(limit);
            applyDefaultCollapseState();
        } else {
            syncCollapseStateForNewGroups();
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
  gap: 30px;
  padding-left: 0;
}

.year-group,
.year-body,
.month-group,
.month-body {
  display: flex;
  flex-direction: column;
}

.year-group {
  gap: 18px;
}

.year-body {
  gap: 20px;
}

.month-group {
  gap: 16px;
}

.month-body {
  gap: 24px;
}

.collapse-header {
  user-select: none;
}

.year-header {
  position: relative;
  padding-left: 60px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.year-dot {
  position: absolute;
  left: 15px;
  width: 22px;
  height: 22px;
  background: var(--ink);
  border-radius: 50%;
  border: 3px solid #fff;
  box-shadow: 0 2px 8px rgba(93, 64, 55, 0.28);
  z-index: 2;
}

.year-title {
  font-size: 1.08rem;
  font-weight: 700;
  color: var(--ink);
}

.month-header {
  position: relative;
  padding-left: 60px;
  display: flex;
  align-items: center;
  gap: 8px;
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

.group-count {
  font-size: 0.82rem;
  color: var(--muted);
}

.collapse-arrow {
  margin-left: auto;
  color: var(--muted);
  font-size: 0.92rem;
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

.image-preview-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.9);
    z-index: 13000;
}

.image-preview-swiper {
    width: 100%;
    height: 100%;
}

.image-preview-item {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.image-preview-image {
    width: 100%;
    height: 100%;
}

.image-preview-close {
    position: fixed;
    top: 16px;
    right: 16px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    z-index: 13001;
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
