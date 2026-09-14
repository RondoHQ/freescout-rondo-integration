<?php

namespace App {
    class Email {
        public static function sanitizeEmail($email) { return $email; }
    }
    class User {
        public $id = 7;
        public $active = true;
        public $admin = false;
        public $mailboxActive = true;
        public static $candidate;
        public static function whereRaw($sql, $params) { return new \BindingTestQuery('users'); }
        public static function lockForUpdate() { return new \BindingTestQuery('users'); }
        public function isDeleted() { return false; }
        public function isActive() { return $this->active; }
        public function isAdmin() { return $this->admin; }
        public function mailboxes() { return new \BindingTestQuery('mailboxes'); }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static $rows = [];
        public static function table($table) { return new \BindingTestQuery($table); }
        public static function transaction($callback, $attempts = 1) { return $callback(); }
    }
}
namespace {
    use App\User;
    use Illuminate\Support\Facades\DB;
    use Modules\RondoIntegration\Services\BindingService;
    use Modules\RondoIntegration\Services\MailboxAccessService;
    use Modules\RondoIntegration\Services\SettingsService;
    use PHPUnit\Framework\TestCase;

    class BindingTestQuery {
        private $table;
        private $filters = [];
        public function __construct($table) { $this->table = $table; }
        public function where($key, $value) { $this->filters[$key] = $value; return $this; }
        public function lockForUpdate() { return $this; }
        public function find($id) { return User::$candidate; }
        public function get() {
            if ($this->table === 'users') {
                return new BindingTestCollection(User::$candidate ? [User::$candidate] : []);
            }
            if ($this->table === 'mailboxes') {
                $mailbox = new class {
                    public function isActive() { return User::$candidate->mailboxActive; }
                };
                return [$mailbox];
            }
            return new BindingTestCollection(array_values(array_filter(DB::$rows[$this->table] ?? [], function ($row) {
                foreach ($this->filters as $key => $value) {
                    if (($row->$key ?? null) !== $value) { return false; }
                }
                return true;
            })));
        }
        public function first() { return $this->get()->first(); }
        public function exists() { return $this->get()->count() > 0; }
        public function insert($row) { DB::$rows[$this->table][] = (object) $row; }
    }
    class BindingTestCollection {
        private $items;
        public function __construct($items) { $this->items = $items; }
        public function count() { return count($this->items); }
        public function first() { return $this->items[0] ?? null; }
    }
    class BindingTestSettings extends SettingsService {
        public function automaticCreationEnabled() { return true; }
    }
    class BindingTestMailboxes extends MailboxAccessService {
        public $reconciled = [];
        public function mappedIds(array $keys, $lock = false) { return $keys; }
        public function reconcile(User $user, array $keys, $lock = false) { $this->reconciled[] = $keys; return []; }
    }
    class BindingServiceTest extends TestCase {
        private $service;
        private $mailboxes;
        private $identity;
        private $basic;
        protected function setUp(): void {
            DB::$rows = [];
            User::$candidate = new User();
            $this->mailboxes = new BindingTestMailboxes();
            $this->service = new BindingService(new BindingTestSettings(), $this->mailboxes);
            $this->identity = ['issuer' => 'https://rondo.example/oauth', 'subject' => str_repeat('s', 43), 'email' => 'agent@example.test'];
            $this->basic = ['active' => false, 'sidebar_access' => true, 'managed_mailboxes' => []];
        }
        public function testExistingBasicAccountBindsAndSignsInAgainWithoutGrantingMailboxes() {
            $this->assertSame(User::$candidate, $this->service->resolve($this->identity, $this->basic));
            $this->assertCount(1, DB::$rows['rondo_oidc_bindings']);
            $this->assertSame(User::$candidate, $this->service->resolve($this->identity, $this->basic));
            $this->assertCount(1, DB::$rows['rondo_oidc_bindings']);
            $this->assertSame([[], []], $this->mailboxes->reconciled);
            $this->assertArrayNotHasKey('rondo_managed_users', DB::$rows);
        }
        public function testBasicAccessNeverCreatesAnAccountEvenWhenAutomaticCreationIsEnabled() {
            User::$candidate = null;
            $this->expectExceptionMessage('account_creation_disabled');
            $this->service->resolve($this->identity, $this->basic);
        }
        public function testExistingAdministratorCanBindWithoutAnAssignedMailbox() {
            User::$candidate->admin = true;
            User::$candidate->mailboxActive = false;
            $this->assertSame(User::$candidate, $this->service->resolve($this->identity, $this->basic));
            $this->assertSame([[]], $this->mailboxes->reconciled);
        }
        public function testDisabledExistingAccountCannotBind() {
            User::$candidate->active = false;
            $this->expectExceptionMessage('identity_ineligible');
            $this->service->resolve($this->identity, $this->basic);
        }
        public function testMissingActiveMailboxPreventsBinding() {
            User::$candidate->mailboxActive = false;
            $this->expectExceptionMessage('identity_ineligible');
            $this->service->resolve($this->identity, $this->basic);
        }
        public function testExistingBindingCannotReactivateDisabledBasicUser() {
            $this->service->resolve($this->identity, $this->basic);
            User::$candidate->active = false;
            $this->expectExceptionMessage('identity_ineligible');
            $this->service->resolve($this->identity, $this->basic);
        }
        public function testRevokedBasicAccessIsRejected() {
            $this->service->resolve($this->identity, $this->basic);
            $this->basic['sidebar_access'] = false;
            $this->expectExceptionMessage('identity_ineligible');
            $this->service->resolve($this->identity, $this->basic);
        }
        public function testOlderRondoResponsesKeepManagedAccountBindingWorking() {
            $this->service->resolve($this->identity, ['active' => true, 'managed_mailboxes' => ['contributie']]);
            $this->assertSame([['contributie']], $this->mailboxes->reconciled);
        }
        public function testOlderInactiveResponsesCannotBind() {
            $this->expectExceptionMessage('identity_ineligible');
            $this->service->resolve($this->identity, ['active' => false, 'managed_mailboxes' => []]);
        }
    }
}
