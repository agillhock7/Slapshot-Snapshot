const API_BASE = "/api/index.php";

async function parseResponse(res) {
  const payload = await res.json().catch(() => ({
    ok: false,
    error: "Invalid server response."
  }));

  if (!res.ok || payload.ok === false) {
    const message = payload.error || `Request failed (${res.status})`;
    throw new Error(message);
  }

  return payload;
}

export async function apiGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API_BASE}?${query.toString()}`, {
    credentials: "include"
  });
  return parseResponse(res);
}

export async function apiPost(action, body = {}) {
  const query = new URLSearchParams({ action });
  const res = await fetch(`${API_BASE}?${query.toString()}`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body)
  });
  return parseResponse(res);
}

export async function apiUpload(action, formData) {
  const query = new URLSearchParams({ action });
  const res = await fetch(`${API_BASE}?${query.toString()}`, {
    method: "POST",
    credentials: "include",
    body: formData
  });
  return parseResponse(res);
}
