<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { apiGet, apiPost, apiUpload } from "./api";

const APP_URL = "https://snap.pucc.us";
const PAGE_SIZE = 18;
const API_MEDIA_PAGE_SIZE = 60;

const loadingSession = ref(true);
const busy = ref(false);
const user = ref(null);
const teams = ref([]);
const activeTeamId = ref(0);
const mediaItems = ref([]);
const modalItem = ref(null);
const mediaFilter = ref("all");
const searchQuery = ref("");
const sortBy = ref("newest");
const groupBy = ref("none");
const galleryView = ref("cinematic");
const selectionMode = ref(false);
const selectedMediaIds = ref([]);
const banner = reactive({ type: "", message: "" });
const authMode = ref("register");
const activeTab = ref("overview");
const dragActive = ref(false);
const pendingJoinCode = ref("");
const uploadQueue = ref([]);
const teamMembers = ref([]);
const membersLoading = ref(false);
const visibleCount = ref(PAGE_SIZE);
const loadMoreSentinel = ref(null);
const mediaOffset = ref(0);
const mediaHasMoreServer = ref(false);
const mediaLoadingMore = ref(false);
const mediaStats = reactive({ total: 0, photos: 0, videos: 0 });
let observer = null;
let keyHandler = null;

const loginForm = reactive({ email: "", password: "" });
const registerForm = reactive({
  display_name: "",
  email: "",
  password: "",
  team_name: ""
});
const createTeamForm = reactive({ name: "" });
const joinTeamForm = reactive({ join_code: "" });
const teamProfileForm = reactive({
  name: "",
  age_group: "",
  season_year: "",
  level: "",
  home_rink: "",
  city: "",
  team_notes: ""
});
const deleteTeamForm = reactive({
  confirm_team_name: "",
  confirm_word: ""
});
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
const accountProfileForm = reactive({
  display_name: "",
  current_password: "",
  new_password: "",
  new_password_confirm: ""
});
const emailChangeForm = reactive({
  requested_email: "",
  reason: ""
});
const teamLogoInput = ref(null);

