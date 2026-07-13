import { Module, forwardRef } from '@nestjs/common';
import { ProjectionModule } from '../projection/projection.module';
import { SessionsController } from './sessions.controller';
import { SessionsService } from './sessions.service';

@Module({
  imports: [forwardRef(() => ProjectionModule)],
  controllers: [SessionsController],
  providers: [SessionsService],
  exports: [SessionsService],
})
export class SessionsModule {}
