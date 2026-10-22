<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * RNF-10: el balance vive en config/auction.php, no hardcodeado.
 */
class AuctionConfigTest extends TestCase
{
    public function test_the_balance_values_are_present_and_integral(): void
    {
        $this->assertSame(100_000, config('auction.participant.starting_balance_cents'));
        $this->assertSame(100, config('auction.lot.min_increment_cents'));
        $this->assertSame(60, config('auction.lot.duration_seconds'));

        foreach (['bids_per_second', 'chat_messages_per_second', 'lot_opens_per_minute', 'lot_deletes_per_minute'] as $limit) {
            $this->assertIsInt(config("auction.limits.{$limit}"), "auction.limits.{$limit} debe ser entero.");
        }

        foreach (['window_seconds', 'extension_seconds', 'max_extensions'] as $key) {
            $this->assertIsInt(config("auction.anti_sniping.{$key}"), "auction.anti_sniping.{$key} debe ser entero.");
        }

        $this->assertSame(280, config('auction.chat.max_length'));
        $this->assertSame(50, config('auction.chat.history'));
        $this->assertSame(10, config('auction.snapshot.bids'));
    }

    public function test_the_queue_values_are_present_and_integral(): void
    {
        $this->assertSame(10, config('auction.queue.max'));
        $this->assertSame(8, config('auction.queue.gap_seconds'));
    }

    public function test_the_host_pin_comes_from_the_environment(): void
    {
        $this->assertArrayHasKey('host_pin', config('auction'));
        $this->assertSame(env('AUCTION_HOST_PIN'), config('auction.host_pin'));
    }

    public function test_the_example_environment_documents_the_host_pin_without_a_value(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('AUCTION_HOST_PIN=', $example);
        $this->assertStringNotContainsString('AUCTION_HOST_PIN=1', $example);
    }
}
