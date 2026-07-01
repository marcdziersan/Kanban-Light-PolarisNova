<?php
/**
 * Schlanker API-Einstiegspunkt für PolarisNova.
 *
 * Die eigentliche Logik liegt in `app/Controller/KanbanApiController.php`.
 */

declare(strict_types=1);

use App\Controller\KanbanApiController;
use App\Core\JsonResponse;

require_once __DIR__ . '/../app/bootstrap.php';

JsonResponse::prepare();
(new KanbanApiController())->handle();
