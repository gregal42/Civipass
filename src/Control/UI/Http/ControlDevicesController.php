<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use App\Control\Application\ControlScope;
use App\Control\Application\ControlSettings;
use App\Control\Application\ControlStorageUnavailable;
use App\Control\Application\ControlActivationService;
use App\Control\Application\ControlUrlFactory;
use App\Control\Application\DeviceApprovalService;
use App\Control\Domain\AssuranceMode;
use App\Control\Domain\ControlActivationRepository;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeactivationReason;
use App\Control\Domain\DeviceStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Permit\Application\QrCodeRenderer;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Administration des appareils de contrôle hors connexion d'une commune pour un événement (LOT 09.2, DM-131). Accès : ADMINISTRATEUR de la commune de l'URL (relu à
 * chaque requête ; 404 uniforme pour une commune ou un événement étranger, 403 pour un autre rôle) ; interrupteur `OFFLINE_CONTROL_ENABLED` à 0 : 404 uniforme. Écritures :
 * commune ACTIVE, authentification complète (`security.yaml`), POST + CSRF propre à l'action et à la cible. Le code d'activation n'est affiché qu'à la réponse du POST qui
 * le crée (jamais dans une URL, un message flash ni la session) ; pages `no-store`. Les services revérifient le droit sous le verrou de la commune.
 */
