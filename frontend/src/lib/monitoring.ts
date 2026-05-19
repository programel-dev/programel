import { apiFetch } from "./api";

export interface MonitoringStatus {
  enabled: boolean;
  slotScrapingEnabled: boolean;
  updatedAt: string | null;
  updatedBy: string | null;
}

export async function getMonitoringStatus(token: string): Promise<MonitoringStatus> {
  return apiFetch<MonitoringStatus>("/admin/monitoring", {
    headers: { Authorization: `Bearer ${token}` },
  });
}

export async function setMonitoringEnabled(enabled: boolean): Promise<MonitoringStatus> {
  return apiFetch<MonitoringStatus>("/admin/monitoring", {
    method: "PATCH",
    body: JSON.stringify({ enabled }),
  });
}

export async function setSlotScrapingEnabled(slotScrapingEnabled: boolean): Promise<MonitoringStatus> {
  return apiFetch<MonitoringStatus>("/admin/monitoring", {
    method: "PATCH",
    body: JSON.stringify({ slotScrapingEnabled }),
  });
}