const isAuthenticated = computed(() => !!user.value);
const activeTeam = computed(() =>
  teams.value.find((team) => Number(team.id) === Number(activeTeamId.value))
);
const activeTeamRole = computed(() => activeTeam.value?.role || "member");
const canManageMembers = computed(
  () => activeTeamRole.value === "owner" || activeTeamRole.value === "admin"
);
const canDeleteTeam = computed(() => activeTeamRole.value === "owner");
const photoCount = computed(() => mediaStats.photos || mediaItems.value.filter((item) => item.media_type === "photo").length);
const videoCount = computed(() => mediaStats.videos || mediaItems.value.filter((item) => item.media_type === "video").length);
const totalMediaCount = computed(() => mediaStats.total || mediaItems.value.length);
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
const sortedMedia = computed(() => {
  const items = [...filteredMedia.value];
  switch (sortBy.value) {
    case "oldest":
      return items.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    case "title":
      return items.sort((a, b) => a.title.localeCompare(b.title));
    case "uploader":
      return items.sort((a, b) => a.uploader_name.localeCompare(b.uploader_name));
    case "gamedate":
      return items.sort((a, b) => new Date(b.game_date || b.created_at) - new Date(a.game_date || a.created_at));
    case "newest":
    default:
      return items.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  }
});
const visibleMedia = computed(() => sortedMedia.value.slice(0, visibleCount.value));
const groupedVisibleMedia = computed(() => {
  const modeGroup = galleryView.value === "timeline" ? "date" : groupBy.value;
  if (modeGroup === "none") return [{ key: "all", label: "All Media", items: visibleMedia.value }];
  const map = new Map();
  for (const item of visibleMedia.value) {
    let key = "Other";
    if (modeGroup === "uploader") key = item.uploader_name || "Unknown";
    if (modeGroup === "type") key = item.media_type === "photo" ? "Photos" : "Videos";
    if (modeGroup === "date") {
      const raw = item.game_date || item.created_at?.slice(0, 10);
      const d = raw ? new Date(`${raw}T00:00:00`) : null;
      if (galleryView.value === "timeline" && d && !Number.isNaN(d.getTime())) {
        key = d.toLocaleString(undefined, { month: "long", year: "numeric" });
      } else {
        key = displayDate(raw);
      }
    }
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(item);
  }
  return Array.from(map.entries()).map(([key, items]) => ({ key, label: key, items }));
});
const hasMoreMedia = computed(
  () => visibleCount.value < sortedMedia.value.length || mediaHasMoreServer.value
);
const selectedCount = computed(() => selectedMediaIds.value.length);
const featuredMedia = computed(() => (visibleMedia.value.length > 0 ? visibleMedia.value[0] : null));
const cinematicReelMedia = computed(() =>
  featuredMedia.value ? visibleMedia.value.slice(1) : visibleMedia.value
);
const modalStrip = computed(() => {
  if (!modalItem.value) return [];
  const idx = modalIndex();
  if (idx < 0) return [];
  const start = Math.max(0, idx - 4);
  const end = Math.min(sortedMedia.value.length, idx + 5);
  return sortedMedia.value.slice(start, end);
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

function cardImageSrc(item) {
  return item.thumbnail_url || item.file_path || "/video-placeholder.svg";
}

function focusImageSrc(item) {
  return item.file_path || item.thumbnail_url || "/video-placeholder.svg";
}

function teamLogoSrc(team) {
  return team?.logo_path || "/brand-mark.svg";
}

function isSelected(mediaId) {
  return selectedMediaIds.value.includes(mediaId);
}

function toggleSelectionMode() {
  selectionMode.value = !selectionMode.value;
  if (!selectionMode.value) selectedMediaIds.value = [];
}

function toggleSelected(mediaId) {
  if (!selectionMode.value) return;
  if (isSelected(mediaId)) {
    selectedMediaIds.value = selectedMediaIds.value.filter((id) => id !== mediaId);
    return;
  }
  selectedMediaIds.value = [...selectedMediaIds.value, mediaId];
}

function selectAllVisible() {
  const ids = visibleMedia.value.map((item) => item.id);
  selectedMediaIds.value = Array.from(new Set([...selectedMediaIds.value, ...ids]));
}

function clearSelection() {
  selectedMediaIds.value = [];
}

function openMediaModal(item) {
  if (selectionMode.value) {
    toggleSelected(item.id);
    return;
  }
  modalItem.value = item;
}

function modalIndex() {
  if (!modalItem.value) return -1;
  return sortedMedia.value.findIndex((item) => item.id === modalItem.value.id);
}

function modalPrev() {
  const idx = modalIndex();
  if (idx > 0) modalItem.value = sortedMedia.value[idx - 1];
}

function modalNext() {
  const idx = modalIndex();
  if (idx >= 0 && idx < sortedMedia.value.length - 1) modalItem.value = sortedMedia.value[idx + 1];
}

function downloadMediaItem(item) {
  if (item.storage_type === "upload" && item.file_path) {
    const link = document.createElement("a");
    link.href = item.file_path;
    link.download = `${item.title || "media"}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    return;
  }
  const target = item.external_url || item.file_path;
  if (target) window.open(target, "_blank", "noopener,noreferrer");
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
  const team = teams.value.find((t) => Number(t.id) === Number(activeTeamId.value)) || null;
  syncTeamProfileForm(team);
  syncAccountProfileForm(user.value);
}

function syncTeamProfileForm(team) {
  teamProfileForm.name = team?.name || "";
  teamProfileForm.age_group = team?.age_group || "";
  teamProfileForm.season_year = team?.season_year || "";
  teamProfileForm.level = team?.level || "";
  teamProfileForm.home_rink = team?.home_rink || "";
  teamProfileForm.city = team?.city || "";
  teamProfileForm.team_notes = team?.team_notes || "";
  deleteTeamForm.confirm_team_name = "";
  deleteTeamForm.confirm_word = "";
}

function syncAccountProfileForm(account) {
  accountProfileForm.display_name = account?.display_name || "";
  accountProfileForm.current_password = "";
  accountProfileForm.new_password = "";
  accountProfileForm.new_password_confirm = "";
}

function resetGalleryPagination() {
  visibleCount.value = PAGE_SIZE;
}

function setupInfiniteScroll() {
  if (observer) observer.disconnect();
  if (!loadMoreSentinel.value || typeof IntersectionObserver === "undefined") return;

  observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting || !hasMoreMedia.value) return;
        if (visibleCount.value < sortedMedia.value.length) {
          visibleCount.value += PAGE_SIZE;
          return;
        }
        if (!mediaLoadingMore.value && mediaHasMoreServer.value) {
          void loadMedia(false);
        }
      });
    },
    { rootMargin: "140px" }
  );
  observer.observe(loadMoreSentinel.value);
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
    if (activeTeamId.value) {
      await loadMedia();
      await loadTeamMembers();
    }
    if (pendingJoinCode.value) await joinTeam(true);
  } catch (err) {
    setBanner("error", err.message);
  } finally {
    loadingSession.value = false;
  }
}

async function loadMedia(reset = true) {
  if (!activeTeamId.value) return;
  if (mediaLoadingMore.value) return;
  mediaLoadingMore.value = true;
  try {
    const offset = reset ? 0 : mediaOffset.value;
    const payload = await apiGet("media_list", {
      team_id: activeTeamId.value,
      limit: API_MEDIA_PAGE_SIZE,
      offset
    });
    const incoming = payload.items || [];
    if (reset) {
      mediaItems.value = incoming;
      visibleCount.value = PAGE_SIZE;
    } else {
      const existing = new Set(mediaItems.value.map((item) => item.id));
      mediaItems.value = [...mediaItems.value, ...incoming.filter((item) => !existing.has(item.id))];
    }
    mediaOffset.value = Number(payload.next_offset || mediaItems.value.length);
    mediaHasMoreServer.value = Boolean(payload.has_more);
    mediaStats.total = Number(payload.total_count || mediaItems.value.length);
    mediaStats.photos = Number(payload.photo_count || 0);
    mediaStats.videos = Number(payload.video_count || 0);
  } finally {
    mediaLoadingMore.value = false;
  }
  const validIds = new Set(mediaItems.value.map((m) => m.id));
  selectedMediaIds.value = selectedMediaIds.value.filter((id) => validIds.has(id));
  if (reset) resetGalleryPagination();
}

async function loadTeamMembers() {
  if (!activeTeamId.value) return;
  membersLoading.value = true;
  try {
    const payload = await apiGet("team_members", { team_id: activeTeamId.value });
    teamMembers.value = payload.members || [];
  } catch (err) {
    setBanner("error", err.message || "Unable to load team members.");
  } finally {
    membersLoading.value = false;
  }
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
    await Promise.all([loadMedia(), loadTeamMembers()]);
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
    await Promise.all([loadMedia(), loadTeamMembers()]);
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
    mediaOffset.value = 0;
    mediaHasMoreServer.value = false;
    mediaStats.total = 0;
    mediaStats.photos = 0;
    mediaStats.videos = 0;
    teamMembers.value = [];
    syncAccountProfileForm(null);
    emailChangeForm.requested_email = "";
    emailChangeForm.reason = "";
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
      await Promise.all([loadMedia(), loadTeamMembers()]);
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
      await Promise.all([loadMedia(), loadTeamMembers()]);
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
  await withBusy(async () => {
    await Promise.all([loadMedia(), loadTeamMembers()]);
  });
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
        uploadQueue.value[queueIndex].status = "error";
        reject(new Error(data.error || "Upload failed."));
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

async function updateAccountProfile() {
  await withBusy(async () => {
    const payload = await apiPost("account_update_profile", {
      display_name: accountProfileForm.display_name,
      current_password: accountProfileForm.current_password,
      new_password: accountProfileForm.new_password,
      new_password_confirm: accountProfileForm.new_password_confirm
    });
    user.value = payload.user || user.value;
    teams.value = payload.teams || teams.value;
    syncAccountProfileForm(user.value);
    setBanner("success", "Account profile updated.");
  });
}

async function requestEmailChange() {
  await withBusy(async () => {
    const payload = await apiPost("account_email_change_request", {
      requested_email: emailChangeForm.requested_email,
      reason: emailChangeForm.reason
    });
    emailChangeForm.requested_email = "";
    emailChangeForm.reason = "";
    setBanner("success", payload.message || "Email change request sent for support review.");
  });
}

async function updateTeamProfile() {
  if (!activeTeamId.value) return;
  await withBusy(async () => {
    const payload = await apiPost("team_update", {
      team_id: activeTeamId.value,
      ...teamProfileForm
    });
    teams.value = payload.teams || [];
    syncTeamProfileForm(teams.value.find((t) => Number(t.id) === Number(activeTeamId.value)));
    setBanner("success", "Team profile updated.");
  });
}

async function uploadTeamLogo(event) {
  const file = event?.target?.files?.[0];
  if (!file || !activeTeamId.value) return;
  await withBusy(async () => {
    const formData = new FormData();
    formData.append("team_id", String(activeTeamId.value));
    formData.append("logo", file);
    const payload = await apiUpload("team_logo_upload", formData);
    teams.value = payload.teams || teams.value;
    syncTeamProfileForm(teams.value.find((t) => Number(t.id) === Number(activeTeamId.value)));
    if (teamLogoInput.value) teamLogoInput.value.value = "";
    setBanner("success", "Team logo updated.");
  });
}

async function removeTeamLogo() {
  if (!activeTeamId.value || !activeTeam.value?.logo_path) return;
  if (!window.confirm("Remove this team logo?")) return;
  await withBusy(async () => {
    const payload = await apiPost("team_logo_delete", { team_id: activeTeamId.value });
    teams.value = payload.teams || teams.value;
    syncTeamProfileForm(teams.value.find((t) => Number(t.id) === Number(activeTeamId.value)));
    if (teamLogoInput.value) teamLogoInput.value.value = "";
    setBanner("success", "Team logo removed.");
  });
}

async function deleteActiveTeam() {
  if (!activeTeamId.value || !activeTeam.value) return;
  await withBusy(async () => {
    const payload = await apiPost("team_delete", {
      team_id: activeTeamId.value,
      confirm_team_name: deleteTeamForm.confirm_team_name,
      confirm_word: deleteTeamForm.confirm_word
    });
    teams.value = payload.teams || [];
    activeTeamId.value = teams.value[0]?.id ? Number(teams.value[0].id) : 0;
    localStorage.setItem("slapshot_active_team_id", String(activeTeamId.value || 0));
    syncTeamProfileForm(teams.value.find((t) => Number(t.id) === Number(activeTeamId.value)));
    if (activeTeamId.value) {
      await Promise.all([loadMedia(), loadTeamMembers()]);
    } else {
      mediaItems.value = [];
      mediaOffset.value = 0;
      mediaHasMoreServer.value = false;
      mediaStats.total = 0;
      mediaStats.photos = 0;
      mediaStats.videos = 0;
      teamMembers.value = [];
    }
    setBanner("success", "Team deleted.");
  });
}

function canManageMember(member) {
  if (!canManageMembers.value) return false;
  if (Number(member.user_id) === Number(user.value?.id || 0)) return false;
  if (member.role === "owner") return false;
  if (activeTeamRole.value === "admin" && member.role === "admin") return false;
  return true;
}

async function updateMemberRole(member, nextRole) {
  if (!canManageMember(member) || member.role === nextRole) return;
  await withBusy(async () => {
    const payload = await apiPost("team_member_role", {
      team_id: activeTeamId.value,
      member_user_id: member.user_id,
      role: nextRole
    });
    teamMembers.value = payload.members || [];
    setBanner("success", `Updated ${member.display_name} to ${nextRole}.`);
  });
}

async function removeMember(member) {
  if (!canManageMember(member)) return;
  if (!window.confirm(`Remove ${member.display_name} from this team?`)) return;
  await withBusy(async () => {
    const payload = await apiPost("team_member_remove", {
      team_id: activeTeamId.value,
      member_user_id: member.user_id
    });
    teamMembers.value = payload.members || [];
    setBanner("success", "Member removed.");
  });
}

async function deleteMedia(id) {
  if (!window.confirm("Delete this media item?")) return;
  await withBusy(async () => {
    await apiPost("media_delete", { media_id: id });
    await loadMedia();
    if (modalItem.value?.id === id) modalItem.value = null;
    selectedMediaIds.value = selectedMediaIds.value.filter((mid) => mid !== id);
    setBanner("success", "Media removed.");
  });
}

async function deleteSelectedMedia() {
  if (selectedMediaIds.value.length === 0) return;
  if (!window.confirm(`Delete ${selectedMediaIds.value.length} selected items?`)) return;
  await withBusy(async () => {
    const payload = await apiPost("media_delete_batch", { media_ids: selectedMediaIds.value });
    await loadMedia();
    selectedMediaIds.value = [];
    modalItem.value = null;
    setBanner("success", `Deleted ${payload.deleted || 0} media items.`);
  });
}

function downloadSelectedMedia() {
  if (selectedMediaIds.value.length === 0) return;
  const selected = sortedMedia.value.filter((item) => selectedMediaIds.value.includes(item.id));
  selected.forEach((item) => downloadMediaItem(item));
  setBanner("success", "Opened selected media for download.");
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
  } catch {}
}

function smsInviteLink() {
  return `sms:?&body=${encodeURIComponent(inviteCopy.value)}`;
}

function emailInviteLink() {
  const subject = encodeURIComponent(`Join ${activeTeam.value?.name || "our team"} on Slapshot Snapshot`);
  return `mailto:?subject=${subject}&body=${encodeURIComponent(inviteCopy.value)}`;
}

watch([mediaFilter, searchQuery, sortBy, groupBy, galleryView, activeTab], async () => {
  resetGalleryPagination();
  await nextTick();
  setupInfiniteScroll();
});

watch(activeTeam, (team) => {
  syncTeamProfileForm(team || null);
});

onMounted(async () => {
  await loadSession();
  await nextTick();
  setupInfiniteScroll();
  keyHandler = (event) => {
    if (!modalItem.value) return;
    if (event.key === "ArrowLeft") modalPrev();
    if (event.key === "ArrowRight") modalNext();
    if (event.key === "Escape") modalItem.value = null;
  };
  window.addEventListener("keydown", keyHandler);
});

onBeforeUnmount(() => {
  if (observer) observer.disconnect();
  if (keyHandler) window.removeEventListener("keydown", keyHandler);
});
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand-lockup">
        <img class="brand-mark" src="/brand-mark.svg" alt="Slapshot Snapshot logo mark" />
        <div class="brand-copy">
          <img class="brand-wordmark" src="/brand-wordmark.svg" alt="Slapshot Snapshot" />
          <p class="brand-kicker">Private Team Media Experience</p>
        </div>
      </div>
      <button v-if="isAuthenticated" class="btn btn-ghost" :disabled="busy" @click="logout">Sign out</button>
    </header>

    <p v-if="banner.message" :class="['banner', banner.type]">{{ banner.message }}</p>

    <section v-if="loadingSession" class="panel padded"><p>Loading session...</p></section>

    <section v-else-if="!isAuthenticated" class="panel auth-panel">
      <div class="switcher">
        <button :class="{ active: authMode === 'register' }" @click="authMode = 'register'">Create account</button>
        <button :class="{ active: authMode === 'login' }" @click="authMode = 'login'">Sign in</button>
      </div>
      <form v-if="authMode === 'register'" class="form-grid" @submit.prevent="register">
        <label><span>Name</span><input v-model="registerForm.display_name" required /></label>
        <label><span>Email</span><input v-model="registerForm.email" type="email" required /></label>
        <label><span>Password</span><input v-model="registerForm.password" type="password" minlength="8" required /></label>
        <label><span>First Team Name</span><input v-model="registerForm.team_name" required /></label>
        <button class="btn" :disabled="busy">Create account & team</button>
      </form>
      <form v-else class="form-grid" @submit.prevent="login">
        <label><span>Email</span><input v-model="loginForm.email" type="email" required /></label>
        <label><span>Password</span><input v-model="loginForm.password" type="password" required /></label>
        <button class="btn" :disabled="busy">Sign in</button>
      </form>
    </section>

    <section v-else class="dashboard">
      <nav class="app-nav panel">
        <button :class="{ active: activeTab === 'overview' }" @click="activeTab = 'overview'">
          <img class="nav-icon" src="/icon-nav-overview.svg" alt="" />
          <span>Overview</span>
        </button>
        <button :class="{ active: activeTab === 'upload' }" @click="activeTab = 'upload'">
          <img class="nav-icon" src="/icon-nav-upload.svg" alt="" />
          <span>Upload</span>
        </button>
        <button :class="{ active: activeTab === 'gallery' }" @click="activeTab = 'gallery'">
          <img class="nav-icon" src="/icon-nav-gallery.svg" alt="" />
          <span>Gallery</span>
        </button>
        <button :class="{ active: activeTab === 'teams' }" @click="activeTab = 'teams'">
          <img class="nav-icon" src="/icon-nav-teams.svg" alt="" />
          <span>Teams</span>
        </button>
        <button :class="{ active: activeTab === 'account' }" @click="activeTab = 'account'">
          <img class="nav-icon" src="/icon-nav-account.svg" alt="" />
          <span>Account</span>
        </button>
      </nav>

      <section v-if="activeTab === 'overview'" class="grid-overview">
        <article class="panel hero">
          <p class="eyebrow">Active Team</p>
          <div class="team-identity">
            <img class="team-logo-badge" :src="teamLogoSrc(activeTeam)" :alt="`${activeTeam?.name || 'Team'} logo`" />
            <div>
              <h2>{{ activeTeam?.name || "No Team Selected" }}</h2>
              <p class="meta">Custom logo + invite branding are team-specific.</p>
            </div>
          </div>
          <p class="hero-copy">Private season timeline, invite-only access, and instant sharing across family and friends.</p>
          <div class="team-meta-strip">
            <span><strong>Age:</strong> {{ activeTeam?.age_group || "Not set" }}</span>
            <span><strong>Season:</strong> {{ activeTeam?.season_year || "Not set" }}</span>
            <span><strong>Level:</strong> {{ activeTeam?.level || "Not set" }}</span>
            <span><strong>Rink:</strong> {{ activeTeam?.home_rink || "Not set" }}</span>
          </div>
          <div class="hero-rink-stage">
            <img class="hero-graphic" src="/graphics-rink-hero.svg" alt="Stylized hockey rink illustration" />
            <span class="hero-puck" aria-hidden="true"></span>
          </div>
          <div class="stats">
            <article><p>Total</p><strong>{{ totalMediaCount }}</strong></article>
            <article><p>Photos</p><strong>{{ photoCount }}</strong></article>
            <article><p>Videos</p><strong>{{ videoCount }}</strong></article>
            <article><p>Latest</p><strong>{{ newestMedia ? displayDate(newestMedia.game_date) : "-" }}</strong></article>
          </div>
        </article>

        <article class="panel invite-card">
          <h3>Invite Center</h3>
          <div class="invite-team-head">
            <img class="invite-team-logo" :src="teamLogoSrc(activeTeam)" :alt="`${activeTeam?.name || 'Team'} logo`" />
            <p class="meta">Your team logo is included in sent invite emails.</p>
          </div>
          <img class="invite-graphic" src="/graphics-invite-badge.svg" alt="Invite sharing graphic" />
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
            <input v-model="emailInviteForm.email" type="email" placeholder="family@example.com" required />
            <textarea v-model="emailInviteForm.message" rows="2" placeholder="Optional personal message" />
            <button class="btn" :disabled="busy || !activeTeamId">Send Invite Email</button>
          </form>
        </article>
      </section>

      <section v-if="activeTab === 'upload'" class="upload-grid">
        <article class="panel stack">
          <h3>Multi-File Upload</h3>
          <p class="mini-note">Drop game photos and clips here to build your season timeline faster.</p>
          <div :class="['dropzone', { active: dragActive }]" @drop="onDrop" @dragover="onDragOver" @dragleave="onDragLeave">
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
              <div class="queue-head"><span>{{ item.name }}</span><strong>{{ item.progress }}%</strong></div>
              <div class="bar"><span :style="{ width: `${item.progress}%` }" /></div>
            </article>
          </div>
        </article>

        <article class="panel stack">
          <h3>Share YouTube Clip</h3>
          <input v-model="uploadLinkForm.title" placeholder="Title" required />
          <textarea v-model="uploadLinkForm.description" rows="2" placeholder="Description" />
          <input v-model="uploadLinkForm.game_date" type="date" />
          <input v-model="uploadLinkForm.url" type="url" placeholder="https://youtube.com/watch?v=..." required />
          <button class="btn btn-secondary" :disabled="busy || !activeTeamId" @click="uploadLink">Share Video Link</button>
        </article>
      </section>

      <section v-if="activeTab === 'gallery'" class="panel padded">
        <div class="toolbar gallery-toolbar">
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
          <div class="gallery-controls">
            <select v-model="sortBy" aria-label="Sort media">
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="gamedate">Game Date</option>
              <option value="title">Title</option>
              <option value="uploader">Uploader</option>
            </select>
            <select v-model="groupBy" :disabled="galleryView === 'timeline'" aria-label="Group media">
              <option value="none">No Group</option>
              <option value="date">Group by Date</option>
              <option value="uploader">Group by Uploader</option>
              <option value="type">Group by Type</option>
            </select>
            <div class="view-switch">
              <button :class="{ active: galleryView === 'cinematic' }" @click="galleryView = 'cinematic'">
                Cinematic
              </button>
              <button :class="{ active: galleryView === 'masonry' }" @click="galleryView = 'masonry'">
                Masonry
              </button>
              <button :class="{ active: galleryView === 'timeline' }" @click="galleryView = 'timeline'">
                Timeline
              </button>
            </div>
            <button class="btn btn-ghost" @click="toggleSelectionMode">
              {{ selectionMode ? "Exit Select" : "Select" }}
            </button>
          </div>
          <input v-model="searchQuery" placeholder="Search title, caption, uploader..." />
        </div>

        <div v-if="selectionMode" class="batch-bar">
          <p>{{ selectedCount }} selected</p>
          <div class="button-row">
            <button class="btn btn-ghost" @click="selectAllVisible">Select Visible</button>
            <button class="btn btn-ghost" @click="clearSelection">Clear</button>
            <button class="btn btn-secondary" :disabled="selectedCount === 0" @click="downloadSelectedMedia">
              Download Selected
            </button>
            <button class="btn btn-danger" :disabled="selectedCount === 0" @click="deleteSelectedMedia">
              Delete Selected
            </button>
          </div>
        </div>

        <section v-if="galleryView === 'cinematic'" class="cinematic-wrap">
          <article
            v-if="featuredMedia"
            class="featured-card"
            :class="{ selected: isSelected(featuredMedia.id) }"
            @click="openMediaModal(featuredMedia)"
          >
            <button
              v-if="selectionMode"
              class="select-dot"
              :class="{ on: isSelected(featuredMedia.id) }"
              @click.stop="toggleSelected(featuredMedia.id)"
              aria-label="Toggle select media"
            />
            <img
              v-if="featuredMedia.media_type === 'photo'"
              :src="cardImageSrc(featuredMedia)"
              :alt="featuredMedia.title"
              loading="eager"
              decoding="async"
            />
            <img
              v-else
              :src="cardImageSrc(featuredMedia)"
              :alt="featuredMedia.title"
              loading="eager"
              decoding="async"
            />
            <div class="featured-overlay">
              <p class="meta">Featured Moment</p>
              <h3>{{ featuredMedia.title }}</h3>
              <p>{{ featuredMedia.description || "No description yet." }}</p>
            </div>
          </article>
          <TransitionGroup name="reel" tag="section" class="cinema-reel">
            <article
              v-for="item in cinematicReelMedia"
              :key="item.id"
              :class="['media-card', 'cinema-card', { selected: isSelected(item.id) }]"
              @click="openMediaModal(item)"
            >
              <button
                v-if="selectionMode"
                class="select-dot"
                :class="{ on: isSelected(item.id) }"
                @click.stop="toggleSelected(item.id)"
                aria-label="Toggle select media"
              />
              <img
                v-if="item.media_type === 'photo'"
                :src="cardImageSrc(item)"
                :alt="item.title"
                loading="lazy"
                decoding="async"
              />
              <img
                v-else
                :src="cardImageSrc(item)"
                :alt="item.title"
                loading="lazy"
                decoding="async"
              />
              <div class="copy">
                <p class="meta">{{ displayDate(item.game_date) }} · {{ item.uploader_name }}</p>
                <h4>{{ item.title }}</h4>
                <p>{{ item.description || "No description yet." }}</p>
              </div>
            </article>
          </TransitionGroup>
        </section>

        <section v-else-if="galleryView === 'masonry'" class="masonry-grid">
          <section v-for="group in groupedVisibleMedia" :key="group.key" class="media-group">
            <h4 v-if="groupBy !== 'none'" class="group-label">{{ group.label }}</h4>
            <div class="gallery masonry-gallery">
              <article
                v-for="item in group.items"
                :key="item.id"
                :class="['media-card', 'masonry-card', { selected: isSelected(item.id) }]"
                @click="openMediaModal(item)"
              >
                <button
                  v-if="selectionMode"
                  class="select-dot"
                  :class="{ on: isSelected(item.id) }"
                  @click.stop="toggleSelected(item.id)"
                  aria-label="Toggle select media"
                />
                <img
                  v-if="item.media_type === 'photo'"
                  :src="cardImageSrc(item)"
                  :alt="item.title"
                  loading="lazy"
                  decoding="async"
                />
                <img
                  v-else
                  :src="cardImageSrc(item)"
                  :alt="item.title"
                  loading="lazy"
                  decoding="async"
                />
                <div class="copy">
                  <p class="meta">{{ displayDate(item.game_date) }} · {{ item.uploader_name }}</p>
                  <h4>{{ item.title }}</h4>
                  <p>{{ item.description || "No description yet." }}</p>
                </div>
              </article>
            </div>
          </section>
        </section>

        <section v-else class="timeline-wrap">
          <section v-for="group in groupedVisibleMedia" :key="group.key" class="timeline-group">
            <h4 class="group-label">{{ group.label }}</h4>
            <div class="timeline-row">
              <article
                v-for="item in group.items"
                :key="item.id"
                :class="['media-card', 'timeline-card', { selected: isSelected(item.id) }]"
                @click="openMediaModal(item)"
              >
                <button
                  v-if="selectionMode"
                  class="select-dot"
                  :class="{ on: isSelected(item.id) }"
                  @click.stop="toggleSelected(item.id)"
                  aria-label="Toggle select media"
                />
                <img
                  v-if="item.media_type === 'photo'"
                  :src="cardImageSrc(item)"
                  :alt="item.title"
                  loading="lazy"
                  decoding="async"
                />
                <img
                  v-else
                  :src="cardImageSrc(item)"
                  :alt="item.title"
                  loading="lazy"
                  decoding="async"
                />
                <div class="copy">
                  <p class="meta">{{ displayDate(item.game_date) }} · {{ item.uploader_name }}</p>
                  <h4>{{ item.title }}</h4>
                  <p>{{ item.description || "No description yet." }}</p>
                </div>
              </article>
            </div>
          </section>
        </section>

        <div ref="loadMoreSentinel" class="load-sentinel" />
        <p v-if="sortedMedia.length === 0" class="empty">No media found for this filter yet.</p>
        <p v-else-if="hasMoreMedia" class="empty">Scroll for more...</p>
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

        <article class="panel team-branding-card">
          <h4>Team Branding</h4>
          <p class="meta">Upload a team logo to personalize invites and in-app team visuals.</p>
          <div class="team-branding-row">
            <img class="team-branding-preview" :src="teamLogoSrc(activeTeam)" :alt="`${activeTeam?.name || 'Team'} logo preview`" />
            <div class="stack compact">
              <input
                ref="teamLogoInput"
                type="file"
                accept="image/png,image/jpeg,image/webp,image/gif"
                :disabled="busy || !activeTeamId || !canManageMembers"
                @change="uploadTeamLogo"
              />
              <div class="button-row">
                <button
                  class="btn btn-danger"
                  type="button"
                  :disabled="busy || !activeTeamId || !activeTeam?.logo_path || !canManageMembers"
                  @click="removeTeamLogo"
                >
                  Remove Logo
                </button>
              </div>
            </div>
          </div>
        </article>

        <form class="panel profile-editor" @submit.prevent="updateTeamProfile">
          <h4>Edit Team Profile</h4>
          <div class="profile-grid">
            <label>
              <span>Team Name</span>
              <input v-model="teamProfileForm.name" required />
            </label>
            <label>
              <span>Age Group</span>
              <input v-model="teamProfileForm.age_group" placeholder="e.g. 12U, 14U, Varsity" />
            </label>
            <label>
              <span>Season Year</span>
              <input v-model="teamProfileForm.season_year" placeholder="e.g. 2026-2027" />
            </label>
            <label>
              <span>Level</span>
              <input v-model="teamProfileForm.level" placeholder="e.g. AA, Travel, House" />
            </label>
            <label>
              <span>Home Rink</span>
              <input v-model="teamProfileForm.home_rink" placeholder="Main arena name" />
            </label>
            <label>
              <span>City</span>
              <input v-model="teamProfileForm.city" placeholder="City / Region" />
            </label>
          </div>
          <label>
            <span>Team Notes</span>
            <textarea
              v-model="teamProfileForm.team_notes"
              rows="3"
              placeholder="Schedule notes, tournament focus, team highlights..."
            />
          </label>
          <button class="btn" :disabled="busy || !activeTeamId">Save Team Profile</button>
        </form>

        <h4>Members</h4>
        <p v-if="membersLoading" class="meta">Loading members...</p>
        <div v-else class="member-list">
          <article v-for="member in teamMembers" :key="member.id" class="member-card">
            <div>
              <strong>{{ member.display_name }}</strong>
              <p class="meta">{{ member.email }}</p>
            </div>
            <div class="member-controls">
              <span class="role-pill">{{ member.role }}</span>
              <template v-if="canManageMember(member)">
                <select :value="member.role" @change="updateMemberRole(member, $event.target.value)">
                  <option value="member">member</option>
                  <option value="admin">admin</option>
                </select>
                <button class="btn btn-danger" @click="removeMember(member)">Remove</button>
              </template>
            </div>
          </article>
        </div>

        <article class="panel delete-team-card">
          <h4>Delete Team</h4>
          <p class="meta">
            This permanently removes team members and media records. This action cannot be undone.
          </p>
          <p v-if="canDeleteTeam" class="meta">
            Safeguard: type `DELETE` and the exact team name `{{ activeTeam?.name }}`.
          </p>
          <p v-else class="meta">Only the team owner can delete a team.</p>
          <div class="profile-grid">
            <label>
              <span>Type DELETE</span>
              <input v-model="deleteTeamForm.confirm_word" :disabled="!canDeleteTeam" />
            </label>
            <label>
              <span>Type Team Name</span>
              <input v-model="deleteTeamForm.confirm_team_name" :disabled="!canDeleteTeam" />
            </label>
          </div>
          <button
            class="btn btn-danger"
            :disabled="busy || !canDeleteTeam || !activeTeamId"
            @click="deleteActiveTeam"
          >
            Delete Team
          </button>
        </article>
      </section>

      <section v-if="activeTab === 'account'" class="account-layout">
        <article class="panel padded account-card">
          <h3>Profile</h3>
          <p class="meta">Manage your account identity and password used to access Slapshot Snapshot.</p>
          <form class="stack compact" @submit.prevent="updateAccountProfile">
            <label>
              <span>Display Name</span>
              <input v-model="accountProfileForm.display_name" minlength="2" maxlength="120" required />
            </label>
            <div class="profile-grid">
              <label>
                <span>Current Password</span>
                <input
                  v-model="accountProfileForm.current_password"
                  type="password"
                  autocomplete="current-password"
                  placeholder="Required only when changing password"
                />
              </label>
              <label>
                <span>New Password</span>
                <input
                  v-model="accountProfileForm.new_password"
                  type="password"
                  minlength="8"
                  autocomplete="new-password"
                  placeholder="Leave blank to keep current"
                />
              </label>
              <label>
                <span>Confirm New Password</span>
                <input
                  v-model="accountProfileForm.new_password_confirm"
                  type="password"
                  minlength="8"
                  autocomplete="new-password"
                  placeholder="Repeat new password"
                />
              </label>
            </div>
            <button class="btn" :disabled="busy">Save Profile</button>
          </form>
        </article>

        <article class="panel padded account-card">
          <h3>Email Change Request</h3>
          <p class="meta">
            Login email changes are reviewed manually by support. Submit your new email and reason below.
            Support receives approve/deny links at <strong>support@pucc.us</strong>.
          </p>
          <form class="stack compact" @submit.prevent="requestEmailChange">
            <label>
              <span>Requested New Email</span>
              <input
                v-model="emailChangeForm.requested_email"
                type="email"
                autocomplete="email"
                placeholder="new-email@example.com"
                required
              />
            </label>
            <label>
              <span>Request Notes</span>
              <textarea
                v-model="emailChangeForm.reason"
                rows="4"
                maxlength="1500"
                placeholder="Why this email should be updated (optional)."
              />
            </label>
            <button class="btn btn-secondary" :disabled="busy">Submit Email Change Request</button>
          </form>
        </article>
      </section>
    </section>

    <section v-if="modalItem" class="modal-backdrop" @click.self="modalItem = null">
      <article class="modal-card">
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="modalItem = null">Close</button>
          <button class="btn btn-ghost" @click="modalPrev">Prev</button>
          <button class="btn btn-ghost" @click="modalNext">Next</button>
          <button class="btn btn-secondary" @click="downloadMediaItem(modalItem)">Download</button>
        </div>
        <h3>{{ modalItem.title }}</h3>
        <p class="meta">{{ displayDate(modalItem.game_date) }} · {{ modalItem.uploader_name }}</p>
        <div class="detail-chips">
          <span>{{ modalItem.media_type }}</span>
          <span>{{ modalItem.storage_type }}</span>
          <span v-if="modalItem.file_size">{{ Math.round(modalItem.file_size / 1024) }} KB</span>
          <span>{{ displayDate(modalItem.game_date || modalItem.created_at?.slice(0, 10)) }}</span>
        </div>
        <p>{{ modalItem.description || "No description." }}</p>
        <img v-if="modalItem.media_type === 'photo'" :src="focusImageSrc(modalItem)" :alt="modalItem.title" class="viewer viewer-image" />
        <video v-else-if="modalItem.storage_type === 'upload'" :src="focusImageSrc(modalItem)" controls class="viewer viewer-video" />
        <iframe v-else :src="modalItem.external_url" title="Shared video" allowfullscreen class="viewer frame" />
        <div v-if="modalStrip.length > 1" class="modal-strip">
          <button
            v-for="stripItem in modalStrip"
            :key="stripItem.id"
            :class="['strip-item', { active: modalItem.id === stripItem.id }]"
            @click="modalItem = stripItem"
          >
            <img
              v-if="stripItem.media_type === 'photo'"
              :src="cardImageSrc(stripItem)"
              :alt="stripItem.title"
            />
            <img
              v-else
              :src="cardImageSrc(stripItem)"
              :alt="stripItem.title"
            />
          </button>
        </div>
        <button class="btn btn-danger" @click="deleteMedia(modalItem.id)">Delete</button>
      </article>
    </section>
  </div>
</template>
