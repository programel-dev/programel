FROM mcr.microsoft.com/playwright:v1.44.0-jammy

WORKDIR /app

COPY package.json ./
RUN PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --production

COPY server.js ./

EXPOSE 3001
CMD ["node", "server.js"]
