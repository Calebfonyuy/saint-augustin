# =============================================================================
# SaintAugustin Frontend – Dockerfile
# Vue 3 + Vite + TypeScript
#
# Dev: Vite dev server with HMR (source mounted via volume)
# Prod: multi-stage build → static assets served by nginx
#
# Ref: https://vite.dev/guide/
# =============================================================================
FROM node:26-alpine3.22 AS builder

WORKDIR /app
COPY package*.json ./
RUN npm i

COPY . .
RUN npm run build



FROM nginx:stable-alpine3.23

ENV API_BASE_URL="http://staug-api:8080"
ENV PROJECTION_WS_URL="ws://staug-projection:3000/ws"
ENV PROJECTION_URL="http://staug-projection:3000"

COPY --from=builder /app/dist /app

COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

COPY ./nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD [ "/entrypoint.sh" ]

