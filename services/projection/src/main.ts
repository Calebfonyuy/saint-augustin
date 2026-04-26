// =============================================================================
// SaintAugustin Projection Service – Entry Point
// NestJS application bootstrap with Socket.IO WebSocket adapter.
//
// Ref: https://docs.nestjs.com/websockets/gateways
// Ref: https://docs.nestjs.com/techniques/configuration
// =============================================================================

import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import { ConfigService } from '@nestjs/config';
import { IoAdapter } from '@nestjs/platform-socket.io';
import { ValidationPipe } from '@nestjs/common';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  const configService = app.get(ConfigService);
  const port = configService.get<number>('PORT', 3000);

  // Validate every controller payload by default — DTOs use class-validator
  // decorators and the global pipe makes them enforceable without adornment
  // on every handler.
  app.useGlobalPipes(
    new ValidationPipe({
      transform: true,
      whitelist: true,
      forbidNonWhitelisted: false,
    }),
  );

  app.useWebSocketAdapter(new IoAdapter(app));

  app.enableCors({
    origin: '*',
    methods: ['GET', 'POST', 'DELETE'],
    allowedHeaders: ['Content-Type', 'X-Control-Token'],
  });

  await app.listen(port);
  // eslint-disable-next-line no-console
  console.log(`🎵 Projection Service listening on :${port}`);
}

bootstrap();
