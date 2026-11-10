<?php declare(strict_types=1);

namespace BonusPoints\Controller;

use BonusPoints\Service\AdminService;
use BonusPoints\Service\BonusService;
use BonusPoints\Service\MemberService;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schnittstelle für das Admin-Modul „Bonuspunkte“. Lesen braucht das Recht customer:read, Ändern customer:update.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly BonusService $bonus,
        private readonly MemberService $members,
    ) {
    }

    #[Route(path: '/api/_action/bonus-points/overview', name: 'api.action.bonus-points.overview', defaults: ['_acl' => ['customer:read']], methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        return new JsonResponse([
            'stats' => $this->admin->stats(),
            'list' => $this->admin->customers(
                (string) $request->query->get('search', ''),
                max(1, (int) $request->query->get('page', 1)),
                (int) $request->query->get('limit', 25),
                (string) $request->query->get('filter', 'active'),
                (string) $request->query->get('sort', 'available'),
                (string) $request->query->get('dir', 'desc')
            ),
        ]);
    }

    #[Route(path: '/api/_action/bonus-points/export', name: 'api.action.bonus-points.export', defaults: ['_acl' => ['customer:read']], methods: ['GET'])]
    public function export(): Response
    {
        return new Response($this->admin->exportCsv(), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bonuspunkte-' . date('Y-m-d') . '.csv"',
        ]);
    }

    #[Route(path: '/api/_action/bonus-points/customer/{id}', name: 'api.action.bonus-points.customer', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['customer:read']], methods: ['GET'])]
    public function customer(string $id): Response
    {
        $c = $this->admin->customer($id);

        return $c === null ? new JsonResponse(['error' => 'Kunde nicht gefunden'], 404) : new JsonResponse($c);
    }

    #[Route(path: '/api/_action/bonus-points/customer/{id}/book', name: 'api.action.bonus-points.book', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['customer:update']], methods: ['POST'])]
    public function book(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $points = \is_array($data) ? (int) ($data['points'] ?? 0) : 0;
        $reason = \is_array($data) ? trim((string) ($data['reason'] ?? '')) : '';
        if ($points === 0 || $reason === '' || !Uuid::isValid($id) || $this->admin->customer($id) === null) {
            return new JsonResponse(['error' => 'Punkte (ungleich 0) und Begründung sind Pflicht.'], 422);
        }
        $this->bonus->addManual($id, $points, $reason);

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/_action/bonus-points/customer/{id}/block', name: 'api.action.bonus-points.block', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['customer:update']], methods: ['POST'])]
    public function block(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $block = \is_array($data) && !empty($data['block']);
        if ($this->admin->customer($id) === null) {
            return new JsonResponse(['error' => 'Kunde nicht gefunden'], 404);
        }
        $block ? $this->members->block($id) : $this->members->unblock($id);

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/_action/bonus-points/import', name: 'api.action.bonus-points.import', defaults: ['_acl' => ['customer:update']], methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $since = \is_array($data) ? (string) ($data['since'] ?? '') : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            return new JsonResponse(['error' => 'Bitte ein Datum im Format JJJJ-MM-TT angeben.'], 422);
        }

        return new JsonResponse($this->admin->importOrders($since, !empty($data['apply'])));
    }
}
