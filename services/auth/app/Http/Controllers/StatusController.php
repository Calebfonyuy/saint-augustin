<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class StatusController
{
    #[OA\Get(
        path: '/auth/status',
        summary: 'Service health check',
        description: 'Returns the current status and version of the Auth Service.',
        tags: ['Status'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is running',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'service', type: 'string', example: 'auth-service'),
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'version', type: 'string', example: '0.1.0'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service' => 'auth-service',
            'status'  => 'ok',
            'version' => '0.1.0',
        ]);
    }
}
