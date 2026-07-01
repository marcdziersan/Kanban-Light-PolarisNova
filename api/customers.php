<?php
/**
 * Schlanker API-Einstiegspunkt für die PolarisNova-Kundenverwaltung.
 *
 * Die eigentliche Logik liegt in `app/Controller/CustomerApiController.php`.
 */

declare(strict_types=1);

use App\Controller\CustomerApiController;
use App\Core\JsonResponse;

require_once __DIR__ . '/../app/bootstrap.php';

JsonResponse::prepare();
(new CustomerApiController())->handle();
