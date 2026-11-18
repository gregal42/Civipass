<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalRequestReadModel;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\TravelMode;
use App\Tests\Support\ControlTestCase;
use Symfony\Component\Uid\Uuid;

/** LOT 08.4 : détection INFORMATIVE de doublons : critères, périmètre de la commune, jamais de refus ni d'exposition au demandeur. */
final class RequestDuplicatesTest extends ControlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
    }

    /** @param list<string> $zones 'org' et/ou 'partner' */
    private function submit(string $last, string $first, string $email, string $phone, string $plate, array $zones = ['org']): string
    {
        $ids = array_map(fn (string $z): string => ('org' === $z ? $this->organizerZone : $this->partnerZone)->id()->toRfc4122(), $zones);
        $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), $last, $first, '12 rue des Lilas', $email, $phone, 'private_person', 'home', 'Motif', TravelMode::Vehicle, [$plate], $ids, true),
            sprintf('dup-%016d', random_int(1, 9_999_999)),
        )->requestId;
        $this->em()->clear();

        return $id;
    }

    /** @return array<string, list<\App\Request\Application\Read\DuplicateMatch>> */
    private function duplicates(string $municipality, string ...$requestIds): array
    {
        return static::getContainer()->get(MunicipalRequestReadModel::class)->duplicatesOf(Uuid::fromString($municipality), array_map(static fn (string $id): Uuid => Uuid::fromString($id), $requestIds));
    }

    public function testEachCriterionIsDetectedAndReported(): void
    {
        $a = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA');
        $sameEmail = $this->submit('DIVERS', 'Un', 'a@exemple.fr', '0622222222', 'BB-222-BB');
        $samePhone = $this->submit('AUTRE', 'Deux', 'b@exemple.fr', '0611111111', 'CC-333-CC');
        $sameName = $this->submit('MARTIN', 'Camille', 'c@exemple.fr', '0633333333', 'DD-444-DD');
        $samePlate = $this->submit('TROIS', 'Trois', 'd@exemple.fr', '0644444444', 'AA-111-AA');
        $unrelated = $this->submit('SEUL', 'Seul', 'e@exemple.fr', '0655555555', 'EE-555-EE');

        $found = $this->duplicates($this->org->id()->toRfc4122(), $a, $unrelated)[Uuid::fromString($a)->toRfc4122()] ?? [];
        $byId = [];
        foreach ($found as $match) {
            $byId[$match->requestId] = $match->criteria;
        }
        self::assertSame(['email'], $byId[Uuid::fromString($sameEmail)->toRfc4122()]);
        self::assertSame(['phone'], $byId[Uuid::fromString($samePhone)->toRfc4122()]);
        self::assertSame(['name'], $byId[Uuid::fromString($sameName)->toRfc4122()]);
        self::assertSame(['plate'], $byId[Uuid::fromString($samePlate)->toRfc4122()]);
        self::assertCount(4, $found);
        self::assertSame([], $this->duplicates($this->org->id()->toRfc4122(), $unrelated), 'une demande sans point commun n\'a aucun doublon');
    }

    public function testOnlyRequestsVisibleToTheCommuneCount(): void
    {
        $forOrg = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA', ['org']);
        $forPartnerOnly = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA', ['partner']);

        self::assertSame([], $this->duplicates($this->org->id()->toRfc4122(), $forOrg), 'la demande de la seule commune partenaire n\'est pas visible pour l\'organisatrice');
        self::assertSame([], $this->duplicates($this->partner->id()->toRfc4122(), $forPartnerOnly), 'et réciproquement');

        $both = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA', ['org', 'partner']);
        $seenByOrg = $this->duplicates($this->org->id()->toRfc4122(), $both)[Uuid::fromString($both)->toRfc4122()] ?? [];
        self::assertSame([Uuid::fromString($forOrg)->toRfc4122()], array_map(static fn ($m): string => $m->requestId, $seenByOrg), 'l\'organisatrice ne voit que sa propre demande');
        $seenByPartner = $this->duplicates($this->partner->id()->toRfc4122(), $both)[Uuid::fromString($both)->toRfc4122()] ?? [];
        self::assertSame([Uuid::fromString($forPartnerOnly)->toRfc4122()], array_map(static fn ($m): string => $m->requestId, $seenByPartner), 'la partenaire ne voit que la sienne');
    }

    public function testInvitedParticipationDoesNotMakeARequestVisible(): void
    {
        $a = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA', ['org']);
        $b = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA', ['org', 'partner']);
        $this->sql("UPDATE event_participation SET status = 'INVITED' WHERE municipality_id = :m", ['m' => $this->org->id()->toBinary()]);
        self::assertSame([], $this->duplicates($this->org->id()->toRfc4122(), $a), 'participation seulement invitée : aucun dossier visible');
        self::assertNotSame('', $b);
    }

    public function testListAndDetailShowAnInformativeIndicationButNeverBlock(): void
    {
        $a = $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA');
        $b = $this->submit('MARTIN', 'Camille', 'z@exemple.fr', '0699999999', 'ZZ-999-ZZ');
        $c = $this->submit('SEUL', 'Seul', 'e@exemple.fr', '0655555555', 'EE-555-EE');
        $this->client->loginUser($this->user($this->instructorO));
        $mid = $this->org->id()->toRfc4122();

        $this->client->request('GET', '/communes/'.$mid.'/demandes');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(2, substr_count($html, 'doublon possible (1)'), 'deux demandes MARTIN se signalent mutuellement, pas la troisième');

        $this->client->request('GET', '/communes/'.$mid.'/demandes/'.$a);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#duplicates', 'Doublon possible');
        self::assertSelectorTextContains('#duplicates', 'même nom et prénom');
        self::assertSelectorTextContains('#duplicates', 'aucune décision n’est prise automatiquement');

        $this->client->request('GET', '/communes/'.$mid.'/demandes/'.$c);
        self::assertSelectorNotExists('#duplicates');

        // Informatif : l'instructeur peut décider normalement une demande signalée.
        $this->client->request('GET', '/communes/'.$mid.'/demandes/'.$b.'/approuver');
        self::assertResponseIsSuccessful();
    }

    public function testPublicFlowNeverRevealsAnotherRequest(): void
    {
        $this->submit('MARTIN', 'Camille', 'a@exemple.fr', '0611111111', 'AA-111-AA');
        // Dépôt d'un second dossier identique par le parcours public : la confirmation ne mentionne jamais l'existence d'un autre dossier.
        $result = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'MARTIN', 'Camille', '12 rue des Lilas', 'a@exemple.fr', '0611111111', 'private_person', 'home', 'Motif', TravelMode::Vehicle, ['AA-111-AA'], [$this->organizerZone->id()->toRfc4122()], true),
            'dup-public-0000000000000001',
        );
        self::assertNotSame('', $result->requestId);
        self::assertStringNotContainsStringIgnoringCase('doublon', json_encode($result, \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM request_permit_request'), 'jamais de refus automatique');
    }
}
