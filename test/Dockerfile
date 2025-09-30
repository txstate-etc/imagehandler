FROM mcr.microsoft.com/playwright:v1.43.0-jammy

WORKDIR /opt/playwright
COPY test/package.json test/package-lock.json ./
RUN npm --quiet install
COPY test/tsconfig.json ./
COPY test/playwright.config.ts ./
COPY test/tests tests

CMD ["npm", "run", "playwright"]
