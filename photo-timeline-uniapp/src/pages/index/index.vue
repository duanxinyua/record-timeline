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
    </view>

    <view class="timeline-shell">
      <view class="timeline-header">
        <text class="h2">{{ appConfig.timelineTitle }}</text>
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
                  <image 
                    class="photo" 
                    :src="item.thumb || item.src" 
                    mode="aspectFill"
                    @click="previewImage(item.src)"
                  ></image>
                  <view class="card-body">
                    <text class="date">{{ formatDate(item.date) }}</text>
                    <text class="title">{{ item.title || appConfig.defaultItemTitle }}</text>
                  </view>
                </view>
              </view>
            </view>
        </view>
      </scroll-view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import config from '../../config.js';

const STORAGE_KEY = 'photoTimelineItems';

// Config
const appConfig = reactive({...config});

// State
const items = ref([]);

// Dynamic API Base URL
const getApiBaseUrl = () => {
    return 'https://api.hetao.us';
};

const API_BASE = getApiBaseUrl();

// Helpers
const previewImage = (url) => {
    uni.previewImage({
        urls: [url]
    });
};

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

// Actions
const fetchConfig = () => {
  uni.request({
      url: `${API_BASE}/config`,
      method: 'GET',
      success: (res) => {
          if (res.statusCode === 200) {
              const data = res.data;
              // Override config
              Object.assign(appConfig, data);
              // Update nav title
              uni.setNavigationBarTitle({
                  title: appConfig.appTitle
              });
          }
      },
      fail: (e) => {
          console.error("Config fetch failed", e);
      }
  });
};

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
  return [...items.value].sort((a, b) => new Date(b.date) - new Date(a.date)); // Sort DESC for vertical? Or ASC? Usually DESC (newest top) or ASC. Keeping separate from horizontal change, but let's stick to user preference. Original was ASC. Let's keep ASC.
});

// Lifecycle
onMounted(() => {
  // Set default title first
  uni.setNavigationBarTitle({
      title: appConfig.appTitle
  });
  
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
  padding: 0 6vw 80px; /* Reduced top padding */
}

.timeline-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
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

.timeline-item {
  position: relative;
  width: 100%;
  padding-left: 60px; /* Space for axis and dot */
  display: block;
}

/* Reset margin from previous horizontal layout */
.timeline-item + .timeline-item {
  margin-left: 0;
}

.dot {
  position: absolute;
  top: 24px; /* Align with card top */
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
  width: 100%; /* Full width of container */
}

.card:active {
  transform: scale(0.99);
}

.photo {
  width: 100%;
  height: auto;
  aspect-ratio: 4/3; /* Consistent aspect ratio */
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
</style>
