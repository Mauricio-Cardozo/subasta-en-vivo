<?php

namespace Tests\Unit;

use App\Events\AuctionStateUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

class AuctionStateUpdatedTest extends TestCase
{
    public function test_it_broadcasts_now_on_the_auction_channel(): void
    {
        $event = new AuctionStateUpdated([]);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertSame('AuctionStateUpdated', $event->broadcastAs());

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('auction', $channels[0]->name);
    }

    public function test_the_public_payload_keeps_only_whitelisted_keys(): void
    {
        $event = new AuctionStateUpdated([
            'version' => 3,
            'lot' => ['id' => 1, 'title' => 'Reloj antiguo', 'status' => 'open', 'ends_at' => '2026-10-15T17:00:10+00:00'],
            'bids' => [['name' => 'Ana', 'amount_cents' => 1500]],
            'action' => ['type' => 'bid_accepted', 'summary' => 'Ana pujó 15,00'],
            'server_now' => '2026-10-15T17:00:00+00:00',
            'correlation_id' => 'abc-123',
            // Datos internos: no deben viajar por el canal público.
            'ip' => '10.0.0.1',
            'triggeredById' => 'session-1',
            'triggeredByIp' => '10.0.0.1',
            'balances' => ['session-1' => 100000],
        ]);

        $this->assertSame([
            'version' => 3,
            'lot' => ['id' => 1, 'title' => 'Reloj antiguo', 'status' => 'open', 'ends_at' => '2026-10-15T17:00:10+00:00'],
            'bids' => [['name' => 'Ana', 'amount_cents' => 1500]],
            'action' => ['type' => 'bid_accepted', 'summary' => 'Ana pujó 15,00'],
            'server_now' => '2026-10-15T17:00:00+00:00',
            'correlation_id' => 'abc-123',
        ], $event->broadcastWith());

        $this->assertArrayNotHasKey('triggeredById', $event->broadcastWith());
        $this->assertArrayNotHasKey('triggeredByIp', $event->broadcastWith());
        $this->assertArrayNotHasKey('balances', $event->broadcastWith());
    }

    public function test_it_waits_for_the_database_transaction_to_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new AuctionStateUpdated([]));
    }
}
