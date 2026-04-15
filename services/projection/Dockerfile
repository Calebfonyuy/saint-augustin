# =============================================================================
# SaintAugustin Projection Service – Dockerfile
# NestJS 10 on Node.js 20 Alpine
#
# Dev mode: mounts source via volume, uses ts-node with --watch.
# Prod mode: multi-stage build, compiled JS only.
#
# Ref: https://docs.nestjs.com/
# Ref: https://hub.docker.com/_/node
# =============================================================================

# --- Build stage ---
FROM node:20-alpine AS builder

WORKDIR /app
COPY package*.json ./
RUN npm i
COPY . .
RUN npm run build

# --- Production stage ---
FROM node:20-alpine AS production

WORKDIR /app
COPY package*.json ./
RUN npm i --omit=dev
COPY --from=builder /app/dist ./dist

EXPOSE 3000

CMD ["node", "dist/main.js"]

# --- Development stage (used by docker-compose) ---
FROM node:20-alpine AS development

WORKDIR /app
COPY package*.json ./
RUN npm i

# Source is mounted via volume in docker-compose
EXPOSE 3000

CMD ["npm", "run", "start:dev"]
