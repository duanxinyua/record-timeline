const storageKey = "photoTimelineItems";

const form = document.getElementById("upload-form");
const dateInput = document.getElementById("date-input");
const captionInput = document.getElementById("caption-input");
const fileInput = document.getElementById("file-input");
const clearBtn = document.getElementById("clear-btn");
const track = document.getElementById("timeline-track");
const template = document.getElementById("timeline-item");
const timeline = document.querySelector(".timeline");
const scrollLeftBtn = document.getElementById("scroll-left");
const scrollRightBtn = document.getElementById("scroll-right");

const state = {
  items: [],
};

const toInputValue = (date) => {
  const pad = (value) => String(value).padStart(2, "0");
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return `${local.getFullYear()}-${pad(local.getMonth() + 1)}-${pad(
    local.getDate()
  )}T${pad(local.getHours())}:${pad(local.getMinutes())}`;
};

const formatDate = (value) => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "未知时间";
  return date.toLocaleString("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const createId = () =>
  (window.crypto && crypto.randomUUID && crypto.randomUUID()) ||
  `id-${Date.now()}-${Math.random().toString(16).slice(2)}`;

const readFile = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

const load = () => {
  try {
    const raw = localStorage.getItem(storageKey);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    return [];
  }
};

const save = () => {
  localStorage.setItem(storageKey, JSON.stringify(state.items));
};

const renderEmpty = () => {
  const empty = document.createElement("li");
  empty.className = "empty";
  empty.textContent = "还没有照片，先上传几张吧。";
  track.appendChild(empty);
};

const render = () => {
  track.innerHTML = "";
  if (!state.items.length) {
    renderEmpty();
    return;
  }

  state.items
    .slice()
    .sort((a, b) => new Date(a.date) - new Date(b.date))
    .forEach((item, index) => {
      const node = template.content.cloneNode(true);
      const li = node.querySelector(".timeline-item");
      const img = node.querySelector(".photo");
      const date = node.querySelector(".date");
      const title = node.querySelector(".title");
      const delBtn = node.querySelector(".delete");

      li.style.setProperty("--i", index);
      li.dataset.id = item.id;

      img.src = item.src;
      img.alt = item.title || "时间轴照片";
      date.textContent = formatDate(item.date);
      title.textContent = item.title || "未命名照片";

      delBtn.addEventListener("click", () => {
        state.items = state.items.filter((entry) => entry.id !== item.id);
        save();
        render();
      });

      track.appendChild(node);
    });
};

const addItems = async (files, dateValue, caption) => {
  const date = dateValue ? new Date(dateValue) : new Date();
  const dateIso = date.toISOString();

  const payloads = await Promise.all(
    Array.from(files).map(async (file) => {
      const src = await readFile(file);
      const baseName = file.name.replace(/\.[^/.]+$/, "");
      return {
        id: createId(),
        date: dateIso,
        title: caption || baseName,
        src,
      };
    })
  );

  state.items = state.items.concat(payloads);
  save();
  render();
  timeline.scrollTo({ left: timeline.scrollWidth, behavior: "smooth" });
};

form.addEventListener("submit", async (event) => {
  event.preventDefault();

  if (!fileInput.files.length) {
    fileInput.focus();
    return;
  }

  const dateValue = dateInput.value;
  const caption = captionInput.value.trim();

  try {
    await addItems(fileInput.files, dateValue, caption);
  } catch (err) {
    alert("读取照片失败，请重试。");
  }

  fileInput.value = "";
});

clearBtn.addEventListener("click", () => {
  if (!state.items.length) return;
  const ok = window.confirm("确定要清空所有照片吗？");
  if (!ok) return;
  state.items = [];
  save();
  render();
});

scrollLeftBtn.addEventListener("click", () => {
  timeline.scrollBy({ left: -360, behavior: "smooth" });
});

scrollRightBtn.addEventListener("click", () => {
  timeline.scrollBy({ left: 360, behavior: "smooth" });
});

state.items = load();
dateInput.value = toInputValue(new Date());
render();
