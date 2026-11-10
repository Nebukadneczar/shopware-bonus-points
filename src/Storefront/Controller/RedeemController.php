<?php declare(strict_types=1);

namespace MaxxpetBonus\Storefront\Controller;

use MaxxpetBonus\Cart\BonusCartProcessor;
use MaxxpetBonus\Cart\GiftCartProcessor;
use MaxxpetBonus\Service\BonusService;
use MaxxpetBonus\Service\MemberService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aktionen in Warenkorb und Kasse: Punkte einlösen, am Programm teilnehmen, Gratisprodukt wählen.
 * Die eigentliche Prüfung machen die Warenkorb-Prozessoren.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class RedeemController extends StorefrontController
{
    private const BACK_ROUTES = ['frontend.checkout.cart.page', 'frontend.checkout.confirm.page', 'frontend.account.maxxpet.bonus.page'];

    public function __construct(
        private readonly CartService $cartService,
        private readonly BonusService $bonus,
        private readonly MemberService $members,
    ) {
    }

    #[Route(
        path: '/checkout/maxxpet-bonus',
        name: 'frontend.maxxpet.bonus.redeem',
        options: ['seo' => false],
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: ['POST']
    )]
    public function redeem(Request $request, SalesChannelContext $context): Response
    {
        $back = $this->back($request);
        $customer = $context->getCustomer();
        if (!$this->bonus->isActive() || $customer === null || $customer->getGuest() || !$this->members->isParticipant($customer->getId())) {
            return $back;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->has(BonusCartProcessor::ID)) {
            $cart = $this->cartService->remove($cart, BonusCartProcessor::ID, $context);
        }

        if ((string) $request->request->get('action') === 'remove') {
            $this->addFlash(self::INFO, 'Die Einlösung deiner Punkte wurde entfernt.');

            return $back;
        }

        $points = max(0, (int) $request->request->get('points'));
        $min = $this->bonus->minRedeem();
        if ($points < $min) {
            $this->addFlash(self::WARNING, sprintf('Du kannst ab %d Punkten einlösen.', $min));

            return $back;
        }

        $item = new LineItem(BonusCartProcessor::ID, BonusCartProcessor::TYPE, null, 1);
        $item->setLabel('Eingelöste Bonuspunkte');
        $item->setPayloadValue('points', $points);
        $item->setGood(false);
        $item->setRemovable(true);
        $item->setStackable(false);

        $cart = $this->cartService->add($cart, $item, $context);

        $added = $cart->getLineItems()->get(BonusCartProcessor::ID);
        if ($added === null) {
            $this->addFlash(self::WARNING, 'Die Punkte konnten nicht eingelöst werden. Prüfe deinen Punktestand, den Mindestwarenwert und den Warenwert.');
        } else {
            $this->addFlash(self::SUCCESS, sprintf('%d Punkte wurden eingelöst.', (int) $added->getPayloadValue('pointsRedeemed')));
        }

        return $back;
    }

    #[Route(
        path: '/checkout/maxxpet-bonus/join',
        name: 'frontend.maxxpet.bonus.join',
        options: ['seo' => false],
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: ['POST']
    )]
    public function join(Request $request, SalesChannelContext $context): Response
    {
        $back = $this->back($request);
        $customer = $context->getCustomer();
        if (!$this->bonus->isActive() || $customer === null || $customer->getGuest()) {
            return $back;
        }
        $state = $this->members->state($customer->getId());
        if (!$state['eligible']) {
            $this->addFlash(self::WARNING, 'Für dein Konto ist die Teilnahme am Bonusprogramm nicht möglich.');

            return $back;
        }
        $this->members->join($customer->getId(), 'account');
        $this->addFlash(self::SUCCESS, 'Willkommen im Bonusprogramm! Ab jetzt sammelst du Punkte.');

        return $back;
    }

    #[Route(
        path: '/checkout/maxxpet-bonus/gift',
        name: 'frontend.maxxpet.bonus.gift',
        options: ['seo' => false],
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: ['POST']
    )]
    public function gift(Request $request, SalesChannelContext $context): Response
    {
        $back = $this->back($request);
        $customer = $context->getCustomer();
        if (!$this->bonus->isActive() || $customer === null || $customer->getGuest() || !$this->members->isParticipant($customer->getId())) {
            return $back;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->has(GiftCartProcessor::CHOICE_ID)) {
            $cart = $this->cartService->remove($cart, GiftCartProcessor::CHOICE_ID, $context);
        }
        $sku = trim((string) $request->request->get('sku'));
        if ((string) $request->request->get('action') === 'remove' || $sku === '') {
            return $back;
        }

        $item = new LineItem(GiftCartProcessor::CHOICE_ID, GiftCartProcessor::TYPE, null, 1);
        $item->setLabel('Gratisprodukt');
        $item->setPayloadValue('sku', $sku);
        $item->setGood(false);
        $item->setRemovable(true);
        $item->setStackable(false);

        $cart = $this->cartService->add($cart, $item, $context);
        if ($cart->getLineItems()->get(GiftCartProcessor::CHOICE_ID) === null) {
            $this->addFlash(self::WARNING, 'Dieses Gratisprodukt ist für deinen Warenkorb nicht verfügbar.');
        } else {
            $this->addFlash(self::SUCCESS, 'Dein Gratisprodukt wurde hinzugefügt.');
        }

        return $back;
    }

    private function back(Request $request): Response
    {
        $route = (string) $request->request->get('redirectTo', 'frontend.checkout.cart.page');
        if (!\in_array($route, self::BACK_ROUTES, true)) {
            $route = 'frontend.checkout.cart.page';
        }

        return $this->redirectToRoute($route);
    }
}
