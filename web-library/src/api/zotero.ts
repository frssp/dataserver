import type { ZoteroItem, ZoteroCollection, ZoteroTag, UserInfo } from '../types/zotero';

const API_KEY_STORAGE = 'zotero_api_key';
const USER_INFO_STORAGE = 'zotero_user_info';

function getApiKey(): string | null {
  return localStorage.getItem(API_KEY_STORAGE);
}

function getStoredUser(): UserInfo | null {
  const raw = localStorage.getItem(USER_INFO_STORAGE);
  return raw ? JSON.parse(raw) : null;
}

function storeAuth(userInfo: UserInfo) {
  localStorage.setItem(API_KEY_STORAGE, userInfo.apiKey);
  localStorage.setItem(USER_INFO_STORAGE, JSON.stringify(userInfo));
}

function clearAuth() {
  localStorage.removeItem(API_KEY_STORAGE);
  localStorage.removeItem(USER_INFO_STORAGE);
}

async function apiFetch<T>(path: string, params: Record<string, string> = {}): Promise<T> {
  const apiKey = getApiKey();
  if (!apiKey) throw new Error('Not authenticated');

  const query = new URLSearchParams({ ...params, format: 'json' });
  const res = await fetch(`${path}?${query}`, {
    headers: { 'Zotero-API-Key': apiKey },
  });

  if (res.status === 403 || res.status === 401) {
    clearAuth();
    throw new Error('Authentication expired');
  }
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return res.json();
}

export async function login(username: string, password: string): Promise<UserInfo> {
  const res = await fetch('/library-auth.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({ error: 'Login failed' }));
    throw new Error(data.error || 'Login failed');
  }

  const { userID, username: name, apiKey } = await res.json();

  // Fetch groups to complete user info
  const meRes = await fetch('/library-auth.php?action=me', {
    headers: { 'Zotero-API-Key': apiKey },
  });
  const meData = meRes.ok ? await meRes.json() : { groups: [] };

  const userInfo: UserInfo = { userID, username: name, apiKey, groups: meData.groups || [] };
  storeAuth(userInfo);
  return userInfo;
}

export async function verifySession(): Promise<UserInfo | null> {
  const apiKey = getApiKey();
  const stored = getStoredUser();
  if (!apiKey || !stored) return null;

  try {
    const res = await fetch('/library-auth.php?action=me', {
      headers: { 'Zotero-API-Key': apiKey },
    });
    if (!res.ok) {
      clearAuth();
      return null;
    }
    const data = await res.json();
    const userInfo: UserInfo = {
      userID: data.userID,
      username: data.username,
      apiKey,
      groups: data.groups || [],
    };
    storeAuth(userInfo);
    return userInfo;
  } catch {
    clearAuth();
    return null;
  }
}

export function logout() {
  clearAuth();
}

export async function fetchCollections(
  type: 'user' | 'group',
  id: number,
): Promise<ZoteroCollection[]> {
  const prefix = type === 'user' ? `/users/${id}` : `/groups/${id}`;
  return apiFetch<ZoteroCollection[]>(`${prefix}/collections`);
}

export async function fetchItems(
  type: 'user' | 'group',
  id: number,
  params: Record<string, string> = {},
): Promise<{ items: ZoteroItem[]; totalResults: number }> {
  const apiKey = getApiKey();
  if (!apiKey) throw new Error('Not authenticated');

  const prefix = type === 'user' ? `/users/${id}` : `/groups/${id}`;
  const query = new URLSearchParams({ format: 'json', ...params });
  const res = await fetch(`${prefix}/items?${query}`, {
    headers: { 'Zotero-API-Key': apiKey },
  });

  if (!res.ok) throw new Error(`API error: ${res.status}`);
  const items: ZoteroItem[] = await res.json();
  const totalResults = parseInt(res.headers.get('Total-Results') || '0', 10);
  return { items, totalResults };
}

export async function fetchTags(
  type: 'user' | 'group',
  id: number,
): Promise<ZoteroTag[]> {
  const prefix = type === 'user' ? `/users/${id}` : `/groups/${id}`;
  return apiFetch<ZoteroTag[]>(`${prefix}/tags`);
}

export async function fetchItemByKey(
  type: 'user' | 'group',
  id: number,
  itemKey: string,
): Promise<ZoteroItem> {
  const prefix = type === 'user' ? `/users/${id}` : `/groups/${id}`;
  return apiFetch<ZoteroItem>(`${prefix}/items/${itemKey}`);
}
