"use client";

import { useState } from "react";
import { createWatch, type EqueueWatchInput } from "@/lib/equeue";

interface WatchFormProps {
  onCreated: () => void;
}

const today = () => new Date().toISOString().slice(0, 10);
const inThirtyDays = () =>
  new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

export function WatchForm({ onCreated }: WatchFormProps) {
  const [dateFrom, setDateFrom] = useState(today());
  const [dateTo, setDateTo] = useState(inThirtyDays());
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    const input: EqueueWatchInput = {
      dateFrom,
      dateTo,
      active: true,
    };

    try {
      await createWatch(input);
      onCreated();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не вдалося зберегти");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
    >
      <h3 className="text-lg font-semibold">Нове відстеження</h3>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">
          <span className="mb-1 block text-zinc-700 dark:text-zinc-300">
            Дата з
          </span>
          <input
            type="date"
            required
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            className="w-full rounded border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800"
          />
        </label>
        <label className="block text-sm">
          <span className="mb-1 block text-zinc-700 dark:text-zinc-300">
            Дата до
          </span>
          <input
            type="date"
            required
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            className="w-full rounded border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800"
          />
        </label>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
      <button
        type="submit"
        disabled={submitting}
        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
      >
        {submitting ? "Зберігаємо…" : "Додати"}
      </button>
    </form>
  );
}
