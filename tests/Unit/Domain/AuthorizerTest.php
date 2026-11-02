<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Role as R;
use App\Services\Authorizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD 15: "test each role against the matrix in section 5". */
final class AuthorizerTest extends TestCase
{
    private Authorizer $auth;

    protected function setUp(): void
    {
        $this->auth = new Authorizer();
    }

    private static function user(R $role, string $id = 'u1'): PortalUser
    {
        return new PortalUser($id, 'Test', null, [$role]);
    }

    /** A record owned by u1 (and, for vendors, whose vendor contact is u1). */
    private static function ownRecord(string $type = 'processing_activity'): array
    {
        return ['owner_portal_user_id' => 'u1', 'entity_type' => $type, 'department_id' => 1, 'vendor_portal_user_id' => $type === 'vendor' ? 'u1' : null];
    }

    private static function othersRecord(string $type = 'processing_activity'): array
    {
        return ['owner_portal_user_id' => 'someone-else', 'entity_type' => $type, 'department_id' => 1, 'vendor_portal_user_id' => null];
    }

    /**
     * Matrix rows: [create, edit own record, edit controls/evidence, approve/accept risk, admin config, read all].
     *
     * @return iterable<string,array{R,array{bool,bool,bool,bool,bool,bool}}>
     */
    public static function matrix(): iterable
    {
        yield 'DPO / Administrator' => [R::Admin, [true, true, true, true, true, true]];
        yield 'Reviewer' => [R::Reviewer, [false, false, true, false, false, false]];
        yield 'Business Owner' => [R::BusinessOwner, [true, true, true, false, false, false]];
        yield 'Vendor' => [R::Vendor, [false, true, true, false, false, false]];
    }

    /** @param array{bool,bool,bool,bool,bool,bool} $expected */
    #[DataProvider('matrix')]
    public function testRoleAgainstMatrix(R $role, array $expected): void
    {
        $u = self::user($role);
        // Vendor's "own record" is a vendor record; everyone else's is a processing activity they own.
        $own = $role === R::Vendor ? self::ownRecord('vendor') : self::ownRecord();

        self::assertSame($expected[0], $this->auth->can($u, P::Create), 'create');
        self::assertSame($expected[1], $this->auth->can($u, P::EditOwn, $own), 'edit own record');
        self::assertSame($expected[2], $this->auth->can($u, P::EditControls, $own), 'edit controls/evidence');
        self::assertSame($expected[3], $this->auth->can($u, P::Approve), 'approve/accept risk');
        self::assertSame($expected[4], $this->auth->can($u, P::AdminConfig), 'admin config');
        self::assertSame($expected[5], $this->auth->can($u, P::ReadAll), 'read all');
    }

    public function testBusinessOwnerCannotTouchSomeoneElsesRecord(): void
    {
        $u = self::user(R::BusinessOwner);
        foreach ([P::EditOwn, P::EditControls, P::View, P::ManageRemediation, P::Submit] as $p) {
            self::assertFalse($this->auth->can($u, $p, self::othersRecord()), $p->value);
        }
    }

    public function testVendorOnlyTouchesItsOwnVendorRecord(): void
    {
        $v = self::user(R::Vendor);
        self::assertFalse($this->auth->can($v, P::EditOwn, self::othersRecord('vendor')));
        self::assertFalse($this->auth->can($v, P::EditControls, self::othersRecord('vendor')));
        self::assertFalse($this->auth->can($v, P::View, self::othersRecord()));
        self::assertFalse($this->auth->can($v, P::EditControls, self::ownRecord('processing_activity')), 'vendor role never edits DPIAs');
    }

    public function testReviewerChecksButDoesNotOwnOrConfigure(): void
    {
        $r = self::user(R::Reviewer);
        foreach ([P::View, P::EditControls, P::ManageRemediation] as $p) {
            self::assertTrue($this->auth->can($r, $p, self::othersRecord()), $p->value);
        }
        foreach ([P::ViewDashboard, P::ConfirmNegligible, P::VerifyEvidence] as $p) {
            self::assertTrue($this->auth->can($r, $p), $p->value);
        }
        foreach ([P::Create, P::Approve, P::AdminConfig, P::ReadAll, P::SaveTemplate] as $p) {
            self::assertFalse($this->auth->can($r, $p), $p->value);
        }
        self::assertFalse($this->auth->can($r, P::EditOwn, self::othersRecord()));
        self::assertFalse($this->auth->can($r, P::Submit, self::othersRecord()));
    }

    public function testDpoAdministratorCanDoEverything(): void
    {
        $d = self::user(R::Admin);
        foreach (P::cases() as $p) {
            self::assertTrue($this->auth->can($d, $p, self::othersRecord()), $p->value);
        }
    }

    public function testOnlyTheDpoAndReviewerSeeTheWholeUniversity(): void
    {
        foreach ([R::Admin, R::Reviewer] as $r) {
            self::assertTrue($this->auth->can(self::user($r), P::ViewDashboard), $r->value);
            self::assertFalse($this->auth->listScope(self::user($r))['owner_only'], $r->value);
        }
        foreach ([R::BusinessOwner, R::Vendor] as $r) {
            self::assertFalse($this->auth->can(self::user($r), P::ViewDashboard), $r->value);
            self::assertTrue($this->auth->listScope(self::user($r))['owner_only'], $r->value);
        }
    }

    public function testDecideFollowsApprovalRouting(): void
    {
        $high = ['required_roles' => [R::Admin]];
        self::assertTrue($this->auth->can(self::user(R::Admin), P::Decide, $high));
        self::assertFalse($this->auth->can(self::user(R::Reviewer), P::Decide, $high));
        self::assertFalse($this->auth->can(self::user(R::BusinessOwner), P::Decide, $high));
        self::assertTrue($this->auth->can(self::user(R::Reviewer), P::Decide, ['required_roles' => [R::Reviewer]]));
    }

    public function testDepartmentScopedBusinessOwner(): void
    {
        $bo = new PortalUser('bo', 'BO', null, [R::BusinessOwner], ['business_owner' => 2]);
        self::assertFalse($this->auth->can($bo, P::EditControls, self::othersRecord()));
        self::assertTrue($this->auth->can($bo, P::EditControls, ['department_id' => 2] + self::othersRecord()));
        self::assertSame([2], $this->auth->listScope($bo)['or_department_ids']);
    }

    public function testBeingAssignedAFixGivesAccessToThatRecordOnly(): void
    {
        $u = self::user(R::BusinessOwner);
        $assigned = ['action_owner_ids' => 'someone,u1'] + self::othersRecord();
        self::assertTrue($this->auth->can($u, P::View, $assigned));
        self::assertTrue($this->auth->can($u, P::EditControls, $assigned));
        foreach ([P::EditOwn, P::Submit, P::ManageRemediation] as $p) {
            self::assertFalse($this->auth->can($u, $p, $assigned), $p->value);
        }
        self::assertFalse($this->auth->can($u, P::View, ['action_owner_ids' => 'u11'] + self::othersRecord()), 'exact id match');
    }

    public function testUserWithNoRoleCanDoNothing(): void
    {
        $u = new PortalUser('x', 'X');
        foreach (P::cases() as $p) {
            self::assertFalse($this->auth->can($u, $p, self::othersRecord()), $p->value);
        }
    }
}
