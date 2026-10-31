<?php

namespace Modules\Platform\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A 4xx error whose message is written for the client and shown as the problem+json `detail` (TASK-0014 #1).
 * Every other exception gets a fixed detail per status. Never put internal or user-supplied data in the message.
 */
final class PublicHttpException extends HttpException {}
