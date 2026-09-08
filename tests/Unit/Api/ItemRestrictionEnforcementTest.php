<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Wiring guards for the item-level restriction on the REST API.
 *
 * GHSA-gxc6-rgv6-wx99: the web read path denied a user excluded through items.restricted_to /
 * restriction_to_roles, while the API authorized on folder membership and the presence of a
 * sharekey only — and restricting an item never revokes the sharekey the excluded user already
 * holds. Every API path that reads or mutates an item must consult the restriction.
 *
 * The decision itself is asserted behaviourally in ItemRestrictionLogicTest; these tests lock
 * the wiring so a future endpoint cannot silently skip it.
 */
class ItemRestrictionEnforcementTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = __DIR__ . '/../../../' . $relativePath;
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        return str_replace("\r\n", "\n", $source);
    }

    private function itemModelSource(): string
    {
        return $this->source('app/api/Model/ItemModel.php');
    }

    private function itemControllerSource(): string
    {
        return $this->source('app/api/Controller/Api/ItemController.php');
    }

    private function folderAccessModelSource(): string
    {
        return $this->source('app/api/Model/FolderAccessModel.php');
    }

    /**
     * Body of a single method, so a guard cannot be satisfied by a match elsewhere in the file.
     *
     * @param string $source     File source
     * @param string $signature  Start of the method signature
     * @return string Method body up to the closing brace at method indentation
     */
    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        self::assertIsInt($start, 'Method not found: ' . $signature);

        $body = substr($source, $start);
        $end = strpos($body, "\n    }\n");
        self::assertIsInt($end, 'Method end not found: ' . $signature);

        return substr($body, 0, $end);
    }

    public function testFolderAccessModelExposesBothRestrictionForms(): void
    {
        $source = $this->folderAccessModelSource();

        self::assertStringContainsString(
            'public function getItemRestrictionSqlConstraint(string $itemAlias, int $userId): string',
            $source
        );
        self::assertStringContainsString(
            'public function satisfiesItemRestriction(int $itemId, int $userId): bool',
            $source
        );

        // Both must delegate to the canonical DB-free decision, never re-implement it.
        self::assertStringContainsString('itemRestrictionSqlPredicate(', $source);
        self::assertStringContainsString('itemRestrictionAllows(', $source);

        // Roles are read live, not taken from the frozen JWT claim: revoking a role must take
        // effect immediately, not at token expiry.
        self::assertStringContainsString('securityPostureUserRoleIds($userId)', $source);
        self::assertStringNotContainsString("\$userData['roles']", $source);
    }

    public function testSqlConstraintFailsClosedOnAnUnknownUser(): void
    {
        $body = $this->methodBody(
            $this->folderAccessModelSource(),
            'public function getItemRestrictionSqlConstraint('
        );

        self::assertStringContainsString('return \' AND 1 = 0\';', $body);
    }

    public function testRowCheckFailsClosedOnAMissingItem(): void
    {
        $body = $this->methodBody(
            $this->folderAccessModelSource(),
            'public function satisfiesItemRestriction('
        );

        self::assertStringContainsString('if ($itemId <= 0 || $userId <= 0) {', $body);
        self::assertStringContainsString('return false;', $body);
        self::assertStringContainsString("prefixTable('restriction_to_roles')", $body);
    }

    public function testGetItemsAppliesTheRestrictionAtTheReadChokePoint(): void
    {
        $body = $this->methodBody($this->itemModelSource(), 'public function getItems(');

        // item/get, item/inFolders and item/changes all materialize their payload here, so the
        // predicate belongs to this method rather than to each caller.
        self::assertStringContainsString(
            "\$folderAccessModel->getItemRestrictionSqlConstraint('i', \$userId)",
            $body
        );
        self::assertStringContainsString('$sqlExtra . $itemRestrictionSql', $body);
    }

    public function testCountItemsCanApplyTheSameRestriction(): void
    {
        $body = $this->methodBody($this->itemModelSource(), 'public function countItems(');

        // X-Total-Count must not advertise items getItems() will filter out.
        self::assertStringContainsString('?int $userId = null', $body);
        self::assertStringContainsString('getItemRestrictionSqlConstraint(', $body);
    }

    public function testSearchTotalsPassTheCallerToCountItems(): void
    {
        $source = $this->itemControllerSource();

        self::assertStringContainsString(
            "countItems(\$sqlExtra, \$sqlParams, (int) \$userData['id'])",
            $source
        );
        self::assertStringContainsString(
            "countItems(\$sqlExtra, [], (int) \$userData['id'])",
            $source
        );
    }

    public function testFindByUrlAppliesTheRestrictionToItsOwnQuery(): void
    {
        $body = $this->methodBody($this->itemControllerSource(), 'public function findByUrlAction(');

        // This endpoint builds its own SELECT and never reaches getItems().
        self::assertStringContainsString(
            "getItemRestrictionSqlConstraint('i', (int) \$userData['id'])",
            $body
        );
    }

    public function testChangesFeedTreatsARestrictedItemAsOutOfScope(): void
    {
        $body = $this->methodBody($this->itemControllerSource(), 'public function changesAction(');

        // The predicate has to reach the visibility clause, not only the payload: otherwise the
        // item stays "visible but undeliverable" and the delta cursor never advances past it.
        self::assertStringContainsString('$itemVisibilitySql .= $folderAccessModel', $body);
        self::assertStringContainsString("getItemRestrictionSqlConstraint('i'", $body);
    }

    public function testUpdateDeniesARestrictedCallerBeforeAnyWrite(): void
    {
        $body = $this->methodBody($this->itemControllerSource(), 'public function updateAction(');

        $folderCheck = strpos($body, 'canAccessItemInFolder(');
        $restrictionCheck = strpos($body, 'satisfiesItemRestriction(');
        $update = strpos($body, '->updateItem(');

        self::assertIsInt($folderCheck);
        self::assertIsInt($restrictionCheck);
        self::assertIsInt($update);
        self::assertLessThan($restrictionCheck, $folderCheck);
        self::assertLessThan(
            $update,
            $restrictionCheck,
            'The restriction must be evaluated before the item is written'
        );
    }

    public function testDeleteDeniesARestrictedCallerBeforeReservingTheIdempotencyKey(): void
    {
        $body = $this->methodBody($this->itemControllerSource(), 'public function deleteAction(');

        $restrictionCheck = strpos($body, 'satisfiesItemRestriction(');
        $reservation = strpos($body, '$idempotencyModel->reserve(');

        self::assertIsInt($restrictionCheck);
        self::assertIsInt($reservation);
        self::assertLessThan(
            $reservation,
            $restrictionCheck,
            'A refused delete must not consume the caller Idempotency-Key'
        );
    }

    public function testDeleteModelRepeatsTheRestrictionUnderTheRowLock(): void
    {
        $body = $this->methodBody($this->itemModelSource(), 'public function deleteItem(');

        // The controller decides before the lock; a concurrent restriction change between the
        // two must not slip through, exactly like the folder re-check next to it.
        $lock = strpos($body, 'FOR UPDATE');
        $restrictionCheck = strpos($body, 'satisfiesItemRestriction(');
        $softDelete = strpos($body, "'inactif' => '1'");

        self::assertIsInt($lock);
        self::assertIsInt($restrictionCheck);
        self::assertIsInt($softDelete);
        self::assertLessThan($restrictionCheck, $lock);
        self::assertLessThan($softDelete, $restrictionCheck);
    }

    public function testGetOtpDeniesARestrictedCallerAfterTheFolderCheck(): void
    {
        $body = $this->methodBody($this->itemControllerSource(), 'public function getOtpAction(');

        $folderCheck = strpos($body, 'canAccessItemInFolder(');
        $restrictionCheck = strpos($body, 'satisfiesItemRestriction(');
        $secretDecryption = strpos($body, "\$otpData['secret']");

        self::assertIsInt($folderCheck);
        self::assertIsInt($restrictionCheck);
        self::assertIsInt($secretDecryption);
        self::assertLessThan($restrictionCheck, $folderCheck);
        self::assertLessThan(
            $secretDecryption,
            $restrictionCheck,
            'The restriction must be evaluated before the TOTP secret is decrypted'
        );
        self::assertStringContainsString("HTTP/1.1 403 Forbidden", $body);
    }
}
