<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { apiGet, apiPost } from "./api";

const APP_URL = "https://snap.pucc.us";

const loadingSession = ref(true);
const busy = ref(false);
const user = ref(null);
const teams = ref([]);
const activeTeamId = ref(0);
const mediaItems = ref([]);
const modalItem = ref(null);
const mediaFilter = ref("all");
const searchQuery = ref("");
const banner = reactive({ type: "", message: "" });
const authMode = ref("register");
const activeTab = ref("overview");
const dragActive = ref(false);
const pendingJoinCode = ref("");
const uploadQueue = ref([]);

const loginForm = reactive({ email: "", password: "" });
const registerForm = reactive({
  display_name: "",
  email: "",
  password: "",
  team_name: ""
});
const createTeamForm = reactive({ name: "" });
const joinTeamForm = reactive({ join_code: "" });
const uploadFileForm = reactive({
  title: "",
  description: "",
  game_date: "",
  files: []
});
const uploadLinkForm = reactive({
  title: "",
  description: "",
  game_date: "",
  url: ""
});
const emailInviteForm = reactive({
  email: "",
  message: ""
});

const isAuthenticated = computed(() => !!user.value);
const activeTeam = computed(() =>
  teams.value.find((team) => Number(team.id) === Number(activeTeamId.value))
);
const photoCount = computed(
  () => mediaItems.value.filter((item) => item.media_type === "photo").length
);
const videoCount = computed(
  () => mediaItems.value.filter((item) => item.media_type === "video").length
);
const newestMedia = computed(() => mediaItems.value[0] || null);
const inviteLink = computed(() =>
  activeTeam.value ? `${APP_URL}/?join=${encodeURIComponent(activeTeam.value.join_code)}` : APP_URL
);
const inviteCopy = computed(() => {
  if (!activeTeam.value) return "";
  return `Join "${activeTeam.value.name}" on Slapshot Snapshot with code ${activeTeam.value.join_code}. ${inviteLink.value}`;
});
const filteredMedia = computed(() => {
  const type = mediaFilter.value;
  const query = searchQuery.value.toLowerCase().trim();
  return mediaItems.value.filter((item) => {
    if (type !== "all" && item.media_type !== type) return false;
    if (!query) return true;
    const text = `${item.title} ${item.description || ""} ${item.uploader_name}`.toLowerCase();
    return text.includes(query);
  });
});
const totalUploadProgress = computed(() => {
  if (!uploadQueue.value.length) return 0;
  const sum = uploadQueue.value.reduce((acc, item) => acc + item.progress, 0);
  return Math.round(sum / uploadQueue.value.length);
});

function setBanner(type, message) {
  banner.type = type;
  banner.message = message;
}

function clearBanner() {
  banner.type = "";
  banner.message = "";
}

function displayDate(date) {
  if (!date) return "No date";
  return new Date(`${date}T00:00:00`).toLocaleDateString();
}

function extractJoinCodeFromUrl() {
  const query = new URLSearchParams(window.location.search);
  const code = (query.get("join") || "").trim().toUpperCase();
  return code.length >= 6 ? code : "";
}

function removeJoinQueryParam() {
  const url = new URL(window.location.href);
  if (url.searchParams.has("join")) {
    url.searchParams.delete("join");
    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }
}

function applySession(payload) {
  user.value = payload.user || null;
  teams.value = payload.teams || [];
  const stored = Number(localStorage.getItem("slapshot_active_team_id") || 0);
  if (teams.value.some((t) => Number(t.id) === stored)) {
    activeTeamId.value = stored;
  } else {
    activeTeamId.value = teams.value[0]?.id ? Number(teams.value[0].id) : 0;
  }
}

async function loadSession() {
  loadingSession.value = true;
  pendingJoinCode.value = extractJoinCodeFromUrl();

  try {
    const payload = await apiGet("session");
    if (!payload.authenticated) {
      user.value = null;
      teams.value = [];
      activeTeamId.value = 0;
      if (pendingJoinCode.value) {
        joinTeamForm.join_code = pendingJoinCode.value;
        setBanner("success", "Invite code detected. Sign in, then tap Join Team.");
      }
      return;
    }

    applySession(payload);
    if (activeTeamId.value) await loadMedia();
    if (pendingJoinCode.value) await joinTeam(true);
  } catch (err) {
    setBanner("error", err.message);
  } finally {
    loadingSession.value = false;
  }
}

