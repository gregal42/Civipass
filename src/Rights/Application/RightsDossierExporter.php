<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Domain\PermitRequestMunicipality;
use App\Rights\Domain\RectificationField;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contenu de l'export d'accès d'UN dossier communal (LOT 05.6.6) : données COMMUNES à la demande (une seule copie, visibles de la commune) et données PROPRES à la commune ;
 * les autres communes n'apparaissent que par un décompte NEUTRE (règle du §4.2 du lot 05.6). Exclus : jetons, secrets, liens, audit nominatif, contenu propre d'une autre commune.
 */
final class RightsDossierExporter
{
    /** Catégories de destinataires (fonctions, jamais des noms d'agents). */
    private const RECIPIENT_CATEGORIES = ['Agents instructeurs de la commune', 'Administrateurs de la commune', 'Organisateurs désignés (archives)', 'Contrôleurs, par lecture du QR (identité et plaques)'];

    public function __construct(
        private readonly PermitRepository $permits,
        private readonly PermitRevisionRepository $revisions,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PermitRequestMunicipality $part): array
    {
        $request = $part->request();
        $own = $part->municipality()->id();
        $decision = $part->decision();
        $permit = $this->permits->findByRequest($request->id());
        $authorization = null;
        foreach (null === $permit ? [] : $permit->authorizations() as $candidate) {
            if ($candidate->municipality()->id()->equals($own)) {
                $authorization = $candidate;
            }
        }
        $history = [];
        $others = 0;
        foreach (null === $permit ? [] : $this->revisions->forPermit($permit->id()) as $revision) {
            if ($revision->municipality()->id()->equals($own)) {
                $history[] = ['type' => $revision->type()->value, 'date' => $revision->occurredAt()->format(\DATE_ATOM)];
            } else {
                ++$others;
            }
        }
        $notifications = [];
        if (null !== $permit) {
            foreach ($this->connection->fetchAllAssociative(
                'SELECT o.type, o.status, o.occurred_at FROM notification_outbox o JOIN permit_authorization a ON a.id = o.authorization_id WHERE a.municipality_id = :m AND o.permit_id = :p ORDER BY o.occurred_at, o.id',
                ['m' => $own->toBinary(), 'p' => $permit->id()->toBinary()],
            ) as $n) {
                $notifications[] = ['type' => $n['type'], 'etat' => $n['status'], 'date' => (new \DateTimeImmutable((string) $n['occurred_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM)];
            }
        }
        $concerned = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality WHERE request_id = :r', ['r' => $request->id()->toBinary()]);
        $mentions = [];
        foreach ($this->connection->fetchAllAssociative('SELECT fields, mention, created_at FROM archive_rectification_mention WHERE request_municipality_id = :d ORDER BY created_at, id', ['d' => $part->id()->toBinary()]) as $m) {
            $mentions[] = [
                'champs' => array_map(static fn (string $c): string => RectificationField::tryFrom($c)?->label() ?? $c, explode(',', (string) $m['fields'])),
                'mention' => $m['mention'],
                'date' => (new \DateTimeImmutable((string) $m['created_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ];
        }

        return [
            'reference' => $request->reference()->value,
            'evenement' => $request->event()->name(),
            'depose_le' => $request->submittedAt()->format(\DATE_ATOM),
            'identite' => ['nom' => $request->lastName(), 'prenom' => $request->firstName(), 'adresse' => $request->address(), 'email' => $request->email(), 'telephone' => $request->phone()],
            'demande' => ['categorie' => $request->categoryLabel(), 'motif' => $request->motifLabel(), 'deplacement' => $request->travelMode()->value],
            'plaques' => array_map(static fn ($v): string => $v->registration(), $request->vehicles()),
            'justificatifs' => array_map(static fn ($a): array => ['nom' => $a->originalName(), 'taille' => $a->sizeBytes(), 'type' => $a->mimeType()], $request->attachments()),
            'dossier_de_votre_commune' => [
                'statut' => $part->status()->value,
                'decision' => null === $decision ? null : ['date' => $decision->decidedAt()->format(\DATE_ATOM), 'resultat' => $decision->outcome()->value, 'motif_refus' => $decision->refusalReasonLabel(), 'commentaire' => $decision->refusalComment(), 'valide_du' => $decision->validFrom()?->format(\DATE_ATOM), 'valide_jusqu_au' => $decision->validUntil()?->format(\DATE_ATOM)],
                'zones' => array_map(static fn ($z): string => $z->zoneLabel(), $part->zones()),
                'laissez_passer' => $permit?->reference()->value,
                'autorisation_revoquee' => $authorization?->isRevoked(),
                'historique_des_droits' => $history,
                'notifications' => $notifications,
            ],
            'autres_communes' => ['communes_concernees_en_plus_de_la_votre' => max(0, $concerned - 1), 'modifications_de_droits_par_d_autres_communes' => $others, 'note' => 'Le contenu propre aux autres communes n’est pas communiqué ; adressez-vous à chacune.'],
            'mentions_de_rectification' => $mentions,
            'categories_de_destinataires' => self::RECIPIENT_CATEGORIES,
        ];
    }

    public function find(string $dossierId): ?PermitRequestMunicipality
    {
        return $this->entityManager->find(PermitRequestMunicipality::class, \Symfony\Component\Uid\Uuid::fromString($dossierId));
    }
}
