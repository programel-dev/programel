"use client";

import { Suspense, useActionState } from "react";
import { useSearchParams } from "next/navigation";
import { loginAction } from "./actions";

function LoginForm() {
  const searchParams = useSearchParams();
  const redirect = searchParams.get("redirect") || "/";

  const action = loginAction.bind(null, redirect);
  const [error, formAction, pending] = useActionState(action, null);

  return (
    <form action={formAction} className="w-full max-w-sm space-y-4 p-8">
      <h1 className="text-2xl font-bold">Login</h1>
      {error && <p className="text-red-500 text-sm">{error}</p>}
      <input
        type="email"
        name="email"
        placeholder="Email"
        required
        className="w-full rounded border p-2"
      />
      <input
        type="password"
        name="password"
        placeholder="Password"
        required
        className="w-full rounded border p-2"
      />
      <button
        type="submit"
        disabled={pending}
        className="w-full rounded bg-blue-600 p-2 text-white hover:bg-blue-700 disabled:opacity-50"
      >
        {pending ? "Logging in..." : "Log in"}
      </button>
    </form>
  );
}

export default function LoginPage() {
  return (
    <div className="flex min-h-screen items-center justify-center">
      <Suspense fallback={<div>Loading...</div>}>
        <LoginForm />
      </Suspense>
    </div>
  );
}
