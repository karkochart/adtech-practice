<?php

namespace Tests\Feature;

use App\Exceptions\BudgetExceededException;
use App\Exceptions\CampaignInactiveException;
use App\Exceptions\ImpressionConflictException;
use App\Models\Campaign;
use App\Services\BudgetService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCampaigns;
use Tests\TestCase;

/**
 * ЗАДАЧА 1 (бюджет кампании). Реализовать App\Services\BudgetService::charge($campaignId, $impressionId, $cost).
 * PHP 7.3: без типизированных свойств, стрелочных функций, match, named arguments.
 *
 * Правила:
 *  - $cost - положительное int (микро-единицы), иначе \InvalidArgumentException
 *  - списание увеличивает campaigns.spent_today и пишет строку в charges (impression_id, campaign_id, cost); возвращает Charge
 *  - дневной бюджет нельзя превысить: BudgetExceededException, ничего не записывается. Ровно в бюджет - можно.
 *  - кампания не active - CampaignInactiveException
 *  - идемпотентность по impression_id: тот же показ (те же campaign_id и cost) - вернуть прежний Charge, второй раз не списывать
 *  - тот же impression_id, но другая кампания или сумма - ImpressionConflictException
 *  - неизвестная кампания - Illuminate\Database\Eloquent\ModelNotFoundException
 */
class BudgetServiceTest extends TestCase
{
    use RefreshDatabase, MakesCampaigns;

    private function svc()
    {
        return app(BudgetService::class);
    }

    public function test_charge_increases_spent_and_writes_a_charge()
    {
        $c = $this->makeCampaign(1000);

        $charge = $this->svc()->charge($c->id, 'imp-1', 100);

        $this->assertSame(100, (int) $c->fresh()->spent_today);
        $this->assertSame('imp-1', $charge->impression_id);
        $this->assertSame(100, (int) $charge->cost);
        $this->assertSame(1, DB::table('charges')->count());
    }

    public function test_exact_budget_is_allowed_and_next_cent_is_not()
    {
        $c = $this->makeCampaign(300);
        $this->svc()->charge($c->id, 'imp-1', 300);

        $this->expectException(BudgetExceededException::class);
        $this->svc()->charge($c->id, 'imp-2', 1);
    }

    public function test_over_budget_changes_nothing()
    {
        $c = $this->makeCampaign(100, 'active', 60);

        try {
            $this->svc()->charge($c->id, 'imp-1', 50);
            $this->fail('expected BudgetExceededException');
        } catch (BudgetExceededException $e) {
        }

        $this->assertSame(60, (int) $c->fresh()->spent_today);
        $this->assertSame(0, DB::table('charges')->count());
    }

    public function test_paused_campaign_is_rejected()
    {
        $c = $this->makeCampaign(1000, 'paused');

        $this->expectException(CampaignInactiveException::class);
        $this->svc()->charge($c->id, 'imp-1', 10);
    }

    public function test_same_impression_is_idempotent()
    {
        $c = $this->makeCampaign(1000);

        $first = $this->svc()->charge($c->id, 'imp-1', 100);
        $second = $this->svc()->charge($c->id, 'imp-1', 100);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(100, (int) $c->fresh()->spent_today);
        $this->assertSame(1, DB::table('charges')->count());
    }

    public function test_repeat_of_known_impression_works_even_if_budget_is_now_exhausted()
    {
        $c = $this->makeCampaign(100);
        $first = $this->svc()->charge($c->id, 'imp-1', 100);

        $again = $this->svc()->charge($c->id, 'imp-1', 100);   // бюджет исчерпан, но это повтор, а не новый показ

        $this->assertSame($first->id, $again->id);
    }

    public function test_same_impression_with_different_cost_is_conflict()
    {
        $c = $this->makeCampaign(1000);
        $this->svc()->charge($c->id, 'imp-1', 100);

        $this->expectException(ImpressionConflictException::class);
        $this->svc()->charge($c->id, 'imp-1', 101);
    }

    public function test_same_impression_on_another_campaign_is_conflict()
    {
        $a = $this->makeCampaign(1000);
        $b = $this->makeCampaign(1000);
        $this->svc()->charge($a->id, 'imp-1', 100);

        $this->expectException(ImpressionConflictException::class);
        $this->svc()->charge($b->id, 'imp-1', 100);
    }

    /** @dataProvider invalidCosts */
    public function test_invalid_cost_is_rejected($cost)
    {
        $c = $this->makeCampaign(1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->charge($c->id, 'imp-1', $cost);
    }

    public function invalidCosts()
    {
        return [[0], [-5], ['10'], [1.5], [null]];
    }

    public function test_unknown_campaign()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->svc()->charge(999999, 'imp-1', 10);
    }

    public function test_budget_large_values_do_not_overflow_into_float()
    {
        $c = $this->makeCampaign(PHP_INT_MAX);   // BIGINT UNSIGNED вмещает
        $this->svc()->charge($c->id, 'imp-1', PHP_INT_MAX - 1);

        $this->assertSame((string) (PHP_INT_MAX - 1), (string) Campaign::find($c->id)->spent_today);
    }
}
