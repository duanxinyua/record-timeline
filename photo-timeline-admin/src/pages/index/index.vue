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
          <button class="btn icon" @click="generateMissingThumbs" v-if="isAdmin" title="补生成视频封面">🎬</button>
          <button class="btn icon" @click="openTrash" v-if="isAdmin" title="回收站">🗑️</button>
          <button class="btn icon" @click="openSettings" v-if="isAdmin">⚙️</button>
        </view>
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
                                    v-if="isVideo(m.src) && !showSettingsModal && !showEditModal"
                                    class="photo"
                                    :src="m.src"
                                    controls
                                  ></video>
                                  <view v-else-if="isVideo(m.src)" class="photo video-placeholder">
                                    <text style="color: #fff;">📹</text>
                                  </view>
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

    <!-- 登录验证弹窗 -->
    <view class="modal-overlay auth-overlay" v-if="showAuthModal">
        <view class="modal-content auth-modal">
            <view class="modal-header">
                <text class="modal-title">管理员登录</text>
                <view class="close-btn" @click="closeAuthModal">✕</view>
            </view>
            <view class="modal-body auth-body">
                <text class="auth-lock">🔐</text>
                <text class="auth-hint">请输入 API 密钥验证身份</text>
                <input
                    class="uni-input auth-input"
                    v-model="authKeyInput"
                    placeholder="请输入 API 密钥"
                    :password="true"
                    confirm-type="done"
                    @confirm="submitAuthLogin"
                />
            </view>
            <view class="modal-footer auth-footer">
                <button class="btn ghost" @click="closeAuthModal" :disabled="authLoading">取消</button>
                <button class="btn primary" @click="submitAuthLogin" :disabled="authLoading">
                    {{ authLoading ? '验证中...' : '验证并登录' }}
                </button>
            </view>
        </view>
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
                <view class="settings-divider"></view>
                <text class="settings-section-title">加载状态文字</text>
                <view class="field">
                    <text class="label-text">加载中提示</text>
                    <input class="uni-input" v-model="editConfig.loadingText" />
                </view>
                <view class="field">
                    <text class="label-text">加载更多提示</text>
                    <input class="uni-input" v-model="editConfig.loadMoreText" />
                </view>
                <view class="field">
                    <text class="label-text">已到底提示</text>
                    <input class="uni-input" v-model="editConfig.endText" />
                </view>
                <view class="field">
                    <text class="label-text">拍摄时间前缀</text>
                    <input class="uni-input" v-model="editConfig.takenAtLabel" />
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
                    <text class="label-text">备注内容</text>
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

    <!-- 回收站弹窗 -->
    <view class="modal-overlay" v-if="showTrashModal">
        <view class="modal-content trash-modal">
            <view class="modal-header">
                <text class="modal-title">🗑️ 回收站</text>
                <view class="close-btn" @click="closeTrash">✕</view>
            </view>
            <view class="modal-body">
                <view v-if="trashItems.length === 0" class="trash-empty">
                    <text style="font-size: 2.5rem; margin-bottom: 12px;">✨</text>
                    <text class="label-text">回收站是空的</text>
                </view>
                <view v-else class="trash-list">
                    <view class="trash-item" v-for="item in trashItems" :key="item.id">
                        <image
                            v-if="!isVideo(item.src)"
                            class="trash-thumb"
                            :src="item.thumb || item.src"
                            mode="aspectFill"
                        ></image>
                        <view v-else class="trash-thumb video-placeholder">
                            <text style="color: #fff; font-size: 1.2rem;">📹</text>
                        </view>
                        <view class="trash-info">
                            <text class="trash-title">{{ getItemDescription(item) || '未命名' }}</text>
                            <text class="trash-date">删除于 {{ formatDate(item.deleted_at) }}</text>
                        </view>
                        <view class="trash-actions">
                            <view class="trash-action-btn restore" @click="handleRestore(item.id)">
                                <text>恢复</text>
                            </view>
                            <view class="trash-action-btn destroy" @click="handlePermanentDelete(item.id)">
                                <text>彻底删除</text>
                            </view>
                        </view>
                    </view>
                </view>
            </view>
            <view class="modal-footer" v-if="trashItems.length > 0">
                <button class="btn danger-btn" @click="handleEmptyTrash">清空回收站 ({{ trashItems.length }})</button>
            </view>
        </view>
    </view>

    <view class="modal-overlay confirm-overlay" v-if="showConfirmModal">
        <view class="modal-content confirm-modal">
            <view class="modal-header">
                <text class="modal-title">{{ confirmDialog.title }}</text>
                <view class="close-btn" @click="closeConfirmDialog" v-if="!confirmLoading">✕</view>
            </view>
            <view class="modal-body confirm-body">
                <text class="confirm-text">{{ confirmDialog.content }}</text>
            </view>
            <view class="modal-footer confirm-footer">
                <button class="btn ghost" @click="closeConfirmDialog" :disabled="confirmLoading">取消</button>
                <button class="btn danger-btn" @click="submitConfirmDialog" :disabled="confirmLoading">
                    {{ confirmLoading ? '处理中...' : confirmDialog.confirmText }}
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
    pageSize: 5,
    loadingText: "加载中...",
    loadMoreText: "上拉加载更多",
    endText: "THE END",
    takenAtLabel: "拍摄:"
});

