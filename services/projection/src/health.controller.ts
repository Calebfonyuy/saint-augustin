import { Controller, Get } from '@nestjs/common';

@Controller()
export class HealthController {
  @Get('health')
  health() {
    return {
      status: 'ok',
      service: 'projection-service',
      timestamp: new Date().toISOString(),
    };
  }
}
