import { Test, TestingModule } from '@nestjs/testing';
import { HealthController } from '../src/health.controller';

describe('HealthController', () => {
  let controller: HealthController;

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [HealthController],
    }).compile();

    controller = module.get<HealthController>(HealthController);
  });

  it('should return ok status', () => {
    const result = controller.health();
    expect(result.status).toBe('ok');
    expect(result.service).toBe('projection-service');
    expect(result.timestamp).toBeDefined();
  });
});
