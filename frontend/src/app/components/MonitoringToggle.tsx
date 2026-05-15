"use client";

import { useState } from "react";
import { MonitoringStatus, setMonitoringEnabled } from "@/lib/monitoring";

interface Props {
  initial: MonitoringStatus;
}

export function MonitoringToggle({ initial }: Props) {
  const [status, setStatus] = useState<MonitoringStatus>(initial);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleToggle() {
    const next = !status.enabled;
    setSaving(true);
    setError(null);
    setStatus((prev) => ({ ...prev, enabled: next }));

    try {
      const updated = await setMonitoringEnabled(next);
      setStatus(updated);
    } catch {
      setStatus(status);
      setError("Не вдалося зберегти");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-xl border border-zinc-200 p-5 dark:border-zinc-800">
      <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-zinc-400">
        Адмін
      </p>
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Polling equeue
        </span>
        <button
          role="switch"
          aria-checked={status.enabled}
          onClick={handleToggle}
          disabled={saving}
          className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-50 ${
            status.enabled
              ? "bg-sky-600"
              : "bg-zinc-300 dark:bg-zinc-600"
          }`}
        >
          <span
            className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
              status.enabled ? "translate-x-6" : "translate-x-1"
            }`}
          />
        </button>
      </div>
      {status.updatedBy && (
        <p className="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
          {formatRelative(status.updatedAt)} · {status.updatedBy}
        </p>
      )}
      {error && (
        <p className="mt-2 text-xs text-red-500">{error}</p>
      )}
    </div>
  );
}

function formatRelative(dateStr: string | null): string {
  if (!dateStr) return "";
  const diffMin = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60_000);
  if (diffMin < 1) return "щойно";
  if (diffMin < 60) return `${diffMin} хв тому`;
  return `${Math.floor(diffMin / 60)} год тому`;
}
