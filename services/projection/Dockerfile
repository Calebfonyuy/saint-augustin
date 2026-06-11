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

FROM node:26-alpine3.22

ENV NODE_ENV="production"
ENV REDIS_HOST="redis"
ENV REDIS_PORT=6379
ENV PORT=3000

WORKDIR /app
COPY package*.json ./
RUN npm i
# RUN npm i -g http-server

COPY . .
COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN npm run build

EXPOSE 3000

ENTRYPOINT [ "/entrypoint.sh" ]

# CMD ["http-server", "dist", "--cors", "-p", "3000"]
CMD ["npm", "run", "start:prod", "--", "--host", "0.0.0.0", "--port", "3000"]