async function loadMedia() {
  if (!activeTeamId.value) return;
  const payload = await apiGet("media_list", { team_id: activeTeamId.value });
  mediaItems.value = payload.items || [];
}

async function withBusy(task) {
  clearBanner();
  busy.value = true;
  try {
    await task();
  } catch (err) {
    setBanner("error", err.message || "Request failed.");
  } finally {
    busy.value = false;
  }
}

async function register() {
  await withBusy(async () => {
    const payload = await apiPost("auth_register", registerForm);
    applySession(payload);
    if (pendingJoinCode.value) {
      joinTeamForm.join_code = pendingJoinCode.value;
      await joinTeam(true);
      return;
    }
    await loadMedia();
    setBanner("success", "Account created and team ready.");
  });
}

async function login() {
  await withBusy(async () => {
    const payload = await apiPost("auth_login", loginForm);
    applySession(payload);
    if (pendingJoinCode.value) {
      joinTeamForm.join_code = pendingJoinCode.value;
      await joinTeam(true);
      return;
    }
    await loadMedia();
    setBanner("success", "Welcome back.");
  });
}

async function logout() {
  await withBusy(async () => {
    await apiPost("auth_logout");
    user.value = null;
    teams.value = [];
    activeTeamId.value = 0;
    mediaItems.value = [];
    localStorage.removeItem("slapshot_active_team_id");
  });
}

async function createTeam() {
  await withBusy(async () => {
    const payload = await apiPost("team_create", createTeamForm);
    teams.value = payload.teams || [];
    createTeamForm.name = "";
    if (payload.created_team_id) {
      activeTeamId.value = Number(payload.created_team_id);
      localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value));
      await loadMedia();
    }
    setBanner("success", "New team created.");
  });
}

async function joinTeam(silent = false) {
  await withBusy(async () => {
    const joinCode = (joinTeamForm.join_code || pendingJoinCode.value || "").trim().toUpperCase();
    if (!joinCode) {
      setBanner("error", "Enter an invite code first.");
      return;
    }
    const payload = await apiPost("team_join", { join_code: joinCode });
    teams.value = payload.teams || [];
    if (payload.joined_team_id) {
      activeTeamId.value = Number(payload.joined_team_id);
      localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value));
      await loadMedia();
    }
    joinTeamForm.join_code = "";
    pendingJoinCode.value = "";
    removeJoinQueryParam();
    if (!silent) setBanner("success", "Joined team.");
  });
}

async function switchTeam() {
  clearBanner();
  localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value));
  await withBusy(loadMedia);
}

function setSelectedFiles(fileList) {
  const files = Array.from(fileList || []);
  uploadFileForm.files = files;
  uploadQueue.value = files.map((file) => ({
    name: file.name,
    size: file.size,
    progress: 0,
    status: "pending"
  }));
}

function onFileInput(event) {
  setSelectedFiles(event.target.files);
}

function onDrop(event) {
  event.preventDefault();
  dragActive.value = false;
  setSelectedFiles(event.dataTransfer?.files);
}

function onDragOver(event) {
  event.preventDefault();
  dragActive.value = true;
}

function onDragLeave() {
  dragActive.value = false;
}

function uploadSingleFile(file, queueIndex, titleForFile) {
  return new Promise((resolve, reject) => {
    const formData = new FormData();
    formData.append("team_id", String(activeTeamId.value));
    formData.append("title", titleForFile);
    formData.append("description", uploadFileForm.description);
    formData.append("game_date", uploadFileForm.game_date);
    formData.append("file", file);

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "/api/index.php?action=media_upload", true);
    xhr.withCredentials = true;
    xhr.upload.onprogress = (event) => {
      if (!event.lengthComputable) return;
      uploadQueue.value[queueIndex].progress = Math.round((event.loaded / event.total) * 100);
      uploadQueue.value[queueIndex].status = "uploading";
    };

    xhr.onload = () => {
      try {
        const data = JSON.parse(xhr.responseText || "{}");
        if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
          uploadQueue.value[queueIndex].progress = 100;
          uploadQueue.value[queueIndex].status = "done";
          resolve();
          return;
        }
        const msg = data.error || "Upload failed.";
        uploadQueue.value[queueIndex].status = "error";
        reject(new Error(msg));
      } catch {
        uploadQueue.value[queueIndex].status = "error";
        reject(new Error("Invalid server response."));
      }
    };

    xhr.onerror = () => {
      uploadQueue.value[queueIndex].status = "error";
      reject(new Error("Network error during upload."));
    };

    xhr.send(formData);
  });
}