const editConfig = reactive({...appConfig});
const showSettingsModal = ref(false);
const showAuthModal = ref(false);
const authKeyInput = ref('');
const authLoading = ref(false);

const dateValue = ref('');
const timeValue = ref('');
const descriptionValue = ref('');
const items = ref([]);
const batchList = ref([]);
const adminKey = ref('');

// 搜索状态
const searchQuery = ref('');

// 分页状态
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

const isAdmin = computed(() => !!adminKey.value);

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

    // circular 模式下首尾也认为是相邻项
    if (current === 0 && index === total - 1) return true;
    if (current === total - 1 && index === 0) return true;

    return false;
};

// ==================== 日期选择 ====================

const bindDateChange = (e) => { dateValue.value = e.detail.value; };
const bindTimeChange = (e) => { timeValue.value = e.detail.value; };

// ==================== 位置信息 ====================

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

const revokeBlobUrl = (url) => {
    // #ifdef H5
    if (typeof url === 'string' && url.startsWith('blob:')) {
        URL.revokeObjectURL(url);
    }
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
            const sourceItem = filePaths[i];
            try {
                uni.showLoading({ title: `正在上传 ${i+1}/${filePaths.length}` });
                const data = await uploadOneFile(sourceItem);

                // 视频无缩略图时，客户端截帧并上传
                if (isVideo(data.url) && !data.thumb) {
                    const fileObj = (typeof sourceItem === 'object') ? sourceItem.file : null;
                    if (fileObj) {
                        const frameFile = await captureVideoFrame(fileObj);
                        if (frameFile) {
                            let frameUrl = '';
                            try {
                                frameUrl = URL.createObjectURL(frameFile);
                                const thumbData = await api.uploadFile(adminKey.value, frameUrl, frameFile, null, { skipThumb: true });
                                data.thumb = thumbData.url;
                            } catch (e) {
                                // 截帧上传失败不影响主流程
                            } finally {
                                revokeBlobUrl(frameUrl);
                            }
                        }
                    }
                }

                batchList.value.push({
                    src: data.url,
                    thumb: data.thumb,
                    name: data.filename || 'media',
                    exif: data.exif
                });
            } finally {
                if (sourceItem && typeof sourceItem === 'object' && sourceItem.path) {
                    revokeBlobUrl(sourceItem.path);
                }
            }
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
        const groupId = Date.now().toString() + '-' + Math.random().toString(36).substr(2, 5);
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
                title: '',
                description: (i === 0) ? ((descriptionValue.value || item.name.replace(/\.[^/.]+$/, "")).trim() || null) : null,
                src: item.src,
                thumb: item.thumb,
                latitude: item.exif ? item.exif.latitude : null,
                longitude: item.exif ? item.exif.longitude : null,
                taken_at: (item.exif && item.exif.date) ? item.exif.date : null,
                group_id: groupId
            };

            await api.createItem(adminKey.value, newItem);
        }

        uni.showToast({ title: '全部发布成功', icon: 'success' });
        descriptionValue.value = '';
        batchList.value = [];
        
        // 重新拉取以呈现聚合并组的动态新结构
        load(true);
    } catch (e) {
        uni.showToast({ title: '发布过程中出错', icon: 'none' });
    } finally {
        uni.hideLoading();
    }
};

