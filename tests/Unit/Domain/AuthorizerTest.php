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
        yield 'Requestor' => [R::Requestor, [true, true, false, false, false, false]];
        yield 'Vendor' => [R::Vendor, [false, true, true, false, false, false]];
        yield 'Business Owner' => [R::BusinessOwner, [false, false, true, false, false, false]];
        yield 'Information Security' => [R::InfoSec, [false, false, true, false, false, false]];
        // The DPO also holds the approve / accept-risk power (the Risk Approver role was retired, DECISIONS D-33).
        yield 'Privacy Team / DPO' => [R::PrivacyDpo, [false, false, true, true, false, false]];
        yield 'Reviewer' => [R::Reviewer, [false, false, true, false, false, false]];
        yield 'Administrator' => [R::Admin, [true, true, true, true, true, true]];
        yield 'Auditor' => [R::Auditor, [false, false, false, false, false, true]];
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

    public function testRequestorCannotEditSomeoneElsesRecord(): void
    {
        self::assertFalse($this->auth->can(self::user(R::Requestor), P::EditOwn, self::othersRecord()));
        self::assertFalse($this->auth->can(self::user(R::Requestor), P::View, self::othersRecord()));
    }

    public function testVendorOnlyTouchesItsOwnVendorRecord(): void
    {
        $v = self::user(R::Vendor);
        self::assertFalse($this->auth->can($v, P::EditOwn, self::othersRecord('vendor')));
        self::assertFalse($this->auth->can($v, P::EditControls, self::othersRecord('vendor')));
        self::assertFalse($this->auth->can($v, P::EditControls, self::ownRecord('processing_activity')), 'vendor role never edits DPIAs');
    }

    public function testAuditorIsReadOnly(): void
    {
        $a = self::user(R::Auditor);
        self::assertTrue($this->auth->can($a, P::View, self::othersRecord()));
        self::assertTrue($this->auth->can($a, P::ViewDashboard));
        foreach ([P::Create, P::EditOwn, P::EditControls, P::Approve, P::AdminConfig, P::ConfirmNegligible, P::VerifyEvidence, P::SaveTemplate, P::ManageRemediation, P::Submit] as $p) {
            self::assertFalse($this->auth->can($a, $p, self::othersRecord()), $p->value);
        }
        self::assertFalse($this->auth->can($a, P::Decide, ['required_roles' => [R::Reviewer]]));
    }

    public function testDerivedPermissions(): void
    {
        self::assertTrue($this->auth->can(self::user(R::Reviewer), P::ConfirmNegligible));
        self::assertTrue($this->auth->can(self::user(R::PrivacyDpo), P::ConfirmNegligible));
        self::assertFalse($this->auth->can(self::user(R::BusinessOwner), P::ConfirmNegligible));

        self::assertTrue($this->auth->can(self::user(R::Reviewer), P::VerifyEvidence));
        self::assertFalse($this->auth->can(self::user(R::InfoSec), P::VerifyEvidence));

        self::assertTrue($this->auth->can(self::user(R::PrivacyDpo), P::SaveTemplate));
        self::assertFalse($this->auth->can(self::user(R::Requestor), P::SaveTemplate));

        foreach ([R::PrivacyDpo, R::Auditor] as $r) {
            self::assertTrue($this->auth->can(self::user($r), P::ViewDashboard), $r->value);
        }
        foreach ([R::Requestor, R::Vendor, R::Reviewer] as $r) {
            self::assertFalse($this->auth->can(self::user($r), P::ViewDashboard), $r->value);
        }
    }

    public function testDecideFollowsApprovalRouting(): void
    {
        $high = ['required_roles' => [R::PrivacyDpo]];
        self::assertTrue($this->auth->can(self::user(R::PrivacyDpo), P::Decide, $high));
        self::assertFalse($this->auth->can(self::user(R::Reviewer), P::Decide, $high));
        self::assertTrue($this->auth->can(self::user(R::Reviewer), P::Decide, ['required_roles' => [R::Reviewer]]));
    }

    public function testDepartmentScopedBusinessOwner(): void
    {
        $bo = new PortalUser('bo', 'BO', null, [R::BusinessOwner], ['business_owner' => 2]);
        self::assertFalse($this->auth->can($bo, P::EditControls, self::othersRecord()));
        self::assertTrue($this->auth->can($bo, P::EditControls, ['department_id' => 2] + self::othersRecord()));
    }

    public function testUserWithNoRoleCanDoNothing(): void
    {
        $u = new PortalUser('x', 'X');
        foreach (P::cases() as $p) {
            self::assertFalse($this->auth->can($u, $p, self::othersRecord()), $p->value);
        }
    }
}
