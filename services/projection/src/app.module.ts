import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { HealthController } from './health.controller';
import { ProjectionModule } from './projection/projection.module';
import { RedisModule } from './redis/redis.module';
import { SessionsModule } from './sessions/sessions.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    RedisModule,
    SessionsModule,
    ProjectionModule,
  ],
  controllers: [HealthController],
})
export class AppModule {}
