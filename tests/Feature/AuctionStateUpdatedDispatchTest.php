<?php

namespace Tests\Feature;

use App\Events\AuctionStateUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuctionStateUpdatedDispatchTest extends TestCase
{
    public function test_the_event_is_held_until_the_database_transaction_commits(): void
    {
        $received = [];

        Event::listen(AuctionStateUpdated::class, function () use (&$received): void {
            $received[] = true;
        });

        DB::beginTransaction();

        try {
            AuctionStateUpdated::dispatch(['version' => 1]);

            $this->assertSame([], $received, 'El evento no debe emitirse dentro de la transacción.');
        } finally {
            DB::commit();
        }

        $this->assertCount(1, $received, 'El evento debe emitirse al confirmar la transacción.');
    }
}
