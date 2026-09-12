FROM node:22-bookworm-slim

ENV NODE_ENV=production

WORKDIR /app

COPY apps/realtime/package.json ./package.json
RUN npm install --omit=dev --ignore-scripts --no-audit --no-fund

COPY apps/realtime/server.js ./server.js

USER node

EXPOSE 1234

CMD ["node", "server.js"]
