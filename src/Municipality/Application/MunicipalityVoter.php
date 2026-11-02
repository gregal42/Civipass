<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Municipality>
 */
final class MunicipalityVoter extends Voter
{
    public const MANAGE_MEMBERS = 'MUNICIPALITY_MANAGE_MEMBERS';
    public const EDIT_CONTACT = 'MUNICIPALITY_EDIT_CONTACT';
    public const READ_AUDIT = 'MUNICIPALITY_READ_AUDIT';

    public function __construct(private readonly MembershipAccessPolicy $policy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Municipality
            && \in_array($attribute, [self::MANAGE_MEMBERS, self::EDIT_CONTACT, self::READ_AUDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE_MEMBERS => $this->policy->canManageMembers($user, $subject),
            self::EDIT_CONTACT => $this->policy->canEditContact($user, $subject),
            self::READ_AUDIT => $this->policy->canReadAudit($user, $subject),
            default => false,
        };
    }
}