const confirmDelete = (id) => {
    uni.showModal({
        title: '确认',
        content: '将移到回收站，可随时恢复。',
        success: async (res) => {
            if (res.confirm) {
                if (!isAdmin.value) return;
                try {
                    await api.deleteItem(adminKey.value, id);
                    await load(true);
                    uni.showToast({ title: '已移到回收站', icon: 'none' });
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

// ==================== 回收站 ====================

const showTrashModal = ref(false);
const trashItems = ref([]);
const showConfirmModal = ref(false);
const confirmLoading = ref(false);
const confirmDialog = reactive({
    title: '',
    content: '',
    confirmText: '确认',
    onConfirm: null
});

const resetConfirmDialog = () => {
    showConfirmModal.value = false;
    confirmLoading.value = false;
    confirmDialog.title = '';
    confirmDialog.content = '';
    confirmDialog.confirmText = '确认';
    confirmDialog.onConfirm = null;
};

const openConfirmDialog = ({ title, content, confirmText = '确认', onConfirm }) => {
    confirmDialog.title = title;
    confirmDialog.content = content;
    confirmDialog.confirmText = confirmText;
    confirmDialog.onConfirm = onConfirm;
    showConfirmModal.value = true;
};

const closeConfirmDialog = () => {
    if (confirmLoading.value) return;
    resetConfirmDialog();
};

const submitConfirmDialog = async () => {
    if (confirmLoading.value) return;
    if (typeof confirmDialog.onConfirm !== 'function') {
        resetConfirmDialog();
        return;
    }

    confirmLoading.value = true;
    try {
        await confirmDialog.onConfirm();
        resetConfirmDialog();
    } catch (e) {
        confirmLoading.value = false;
    }
};

const refreshTrash = async () => {
    const data = await api.fetchTrash(adminKey.value);
    trashItems.value = Array.isArray(data) ? data : [];
};

const openTrash = async () => {
    showTrashModal.value = true;
    try {
        await refreshTrash();
    } catch (e) {
        uni.showToast({ title: '加载回收站失败', icon: 'none' });
    }
};

const closeTrash = () => { showTrashModal.value = false; };

const handleRestore = async (id) => {
    try {
        await api.restoreItem(adminKey.value, id);
        await refreshTrash();
        await load(true);
        uni.showToast({ title: '已恢复', icon: 'success' });
    } catch (e) {
        uni.showToast({ title: '恢复失败', icon: 'none' });
    }
};

const handlePermanentDelete = (id) => {
    openConfirmDialog({
        title: '⚠️ 彻底删除',
        content: '文件将永久删除且不可恢复，确定吗？',
        confirmText: '彻底删除',
        onConfirm: async () => {
            try {
                await api.permanentDeleteItem(adminKey.value, id);
                await refreshTrash();
                await load(true);
                uni.showToast({ title: '已彻底删除', icon: 'none' });
            } catch (e) {
                uni.showToast({ title: '删除失败', icon: 'none' });
                throw e;
            }
        }
    });
};

const handleEmptyTrash = () => {
    openConfirmDialog({
        title: '⚠️ 清空回收站',
        content: `将彻底删除 ${trashItems.value.length} 个条目及其文件，不可恢复！`,
        confirmText: '清空回收站',
        onConfirm: async () => {
            try {
                const result = await api.emptyTrash(adminKey.value);
                await refreshTrash();
                await load(true);
                uni.showToast({ title: `已清空 ${result.deleted} 项`, icon: 'none' });
            } catch (e) {
                uni.showToast({ title: '清空失败', icon: 'none' });
                throw e;
            }
        }
    });
};

// ==================== 补生成视频封面 ====================

const captureFrameFromUrl = (videoUrl) => {
    // #ifdef H5
    return new Promise((resolve) => {
        const video = document.createElement('video');
        video.crossOrigin = 'anonymous';
        video.preload = 'auto';
        video.muted = true;
        video.playsInline = true;
        video.src = videoUrl;
        let resolved = false;
        const done = (r) => { if (!resolved) { resolved = true; resolve(r); } };
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
        setTimeout(() => done(null), 15000);
    });
    // #endif
    // #ifndef H5
    return Promise.resolve(null);
    // #endif
};

const generateMissingThumbs = async () => {
    let sourceItems = items.value;

    // 扫描全量列表，而不是仅当前分页已加载的数据，避免误判“都有封面”
    try {
        const allData = await api.fetchItems(adminKey.value, 0, 0, searchQuery.value);
        const allItems = allData.items || allData;
        if (Array.isArray(allItems)) {
            sourceItems = allItems;
        }
    } catch (e) {
        if (e && e.message === 'AUTH_FAILED') {
            uni.showToast({ title: '密钥失效', icon: 'none' });
            adminKey.value = '';
            uni.removeStorageSync('peanut_api_key');
            return;
        }
        // 拉取全量失败时，回退到当前已加载列表，保证功能可用
    }

    const videoMedia = [];
    for (const item of sourceItems) {
        const mediaList = Array.isArray(item.media) ? item.media : [];
        for (const media of mediaList) {
            if (isVideo(media.src) && !media.thumb) {
                videoMedia.push({ id: media.id, src: media.src });
            }
        }
    }

    if (videoMedia.length === 0) {
        uni.showToast({ title: '所有视频都有封面', icon: 'none' });
        return;
    }

    uni.showLoading({ title: `处理中 0/${videoMedia.length}` });
    let success = 0;

    for (let i = 0; i < videoMedia.length; i++) {
        uni.showLoading({ title: `截帧 ${i + 1}/${videoMedia.length}` });
        const media = videoMedia[i];
        try {
            const frameFile = await captureFrameFromUrl(media.src);
            if (frameFile) {
                let frameUrl = '';
                try {
                    frameUrl = URL.createObjectURL(frameFile);
                    const thumbData = await api.uploadFile(adminKey.value, frameUrl, frameFile, null, { skipThumb: true });
                    await api.updateItem(adminKey.value, media.id, { thumb: thumbData.url });
                } finally {
                    revokeBlobUrl(frameUrl);
                }
                success++;
            }
        } catch (e) {
            console.warn('视频截帧失败:', media.id, e);
        }
    }

    uni.hideLoading();
    await load(true);
    uni.showToast({ title: `已生成 ${success}/${videoMedia.length} 个封面`, icon: 'none' });
};

// ==================== 编辑条目 ====================

const showEditModal = ref(false);
const editItemData = reactive({ id: null, description: '', originalTitle: '', dateStr: '', timeStr: '' });

const openEdit = (item) => {
    editItemData.id = item.id;
    editItemData.originalTitle = item.title || '';
    editItemData.description = getItemDescription(item);
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
    const content = (editItemData.description || '').trim();
    const fallbackTitle = (editItemData.originalTitle || '').trim();
    const updateData = {
        title: '',
        description: content || fallbackTitle || null
    };
    if (editItemData.dateStr) {
        const dateStr = editItemData.dateStr + (editItemData.timeStr ? 'T' + editItemData.timeStr : 'T00:00');
        updateData.date = new Date(dateStr).toISOString();
    }
    try {
        await api.updateItem(adminKey.value, editItemData.id, updateData);
        await load(true);
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
        const data = await api.fetchConfig(adminKey.value);
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
    showAuthModal.value = true;
    authKeyInput.value = '';
};

const closeAuthModal = () => {
    if (authLoading.value) return;
    showAuthModal.value = false;
    authKeyInput.value = '';
};

const submitAuthLogin = async () => {
    if (authLoading.value) return;
    const key = authKeyInput.value.trim();
    if (!key) {
        uni.showToast({ title: '请输入密钥', icon: 'none' });
        return;
    }

    authLoading.value = true;
    try {
        await api.verifyKey(key);
        uni.setStorageSync('peanut_api_key', key);
        adminKey.value = key;
        showAuthModal.value = false;
        authKeyInput.value = '';
        uni.showToast({ title: '登录成功', icon: 'success' });
        initAfterAuth();
    } catch (e) {
        uni.showToast({ title: '密钥无效，请重新输入', icon: 'none' });
    } finally {
        authLoading.value = false;
    }
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

const loadGroupCounts = async () => {
    try {
        const data = await api.fetchItemCounts(
            adminKey.value || uni.getStorageSync('peanut_api_key') || '',
            searchQuery.value
        );
        yearCountMap.value = (data && data.year_counts) || {};
        monthCountMap.value = (data && data.month_counts) || {};
    } catch (e) {
        resetGroupCountMaps();
    }
};

const appendNextPage = async (limit) => {
    const data = await api.fetchItems(adminKey.value || uni.getStorageSync('peanut_api_key') || '', page.value, limit, searchQuery.value);
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
        if (isRefresh) {
            uni.stopPullDownRefresh();
        }
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
    if (!adminKey.value) return;
    loadMore();
});

// 搜索输入事件（H5 端兼容）
const onSearchInput = (e) => {
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

// ==================== 生命周期 ====================

const initAfterAuth = () => {
    loadConfig();
    load();
};

onLoad(() => {
    let key = '';
    let keyFromUrl = false;
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
            keyFromUrl = !!key;
        }
    }
    // #endif

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

    const tryKey = async (k, fromUrl = false) => {
        try {
            await api.verifyKey(k);
            uni.setStorageSync('peanut_api_key', k);
            adminKey.value = k;
            if (fromUrl) {
                clearUrlKey();
            }
            uni.showToast({ title: '管理员模式已激活', icon: 'none' });
            initAfterAuth();
        } catch (e) {
            uni.removeStorageSync('peanut_api_key');
            adminKey.value = '';
        }
    };

    if (key) {
        tryKey(key, keyFromUrl);
    } else {
        const stored = uni.getStorageSync('peanut_api_key');
        if (stored && !queryKeyDetected) {
            tryKey(stored, false);
        }
    }
});

onMounted(() => {
    initBackTopPosition();
    const now = new Date();
    dateValue.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    timeValue.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
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
  cursor: pointer;
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

.timeline-top-anchor {
    width: 100%;
    height: 0;
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

.settings-divider {
    height: 1px;
    background: var(--line);
    margin: 8px 0;
}

.settings-section-title {
    font-size: 0.8rem;
    color: var(--accent);
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
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

/* ==================== 回收站 ==================== */

.trash-modal {
    max-height: 85vh;
}

.trash-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 0;
}

.trash-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.trash-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: rgba(0, 0, 0, 0.02);
    border-radius: 12px;
    border: 1px solid var(--line);
}

.trash-thumb {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
}

.trash-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.trash-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--ink);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.trash-date {
    font-size: 0.7rem;
    color: var(--muted);
}

.trash-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

.trash-action-btn {
    padding: 6px 12px;
    border-radius: 99px;
    font-size: 0.75rem;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.2s;
}

.trash-action-btn.restore {
    background: rgba(76, 175, 80, 0.1);
    color: #4caf50;
    border: 1px solid rgba(76, 175, 80, 0.2);
}

.trash-action-btn.restore:active {
    background: rgba(76, 175, 80, 0.2);
}

.trash-action-btn.destroy {
    background: rgba(244, 67, 54, 0.08);
    color: #f44336;
    border: 1px solid rgba(244, 67, 54, 0.15);
}

.trash-action-btn.destroy:active {
    background: rgba(244, 67, 54, 0.2);
}

.danger-btn {
    background: #f44336 !important;
    color: #fff !important;
    border: none;
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
}

.auth-overlay {
    z-index: 11000;
}

.confirm-overlay {
    z-index: 12000;
}

.confirm-modal {
    max-width: 420px;
}

.confirm-body {
    gap: 0;
}

.confirm-text {
    font-size: 0.95rem;
    color: var(--ink);
    line-height: 1.6;
    white-space: pre-wrap;
}

.confirm-footer {
    display: flex;
    gap: 10px;
}

.confirm-footer .btn {
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

.auth-modal {
    max-width: 420px;
}

.auth-body {
    align-items: center;
    text-align: center;
    gap: 12px;
}

.auth-lock {
    font-size: 2.2rem;
    line-height: 1;
}

.auth-hint {
    font-size: 0.9rem;
    color: var(--muted);
}

.auth-input {
    width: 100%;
    text-align: left;
    margin-top: 4px;
}

.auth-footer {
    display: flex;
    gap: 10px;
}

.auth-footer .btn {
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
