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

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  const configService = app.get(ConfigService);
  const port = configService.get<number>('PORT', 3000);

  // Use Socket.IO adapter for WebSocket transport
  app.useWebSocketAdapter(new IoAdapter(app));

  // Enable CORS for development
  app.enableCors({
    origin: '*',
    methods: ['GET', 'POST'],
  });

  await app.listen(port);
  console.log(`🎵 Projection Service running on port ${port}`);
  console.log(`   Health: http://localhost:${port}/health`);
  console.log(`   WebSocket: ws://localhost:${port}`);
}

bootstrap();
