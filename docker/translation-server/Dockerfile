FROM node:18-slim

RUN apt-get update && apt-get install -y git && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://github.com/zotero/translation-server.git /app

WORKDIR /app

RUN npm install --legacy-peer-deps

EXPOSE 1969

CMD ["node", "src/server.js"]
