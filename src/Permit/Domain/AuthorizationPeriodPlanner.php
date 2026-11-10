<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventStatus;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Request\Domain\DurationMode;
use App\Request\Domain\GrantWindow;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Règles de prolongation, de reprise et de raccourcissement d'une autorisation (LOT 05.5.2, DM-119 §4.2, décisions du 10 novembre 2026). Pure : aucune
 * lecture ni écriture, un instant et un état en entrée, un plan (ou un refus motivé en français) en sortie ; elle sert à l'aperçu comme à l'écriture.
 *
 * Période courante `[from, until)` (`until` nul : jusqu'à la clôture), début INCLUS, fin EXCLUE ; instant `now` à la seconde.
 *  - PROLONGATION (courante non terminée) : la fin avance (≥ 1 minute), jamais au-delà de la fin de l'événement ; « jusqu'à la clôture » possible.
 *  - REPRISE (courante terminée STRICTEMENT avant `now`) : une NOUVELLE période commence à `now` ; l'ancienne est archivée ; AUCUN droit pendant l'interruption,
 *    aucun rétablissement rétroactif ; durée minimale d'une minute ; événement ACTIF et non terminé.
 *  - RACCOURCISSEMENT : la fin recule ; une date passée devient une FIN IMMÉDIATE (`now`), jamais antidatée ; une fin future doit être à plus d'une minute.
 * Prolongation et reprise sont refusées si l'événement n'est pas ACTIF (suspendu, clos) ; le raccourcissement, qui RETIRE des droits, reste possible pendant
 * une suspension (interprétation à confirmer) mais pas sur un événement clos. Une autorisation révoquée refuse tout.
 */
final readonly class AuthorizationPeriodPlanner
{
    /** Durée minimale d'une période programmée (décision 5). */
    public const MINIMUM_SECONDS = 60;

    public function __construct(private LocalTimeResolver $resolver = new LocalTimeResolver())
    {
    }

    /**
     * @throws DomainRuleViolation refus motivé
     */
    public function extend(GrantWindow $current, bool $revoked, EventStatus $eventStatus, ?\DateTimeImmutable $eventEnd, EventTimezone $timezone, \DateTimeImmutable $now, PeriodEnd $end): PeriodPlan
    {
        $now = self::second($now);
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : aucune période ne peut plus être modifiée.');
        }
        self::assertEventActive($eventStatus);
        if (null !== $current->until && $current->until < $now) {
            return $this->resume($current, $eventEnd, $timezone, $now, $end);
        }
        if (null === $current->until) {
            throw new DomainRuleViolation('Cette autorisation est déjà valable jusqu\'à la clôture de l\'événement : prolongez la fin de l\'événement pour la repousser.');
        }
        $until = $this->resolve($end, $timezone, $current->until);
        if (null === $until) {
            return new PeriodPlan(PeriodKind::Extended, $current, DurationMode::UntilClosure, $current->from, null, false);
        }
        if ($until < $current->until->modify('+'.self::MINIMUM_SECONDS.' seconds')) {
            throw new DomainRuleViolation('La nouvelle fin doit être postérieure d\'au moins une minute à la fin actuelle.');
        }
        self::assertWithinEvent($until, $eventEnd);

        return new PeriodPlan(PeriodKind::Extended, $current, DurationMode::Custom, $current->from, $until, false);
    }

    /**
     * @throws DomainRuleViolation refus motivé
     */
    public function shorten(GrantWindow $current, bool $revoked, EventStatus $eventStatus, ?\DateTimeImmutable $eventEnd, EventTimezone $timezone, \DateTimeImmutable $now, PeriodEnd $end): PeriodPlan
    {
        $now = self::second($now);
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : aucune période ne peut plus être modifiée.');
        }
        if (EventStatus::Closed === $eventStatus) {
            throw new DomainRuleViolation('L\'événement est clos : les droits sont déjà terminés.');
        }
        if (null !== $current->until && $current->until <= $now) {
            throw new DomainRuleViolation('Cette période est déjà terminée : il n\'y a rien à raccourcir.');
        }
        if (DurationMode::Custom !== $end->mode) {
            throw new DomainRuleViolation('Indiquez la date et l\'heure de la nouvelle fin.');
        }
        $requested = $this->resolve($end, $timezone, $now) ?? throw new DomainRuleViolation('Indiquez la date et l\'heure de la nouvelle fin.');
        $ends = array_filter([$current->until, $eventEnd], static fn (?\DateTimeImmutable $d): bool => null !== $d);
        $effective = [] === $ends ? null : min($ends);
        if (null !== $effective && $requested >= $effective) {
            throw new DomainRuleViolation('La nouvelle fin doit être antérieure à la fin actuelle de l\'autorisation.');
        }
        $immediate = $requested <= $now;
        if (!$immediate && $requested < $now->modify('+'.self::MINIMUM_SECONDS.' seconds')) {
            throw new DomainRuleViolation('Indiquez une fin dans plus d\'une minute, ou une date déjà passée pour une fin immédiate.');
        }
        $applied = $immediate ? $now : $requested; // jamais antidaté
        if ($applied <= $current->from) {
            throw new DomainRuleViolation('La nouvelle fin doit être postérieure au début de la période : pour retirer tous les droits, révoquez l\'autorisation.');
        }

        return new PeriodPlan(PeriodKind::Shortened, $current, DurationMode::Custom, $current->from, $applied, false, $requested, $immediate);
    }

    private function resume(GrantWindow $current, ?\DateTimeImmutable $eventEnd, EventTimezone $timezone, \DateTimeImmutable $now, PeriodEnd $end): PeriodPlan
    {
        if (null !== $eventEnd && $eventEnd <= $now) {
            throw new DomainRuleViolation('La fin prévue de l\'événement est passée : prolongez d\'abord la fin de l\'événement pour pouvoir reprendre les droits.');
        }
        $until = $this->resolve($end, $timezone, $now);
        if (null !== $until) {
            if ($until < $now->modify('+'.self::MINIMUM_SECONDS.' seconds')) {
                throw new DomainRuleViolation('La nouvelle période doit durer au moins une minute.');
            }
            self::assertWithinEvent($until, $eventEnd);
        }

        return new PeriodPlan(PeriodKind::Resumed, $current, null === $until ? DurationMode::UntilClosure : DurationMode::Custom, $now, $until, true);
    }

    /**
     * Instant UTC de la fin demandée ; `null` pour « jusqu'à la clôture ». Durée fixe : ajoutée à `$base` en temps ÉCOULÉ (un changement d'heure ne la modifie pas).
     *
     * @throws DomainRuleViolation heure inexistante ou ambiguë sans choix, date invalide (messages du résolveur)
     */
    private function resolve(PeriodEnd $end, EventTimezone $timezone, \DateTimeImmutable $base): ?\DateTimeImmutable
    {
        if (DurationMode::UntilClosure === $end->mode) {
            return null;
        }
        if (DurationMode::Custom === $end->mode) {
            return $this->resolver->resolve($end->date ?? throw new DomainRuleViolation('Indiquez la date de fin.'), $end->time ?? throw new DomainRuleViolation('Indiquez l\'heure de fin.'), $timezone, $end->occurrence)
                ->setTimezone(new \DateTimeZone('UTC'));
        }
        $base = $base->setTimezone(new \DateTimeZone('UTC'));

        return $base->setTimestamp($base->getTimestamp() + ($end->mode->fixedHours() ?? 0) * 3600);
    }

    private static function assertEventActive(EventStatus $status): void
    {
        if (EventStatus::Active !== $status) {
            throw new DomainRuleViolation(EventStatus::Closed === $status ? 'L\'événement est clos : les droits ne peuvent plus être prolongés ni repris.' : 'L\'événement n\'est pas actif (suspendu) : les droits ne peuvent être ni prolongés ni repris tant qu\'il ne l\'est pas redevenu.');
        }
    }

    private static function assertWithinEvent(\DateTimeImmutable $until, ?\DateTimeImmutable $eventEnd): void
    {
        if (null !== $eventEnd && $until > $eventEnd) {
            throw new DomainRuleViolation('La nouvelle fin ne peut pas dépasser la fin prévue de l\'événement.');
        }
    }

    private static function second(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTimestamp($utc->getTimestamp());
    }
}
