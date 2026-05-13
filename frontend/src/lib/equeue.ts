import { apiFetch } from "./api";

export interface EqueueWatch {
  id: number;
  serviceCode: string;
  serviceLabel: string | null;
  dateFrom: string;
  dateTo: string;
  active: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface EqueueWatchInput {
  serviceCode: string;
  serviceLabel?: string | null;
  dateFrom: string;
  dateTo: string;
  active: boolean;
}

interface HydraCollection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

export async function listWatches(): Promise<EqueueWatch[]> {
  const data = await apiFetch<HydraCollection<EqueueWatch> | EqueueWatch[]>(
    "/equeue_watches",
  );
  if (Array.isArray(data)) return data;
  return data.member ?? data["hydra:member"] ?? [];
}

export function createWatch(input: EqueueWatchInput): Promise<EqueueWatch> {
  return apiFetch<EqueueWatch>("/equeue_watches", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export function updateWatch(
  id: number,
  input: Partial<EqueueWatchInput>,
): Promise<EqueueWatch> {
  return apiFetch<EqueueWatch>(`/equeue_watches/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/merge-patch+json" },
    body: JSON.stringify(input),
  });
}

export function deleteWatch(id: number): Promise<void> {
  return apiFetch<void>(`/equeue_watches/${id}`, { method: "DELETE" });
}

export interface TelegramConnectLink {
  url: string;
  expiresAt: string;
}

export function createTelegramConnectLink(): Promise<TelegramConnectLink> {
  return apiFetch<TelegramConnectLink>("/telegram/connect-link", {
    method: "POST",
  });
}

export interface TelegramStatus {
  connected: boolean;
  connectedAt: string | null;
}

export function getTelegramStatus(): Promise<TelegramStatus> {
  return apiFetch<TelegramStatus>("/telegram/status");
}
