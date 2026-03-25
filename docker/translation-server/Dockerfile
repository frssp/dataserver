FROM node:18-slim

RUN apt-get update && apt-get install -y git ca-certificates && rm -rf /var/lib/apt/lists/*

# Copy corporate CA certificate for internal network
COPY ca-cert.pem /usr/local/share/ca-certificates/ca-cert.crt
RUN update-ca-certificates

# Ensure Node.js and git use the system CA bundle
ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

RUN git clone --depth 1 https://github.com/zotero/translation-server.git /app

WORKDIR /app

RUN npm install --legacy-peer-deps

EXPOSE 1969

CMD ["node", "src/server.js"]
