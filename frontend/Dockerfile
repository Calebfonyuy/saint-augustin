# =============================================================================
# SaintAugustin Frontend – Dockerfile
# Vue 3 + Vite + TypeScript
#
# Dev: Vite dev server with HMR (source mounted via volume)
# Prod: multi-stage build → static assets served by nginx
#
# Ref: https://vite.dev/guide/
# =============================================================================
FROM node:26-alpine3.22

ENV VITE_API_BASE_URL="http://staug-api:8080/api"
ENV VITE_WS_URL="ws://staug-projection:3000/ws"
ENV VITE_PROJECTION_BASE_URL="http://staug-projection:3000"

WORKDIR /app
COPY package*.json ./
RUN npm i

COPY . .
COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT [ "/entrypoint.sh" ]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "80"]
