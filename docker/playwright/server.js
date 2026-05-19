'use strict';

const express = require('express');
const { chromium } = require('playwright-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
chromium.use(StealthPlugin());

const app = express();
app.use((req, _res, next) => { console.log(`${new Date().toISOString()} ${req.method} ${req.path}`); next(); });
const PORT = 3001;
const TARGET_URL = 'https://munich.pasport.org.ua/solutions/e-queue';
const PAGE_TIMEOUT_MS = 30_000;
const SELECT_TIMEOUT_MS = 1_000;

const UA_MONTHS = ['січня','лютого','березня','квітня','травня','червня','липня','серпня','вересня','жовтня','листопада','грудня'];

function formatDateUk(date) {
    const parts = date.split('.');
    if (parts.length !== 3) return date;
    const day = parseInt(parts[0], 10);
    const monthIdx = parseInt(parts[1], 10) - 1;
    return `${day} ${UA_MONTHS[monthIdx] ?? parts[1]} ${parts[2]}`;
}

app.get('/health', (_req, res) => {
    res.json({ status: 'ok' });
});

app.get('/slots', async (_req, res) => {
    const fetchedAt = new Date().toISOString();
    let browser = null;

    try {
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();

        try {
            await page.goto(TARGET_URL, { waitUntil: 'networkidle', timeout: PAGE_TIMEOUT_MS });
        } catch (e) {
            if (!String(e?.message).includes('networkidle')) {
                return res.json({ success: false, reason: 'timeout', fetchedAt });
            }
        }

        if (await page.locator('text=Наразі всі місця зайняті').count() > 0) {
            return res.json({ success: true, date: null, dateFormatted: null, slots: [], fetchedAt });
        }

        const serviceSelect = page.locator('select#service, select').first();
        try {
            await serviceSelect.waitFor({ timeout: PAGE_TIMEOUT_MS });
        } catch {
            return res.json({ success: false, reason: 'blocked', fetchedAt });
        }
        await serviceSelect.selectOption('4');

        let date = null;
        try {
            await page.waitForFunction(
                () => Array.from(document.querySelector('select#date')?.options ?? []).some(o => o.value !== ''),
                { timeout: SELECT_TIMEOUT_MS },
            );
            date = await page.evaluate(
                () => Array.from(document.querySelector('select#date').options).find(o => o.value !== '')?.value ?? null,
            );
        } catch { /* no dates available */ }

        if (!date) {
            return res.json({ success: true, date: null, dateFormatted: null, slots: [], fetchedAt });
        }

        await page.selectOption('select#date', date);

        let slots = [];
        try {
            await page.waitForFunction(
                () => Array.from(document.querySelector('select#time')?.options ?? []).some(o => o.value !== ''),
                { timeout: SELECT_TIMEOUT_MS },
            );
            slots = await page.evaluate(
                () => Array.from(document.querySelector('select#time').options)
                    .filter(o => o.value !== '')
                    .map(o => o.text.trim()),
            );
        } catch { /* no time slots */ }

        return res.json({ success: true, date, dateFormatted: formatDateUk(date), slots, fetchedAt });

    } catch (err) {
        const reason = String(err?.message ?? '').includes('timeout') ? 'timeout' : 'blocked';
        return res.json({ success: false, reason, fetchedAt });
    } finally {
        await browser?.close();
    }
});

app.listen(PORT, () => console.log(`playwright-equeue listening on :${PORT}`));
