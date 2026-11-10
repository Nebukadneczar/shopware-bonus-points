<?php declare(strict_types=1);

namespace BonusPoints\Storefront\Controller;

use BonusPoints\Service\BonusService;
use BonusPoints\Service\MemberService;
use BonusPoints\Service\ReferralService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Kundenkonto-Seite „Meine Punkte“ (/account/bonus). Nur für angemeldete Kunden mit Konto.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BonusController extends StorefrontController
{
    public function __construct(
        private readonly GenericPageLoaderInterface $pageLoader,
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly ReferralService $referrals,
    ) {
    }

    #[Route(
        path: '/account/bonus',
        name: 'frontend.account.bonus_points.page',
        options: ['seo' => false],
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (!$this->bonus->isActive() || $customer === null || $customer->getGuest()) {
            throw $this->createNotFoundException();
        }

        $page = $this->pageLoader->load($request, $context);
        $page->getMetaInformation()?->setRobots('noindex,follow');

        $id = $customer->getId();
        $state = $this->members->state($id);
        $participant = $this->members->isParticipant($id);

        $referral = ['enabled' => false];
        if ($participant && $this->referrals->enabled()) {
            $code = $this->members->referralCode($id);
            $stats = $this->referrals->stats($id);
            $referral = [
                'enabled' => true,
                'code' => $code,
                'link' => rtrim($this->generateUrl('frontend.home.page', [], UrlGeneratorInterface::ABSOLUTE_URL), '/') . '/?bref=' . $code,
                'count' => $stats['count'],
                'points' => $stats['points'],
                'perEuro' => $this->referrals->pointsPerEuro(),
                'welcome' => $this->referrals->welcomePoints(),
            ];
        }

        $actions = [];
        foreach (['registration' => 'Registrierung', 'newsletter' => 'Newsletter-Anmeldung', 'review' => 'Produktbewertung (nach Freigabe)', 'birthday' => 'Geburtstag (beim Anmelden am Geburtstag)'] as $key => $label) {
            $p = $this->bonus->actionPoints($key);
            if ($p > 0) {
                $actions[] = ['label' => $label, 'points' => $p];
            }
        }

        return $this->renderStorefront('@BonusPoints/storefront/page/account/bonus/index.html.twig', [
            'page' => $page,
            'participant' => $participant,
            'canJoin' => !$participant && $this->members->enrollment() === 'optin' && $state['eligible'] && !$state['member'],
            'notEligible' => !$participant && !($this->members->enrollment() === 'optin' && $state['eligible'] && !$state['member']),
            'bonus' => $participant ? $this->bonus->account($id) : null,
            'entries' => $participant ? $this->bonus->entries($id, 50) : [],
            'levels' => $this->bonus->levels(),
            'pointsPerEuro' => $this->bonus->pointsPerEuro(),
            'lockDays' => $this->bonus->lockDays(),
            'pointValue' => $this->bonus->pointValue(),
            'minRedeem' => $this->bonus->minRedeem(),
            'referral' => $referral,
            'actions' => $actions,
        ]);
    }
}
