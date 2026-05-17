<?php

namespace Tests\Feature;

use App\Core\Exceptions\InsufficientFundsException;
use App\Core\Models\User;
use App\Core\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = new WalletService();
    }

    // ── credit ────────────────────────────────────────────────────────────────

    public function test_credit_increases_cash_balance(): void
    {
        $user = User::factory()->create();
        $initialBalance = $this->wallet->getBalance($user);

        $newBalance = $this->wallet->credit($user, 500, 'test credit');

        $this->assertSame($initialBalance + 500, $newBalance);
        $this->assertSame($initialBalance + 500, $this->wallet->getBalance($user));
    }

    public function test_credit_throws_on_non_positive_amount(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->credit($user, 0, 'zero amount');
    }

    public function test_credit_throws_on_negative_amount(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->credit($user, -100, 'negative amount');
    }

    // ── debit ─────────────────────────────────────────────────────────────────

    public function test_debit_decreases_cash_balance(): void
    {
        $user = User::factory()->create();
        $this->wallet->credit($user, 1000, 'setup');

        $initialBalance = $this->wallet->getBalance($user);
        $newBalance = $this->wallet->debit($user, 300, 'test debit');

        $this->assertSame($initialBalance - 300, $newBalance);
        $this->assertSame($initialBalance - 300, $this->wallet->getBalance($user));
    }

    public function test_debit_throws_insufficient_funds_exception(): void
    {
        $user = User::factory()->create();
        // Ensure the user has less than we try to debit
        $user->profile()->update(['cash' => 50]);

        $this->expectException(InsufficientFundsException::class);
        $this->wallet->debit($user, 100, 'too much');
    }

    public function test_debit_throws_on_non_positive_amount(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->debit($user, 0, 'zero amount');
    }

    // ── transfer ──────────────────────────────────────────────────────────────

    public function test_transfer_moves_cash_between_users(): void
    {
        $sender    = User::factory()->create();
        $recipient = User::factory()->create();

        $this->wallet->credit($sender, 1000, 'setup');
        $senderInitial    = $this->wallet->getBalance($sender);
        $recipientInitial = $this->wallet->getBalance($recipient);

        $this->wallet->transfer($sender, $recipient, 400, 'test transfer');

        $this->assertSame($senderInitial - 400, $this->wallet->getBalance($sender));
        $this->assertSame($recipientInitial + 400, $this->wallet->getBalance($recipient));
    }

    public function test_transfer_throws_insufficient_funds(): void
    {
        $sender    = User::factory()->create();
        $recipient = User::factory()->create();

        $sender->profile()->update(['cash' => 50]);

        $this->expectException(InsufficientFundsException::class);
        $this->wallet->transfer($sender, $recipient, 100, 'too much');
    }

    public function test_transfer_throws_on_non_positive_amount(): void
    {
        $sender    = User::factory()->create();
        $recipient = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->transfer($sender, $recipient, 0, 'zero');
    }

    public function test_transfer_is_atomic_on_insufficient_funds(): void
    {
        $sender    = User::factory()->create();
        $recipient = User::factory()->create();

        $sender->profile()->update(['cash' => 50]);
        $recipientBefore = $this->wallet->getBalance($recipient);

        try {
            $this->wallet->transfer($sender, $recipient, 200, 'fail');
        } catch (InsufficientFundsException $e) {
            // Expected
        }

        // Recipient's balance must be unchanged — transaction rolled back
        $this->assertSame($recipientBefore, $this->wallet->getBalance($recipient));
    }

    // ── balance queries ───────────────────────────────────────────────────────

    public function test_get_balance_returns_current_cash(): void
    {
        $user = User::factory()->create();
        $user->profile()->update(['cash' => 750]);

        $this->assertSame(750, $this->wallet->getBalance($user));
    }

    public function test_get_bank_balance_returns_current_bank(): void
    {
        $user = User::factory()->create();
        $user->profile()->update(['bank' => 5000]);

        $this->assertSame(5000, $this->wallet->getBankBalance($user));
    }
}
