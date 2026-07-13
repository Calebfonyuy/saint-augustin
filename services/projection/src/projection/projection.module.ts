import { Module, forwardRef } from '@nestjs/common';
import { SessionsModule } from '../sessions/sessions.module';
import { ProjectionGateway } from './projection.gateway';

@Module({
  imports: [forwardRef(() => SessionsModule)],
  providers: [ProjectionGateway],
  exports: [ProjectionGateway],
})
export class ProjectionModule {}
