<?php

namespace Tests\Feature\Models;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Modules\BillCom\BillComCustomer;

/**
 * App\Models\Site: encrypted casts, hidden attributes, the notArchived
 * global scope, the server()/billComCustomer() relationships, and the
 * small hosting-provider/uptime-ignore identity helpers.
 *
 * billComCustomer(): BillComCustomer has no HasFactory/factory of its own
 * (no module in this app wires up a Database\Factories\Modules\* autoload
 * path — composer.json only maps Database\Factories\ to database/factories/
 * for the App\ namespace), so rather than bolt on new factory infrastructure
 * for a single relationship test, the row is created directly via
 * BillComCustomer::create() — its PK is a plain string (Bill.com's own id,
 * non-incrementing) and every column is already in $fillable, so this is a
 * complete, realistic row without needing a factory at all.
 */
describe('Site encrypted casts', function () {
    it('round-trips db_password and companion_secret through the encrypted cast', function () {
        $site = Site::factory()->create([
            'db_password' => 'secret123',
            'companion_secret' => 'hmac-secret-abc',
        ]);

        $fresh = $site->fresh();

        expect($fresh->db_password)->toBe('secret123')
            ->and($fresh->companion_secret)->toBe('hmac-secret-abc');
    });

    it('stores db_password and companion_secret encrypted in the raw column, not as plaintext', function () {
        $site = Site::factory()->create([
            'db_password' => 'secret123',
            'companion_secret' => 'hmac-secret-abc',
        ]);

        $rawPassword = DB::table('sites')->where('id', $site->id)->value('db_password');
        $rawSecret = DB::table('sites')->where('id', $site->id)->value('companion_secret');

        expect($rawPassword)->not->toBe('secret123')
            ->and($rawSecret)->not->toBe('hmac-secret-abc');
    });
});

describe('Site $hidden attributes', function () {
    it('excludes db_password and companion_secret from array/JSON serialization', function () {
        $site = Site::factory()->create([
            'db_password' => 'x',
            'companion_secret' => 'y',
        ]);

        $array = $site->toArray();

        expect($array)->not->toHaveKey('db_password')
            ->and($array)->not->toHaveKey('companion_secret');
    });
});

describe('Site notArchived global scope', function () {
    it('excludes archived sites from Site::all() and Site::query()->get(), but withoutGlobalScopes() includes them', function () {
        $archived = Site::factory()->archived()->create();
        $active = Site::factory()->create();

        $allIds = Site::all()->pluck('id')->all();
        $queryIds = Site::query()->get()->pluck('id')->all();
        $unscopedIds = Site::withoutGlobalScopes()->get()->pluck('id')->all();

        expect($allIds)->toBe([$active->id])
            ->and($queryIds)->toBe([$active->id])
            ->and($unscopedIds)->toContain($archived->id)
            ->and($unscopedIds)->toContain($active->id)
            ->and($unscopedIds)->toHaveCount(2);
    });
});

describe('Site::server()', function () {
    it('resolves the owning Server for a SpinupWP site', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);

        expect($site->server()->first()->id)->toBe($server->id)
            ->and($site->server)->toBeInstanceOf(Server::class);
    });

    it('returns null for a Pressable site since server_id is null', function () {
        $site = Site::factory()->pressable()->create();

        expect($site->server_id)->toBeNull()
            ->and($site->server()->first())->toBeNull()
            ->and($site->server)->toBeNull();
    });
});

describe('Site::billComCustomer()', function () {
    it('resolves the linked BillComCustomer when bill_com_customer_id is set to a real id', function () {
        $customer = BillComCustomer::create([
            'id' => '0cuTestCustomer123',
            'name' => 'Acme Corp',
            'company_name' => 'Acme Corporation',
            'email' => 'billing@acme.example',
            'archived' => false,
        ]);

        $site = Site::factory()->create(['bill_com_customer_id' => $customer->id]);

        expect($site->billComCustomer)->toBeInstanceOf(BillComCustomer::class)
            ->and($site->billComCustomer->id)->toBe($customer->id)
            ->and($site->billComCustomer->name)->toBe('Acme Corp');
    });
});

describe('Site::isPressable() / Site::isSpinupWp()', function () {
    it('returns true/false correctly for a Pressable site', function () {
        $site = Site::factory()->pressable()->create();

        expect($site->isPressable())->toBeTrue()
            ->and($site->isSpinupWp())->toBeFalse();
    });

    it('returns true/false correctly for a SpinupWP site', function () {
        $site = Site::factory()->spinupwp()->create();

        expect($site->isSpinupWp())->toBeTrue()
            ->and($site->isPressable())->toBeFalse();
    });
});

describe('Site::isUptimeIgnored()', function () {
    it('is false when uptime_ignored_at is null', function () {
        $site = Site::factory()->create(['uptime_ignored_at' => null]);

        expect($site->isUptimeIgnored())->toBeFalse();
    });

    it('is true when uptime_ignored_at is set', function () {
        $site = Site::factory()->create(['uptime_ignored_at' => now()]);

        expect($site->isUptimeIgnored())->toBeTrue();
    });
});
