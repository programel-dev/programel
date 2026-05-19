import { refreshToken, logout } from "./auth";

const API_INTERNAL_URL = process.env.API_INTERNAL_URL || "http://api:9000";
const API_PUBLIC_URL =
  process.env.NEXT_PUBLIC_API_URL || "/api/v1";

function isServer(): boolean {
  return typeof window === "undefined";
}

function getBaseUrl(): string {
  return isServer() ? `${API_INTERNAL_URL}/api/v1` : API_PUBLIC_URL;
}

let isRefreshing = false;

export async function apiFetch<T>(
  path: string,
  init?: RequestInit,
): Promise<T> {
  const url = `${getBaseUrl()}${path}`;

  const res = await fetch(url, {
    ...init,
    credentials: isServer() ? "omit" : "include",
    headers: {
      "Content-Type": "application/json",
      ...init?.headers,
    },
  });

  if (res.status === 401 && !isServer() && !isRefreshing) {
    isRefreshing = true;
    try {
      const refreshed = await refreshToken();
      if (refreshed) {
        isRefreshing = false;
        return apiFetch<T>(path, init);
      }
    } catch {
      // refresh failed
    }
    isRefreshing = false;
    logout();
    throw new ApiError(401, "Session expired");
  }

  if (!res.ok) {
    throw new ApiError(res.status, await res.text());
  }

  const contentType = res.headers.get("content-type");
  if (!contentType?.includes("application/json")) {
    return undefined as T;
  }

  return res.json();
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: string,
  ) {
    super(`API error ${status}`);
    this.name = "ApiError";
  }
}
