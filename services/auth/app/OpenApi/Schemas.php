<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Reusable OpenAPI schema definitions.
 *
 * These are referenced via $ref across controller annotations so they are
 * defined once and stay consistent.
 */

#[OA\Schema(
    schema: 'TokenResponse',
    description: 'Successful authentication response carrying a Sanctum Bearer token.',
    required: ['token_type', 'access_token', 'user'],
    properties: [
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'access_token', type: 'string', example: '1|abc123...'),
        new OA\Property(
            property: 'user',
            type: 'object',
            required: ['id', 'email', 'display_name', 'roles'],
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid', example: 'a1b2c3d4-...'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
                new OA\Property(property: 'display_name', type: 'string', example: 'Jean Dupont'),
                new OA\Property(
                    property: 'roles',
                    type: 'array',
                    items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
                    example: ['musician'],
                ),
            ],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    description: 'Generic error response.',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    description: 'Laravel validation failure (HTTP 422).',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The email field is required.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
            example: ['email' => ['The email field is required.']],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'InvitationResource',
    description: 'An admin-issued invitation record.',
    required: ['id', 'email', 'roles', 'expires_at', 'accepted'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'newuser@example.com'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
            example: ['musician'],
        ),
        new OA\Property(property: 'invited_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'accepted', type: 'boolean', example: false),
    ],
    type: 'object',
)]
class Schemas {}
