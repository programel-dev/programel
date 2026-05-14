"use client";

import Link from "next/link";
import { logoutAction } from "@/app/logout/actions";

export function Header() {
  return (
    <header className="border-b border-zinc-200 dark:border-zinc-800">
      <div className="mx-auto flex w-full max-w-3xl items-center justify-between px-8 py-4">
        <Link
          href="/"
          className="text-sm font-semibold text-zinc-900 hover:text-zinc-600 dark:text-zinc-50 dark:hover:text-zinc-400"
        >
          programel.com
        </Link>
        <form action={logoutAction}>
          <button
            type="submit"
            className="text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-50"
          >
            Logout
          </button>
        </form>
      </div>
    </header>
  );
}
