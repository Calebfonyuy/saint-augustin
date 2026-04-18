<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * SaintAugustin Auth Service – OpenAPI base info block.
 *
 * This class carries the top-level @OA\Info annotation that swagger-php
 * needs to generate a valid OpenAPI 3.0 document. Route-level annotations
 * live on the controllers.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SaintAugustin Auth Service',
    description: 'Authentication, user management, and role assignment for the SaintAugustin worship platform.',
    contact: new OA\Contact(
        name: 'SaintAugustin',
        email: 'admin@saintaugustin.local',
    ),
)]
#[OA\Server(
    url: '/api',
    description: 'Local development (via API gateway)',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Bearer token obtained from POST /api/auth/login',
)]
class ApiInfo {}