async function uploadFiles() {
  if (!uploadFileForm.files.length) {
    setBanner("error", "Choose one or more photo/video files first.");
    return;
  }

  await withBusy(async () => {
    for (let i = 0; i < uploadFileForm.files.length; i++) {
      const file = uploadFileForm.files[i];
      const baseTitle = uploadFileForm.title.trim();
      const titleForFile =
        baseTitle === ""
          ? file.name.replace(/\.[^.]+$/, "")
          : uploadFileForm.files.length > 1
            ? `${baseTitle} (${i + 1}/${uploadFileForm.files.length})`
            : baseTitle;
      await uploadSingleFile(file, i, titleForFile);
    }

    uploadFileForm.title = "";
    uploadFileForm.description = "";
    uploadFileForm.game_date = "";
    uploadFileForm.files = [];
    await loadMedia();
    setBanner("success", "Uploads complete.");
  });
}

async function uploadLink() {
  await withBusy(async () => {
    await apiPost("media_external", {
      team_id: activeTeamId.value,
      ...uploadLinkForm
    });
    uploadLinkForm.title = "";
    uploadLinkForm.description = "";
    uploadLinkForm.game_date = "";
    uploadLinkForm.url = "";
    await loadMedia();
    setBanner("success", "Video link shared.");
  });
}

async function sendInviteEmail() {
  await withBusy(async () => {
    await apiPost("invite_email", {
      team_id: activeTeamId.value,
      email: emailInviteForm.email,
      message: emailInviteForm.message
    });
    emailInviteForm.email = "";
    emailInviteForm.message = "";
    setBanner("success", "Invite email sent.");
  });
}

async function deleteMedia(id) {
  if (!window.confirm("Delete this media item?")) return;
  await withBusy(async () => {
    await apiPost("media_delete", { media_id: id });
    await loadMedia();
    if (modalItem.value?.id === id) modalItem.value = null;
    setBanner("success", "Media removed.");
  });
}

async function copyInviteCode() {
  if (!activeTeam.value) return;
  try {
    await navigator.clipboard.writeText(activeTeam.value.join_code);
    setBanner("success", "Invite code copied.");
  } catch {
    setBanner("error", "Clipboard not available on this browser.");
  }
}

async function copyInviteMessage() {
  if (!activeTeam.value) return;
  try {
    await navigator.clipboard.writeText(inviteCopy.value);
    setBanner("success", "Invite message copied.");
  } catch {
    setBanner("error", "Clipboard not available on this browser.");
  }
}

async function copyInviteLink() {
  if (!activeTeam.value) return;
  try {
    await navigator.clipboard.writeText(inviteLink.value);
    setBanner("success", "Invite link copied.");
  } catch {
    setBanner("error", "Clipboard not available on this browser.");
  }
}

async function nativeShareInvite() {
  if (!activeTeam.value) return;
  if (!navigator.share) {
    setBanner("error", "Native share not supported on this browser.");
    return;
  }
  try {
    await navigator.share({
      title: `Join ${activeTeam.value.name}`,
      text: inviteCopy.value,
      url: inviteLink.value
    });
    setBanner("success", "Invite shared.");
  } catch {
    // no-op on cancel
  }
}

function smsInviteLink() {
  const text = encodeURIComponent(inviteCopy.value);
  return `sms:?&body=${text}`;
}

function emailInviteLink() {
  const subject = encodeURIComponent(`Join ${activeTeam.value?.name || "our team"} on Slapshot Snapshot`);
  const body = encodeURIComponent(inviteCopy.value);
  return `mailto:?subject=${subject}&body=${body}`;
}

