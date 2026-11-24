<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\PartnerCode;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\HeadcountTooLowException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgencyPricingServiceTest extends TestCase
{
    private AgencyPricingService $pricing;

    private CarbonImmutable $foundingDate;

    private CarbonImmutable $standardDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(AgencyPricingService::class);
        $this->foundingDate = CarbonImmutable::parse('2026-10-22 12:00', 'America/New_York');
        $this->standardDate = CarbonImmutable::parse('2027-03-01 12:00', 'America/New_York');
    }

    /**
     * @return iterable<string, array{int, AgencyTier, int, int}>
     */
    public static function foundingExamples(): iterable
    {
        // headcount, tier, rate, annual total (cents)
        yield 'spec: 4 people' => [4, AgencyTier::Agency, 15900, 63600];
        yield 'spec: 12 people' => [12, AgencyTier::AgencyPlus, 14900, 178800];
        yield 'spec: 60 people' => [60, AgencyTier::AgencyEnterprise, 12900, 774000];
        yield 'minimum: 2 people' => [2, AgencyTier::Agency, 15900, 31800];
        yield 'boundary: 9 people' => [9, AgencyTier::Agency, 15900, 143100];
        yield 'boundary: 10 people' => [10, AgencyTier::AgencyPlus, 14900, 149000];
        yield 'boundary: 49 people' => [49, AgencyTier::AgencyPlus, 14900, 730100];
        yield 'boundary: 50 people' => [50, AgencyTier::AgencyEnterprise, 12900, 645000];
    }

    #[DataProvider('foundingExamples')]
    public function test_founding_rates_by_headcount(int $headcount, AgencyTier $tier, int $rate, int $total): void
    {
        $quote = $this->pricing->quote($headcount, $this->foundingDate);

        $this->assertSame($tier, $quote->tier);
        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame($rate, $quote->rate);
        $this->assertSame($total, $quote->subtotal);
        $this->assertSame($total, $quote->total);
        $this->assertSame(0, $quote->surcharge);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function standardExamples(): iterable
    {
        yield '9 people' => [9, 17900];
        yield '10 people' => [10, 15900];
        yield '49 people' => [49, 15900];
        yield '50 people' => [50, 13900];
    }

    #[DataProvider('standardExamples')]
    public function test_standard_rates_by_headcount(int $headcount, int $rate): void
    {
        $quote = $this->pricing->quote($headcount, $this->standardDate);

        $this->assertSame(RateType::Standard, $quote->rateType);
        $this->assertSame($rate, $quote->rate);
        $this->assertSame($rate * $headcount, $quote->total);
    }

    public function test_a_principal_alone_pays_for_the_two_member_minimum(): void
    {
        $quote = $this->pricing->quote(1, $this->foundingDate);

        $this->assertSame(1, $quote->headcount);
        $this->assertSame(2, $quote->seats);
        $this->assertSame(AgencyTier::Agency, $quote->tier);
        $this->assertSame(31800, $quote->subtotal);
        $this->assertTrue($quote->minimumApplied());
        $this->assertSame('Minimum 2 members: $159 × 2', $quote->minimumLine());
        $this->assertSame('1 person · Agency · minimum 2 members at $159 · $318 a year', $quote->summary());

        // Two people: no minimum line.
        $this->assertFalse($this->pricing->quote(2, $this->foundingDate)->minimumApplied());
    }

    public function test_nobody_is_not_an_agency(): void
    {
        $this->expectException(HeadcountTooLowException::class);

        $this->pricing->quote(0, $this->foundingDate);
    }

    public function test_employees_are_billed_like_producers_by_default(): void
    {
        $quote = $this->pricing->quote(5, $this->foundingDate, employees: 3);

        $this->assertSame(5, $quote->seats);
        $this->assertSame(79500, $quote->subtotal);
    }

    public function test_without_billing_employees_they_count_toward_the_minimum_only(): void
    {
        config(['agency.bill_employees' => false]);

        // Principal + 3 employees: one producer billed, the minimum makes it two.
        $alone = $this->pricing->quote(4, $this->foundingDate, employees: 3);
        $this->assertSame(1, $alone->billedPeople);
        $this->assertSame(2, $alone->seats);
        $this->assertSame(31800, $alone->subtotal);
        $this->assertTrue($alone->minimumApplied());

        // 3 producers + 9 employees: 3 billed; the tier still follows everyone (12 people).
        $mixed = $this->pricing->quote(12, $this->foundingDate, employees: 9);
        $this->assertSame(AgencyTier::AgencyPlus, $mixed->tier);
        $this->assertSame(3, $mixed->seats);
        $this->assertSame(44700, $mixed->subtotal);
        $this->assertSame('12 people · Agency Plus · $149 each for 3 billed · $447 a year', $mixed->summary());
    }

    /**
     * @return iterable<string, array{string, string, RateType}>
     */
    public static function cutoffMoments(): iterable
    {
        yield 'last second of 2026, Eastern' => ['2026-12-31 23:59:59', 'America/New_York', RateType::Founding];
        yield 'midnight Jan 1, 2027, Eastern' => ['2027-01-01 00:00:00', 'America/New_York', RateType::Standard];
        yield 'UTC just before Eastern midnight' => ['2027-01-01 04:59:59', 'UTC', RateType::Founding];
        yield 'UTC at Eastern midnight' => ['2027-01-01 05:00:00', 'UTC', RateType::Standard];
        yield 'Pacific on New Year\'s Eve evening' => ['2026-12-31 21:30:00', 'America/Los_Angeles', RateType::Standard];
    }

    #[DataProvider('cutoffMoments')]
    public function test_founding_cutoff_is_midnight_eastern(string $moment, string $timezone, RateType $expected): void
    {
        $this->assertSame($expected, $this->pricing->rateTypeFor(CarbonImmutable::parse($moment, $timezone)));
    }

    public function test_founding_rates_stay_locked_at_renewal(): void
    {
        $quote = $this->pricing->quote(12, $this->standardDate, rateType: RateType::Founding);

        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame(178800, $quote->total);
    }

    /**
     * The client's partner rates: 2 to 49 people $149 (Agency and Agency Plus as one), 50+ $129.
     *
     * @return iterable<string, array{int, string, int, int}>
     */
    public static function partnerRates(): iterable
    {
        yield 'Agency, founding' => [4, 'founding', 14900, 59600];
        yield 'Agency, standard' => [4, 'standard', 14900, 59600];
        yield 'Agency Plus, founding' => [12, 'founding', 14900, 178800];
        yield 'Agency Plus, standard' => [12, 'standard', 14900, 178800];
        yield 'Enterprise, founding' => [60, 'founding', 12900, 774000];
        yield 'Enterprise, standard' => [60, 'standard', 12900, 774000];
        yield 'principal alone' => [1, 'founding', 14900, 29800];
    }

    #[DataProvider('partnerRates')]
    public function test_a_partner_code_sets_the_partner_rate_of_the_tier(int $headcount, string $when, int $rate, int $total): void
    {
        $quote = $this->pricing->quote($headcount, $when === 'founding' ? $this->foundingDate : $this->standardDate, PartnerCode::factory()->make());

        $this->assertTrue($quote->partnerCodeValid);
        $this->assertTrue($quote->partnerRateApplied);
        $this->assertSame($rate, $quote->rate);
        $this->assertSame($total, $quote->total);
    }

    public function test_partner_rates_come_from_the_config(): void
    {
        config(['agency.partner_rates.agency_50plus' => 11900]);

        $this->assertSame(11900, $this->pricing->quote(60, $this->foundingDate, PartnerCode::factory()->make())->rate);
        $this->assertSame(11900, AgencyPricingService::partnerRate(AgencyTier::AgencyEnterprise));
    }

    public function test_a_partner_rate_above_the_tier_rate_does_not_stack(): void
    {
        config(['agency.partner_rates.agency_50plus' => 15000]);

        $quote = $this->pricing->quote(60, $this->foundingDate, PartnerCode::factory()->make());

        $this->assertTrue($quote->partnerCodeValid);
        $this->assertFalse($quote->partnerRateApplied);
        $this->assertSame(12900, $quote->rate);
    }

    public function test_invalid_partner_codes_are_ignored(): void
    {
        $codes = [
            PartnerCode::factory()->inactive()->make(),
            PartnerCode::factory()->make(['expires_at' => $this->foundingDate->subDay()]),
        ];

        foreach ($codes as $code) {
            $quote = $this->pricing->quote(4, $this->foundingDate, $code);

            $this->assertFalse($quote->partnerCodeValid);
            $this->assertSame(63600, $quote->total);
        }

        // Validity is checked at the payment moment.
        $expiringLater = PartnerCode::factory()->make(['expires_at' => $this->foundingDate->addDay()]);
        $this->assertTrue($this->pricing->quote(4, $this->foundingDate, $expiringLater)->partnerRateApplied);
    }

    public function test_surcharge_applies_to_credit_cards_only(): void
    {
        $credit = $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit');

        $this->assertSame(178800, $credit->subtotal);
        $this->assertSame(5185, $credit->surcharge); // 2.9% of $1,788 = $51.852
        $this->assertSame(183985, $credit->total);

        $this->assertSame(1844, $this->pricing->quote(4, $this->foundingDate, cardFunding: 'credit')->surcharge); // $18.444

        foreach (['debit', 'prepaid', 'unknown', null] as $funding) {
            $quote = $this->pricing->quote(12, $this->foundingDate, cardFunding: $funding);

            $this->assertSame(0, $quote->surcharge);
            $this->assertSame(178800, $quote->total);
        }
    }

    public function test_no_surcharge_for_a_payer_in_an_exempt_state(): void
    {
        // Empty by default: every state pays it.
        $this->assertSame([], config('agency.surcharge_exempt_states'));
        $this->assertSame(5185, $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit', payerState: 'CT')->surcharge);

        config(['agency.surcharge_exempt_states' => ['CT', 'MA']]);

        $exempt = $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit', payerState: 'ct');
        $this->assertSame(0, $exempt->surcharge);
        $this->assertSame(178800, $exempt->total);
        $this->assertSame(5185, $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit', payerState: 'TX')->surcharge);
        $this->assertSame(5185, $this->pricing->quote(12, $this->foundingDate, cardFunding: 'credit')->surcharge);

        // Renewals: the agency's legal address.
        $agency = Agency::factory()->make(['rate_type' => RateType::Founding, 'legal_address' => '12 Elm Street, Suite 4, Hartford, CT 06103']);
        $agency->headcount = 12;
        $this->assertSame('CT', $agency->legalState());
        $this->assertSame(0, $this->pricing->quoteForAgency($agency, $this->foundingDate, 'credit')->surcharge);

        $agency->legal_address = '500 Congress Ave, Austin, TX 78701-1234';
        $this->assertSame('TX', $agency->legalState());
        $this->assertSame(5185, $this->pricing->quoteForAgency($agency, $this->foundingDate, 'credit')->surcharge);
    }

    public function test_surcharge_is_charged_on_the_discounted_amount(): void
    {
        $quote = $this->pricing->quote(4, $this->foundingDate, PartnerCode::factory()->make(), 'credit');

        $this->assertSame(59600, $quote->subtotal);
        $this->assertSame(1728, $quote->surcharge); // 2.9% of $596 = $17.284
    }

    public function test_quote_for_an_agency_uses_its_headcount_locked_rate_and_partner_code(): void
    {
        $agency = Agency::factory()->make(['rate_type' => RateType::Founding]);
        $agency->headcount = 4;
        $agency->setRelation('partnerCode', PartnerCode::factory()->make());

        $quote = $this->pricing->quoteForAgency($agency, $this->standardDate);

        $this->assertSame(RateType::Founding, $quote->rateType);
        $this->assertSame(14900, $quote->rate);
        $this->assertSame(59600, $quote->total);
    }

    public function test_stripe_price_follows_tier_and_rate_type(): void
    {
        config(['agency.tiers.agency_10to49.stripe_prices.standard.price_id' => 'price_123']);

        $this->assertSame('agency_founding_2to9', $this->pricing->quote(4, $this->foundingDate)->stripePrice()['lookup_key']);
        $this->assertSame('agency_founding_50plus', $this->pricing->quote(60, $this->foundingDate)->stripePrice()['lookup_key']);
        $this->assertSame(
            ['lookup_key' => 'agency_standard_10to49', 'price_id' => 'price_123'],
            $this->pricing->quote(12, $this->standardDate)->stripePrice(),
        );
    }

    public function test_summary_matches_the_live_price_box(): void
    {
        $this->assertSame('12 people · Agency Plus · $149 each · $1,788 a year', $this->pricing->quote(12, $this->foundingDate)->summary());
        $this->assertSame('$7,740', Money::format(774000));
        $this->assertSame('$51.85', Money::format(5185));
    }
}
