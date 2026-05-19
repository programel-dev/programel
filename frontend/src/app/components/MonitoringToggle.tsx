"use client";

import { useState } from "react";
import { MonitoringStatus, setMonitoringEnabled, setSlotScrapingEnabled } from "@/lib/monitoring";

interface Props {
  initial: MonitoringStatus;
}

export function MonitoringToggle({ initial }: Props) {
  const [status, setStatus] = useState<MonitoringStatus>(initial);
  const [savingPolling, setSavingPolling] = useState(false);
  const [savingSlots, setSavingSlots] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handlePollingToggle() {
    const previous = status;
    const next = !status.enabled;
    setSavingPolling(true);
    setError(null);
    setStatus((prev) => ({ ...prev, enabled: next }));

    try {
      const updated = await setMonitoringEnabled(next);
      setStatus(updated);
    } catch {
      setStatus(previous);
      setError("Не вдалося зберегти");
    } finally {
      setSavingPolling(false);
    }
  }

  async function handleSlotScrapingToggle() {
    const previous = status;
    const next = !status.slotScrapingEnabled;
    setSavingSlots(true);
    setError(null);
    setStatus((prev) => ({ ...prev, slotScrapingEnabled: next }));

    try {
      const updated = await setSlotScrapingEnabled(next);
      setStatus(updated);
    } catch {
      setStatus(previous);
      setError("Не вдалося зберегти");
    } finally {
      setSavingSlots(false);
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
        <Toggle
          checked={status.enabled}
          disabled={savingPolling}
          label="Polling equeue"
          onChange={handlePollingToggle}
        />
      </div>
      <div className="mt-3 flex items-center justify-between">
        <span className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Slot scraping
        </span>
        <Toggle
          checked={status.slotScrapingEnabled}
          disabled={savingSlots}
          label="Slot scraping"
          onChange={handleSlotScrapingToggle}
        />
      </div>
      {status.updatedBy && status.updatedAt && (
        <p className="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
          {formatRelative(status.updatedAt)} · {status.updatedBy}
        </p>
      )}
      {error && (
        <p className="mt-2 text-xs text-red-500">{error}</p>
      )}
    </div>
  );
}

function Toggle({
  checked,
  disabled,
  label,
  onChange,
}: {
  checked: boolean;
  disabled: boolean;
  label: string;
  onChange: () => void;
}) {
  return (
    <button
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={onChange}
      disabled={disabled}
      className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-50 ${
        checked ? "bg-sky-600" : "bg-zinc-300 dark:bg-zinc-600"
      }`}
    >
      <span
        className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
          checked ? "translate-x-6" : "translate-x-1"
        }`}
      />
    </button>
  );
}

function formatRelative(dateStr: string | null): string {
  if (!dateStr) return "";
  const diffMin = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60_000);
  if (diffMin < 1) return "щойно";
  if (diffMin < 60) return `${diffMin} хв тому`;
  if (diffMin < 1440) return `${Math.floor(diffMin / 60)} год тому`;
  return `${Math.floor(diffMin / 1440)} дн тому`;
}
