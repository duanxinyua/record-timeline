<script>
export default {
  onLaunch: function () {
    console.log('App Launch')
  },
  onShow: function () {
    console.log('App Show')
  },
  onHide: function () {
    console.log('App Hide')
  },
}
</script>

<style>
/*每个页面公共css */
@import url("https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap");

:root {
  --bg: #FFFBF0;
  --bg-strong: #FFF0E0;
  --card: rgba(255, 255, 255, 0.9);
  --ink: #5D4037;
  --muted: #9D8177;
  --accent: #E6B475;
  --accent-2: #D4A373;
  --line: #E6D5C4;
  --shadow: 0 20px 40px rgba(93, 64, 55, 0.08);
}

view, text, image, scroll-view, input, button, label {
  box-sizing: border-box;
}

page {
  /* body styles go to page in uni-app */
  margin: 0;
  font-family: "Space Grotesk", "IBM Plex Sans", sans-serif;
  background: radial-gradient(
      circle at 10% 10%,
      rgba(255, 183, 178, 0.15),
      transparent 60%
    ),
    radial-gradient(
      circle at 90% 20%,
      rgba(230, 180, 117, 0.12),
      transparent 60%
    ),
    linear-gradient(180deg, var(--bg), var(--bg-strong));
  color: var(--ink);
  min-height: 100vh;
}

.bg-noise {
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)' opacity='.05'/%3E%3C/svg%3E");
  opacity: 0.4;
  pointer-events: none;
  z-index: 0;
}
</style>
