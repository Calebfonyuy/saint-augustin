import { Module } from '@nestjs/common';
import { SessionsModule } from '../sessions/sessions.module';
import { ProjectionGateway } from './projection.gateway';

@Module({
  imports: [SessionsModule],
  providers: [ProjectionGateway],
})
export class ProjectionModule {}
