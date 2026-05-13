"use client";

import { useCallback, useEffect, useState } from "react";
import {
  deleteWatch,
  listWatches,
  updateWatch,
  type EqueueWatch,
} from "@/lib/equeue";
import { WatchForm } from "./WatchForm";

export function WatchList() {
  const [watches, setWatches] = useState<EqueueWatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setWatches(await listWatches());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не вдалося завантажити");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  async function toggle(watch: EqueueWatch) {
    await updateWatch(watch.id, { active: !watch.active });
    await refresh();
  }

  async function remove(id: number) {
    await deleteWatch(id);
    await refresh();
  }

  return (
    <div className="space-y-6">
      <WatchForm onCreated={refresh} />

      <section>
        <h3 className="mb-3 text-lg font-semibold">Активні відстеження</h3>
        {loading && <p className="text-sm text-zinc-500">Завантаження…</p>}
        {error && <p className="text-sm text-red-600">{error}</p>}
        {!loading && watches.length === 0 && (
          <p className="text-sm text-zinc-500">Поки немає відстежень.</p>
        )}
        <ul className="space-y-2">
          {watches.map((watch) => (
            <li
              key={watch.id}
              className="flex items-center justify-between gap-3 rounded-md border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
            >
              <div>
                <p className="font-medium">
                  {watch.serviceLabel || watch.serviceCode}
                </p>
                <p className="text-xs text-zinc-500">
                  {watch.dateFrom} — {watch.dateTo}
                  {!watch.active && (
                    <span className="ml-2 rounded bg-zinc-200 px-1 py-0.5 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                      на паузі
                    </span>
                  )}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => toggle(watch)}
                  className="rounded-md border border-zinc-300 px-3 py-1 text-xs hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                >
                  {watch.active ? "На паузу" : "Активувати"}
                </button>
                <button
                  type="button"
                  onClick={() => remove(watch.id)}
                  className="rounded-md border border-red-300 px-3 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700 dark:hover:bg-red-900/30"
                >
                  Видалити
                </button>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
