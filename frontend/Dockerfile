# =============================================================================
# SaintAugustin Frontend – Dockerfile
# Vue 3 + Vite + TypeScript
#
# Dev: Vite dev server with HMR (source mounted via volume)
# Prod: multi-stage build → static assets served by nginx
#
# Ref: https://vite.dev/guide/
# =============================================================================

# --- Development stage (used by docker-compose) ---
FROM node:20-alpine AS development

WORKDIR /app
COPY package*.json ./
RUN npm i
# Source mounted via volume in docker-compose

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]

# --- Build stage ---
FROM node:20-alpine AS builder

WORKDIR /app
COPY package*.json ./
RUN npm i
COPY . .
RUN npm run build

# --- Production stage ---
FROM nginx:1.27-alpine AS production

COPY --from=builder /app/dist /usr/share/nginx/html

# SPA fallback: all routes → index.html
RUN echo 'server { \
    listen 80; \
    root /usr/share/nginx/html; \
    index index.html; \
    location / { \
        try_files $uri $uri/ /index.html; \
    } \
}' > /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
