<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rights;

use App\Rights\Application\RectificationService;
use App\Rights\Domain\RectificationMention;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\RightsScenarioTestCase;

/**
 * Rectification d'archive par MENTION (LOT 05.6.6) : ajoutée à côté de la valeur d'origine, jamais en remplacement ; demandes et décisions historiques intactes ; événement clos
 * seulement ; propre au dossier de la commune ; supprimée avec le dossier.
 */
final class RightsRectificationTest extends RightsScenarioTestCase
{
    private function mentions(): RectificationService
    {
        return static::getContainer()->get(RectificationService::class);
    }

    /** @return array<string, mixed> */
    private function history(): array
    {
        $c = $this->em()->getConnection();

        return [
            'request' => $c->fetchAllAssociative('SELECT * FROM request_permit_request ORDER BY id'),
            'decisions' => $c->fetchAllAssociative('SELECT * FROM request_permit_request_decision ORDER BY id'),
            'parts' => $c->fetchAllAssociative('SELECT * FROM request_permit_request_municipality ORDER BY id'),
            'permits' => $c->fetchAllAssociative('SELECT * FROM permit_permit ORDER BY id'),
        ];
    }

    public function testAMentionIsAddedBesideTheOriginalValueWithoutAlteringAnyHistory(): void
    {
        $this->close();
        $id = $this->verified(RightsRequestKind::Rectification);
        $dossier = $this->dossierOf($this->organizerId);
        $before = $this->history();

        $this->mentions()->addMention($this->managed($this->admin), $this->organizerId, $id, $dossier, ['NAME', 'ADDRESS'], 'Le nom correct est « Martin-Durand ».');

        self::assertSame($before, $this->history(), 'demandes, décisions et laissez-passer historiques inchangés');
        self::assertSame(1, $this->rows('archive_rectification_mention'));
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_MENTION_ADDED'));
        $audit = (string) $this->em()->getConnection()->fetchOne('SELECT new_value FROM audit_log WHERE action = \'RIGHTS_MENTION_ADDED\'');
        self::assertStringNotContainsString('Martin-Durand', $audit, 'le texte de la mention n\'est pas dans l\'audit');

        // Affichée à côté de la valeur d'origine dans l'archive de la commune, jamais dans celle de l'autre.
        $consultation = $this->openDossier($this->admin, $this->organizerId, $dossier);
        self::assertNotNull($consultation);
        $this->client->request('GET', $this->archiveUrl($this->organizerId, '/consultations/'.$consultation));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Martin-Durand', $body);
        self::assertStringContainsString('Martin', $body);
        self::assertStringContainsString('valeurs d’origine', $body);

        $partnerConsultation = $this->openDossier($this->partnerAdmin, $this->partnerId, $this->dossierOf($this->partnerId));
        self::assertNotNull($partnerConsultation);
        $this->client->request('GET', $this->archiveUrl($this->partnerId, '/consultations/'.$partnerConsultation));
        self::assertStringNotContainsString('Martin-Durand', (string) $this->client->getResponse()->getContent(), 'une mention est propre au dossier de sa commune');
    }

    public function testAMentionNeedsAClosedEventAnAttestedRectificationRequestAndAValidText(): void
    {
        $admin = $this->managed($this->admin);
        $dossier = $this->dossierOf($this->organizerId);
        $id = $this->verified(RightsRequestKind::Rectification);
        try {
            $this->mentions()->addMention($admin, $this->organizerId, $id, $dossier, ['NAME'], 'Mention.');
            self::fail('Mention sur un événement actif.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('correction d’identité', $e->getMessage());
        }

        $this->close();
        $notAttested = $this->register(RightsRequestKind::Rectification);
        $access = $this->verified(RightsRequestKind::Access);
        foreach ([
            [$notAttested, $dossier, ['NAME'], 'Mention.'],
            [$access, $dossier, ['NAME'], 'Mention.'],
            [$id, $dossier, [], 'Mention.'],
            [$id, $dossier, ['INCONNU'], 'Mention.'],
            [$id, $dossier, ['NAME'], ''],
            [$id, $dossier, ['NAME'], str_repeat('x', RectificationMention::MAX_LENGTH + 1)],
        ] as [$request, $target, $fields, $text]) {
            try {
                $this->mentions()->addMention($admin, $this->organizerId, $request, $target, $fields, $text);
                self::fail('Mention invalide acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('archive_rectification_mention'));
    }

    public function testNobodyElseAndNoOtherDossierCanReceiveAMention(): void
    {
        $this->close();
        $id = $this->verified(RightsRequestKind::Rectification);
        $own = $this->dossierOf($this->organizerId);
        $partnerDossier = $this->dossierOf($this->partnerId);

        foreach ([[$this->manager, $this->organizerId, $own], [$this->instructor, $this->organizerId, $own], [$this->partnerAdmin, $this->partnerId, $partnerDossier], [$this->partnerAdmin, $this->organizerId, $own], [$this->superAdmin, $this->organizerId, $own], [$this->admin, $this->organizerId, $partnerDossier], [$this->admin, $this->organizerId, 'pas-un-uuid']] as [$user, $commune, $target]) {
            try {
                $this->mentions()->addMention($this->managed($user), $commune, $id, $target, ['NAME'], 'Mention.');
                self::fail('Mention par un utilisateur non habilité ou sur un dossier d\'une autre commune.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('archive_rectification_mention'));
    }

    public function testAMentionIsDeletedWithItsDossierButTheRegisterRowStays(): void
    {
        $this->dueForPurge();
        $id = $this->verified(RightsRequestKind::Rectification);
        $a = $this->dossierOf($this->organizerId);
        $this->mentions()->addMention($this->managed($this->admin), $this->organizerId, $id, $a, ['NAME'], 'Rectification.');
        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, false);
        $this->svc()->close($this->managed($this->admin), $this->organizerId, $id);
        $this->em()->clear();

        self::assertSame(1, 'PURGED' === $this->purge($a)->status ? 1 : 0);
        self::assertSame(0, $this->rows('archive_rectification_mention'), 'la mention est supprimée avec le dossier');
        self::assertSame(0, $this->rows('rights_request_dossier'));
        self::assertSame(1, $this->rows('rights_request'));
    }
}
