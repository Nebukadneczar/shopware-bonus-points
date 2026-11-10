<?php declare(strict_types=1);

namespace BonusPoints\Subscriber;

use Doctrine\DBAL\Connection;
use BonusPoints\Service\BonusService;
use BonusPoints\Service\MemberService;
use BonusPoints\Service\ReferralService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aktionen rund ums Kundenkonto: Beitritt bei der Registrierung, Willkommensbonus, Empfehlungscode,
 * Newsletter, Bewertungsprämie und Geburtstagsbonus. Fehler werden nur protokolliert.
 */
class CustomerSubscriber implements EventSubscriberInterface
{
    public const SESSION_REF = 'bonus_points_ref';

    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly ReferralService $referrals,
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            CustomerRegisterEvent::class => 'onRegister',
            CustomerLoginEvent::class => 'onLogin',
            NewsletterConfirmEvent::class => 'onNewsletter',
            'product_review.written' => 'onReviewWritten',
        ];
    }

    /** Empfehlungslink: ?bref=CODE merkt sich den Code für die Registrierung. */
    public function onRequest(RequestEvent $event): void
    {
        try {
            if (!$event->isMainRequest()) {
                return;
            }
            $request = $event->getRequest();
            $code = $request->query->get('bref');
            if (!\is_string($code) || $code === '' || !$request->hasSession() || !$this->referrals->enabled()) {
                return;
            }
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
            if ($code !== '' && \strlen($code) <= 16) {
                $request->getSession()->set(self::SESSION_REF, $code);
            }
        } catch (\Throwable) {
            // Empfehlungslink ist optional und darf nie eine Seite stören
        }
    }

    public function onRegister(CustomerRegisterEvent $event): void
    {
        $this->run('register', function () use ($event): void {
            $customer = $event->getCustomer();
            if ($customer->getGuest() || !$this->bonus->isActive()) {
                return;
            }
            $id = $customer->getId();
            $request = $this->requestStack->getCurrentRequest();

            if ($request !== null && $request->request->get('bonusPointsJoin')) {
                $this->members->join($id, 'register');
            }

            // Empfehlung: Code aus dem Formular oder aus dem Empfehlungslink
            $code = '';
            if ($request !== null) {
                $code = (string) $request->request->get('bonusPointsRef', '');
                if ($code === '' && $request->hasSession()) {
                    $code = (string) $request->getSession()->get(self::SESSION_REF, '');
                }
            }
            if ($code !== '') {
                $this->referrals->register($id, $code);
            }

            $pts = $this->bonus->actionPoints('registration');
            if ($pts > 0) {
                $this->bonus->addAction($id, 'registration', $pts, 'Willkommensbonus für dein neues Konto', 'registration');
            }
        });
    }

    public function onLogin(CustomerLoginEvent $event): void
    {
        $this->run('login', function () use ($event): void {
            $pts = $this->bonus->actionPoints('birthday');
            $customer = $event->getCustomer();
            if ($pts <= 0 || $customer->getGuest() || !$this->bonus->isActive()) {
                return;
            }
            $birthday = $customer->getBirthday();
            if ($birthday === null) {
                return;
            }
            $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
            if ($birthday->format('m-d') !== $today->format('m-d')) {
                return;
            }
            $this->bonus->addAction($customer->getId(), 'birthday', $pts, 'Alles Gute zum Geburtstag!', 'birthday:' . $today->format('Y'));
        });
    }

    public function onNewsletter(NewsletterConfirmEvent $event): void
    {
        $this->run('newsletter', function () use ($event): void {
            $pts = $this->bonus->actionPoints('newsletter');
            if ($pts <= 0) {
                return;
            }
            $email = $event->getNewsletterRecipient()->getEmail();
            foreach ($this->bonus->findCustomers($email) as $c) {
                $this->bonus->addAction($c['id'], 'newsletter', $pts, 'Danke für die Newsletter-Anmeldung', 'newsletter');
            }
        });
    }

    /** Freigegebene Bewertung → Prämie (einmal je Kunde und Produkt). */
    public function onReviewWritten(EntityWrittenEvent $event): void
    {
        $this->run('review', function () use ($event): void {
            $pts = $this->bonus->actionPoints('review');
            if ($pts <= 0) {
                return;
            }
            foreach ($event->getWriteResults() as $result) {
                $payload = $result->getPayload();
                if (!\array_key_exists('status', $payload) || !$payload['status']) {
                    continue;
                }
                $pk = $result->getPrimaryKey();
                $id = \is_array($pk) ? (string) ($pk['id'] ?? '') : (string) $pk;
                if (!Uuid::isValid($id)) {
                    continue;
                }
                $row = $this->db->fetchAssociative(
                    'SELECT LOWER(HEX(customer_id)) AS cid, LOWER(HEX(product_id)) AS pid, status FROM product_review WHERE id = :id',
                    ['id' => Uuid::fromHexToBytes($id)]
                );
                if (!$row || empty($row['cid']) || (int) $row['status'] !== 1) {
                    continue;
                }
                $this->bonus->addAction((string) $row['cid'], 'review', $pts, 'Prämie für deine Produktbewertung', 'review:' . $row['pid']);
            }
        });
    }

    private function run(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->error('BonusPoints (' . $what . '): ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
