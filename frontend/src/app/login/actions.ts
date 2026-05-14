"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";

const API_INTERNAL_URL = process.env.API_INTERNAL_URL || "http://api:9000";

export async function loginAction(
  redirectTo: string,
  _prevState: string | null,
  formData: FormData,
): Promise<string | null> {
  const email = formData.get("email") as string;
  const password = formData.get("password") as string;

  const res = await fetch(`${API_INTERNAL_URL}/api/v1/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });

  if (!res.ok) {
    return res.status === 401 ? "Невірний email або пароль" : "Помилка сервера";
  }

  const data = await res.json();

  const cookieStore = await cookies();
  cookieStore.set("BEARER", data.token, {
    path: "/",
    secure: true,
    httpOnly: false,
    sameSite: "lax",
    maxAge: 3600,
  });
  if (data.refresh_token) {
    cookieStore.set("refresh_token", data.refresh_token, {
      path: "/",
      secure: true,
      httpOnly: true,
      sameSite: "lax",
      maxAge: 60 * 60 * 24 * 30,
    });
  }

  redirect(redirectTo || "/");
}
