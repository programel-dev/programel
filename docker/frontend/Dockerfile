FROM node:20-alpine AS base

WORKDIR /app

# --- Development ---
FROM base AS dev

COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci

EXPOSE 3000
ENV WATCHPACK_POLLING=true

CMD ["npm", "run", "dev"]

# --- Dependencies ---
FROM base AS deps

COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci

# --- Build ---
FROM base AS builder

COPY --from=deps /app/node_modules ./node_modules
COPY frontend/ .

ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

# --- Production ---
FROM base AS prod

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nextjs

COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000
ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget -qO- http://localhost:3000/ || exit 1

CMD ["node", "server.js"]
