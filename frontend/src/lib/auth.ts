const API_URL = process.env.NEXT_PUBLIC_API_URL || "/api/v1";

export async function login(
  email: string,
  password: string,
): Promise<{ token: string; refresh_token: string }> {
  const res = await fetch(`${API_URL}/auth/login`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });

  if (!res.ok) {
    throw new Error(res.status === 401 ? "Invalid credentials" : "Login failed");
  }

  return res.json();
}

export async function refreshToken(): Promise<boolean> {
  const res = await fetch(`${API_URL}/auth/refresh`, {
    method: "POST",
    credentials: "include",
  });

  return res.ok;
}

export async function logout(): Promise<void> {
  document.cookie = "BEARER=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Secure; SameSite=Lax";
  document.cookie = "refresh_token=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Secure; SameSite=Lax";
  window.location.href = "/login";
}
