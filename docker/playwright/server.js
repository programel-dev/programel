'use strict';

const express = require('express');
const { chromium } = require('playwright');

const app = express();
const PORT = 3001;
const TARGET_URL = 'https://munich.pasport.org.ua/solutions/e-queue';
const FULL_TIMEOUT_MS = 30_000;
const DATE_TIMEOUT_MS = 10_000;

app.get('/health', (_req, res) => {
    res.json({ status: 'ok' });
});

app.get('/slots', async (_req, res) => {
    const fetchedAt = new Date().toISOString();
    let browser = null;

    try {
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        const apiResponses = [];

        page.on('response', async (response) => {
            if (!response.url().includes('pasport.org.ua')) return;
            const ct = response.headers()['content-type'] ?? '';
            if (!ct.includes('json')) return;
            try {
                const body = await response.json();
                apiResponses.push({ url: response.url(), body });
            } catch { /* non-JSON body, skip */ }
        });

        try {
            await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: FULL_TIMEOUT_MS });
        } catch {
            return res.json({ success: false, reason: 'timeout', fetchedAt });
        }

        // Detect block page (Cloudflare challenge or similar)
        const pageContent = await page.content();
        if (!pageContent.includes('service') || pageContent.includes('cf-browser-verification')) {
            return res.json({ success: false, reason: 'blocked', fetchedAt });
        }

        try {
            await page.waitForSelector('select[name="service"]', { timeout: 10_000 });
        } catch {
            return res.json({ success: false, reason: 'blocked', fetchedAt });
        }

        // Select service=4 and wait for getDays AJAX response
        const daysPromise = captureResponse(apiResponses, isDaysResponse, FULL_TIMEOUT_MS);
        await page.selectOption('select[name="service"]', '4');
        const daysBody = await daysPromise;

        if (daysBody === null) {
            return res.json({ success: false, reason: 'timeout', fetchedAt });
        }

        const dates = extractDates(daysBody);
        if (dates.length === 0) {
            return res.json({ success: true, slots: [], fetchedAt });
        }

        // For each date, click it and capture getTimes AJAX response
        const slots = [];
        for (const date of dates) {
            apiResponses.length = 0;
            const timesPromise = captureResponse(apiResponses, isTimesResponse, DATE_TIMEOUT_MS);

            const clicked = await trySelectDate(page, date);
            if (!clicked) {
                await timesPromise; // drain the promise
                continue;
            }

            const timesBody = await timesPromise;
            if (timesBody !== null) {
                const times = extractTimes(timesBody);
                if (times.length > 0) {
                    slots.push({ date, times });
                }
            }
        }

        return res.json({ success: true, slots, fetchedAt });

    } catch (err) {
        const reason = String(err?.message ?? '').includes('timeout') ? 'timeout' : 'blocked';
        return res.json({ success: false, reason, fetchedAt });
    } finally {
        await browser?.close();
    }
});

/** Polls array for a matching entry until timeout, returns matched body or null */
function captureResponse(list, matcher, timeoutMs) {
    return new Promise((resolve) => {
        const deadline = Date.now() + timeoutMs;
        const tick = () => {
            const found = list.find(r => matcher(r.body));
            if (found) return resolve(found.body);
            if (Date.now() >= deadline) return resolve(null);
            setTimeout(tick, 250);
        };
        tick();
    });
}

async function trySelectDate(page, date) {
    const selectors = [
        `[data-date="${date}"]`,
        `[data-value="${date}"]`,
        `td[onclick*="${date}"]`,
        `.day[data-date="${date}"]`,
    ];
    for (const sel of selectors) {
        try {
            const el = page.locator(sel).first();
            if (await el.count() > 0) {
                await el.click({ timeout: 3_000 });
                return true;
            }
        } catch { /* try next selector */ }
    }
    try {
        await page.selectOption('select[name="date"], select[name="d"]', date, { timeout: 3_000 });
        return true;
    } catch { /* no select element found */ }
    return false;
}

function isDaysResponse(body) {
    const s = JSON.stringify(body);
    return /\d{4}-\d{2}-\d{2}/.test(s) && !isTimesResponse(body);
}

function isTimesResponse(body) {
    return /"(\d{2}:\d{2})"/.test(JSON.stringify(body));
}

function extractDates(body) {
    const found = JSON.stringify(body).match(/\d{4}-\d{2}-\d{2}/g) ?? [];
    return [...new Set(found)].sort();
}

function extractTimes(body) {
    const found = JSON.stringify(body).match(/"(\d{2}:\d{2})"/g)?.map(t => t.replace(/"/g, '')) ?? [];
    return [...new Set(found)].sort();
}

app.listen(PORT, () => console.log(`playwright-equeue listening on :${PORT}`));