onMounted(loadSession);
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand">
        <p class="brand-kicker">Slapshot Snapshot</p>
        <h1>Rink Relay</h1>
      </div>
      <button v-if="isAuthenticated" class="btn btn-ghost" :disabled="busy" @click="logout">
        Sign out
      </button>
    </header>

    <p v-if="banner.message" :class="['banner', banner.type]">{{ banner.message }}</p>

    <section v-if="loadingSession" class="panel padded">
      <p>Loading session...</p>
    </section>

    <section v-else-if="!isAuthenticated" class="panel auth-panel">
      <div class="switcher">
        <button :class="{ active: authMode === 'register' }" @click="authMode = 'register'">
          Create account
        </button>
        <button :class="{ active: authMode === 'login' }" @click="authMode = 'login'">
          Sign in
        </button>
      </div>
      <form v-if="authMode === 'register'" class="form-grid" @submit.prevent="register">
        <label><span>Name</span><input v-model="registerForm.display_name" required /></label>
        <label><span>Email</span><input v-model="registerForm.email" type="email" required /></label>
        <label>
          <span>Password</span>
          <input v-model="registerForm.password" type="password" minlength="8" required />
        </label>
        <label>
          <span>First Team Name</span>
          <input v-model="registerForm.team_name" required />
        </label>
        <button class="btn" :disabled="busy">Create account & team</button>
      </form>
      <form v-else class="form-grid" @submit.prevent="login">
        <label><span>Email</span><input v-model="loginForm.email" type="email" required /></label>
        <label>
          <span>Password</span>
          <input v-model="loginForm.password" type="password" required />
        </label>
        <button class="btn" :disabled="busy">Sign in</button>
      </form>
    </section>

    <section v-else class="dashboard">
      <nav class="app-nav panel">
        <button :class="{ active: activeTab === 'overview' }" @click="activeTab = 'overview'">
          Overview
        </button>
        <button :class="{ active: activeTab === 'upload' }" @click="activeTab = 'upload'">Upload</button>
        <button :class="{ active: activeTab === 'gallery' }" @click="activeTab = 'gallery'">
          Gallery
        </button>
        <button :class="{ active: activeTab === 'teams' }" @click="activeTab = 'teams'">Teams</button>
      </nav>

      <section v-if="activeTab === 'overview'" class="grid-overview">
        <article class="panel hero">
          <p class="eyebrow">Active Team</p>
          <h2>{{ activeTeam?.name || "No Team Selected" }}</h2>
          <p class="hero-copy">
            Build a private season timeline with clips, photos, and invite-only family access.
          </p>
          <div class="stats">
            <article><p>Total</p><strong>{{ mediaItems.length }}</strong></article>
            <article><p>Photos</p><strong>{{ photoCount }}</strong></article>
            <article><p>Videos</p><strong>{{ videoCount }}</strong></article>
            <article><p>Latest</p><strong>{{ newestMedia ? displayDate(newestMedia.game_date) : "-" }}</strong></article>
          </div>
        </article>

        <article class="panel invite-card">
          <h3>Invite Center</h3>
          <p class="invite-code">{{ activeTeam?.join_code || "----" }}</p>
          <div class="button-row">
            <button class="btn btn-ghost" @click="copyInviteCode">Copy Code</button>
            <button class="btn btn-ghost" @click="copyInviteLink">Copy Link</button>
            <button class="btn btn-ghost" @click="copyInviteMessage">Copy Invite</button>
          </div>
          <div class="button-row">
            <button class="btn" @click="nativeShareInvite">Share</button>
            <a class="btn btn-secondary link-btn" :href="smsInviteLink()">Text</a>
            <a class="btn btn-secondary link-btn" :href="emailInviteLink()">Email App</a>
          </div>
          <form class="stack compact" @submit.prevent="sendInviteEmail">
            <h4>Send Auto Email Invite</h4>
            <input
              v-model="emailInviteForm.email"
              type="email"
              placeholder="family@example.com"
              required
            />
            <textarea
              v-model="emailInviteForm.message"
              rows="2"
              placeholder="Optional personal message"
            />
            <button class="btn" :disabled="busy || !activeTeamId">Send Invite Email</button>
          </form>
        </article>
      </section>

      <section v-if="activeTab === 'upload'" class="upload-grid">
        <article class="panel stack">
          <h3>Multi-File Upload</h3>
          <div
            :class="['dropzone', { active: dragActive }]"
            @drop="onDrop"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
          >
            <p>Drag & drop files here or choose manually</p>
            <input type="file" accept="image/*,video/*" multiple @change="onFileInput" />
          </div>
          <input v-model="uploadFileForm.title" placeholder="Optional shared title prefix" />
          <textarea v-model="uploadFileForm.description" rows="2" placeholder="Description" />
          <input v-model="uploadFileForm.game_date" type="date" />
          <button class="btn" :disabled="busy || !activeTeamId || !uploadFileForm.files.length" @click="uploadFiles">
            Upload {{ uploadFileForm.files.length || "" }} File{{ uploadFileForm.files.length === 1 ? "" : "s" }}
          </button>
          <div v-if="uploadQueue.length" class="queue">
            <p class="meta">Overall Progress: {{ totalUploadProgress }}%</p>
            <article v-for="(item, index) in uploadQueue" :key="item.name + index">
              <div class="queue-head">
                <span>{{ item.name }}</span>
                <strong>{{ item.progress }}%</strong>
              </div>
              <div class="bar"><span :style="{ width: `${item.progress}%` }" /></div>
            </article>
          </div>
        </article>

        <article class="panel stack">
          <h3>Share YouTube Clip</h3>
          <input v-model="uploadLinkForm.title" placeholder="Title" required />
          <textarea v-model="uploadLinkForm.description" rows="2" placeholder="Description" />
          <input v-model="uploadLinkForm.game_date" type="date" />
          <input
            v-model="uploadLinkForm.url"
            type="url"
            placeholder="https://youtube.com/watch?v=..."
            required
          />
          <button class="btn btn-secondary" :disabled="busy || !activeTeamId" @click="uploadLink">
            Share Video Link
          </button>
        </article>
      </section>

      <section v-if="activeTab === 'gallery'" class="panel padded">
        <div class="toolbar">
          <div class="chips">
            <button
              v-for="type in ['all', 'photo', 'video']"
              :key="type"
              :class="['chip', { active: mediaFilter === type }]"
              @click="mediaFilter = type"
            >
              {{ type }}
            </button>
          </div>
          <input v-model="searchQuery" placeholder="Search title, caption, uploader..." />
        </div>

        <section class="gallery">
          <article
            v-for="item in filteredMedia"
            :key="item.id"
            class="media-card"
            @click="modalItem = item"
          >
            <img
              v-if="item.media_type === 'photo'"
              :src="item.file_path || item.thumbnail_url"
              :alt="item.title"
            />
            <img v-else :src="item.thumbnail_url || '/video-placeholder.svg'" :alt="item.title" />
            <div class="copy">
              <p class="meta">{{ displayDate(item.game_date) }} · {{ item.uploader_name }}</p>
              <h4>{{ item.title }}</h4>
              <p>{{ item.description || "No description yet." }}</p>
            </div>
          </article>
        </section>
        <p v-if="filteredMedia.length === 0" class="empty">No media found for this filter yet.</p>
      </section>

      <section v-if="activeTab === 'teams'" class="panel padded team-admin">
        <h3>Team Management</h3>
        <label>
          <span>Active Team</span>
          <select v-model="activeTeamId" @change="switchTeam">
            <option v-for="team in teams" :key="team.id" :value="Number(team.id)">
              {{ team.name }} ({{ team.member_count }} members)
            </option>
          </select>
        </label>
        <div class="team-actions">
          <form class="stack compact" @submit.prevent="createTeam">
            <h4>Create New Team</h4>
            <input v-model="createTeamForm.name" placeholder="Next season team name" required />
            <button class="btn" :disabled="busy">Create Team</button>
          </form>
          <form class="stack compact" @submit.prevent="joinTeam">
            <h4>Join Existing Team</h4>
            <input v-model="joinTeamForm.join_code" placeholder="Enter invite code" required />
            <button class="btn btn-secondary" :disabled="busy">Join Team</button>
          </form>
        </div>
      </section>
    </section>

    <section v-if="modalItem" class="modal-backdrop" @click.self="modalItem = null">
      <article class="modal-card">
        <button class="btn btn-ghost" @click="modalItem = null">Close</button>
        <h3>{{ modalItem.title }}</h3>
        <p class="meta">{{ displayDate(modalItem.game_date) }} · {{ modalItem.uploader_name }}</p>
        <p>{{ modalItem.description || "No description." }}</p>
        <img
          v-if="modalItem.media_type === 'photo'"
          :src="modalItem.file_path || modalItem.thumbnail_url"
          :alt="modalItem.title"
          class="viewer"
        />
        <video
          v-else-if="modalItem.storage_type === 'upload'"
          :src="modalItem.file_path"
          controls
          class="viewer"
        />
        <iframe
          v-else
          :src="modalItem.external_url"
          title="Shared video"
          allowfullscreen
          class="viewer frame"
        />
        <button class="btn btn-danger" @click="deleteMedia(modalItem.id)">Delete</button>
      </article>
    </section>
  </div>
</template>
