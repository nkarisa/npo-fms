<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\EntityScope;
use App\Libraries\SignIn;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Each entity's books are kept apart. Someone works in one entity at a time — the
 * picker at the top of the page — and sees, finds and records only that entity's
 * records, with the role they hold there. Someone holding a role at every entity
 * may also read all of them together, in the consolidated view, which changes
 * nothing.
 *
 * The demonstration organisation keeps its ledger at the head office (ELOG-NS);
 * B. Omondi is an Accountant at the Rift Valley office (ELOG-RV) only, and
 * S. Njeri an Accountant at the head office and the Coast office.
 */
final class EntitySegmentationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    private const BRANCH_ONLY = 'b.omondi@elog.or.ke';
    private const TWO_ENTITIES = 's.njeri@elog.or.ke';
    private const EVERYWHERE = 'w.kamau@elog.or.ke';

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        // Suspended and invited in the demonstration data; here they are at work.
        db_connect()->table('users')->whereIn('email', [self::BRANCH_ONLY, 'g.wambui@elog.or.ke'])->update(['status' => 'active']);
        Repository::forget();
    }

    public function testThePickerOffersTheEntitiesSomeoneHoldsARoleAt(): void
    {
        $this->workIn(self::BRANCH_ONLY);
        $this->assertSame(['ELOG Rift Valley Office'], $this->pickerNames());

        $this->workIn(self::TWO_ENTITIES);
        $picker = $this->api('get', 'api/me')['entity'];
        $this->assertSame(['ELOG National Secretariat', 'ELOG Coast Regional Office'], array_column($picker['options'], 'name'));
        $this->assertFalse($picker['canConsolidate']);
        $this->assertTrue($picker['options'][0]['current'], 'Someone who has not chosen starts at the head office');

        $this->workIn(self::EVERYWHERE);
        $names = $this->pickerNames();
        $this->assertCount(6, $names);
        $this->assertSame('Consolidated', end($names));
    }

    public function testSomeoneSwitchesOnlyToAnEntityTheyHoldARoleAt(): void
    {
        $this->workIn(self::TWO_ENTITIES);

        $this->api('post', 'api/me/entity', ['entity' => 'ELOG-CST']);
        $this->assertSame(
            $this->entityId('ELOG-CST'),
            (int) db_connect()->table('users')->where('email', self::TWO_ENTITIES)->get()->getRowArray()['last_entity_id'],
            'The choice is remembered for the next sign-in'
        );
        // A later request without a choice in its session comes back to the one remembered.
        $this->workIn(self::TWO_ENTITIES);
        $this->assertSame('ELOG Coast Regional Office', $this->current());

        $this->assertStringContainsString('do not hold a role', $this->api('post', 'api/me/entity', ['entity' => 'ELOG-RV'], 403)['error']);
        $this->assertStringContainsString('every entity', $this->api('post', 'api/me/entity', ['entity' => 'all'], 403)['error']);
    }

    public function testEveryEntityKeepsItsBooksOnTheOrganisationsCalendar(): void
    {
        $db = db_connect();
        $calendar = static fn (string $code) => array_column($db->table('periods p')->select('p.code, p.status')
            ->join('entities e', 'e.id = p.entity_id')->where('e.code', $code)->orderBy('p.starts_on')->get()->getResultArray(), 'status', 'code');

        $head = $calendar('ELOG-NS');
        $this->assertNotEmpty($head);
        foreach (['ELOG-CST', 'ELOG-WST', 'ELOG-TRUST', 'ELOG-RV'] as $code) {
            $this->assertSame($head, $calendar($code), $code . ' has the head office\'s months, open and closed alike');
        }
    }

    /**
     * The guarantee: whatever endpoint someone at the Rift Valley office reads, not
     * one head-office document reference comes back.
     */
    public function testNothingOfAnotherEntityIsServedByAnyEndpoint(): void
    {
        $refs = $this->headOfficeReferences();
        $this->assertGreaterThan(500, count($refs));

        $this->workIn(self::BRANCH_ONLY);
        $read = 0;
        foreach ($this->plainGetRoutes() as $route) {
            $result = $this->get($route);
            $status = $result->response()->getStatusCode();
            if ($status !== 200) {
                $this->assertContains($status, [403, 404, 409, 422], $route . ' failed: ' . substr((string) $result->response()->getBody(), 0, 300));

                continue;
            }
            $body = (string) $result->response()->getBody();
            foreach ($refs as $ref) {
                $this->assertStringNotContainsString($ref, $body, $route . ' serves ' . $ref . ', a head-office record');
            }
            $read++;
        }
        $this->assertGreaterThan(20, $read);

        // Nor does searching for one find it — though searching at the head office does.
        $journal = $this->headOfficeJournal();
        $this->assertSame(0, $this->api('get', 'api/search?q=' . rawurlencode($journal))['count']);
        $this->workIn(self::EVERYWHERE, 'ELOG-NS');
        $this->assertGreaterThan(0, $this->api('get', 'api/search?q=' . rawurlencode($journal))['count']);
    }

    /**
     * The branches hold no postings yet, so read together the books are the head
     * office's: every figure in the consolidated statements agrees to it.
     */
    public function testTheConsolidationAddsUpTheEntitiesBooks(): void
    {
        $read = function (string $entity) {
            $this->workIn(self::EVERYWHERE, $entity);

            return array_map(fn ($route) => $this->api('get', $route), ['api/reports', 'api/funds', 'api/coa', 'api/gl?account=1110']);
        };
        $this->assertEquals($read('ELOG-NS'), $read(EntityScope::CONSOLIDATED));
    }

    public function testARecordOfAnotherEntityIsNotFound(): void
    {
        $ref = $this->headOfficeJournal();

        $this->workIn(self::EVERYWHERE);
        $this->api('get', 'api/journals/' . $ref);

        $this->workIn(self::BRANCH_ONLY);
        $this->api('get', 'api/journals/' . $ref, [], 404);
        $this->api('post', 'api/journals/' . $ref . '/approve', [], 404);
    }

    public function testWhatIsRecordedInAnEntityIsSeenThereAndInTheConsolidation(): void
    {
        $this->workIn(self::BRANCH_ONLY);
        $made = $this->api('post', 'api/journals', [
            'date' => '2026-08-20', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => 'Draft', 'docLink' => 'auto',
            'memo' => '', 'narration' => 'Rift Valley office rent accrual',
            'lines' => [$this->line('5330', 900, 0), $this->line('2120', 0, 900)],
        ], 201)['journal'];
        $row = db_connect()->table('journals')->where('reference', $made['ref'])->get()->getRowArray();
        $this->assertSame($this->entityId('ELOG-RV'), (int) $row['entity_id']);
        // Numbered across the organisation, so the reference names one record even read together.
        $this->assertNotContains($made['ref'], $this->headOfficeReferences());

        // [journals in the register, of which found by the narration]
        $register = function () {
            $all = $this->api('get', 'api/journals');
            $found = $this->api('get', 'api/journals?q=' . rawurlencode('Rift Valley office rent'));

            return [$all['total'], array_column($found['rows'], 'ref')];
        };
        $this->assertSame([1, [$made['ref']]], $register());

        $this->workIn(self::EVERYWHERE, 'ELOG-NS');
        [$headOffice, $found] = $register();
        $this->assertSame([], $found);

        $this->workIn(self::EVERYWHERE, 'ELOG-RV');
        $this->assertSame([1, [$made['ref']]], $register());

        $this->workIn(self::EVERYWHERE, EntityScope::CONSOLIDATED);
        $this->assertSame([$headOffice + 1, [$made['ref']]], $register());
    }

    public function testTheConsolidatedViewChangesNothing(): void
    {
        $this->workIn(self::EVERYWHERE, EntityScope::CONSOLIDATED);

        $refused = $this->api('post', 'api/journals', ['narration' => 'x'], 409);
        $this->assertStringContainsString('consolidated books, which are read only', $refused['error']);
        // The person's own account is theirs, whichever books they are reading.
        $this->api('post', 'api/notifications/read', []);
        $this->api('post', 'api/me/entity', ['entity' => 'ELOG-CST']);
    }

    public function testSomeoneActsWithTheRoleTheyHoldAtTheEntityTheyAreIn(): void
    {
        $db = db_connect();
        $userId = (new Lookups())->userId('g.wambui@elog.or.ke');
        $accountant = (int) $db->table('roles')->where('name', 'Accountant')->get()->getRowArray()['id'];
        $db->table('user_entity_roles')->insert(['user_id' => $userId, 'entity_id' => $this->entityId('ELOG-CST'), 'role_id' => $accountant]);

        $this->workIn('g.wambui@elog.or.ke', 'ELOG-NS');
        $me = $this->api('get', 'api/me')['me'];
        $this->assertSame('Programme Officer', $me['role']);
        $this->assertFalse($me['canPrepare']);

        $this->workIn('g.wambui@elog.or.ke', 'ELOG-CST');
        $me = $this->api('get', 'api/me')['me'];
        $this->assertSame('Accountant', $me['role']);
        $this->assertSame(['Accountant'], $me['roles']);
        $this->assertTrue($me['canPrepare']);
    }

    // ------------------------------------------------------------------

    /** Signs in as a user, working in the entity named (by code or "all"), or wherever they would land. */
    private function workIn(string $email, ?string $entity = null): void
    {
        $this->signIn($email);
        $id = (new Lookups())->userId($email);
        $choice = match (true) {
            $entity === null                      => [],
            $entity === EntityScope::CONSOLIDATED => [EntityScope::SESSION => EntityScope::CONSOLIDATED],
            default                               => [EntityScope::SESSION => $this->entityId($entity)],
        };
        $this->withSession(SignIn::values($id) + $choice);
        Repository::forget();
    }

    private function pickerNames(): array
    {
        return array_column($this->api('get', 'api/me')['entity']['options'], 'name');
    }

    private function current(): string
    {
        foreach ($this->api('get', 'api/me')['entity']['options'] as $o) {
            if ($o['current']) {
                return $o['name'];
            }
        }

        return '';
    }

    private function entityId(string $code): int
    {
        return (int) db_connect()->table('entities')->where('code', $code)->get()->getRowArray()['id'];
    }

    private function headOfficeJournal(): string
    {
        return (string) db_connect()->table('journals')->where('entity_id', $this->entityId('ELOG-NS'))->where('status', 'posted')
            ->orderBy('id')->get(1)->getRowArray()['reference'];
    }

    /** The references of the head office's documents: journals, bills, invoices, orders, advances and the rest. */
    private function headOfficeReferences(): array
    {
        $db = db_connect();
        $head = $this->entityId('ELOG-NS');
        $refs = [];
        foreach (['journals', 'bills', 'invoices', 'requisitions', 'purchase_orders', 'goods_received_notes', 'advances', 'donor_reports', 'payment_runs'] as $table) {
            foreach ($db->table($table)->select('reference')->where('entity_id', $head)->get()->getResultArray() as $r) {
                // Short references ("RUN-1") would match unrelated text.
                if (strlen((string) $r['reference']) >= 8) {
                    $refs[] = (string) $r['reference'];
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /** Every API route read with GET that takes no parameter. */
    private function plainGetRoutes(): array
    {
        $routes = [];
        foreach (array_keys(service('routes')->getRoutes('GET')) as $route) {
            if (str_starts_with($route, 'api/') && !str_contains($route, '(') && !str_starts_with($route, 'api/auth')) {
                $routes[] = $route;
            }
        }
        sort($routes);

        return $routes;
    }

    private function line(string $code, float $dr, float $cr): array
    {
        return ['code' => $code, 'desc' => '', 'grantRef' => '', 'fund' => 'General Fund', 'program' => 'Shared services', 'dr' => $dr, 'cr' => $cr];
    }

    private function api(string $method, string $url, array $body = [], int $status = 200): array
    {
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }
}
