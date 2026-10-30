<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Future owner plugin boundary. No implementation, seed or ownership inference here.
 * read() must authenticate actor, verify persistent ownership and return a ready,
 * read-only, versioned DTO matching the registration characteristic/RES contract.
 * identity: source_type=permanent_pet, owner_uid, source_key, source_fingerprint.
 * Provider registration is server-only through the explicit entity-service factory.
 */
interface AF_ACS_PermanentPetSource
{
    public function read(string $sourceKey,int $actorUid): array;
    public function choices(int $actorUid,int $after): array;
}
