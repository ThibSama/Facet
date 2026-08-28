<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Auth;

use Facet\Account\Account;
use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Auth\AccessDecision;
use Facet\Auth\AccessPolicy;
use Facet\Routing\RouteCatalog;
use Facet\Routing\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may reach what, asserted as a complete truth table.
 *
 * The policy is a pure function of a visibility and a principal, which is why
 * this can be exhaustive rather than a sample: every case of the enum, against
 * anonymous, admin, client and disabled. A rule that only holds for the
 * combinations somebody thought to try is not a rule.
 */
final class AccessPolicyTest extends TestCase
{
    private static function admin(): Account
    {
        return new Account(1, 'ada@example.com', Role::Admin, AccountStatus::Active);
    }

    private static function client(): Account
    {
        return new Account(2, 'grace@example.com', Role::Client, AccountStatus::Active);
    }

    private static function disabledAdmin(): Account
    {
        return new Account(3, 'alan@example.com', Role::Admin, AccountStatus::Disabled);
    }

    /**
     * @return array<string, array{Visibility, string, AccessDecision}>
     */
    public static function matrix(): array
    {
        $cases = [];

        $principals = ['anonymous', 'admin', 'client', 'disabled admin'];

        $expected = [
            // visibility            anonymous                        admin                            client                           disabled
            'public' => [Visibility::Public, [
                AccessDecision::Allow, AccessDecision::Allow, AccessDecision::Allow, AccessDecision::Allow,
            ]],
            'guest' => [Visibility::Guest, [
                AccessDecision::Allow,
                AccessDecision::AlreadyAuthenticated,
                AccessDecision::AlreadyAuthenticated,
                AccessDecision::Allow,
            ]],
            'authenticated' => [Visibility::Authenticated, [
                AccessDecision::Authenticate,
                AccessDecision::Allow,
                AccessDecision::Allow,
                AccessDecision::Authenticate,
            ]],
            'admin' => [Visibility::Admin, [
                AccessDecision::Authenticate,
                AccessDecision::Allow,
                AccessDecision::Forbid,
                AccessDecision::Authenticate,
            ]],
            'client' => [Visibility::Client, [
                AccessDecision::Authenticate,
                AccessDecision::Forbid,
                AccessDecision::Allow,
                AccessDecision::Authenticate,
            ]],
        ];

        foreach ($expected as $label => [$visibility, $decisions]) {
            foreach ($principals as $index => $principal) {
                $cases[$label . ' / ' . $principal] = [$visibility, $principal, $decisions[$index]];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function testTheDecisionTableIsExhaustive(
        Visibility $visibility,
        string $principal,
        AccessDecision $expected
    ): void {
        $account = match ($principal) {
            'admin' => self::admin(),
            'client' => self::client(),
            'disabled admin' => self::disabledAdmin(),
            default => null,
        };

        self::assertSame($expected, AccessPolicy::decide($visibility, $account));
    }

    /**
     * Every case of the enum appears in the table above. A visibility added
     * later without a row here would be a hole in the guard that no other test
     * would notice.
     */
    public function testEveryVisibilityIsCovered(): void
    {
        $covered = [];

        foreach (self::matrix() as [$visibility]) {
            $covered[$visibility->value] = true;
        }

        foreach (Visibility::cases() as $case) {
            self::assertArrayHasKey($case->value, $covered, $case->value . ' has no row in the decision table');
        }
    }

    /**
     * There is no hierarchy, and this is the assertion that says so. An admin
     * is refused the client area exactly as a client is refused the admin area.
     */
    public function testPrivilegeIsNotAHierarchy(): void
    {
        self::assertSame(AccessDecision::Forbid, AccessPolicy::decide(Visibility::Client, self::admin()));
        self::assertSame(AccessDecision::Forbid, AccessPolicy::decide(Visibility::Admin, self::client()));
    }

    /**
     * A disabled account is never a principal, whatever hands it to the policy.
     * The authenticator refuses to return one; asserting it here as well keeps
     * the policy correct in isolation.
     */
    public function testADisabledAccountIsTreatedAsAnonymousEverywhere(): void
    {
        foreach (Visibility::cases() as $visibility) {
            self::assertSame(
                AccessPolicy::decide($visibility, null),
                AccessPolicy::decide($visibility, self::disabledAdmin()),
                $visibility->value
            );
        }
    }

    /**
     * Where each role's own area is, taken from the catalog rather than written
     * down again.
     */
    public function testEachRoleHasItsOwnAreaAndTheyAreDistinct(): void
    {
        self::assertSame(RouteCatalog::ADMIN_DASHBOARD, AccessPolicy::homeRouteFor(Role::Admin));
        self::assertSame(RouteCatalog::CLIENT_AREA, AccessPolicy::homeRouteFor(Role::Client));

        self::assertSame('/admin', AccessPolicy::homePathFor(Role::Admin));
        self::assertSame('/client', AccessPolicy::homePathFor(Role::Client));

        self::assertNotSame(AccessPolicy::homePathFor(Role::Admin), AccessPolicy::homePathFor(Role::Client));

        // And each is the area that role is actually permitted to reach.
        foreach (Role::cases() as $role) {
            $route = RouteCatalog::get(AccessPolicy::homeRouteFor($role));
            $account = new Account(9, 'x@example.com', $role, AccountStatus::Active);

            self::assertSame(AccessDecision::Allow, AccessPolicy::decide($route->visibility(), $account));
        }
    }
}
