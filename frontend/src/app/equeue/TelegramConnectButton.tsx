"use client";

import { useEffect, useState } from "react";
import {
  createTelegramConnectLink,
  getTelegramStatus,
  type TelegramStatus,
} from "@/lib/equeue";

export function TelegramConnectButton() {
  const [status, setStatus] = useState<TelegramStatus | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getTelegramStatus()
      .then((s) => {
        if (!cancelled) setStatus(s);
      })
      .catch(() => {
        if (!cancelled) setStatus({ connected: false, connectedAt: null });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleConnect() {
    setLoading(true);
    setError(null);
    try {
      const link = await createTelegramConnectLink();
      window.open(link.url, "_blank", "noopener,noreferrer");
    } catch {
      setError("Не вдалося згенерувати посилання");
    } finally {
      setLoading(false);
    }
  }

  if (status?.connected) {
    return (
      <div className="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-700 dark:bg-green-900/30 dark:text-green-100">
        ✅ Telegram-акаунт підключено
        {status.connectedAt && (
          <span className="ml-2 opacity-70">
            ({new Date(status.connectedAt).toLocaleDateString("uk-UA")})
          </span>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <button
        type="button"
        onClick={handleConnect}
        disabled={loading}
        className="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50"
      >
        {loading ? "Генеруємо посилання…" : "Підключити Telegram"}
      </button>
      {error && <p className="text-sm text-red-600">{error}</p>}
      <p className="text-xs text-zinc-500">
        Натисни кнопку — відкриється Telegram-бот; надішли йому команду
        <code className="ml-1 rounded bg-zinc-100 px-1 dark:bg-zinc-800">
          /start
        </code>{" "}
        з посилання, і ми будемо повідомляти тебе про вільні слоти.
      </p>
    </div>
  );
}
