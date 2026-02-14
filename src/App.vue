<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { apiGet, apiPost, apiUpload } from "./api";

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
  file: null
});
const uploadLinkForm = reactive({
  title: "",
  description: "",
  game_date: "",
  url: ""
});

const isAuthenticated = computed(() => !!user.value);
const activeTeam = computed(() =>
  teams.value.find((team) => Number(team.id) === Number(activeTeamId.value))
);

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

function setBanner(type, message) {
  banner.type = type;
  banner.message = message;
}

function clearBanner() {
  banner.type = "";
  banner.message = "";
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
  try {
    const payload = await apiGet("session");
    if (payload.authenticated) {
      applySession(payload);
      if (activeTeamId.value) {
        await loadMedia();
      }
    } else {
      user.value = null;
      teams.value = [];
      activeTeamId.value = 0;
    }
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
    await loadMedia();
    setBanner("success", "Account created and team ready.");
  });
}

async function login() {
  await withBusy(async () => {
    const payload = await apiPost("auth_login", loginForm);
    applySession(payload);
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

async function joinTeam() {
  await withBusy(async () => {
    const payload = await apiPost("team_join", joinTeamForm);
    teams.value = payload.teams || [];
    joinTeamForm.join_code = "";
    if (payload.joined_team_id) {
      activeTeamId.value = Number(payload.joined_team_id);
      localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value));
      await loadMedia();
    }
    setBanner("success", "Joined team.");
  });
}

async function switchTeam() {
  clearBanner();
  localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value));
  await withBusy(loadMedia);
}

async function uploadFile() {
  if (!uploadFileForm.file) {
    setBanner("error", "Choose a photo or video file first.");
    return;
  }
  await withBusy(async () => {
    const formData = new FormData();
    formData.append("team_id", String(activeTeamId.value));
    formData.append("title", uploadFileForm.title);
    formData.append("description", uploadFileForm.description);
    formData.append("game_date", uploadFileForm.game_date);
    formData.append("file", uploadFileForm.file);
    await apiUpload("media_upload", formData);
    uploadFileForm.title = "";
    uploadFileForm.description = "";
    uploadFileForm.game_date = "";
    uploadFileForm.file = null;
    await loadMedia();
    setBanner("success", "Media uploaded.");
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

async function deleteMedia(id) {
  if (!window.confirm("Delete this media item?")) return;
  await withBusy(async () => {
    await apiPost("media_delete", { media_id: id });
    await loadMedia();
    if (modalItem.value?.id === id) modalItem.value = null;
    setBanner("success", "Media removed.");
  });
}

function onFileInput(event) {
  uploadFileForm.file = event.target.files?.[0] || null;
}

function displayDate(date) {
  if (!date) return "No date";
  return new Date(`${date}T00:00:00`).toLocaleDateString();
}

onMounted(loadSession);
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div>
        <p class="brand-kicker">Slapshot Snapshot</p>
        <h1>Team Media Hub</h1>
      </div>
      <button v-if="isAuthenticated" class="btn btn-ghost" :disabled="busy" @click="logout">
        Sign out
      </button>
    </header>

    <p v-if="banner.message" :class="['banner', banner.type]">{{ banner.message }}</p>

    <section v-if="loadingSession" class="panel">
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
        <label>
          <span>Name</span>
          <input v-model="registerForm.display_name" required />
        </label>
        <label>
          <span>Email</span>
          <input v-model="registerForm.email" type="email" required />
        </label>
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
        <label>
          <span>Email</span>
          <input v-model="loginForm.email" type="email" required />
        </label>
        <label>
          <span>Password</span>
          <input v-model="loginForm.password" type="password" required />
        </label>
        <button class="btn" :disabled="busy">Sign in</button>
      </form>
    </section>

    <section v-else class="layout">
      <aside class="panel sidebar">
        <h2>Your Teams</h2>
        <label>
          <span>Active Team</span>
          <select v-model="activeTeamId" @change="switchTeam">
            <option v-for="team in teams" :key="team.id" :value="Number(team.id)">
              {{ team.name }} ({{ team.member_count }} members)
            </option>
          </select>
        </label>
        <p v-if="activeTeam" class="invite">
          Invite code: <strong>{{ activeTeam.join_code }}</strong>
        </p>

        <form class="stack" @submit.prevent="createTeam">
          <h3>Create Team</h3>
          <input v-model="createTeamForm.name" placeholder="Next season team name" required />
          <button class="btn" :disabled="busy">Create Team</button>
        </form>

        <form class="stack" @submit.prevent="joinTeam">
          <h3>Join Team</h3>
          <input v-model="joinTeamForm.join_code" placeholder="Enter join code" required />
          <button class="btn btn-secondary" :disabled="busy">Join</button>
        </form>
      </aside>

      <main class="panel main">
        <section class="upload-grid">
          <form class="stack" @submit.prevent="uploadFile">
            <h3>Upload Photo/Video</h3>
            <input v-model="uploadFileForm.title" placeholder="Title" />
            <textarea v-model="uploadFileForm.description" rows="2" placeholder="Description" />
            <input v-model="uploadFileForm.game_date" type="date" />
            <input type="file" accept="image/*,video/*" @change="onFileInput" required />
            <button class="btn" :disabled="busy || !activeTeamId">Upload</button>
          </form>

          <form class="stack" @submit.prevent="uploadLink">
            <h3>Share YouTube Video</h3>
            <input v-model="uploadLinkForm.title" placeholder="Title" required />
            <textarea v-model="uploadLinkForm.description" rows="2" placeholder="Description" />
            <input v-model="uploadLinkForm.game_date" type="date" />
            <input
              v-model="uploadLinkForm.url"
              type="url"
              placeholder="https://youtube.com/watch?v=..."
              required
            />
            <button class="btn btn-secondary" :disabled="busy || !activeTeamId">Share Link</button>
          </form>
        </section>

        <section class="toolbar">
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
          <input v-model="searchQuery" placeholder="Search titles, captions, uploader..." />
        </section>

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
            <img
              v-else
              :src="item.thumbnail_url || '/video-placeholder.svg'"
              :alt="item.title"
            />
            <div class="copy">
              <p class="meta">{{ displayDate(item.game_date) }} · {{ item.uploader_name }}</p>
              <h4>{{ item.title }}</h4>
              <p>{{ item.description || "No description yet." }}</p>
            </div>
          </article>
          <p v-if="filteredMedia.length === 0" class="empty">No media found for this filter yet.</p>
        </section>
      </main>
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