#[Route('/communes/{municipalityId}/evenements/{eventId}/controle', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID])]
final class ControlDevicesController extends AbstractController
{
    public function __construct(
        private readonly ControlSettings $settings,
        private readonly MunicipalityContext $context,
        private readonly ShellContextFactory $shell,
        private readonly ControlScope $scope,
        private readonly ControlActivationService $activations,
        private readonly DeviceApprovalService $approvals,
        private readonly ControlActivationRepository $activationRepository,
        private readonly ControlDeviceRepository $devices,
        private readonly ControlUrlFactory $urls,
        private readonly QrCodeRenderer $qr,
        private readonly \Psr\Clock\ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_control_devices', methods: ['GET'])]
    public function index(string $municipalityId, string $eventId): Response
    {
        [$user, $municipality, $event] = $this->administrator($municipalityId, $eventId, write: false);
        $now = $this->clock->now();
        $uuid = Uuid::fromString($eventId);
        $devices = $this->devices->listFor($municipality->id(), $uuid);

        return $this->page($user, $municipalityId, 'Contrôle hors connexion', 'municipal/control/index.html.twig', [
            'event_name' => $event->name(),
            'event_id' => $eventId,
            'writable' => MunicipalityStatus::Active === $municipality->status(),
            'pending' => array_values(array_filter($devices, static fn (ControlDevice $d): bool => $d->isAwaitingApproval($now))),
            'devices' => array_values(array_filter($devices, static fn (ControlDevice $d): bool => DeviceStatus::Pending !== $d->status() || !$d->isAwaitingApproval($now))),
            'codes' => $this->activationRepository->listFor($municipality->id(), $uuid, 10),
            'now' => $now,
            'hard_max_hours' => intdiv($this->settings->hardMaxSeconds, 3600),
        ]);
    }

    #[Route('/code', name: 'app_control_code_new', methods: ['GET', 'POST'])]
    public function newCode(string $municipalityId, string $eventId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, $eventId, write: true);
        $form = ['mode' => AssuranceMode::Standard->value, 'devices' => '5', 'hard' => (string) intdiv($this->settings->hardMaxSeconds, 60), 'warn' => (string) intdiv($this->settings->defaultWarnSeconds, 60), 'minutes' => (string) $this->settings->codeMinutes];
        if ($request->isMethod('POST')) {
            $post = $request->request->all();
            $form = [
                'mode' => $this->text($post['mode'] ?? ''), 'devices' => $this->text($post['devices'] ?? ''), 'hard' => $this->text($post['hard'] ?? ''),
                'warn' => $this->text($post['warn'] ?? ''), 'minutes' => $this->text($post['minutes'] ?? ''),
            ];
            if (!$this->isCsrfTokenValid('control_code_'.$eventId, $this->text($post['_token'] ?? ''))) {
                return $this->codeForm($user, $municipalityId, $eventId, $form, 'Le formulaire a expiré. Validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            try {
                $mode = AssuranceMode::tryFrom($form['mode']) ?? throw new DomainRuleViolation('Choisissez un mode d’assurance.');
                $issued = $this->activations->issue(
                    $municipality, Uuid::fromString($eventId), $user, $mode,
                    $this->whole($form['devices'], 'Nombre d’appareils'), $this->minutes($form['warn']), $this->minutes($form['hard']), $this->whole($form['minutes'], 'Durée de validité du code'),
                );
            } catch (DomainRuleViolation $e) {
                return $this->codeForm($user, $municipalityId, $eventId, $form, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (ControlStorageUnavailable) {
                return $this->codeForm($user, $municipalityId, $eventId, $form, 'Le service est momentanément indisponible. Réessayez dans quelques instants.', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            // Pas de redirection : le code n'est affiché qu'ici, une seule fois.
            return $this->page($user, $municipalityId, 'Code d’activation', 'municipal/control/code_created.html.twig', [
                'event_id' => $eventId,
                'code' => $issued->code->display(),
                'qr_svg' => $this->qr->svg($this->urls->activation($issued->code->value())),
                'expires_at' => $issued->activation->expiresAt(),
                'max_devices' => $issued->activation->maxDevices(),
                'mode' => $issued->activation->assuranceMode(),
                'warn_hours' => $issued->activation->trustWarnSeconds() / 3600,
                'hard_hours' => $issued->activation->trustHardSeconds() / 3600,
                'back_url' => $this->generateUrl('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            ]);
        }

        return $this->codeForm($user, $municipalityId, $eventId, $form);
    }

    #[Route('/codes/{activationId}/annuler', name: 'app_control_code_revoke', requirements: ['activationId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function revokeCode(string $municipalityId, string $eventId, string $activationId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, $eventId, write: true);
        $this->ownedActivation($municipality, $eventId, $activationId);
        if ($request->isMethod('POST')) {
            $this->guardCsrf('control_code_revoke_'.$activationId, $request);
            try {
                $this->activations->revoke($municipality, Uuid::fromString($activationId), $user);
                $this->addFlash('success', 'Code annulé : il ne permet plus d’enregistrer d’appareil.');
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->back($municipalityId, $eventId);
        }

        return $this->confirm($user, $municipalityId, $eventId, 'Annuler ce code d’activation', ['Le code ne permettra plus d’enregistrer d’appareil.'], ['Les appareils déjà enregistrés avec ce code ne sont pas touchés.'], 'Annuler le code', $this->generateUrl('app_control_code_revoke', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'activationId' => $activationId]), 'control_code_revoke_'.$activationId);
    }

    #[Route('/appareils/{deviceId}/approuver', name: 'app_control_device_approve', requirements: ['deviceId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function approve(string $municipalityId, string $eventId, string $deviceId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, $eventId, write: true);
        $device = $this->ownedDevice($municipality, $eventId, $deviceId);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->guardCsrf('control_device_approve_'.$deviceId, $request);
            try {
                $this->approvals->approve($municipality, $device->id(), $this->text($request->request->get('pairing')), $user);
                $this->addFlash('success', 'Appareil approuvé : il peut maintenant recevoir les données de contrôle de votre commune.');

                return $this->back($municipalityId, $eventId);
            } catch (DomainRuleViolation $e) {
                $error = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } catch (ControlStorageUnavailable) {
                $error = 'Le service est momentanément indisponible. Réessayez dans quelques instants.';
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        return $this->page($user, $municipalityId, 'Approuver un appareil', 'municipal/control/approve.html.twig', [
            'device' => $device, 'error' => $error, 'csrf' => 'control_device_approve_'.$deviceId,
            'action_url' => $this->generateUrl('app_control_device_approve', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'deviceId' => $deviceId]),
            'cancel_url' => $this->generateUrl('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
        ], $status);
    }

    #[Route('/appareils/{deviceId}/desactiver', name: 'app_control_device_deactivate', requirements: ['deviceId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function deactivateDevice(string $municipalityId, string $eventId, string $deviceId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, $eventId, write: true);
        $device = $this->ownedDevice($municipality, $eventId, $deviceId);
        $reasons = DeviceStatus::Pending === $device->status()
            ? [DeactivationReason::Rejected, DeactivationReason::Other]
            : [DeactivationReason::Lost, DeactivationReason::Compromised, DeactivationReason::EndOfUse, DeactivationReason::Other];
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->guardCsrf('control_device_deactivate_'.$deviceId, $request);
            try {
                $reason = DeactivationReason::tryFrom($this->text($request->request->get('reason'))) ?? throw new DomainRuleViolation('Choisissez un motif.');
                if (!\in_array($reason, $reasons, true)) {
                    throw new DomainRuleViolation('Motif non applicable à cet appareil.');
                }
                $this->approvals->deactivateDevice($municipality, $device->id(), $reason, $user);
                $this->addFlash('success', 'Appareil désactivé. Il sera refusé et ses données supprimées dès sa prochaine connexion.');

                return $this->back($municipalityId, $eventId);
            } catch (DomainRuleViolation $e) {
                $error = $e->getMessage();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->page($user, $municipalityId, 'Désactiver un appareil', 'municipal/control/deactivate.html.twig', [
            'device' => $device, 'reasons' => $reasons, 'error' => $error, 'csrf' => 'control_device_deactivate_'.$deviceId,
            'action_url' => $this->generateUrl('app_control_device_deactivate', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'deviceId' => $deviceId]),
            'cancel_url' => $this->generateUrl('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
        ], $status);
    }

    #[Route('/appareils/{deviceId}/renouveler', name: 'app_control_device_renew', requirements: ['deviceId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function renew(string $municipalityId, string $eventId, string $deviceId, Request $request): Response
    {
        [$user, $municipality] = $this->administrator($municipalityId, $eventId, write: true);
        $device = $this->ownedDevice($municipality, $eventId, $deviceId);
        if ($request->isMethod('POST')) {
            $this->guardCsrf('control_device_renew_'.$deviceId, $request);
            try {
                $this->approvals->renew($municipality, $device->id(), $user);
                $this->addFlash('success', 'Autorisation de l’appareil renouvelée.');
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->back($municipalityId, $eventId);
        }

        return $this->confirm($user, $municipalityId, $eventId, 'Renouveler l’autorisation de « '.$device->label().' »', [sprintf('L’appareil reste autorisé %d heures de plus à compter de maintenant (dans la limite de la fin de l’événement).', $this->settings->deviceLifetimeHours)], ['Le secret de l’appareil n’est pas modifié.'], 'Renouveler', $this->generateUrl('app_control_device_renew', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'deviceId' => $deviceId]), 'control_device_renew_'.$deviceId);
    }

    /**
     * @return array{0: User, 1: Municipality, 2: \App\Event\Domain\Event}
     */
    private function administrator(string $municipalityId, string $eventId, bool $write): array
    {
        if (!$this->settings->enabled) {
            throw new NotFoundHttpException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $membership = $this->context->requireMembership($user, $municipalityId, write: false); // 404 uniforme : inconnue, étrangère ou non membre
        if (!$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw new AccessDeniedHttpException('Réservé aux administrateurs communaux.');
        }
        $municipality = $membership->municipality();
        if ($write && MunicipalityStatus::Active !== $municipality->status()) {
            throw new AccessDeniedHttpException('Commune suspendue : gestion du contrôle en lecture seule.');
        }
        try {
            $event = $this->scope->eventOf($municipality, Uuid::fromString($eventId));
        } catch (OperationNotAllowed) {
            throw new NotFoundHttpException();
        }

        return [$user, $municipality, $event];
    }

    private function ownedActivation(Municipality $municipality, string $eventId, string $activationId): void
    {
        $activation = $this->activationRepository->find(Uuid::fromString($activationId));
        if (null === $activation || !$activation->municipality()->id()->equals($municipality->id()) || $activation->event()->id()->toRfc4122() !== $eventId) {
            throw new NotFoundHttpException();
        }
    }

    private function ownedDevice(Municipality $municipality, string $eventId, string $deviceId): ControlDevice
    {
        $device = $this->devices->find(Uuid::fromString($deviceId));
        if (null === $device || !$device->activation()->municipality()->id()->equals($municipality->id()) || $device->activation()->event()->id()->toRfc4122() !== $eventId) {
            throw new NotFoundHttpException();
        }

        return $device;
    }

    private function guardCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $this->text($request->request->get('_token')))) {
            throw new AccessDeniedHttpException('Le formulaire a expiré. Rien n’a été modifié : validez à nouveau.');
        }
    }

    /**
     * @param array<string, string> $form
     */
    private function codeForm(User $user, string $municipalityId, string $eventId, array $form, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->page($user, $municipalityId, 'Créer un code d’activation', 'municipal/control/code_new.html.twig', [
            'form' => $form, 'error' => $error, 'csrf' => 'control_code_'.$eventId, 'hard_max_minutes' => intdiv($this->settings->hardMaxSeconds, 60),
            'action_url' => $this->generateUrl('app_control_code_new', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            'cancel_url' => $this->generateUrl('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
        ], $status);
    }

    /**
     * @param list<string> $consequences
     * @param list<string> $keeps
     */
    private function confirm(User $user, string $municipalityId, string $eventId, string $title, array $consequences, array $keeps, string $label, string $actionUrl, string $csrfId): Response
    {
        return $this->page($user, $municipalityId, $title, 'municipal/control/confirm.html.twig', [
            'title' => $title, 'consequences' => $consequences, 'keeps' => $keeps, 'confirm_label' => $label, 'action_url' => $actionUrl, 'csrf' => $csrfId,
            'cancel_url' => $this->generateUrl('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(User $user, string $municipalityId, string $title, string $template, array $data, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $this->shell->forMunicipality($user, $municipalityId, 'events', $title) + $data, new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function back(string $municipalityId, string $eventId): Response
    {
        return $this->redirectToRoute('app_control_devices', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function whole(string $value, string $label): int
    {
        if (1 !== preg_match('/^\d{1,4}$/', trim($value))) {
            throw new DomainRuleViolation($label.' : saisissez un nombre entier.');
        }

        return (int) $value;
    }

    /** Minutes saisies → secondes. */
    private function minutes(string $value): int
    {
        return $this->whole($value, 'Durée en minutes') * 60;
    }
}
