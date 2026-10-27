<?php

/**
 * Two additions to one delivery, written at the same moment.
 *
 * Each read the earlier order's list of additions, sent the whole list of
 * goods with its own at the bottom, and then wrote the list back with itself
 * on it. Overlapping, the second PUT replaced the first one's goods and the
 * second list replaced the first one's id: one addition lost, at Økoskabet and
 * in the order, with nothing to ever put it back. One after the other they
 * were always fine.
 *
 * Økoskabet is modelled as "the last list of goods written wins". The overlap
 * is made by running the second addition inside the first one's PUT, the way
 * a second request would run while the first waits for Økoskabet.
 */
class TwoAdditionsAtOnceTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,string>|null The goods Økoskabet holds, by name. */
	private $holds = null;

	/** @var callable|null Runs once, while the first addition's PUT is in flight. */
	private $meanwhile = null;

	/** @var int What a PUT is answered with. */
	private $put_code = 200;

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->holds     = null;
		$this->meanwhile = null;
		$this->put_code  = 200;

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				if ($this->meanwhile !== null) {
					$meanwhile       = $this->meanwhile;
					$this->meanwhile = null;
					$meanwhile();
				}
				$body = json_decode((string) $args['body'], true);
				if ($this->put_code === 200 && isset($body['line_items'])) {
					$this->holds = array_column($body['line_items'], 'name');
				}

				return array('response' => array('code' => $this->put_code), 'body' => '{}');
			}
			if (strpos((string) $url, '/api/v1/shipments/') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
					'shipment_reference' => 'x', 'status' => 'registered', 'notes' => '',
				)));
			}
			if (strpos((string) $url, '/api/v1/configuration') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(array('order_cutoff' => array(
					'time' => '23:59:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen',
				))));
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/** One after the other, as a bulk "mark processing" does it: both ride along. */
	public function test_two_additions_one_after_the_other_both_ride_along(): void
	{
		$parent = $this->a_delivery();
		$bread  = $this->an_addition_to($parent, 'Rugbrød');
		$butter = $this->an_addition_to($parent, 'Smør');

		wc_get_order($bread->get_id())->update_status('processing');
		wc_get_order($butter->get_id())->update_status('processing');

		$this->assertSame(array('Økologiske æg', 'ADDITION — Rugbrød', 'ADDITION — Smør'), $this->holds);
		$this->assertSame(array($bread->get_id(), $butter->get_id()), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/**
	 * One arriving while another is being written is held for the shop, and
	 * the one being written gets on whole. Neither is lost without a word.
	 *
	 * @dataProvider with_and_without_an_earlier_addition
	 */
	public function test_an_addition_arriving_while_another_is_written_is_held(bool $earlier): void
	{
		$parent = $this->a_delivery();
		$goods  = array('Økologiske æg');
		$ids    = array();
		if ($earlier) {
			$eggs = $this->an_addition_to($parent, 'Æblemost');
			wc_get_order($eggs->get_id())->update_status('processing');
			$goods[] = 'ADDITION — Æblemost';
			$ids[]   = $eggs->get_id();
		}
		$bread  = $this->an_addition_to($parent, 'Rugbrød');
		$butter = $this->an_addition_to($parent, 'Smør');

		$this->meanwhile = static function () use ($butter, $parent) {
			oko_send_addition_to_its_order(wc_get_order($butter->get_id()), $parent->get_id(), array());
		};
		oko_send_addition_to_its_order(wc_get_order($bread->get_id()), $parent->get_id(), array());

		$goods[] = 'ADDITION — Rugbrød';
		$ids[]   = $bread->get_id();
		$this->assertSame($goods, $this->holds, 'The addition being written gets on whole.');
		$this->assertSame($ids, oko_addition_ids(wc_get_order($parent->get_id())));

		$butter = wc_get_order($butter->get_id());
		$this->assertSame('on-hold', $butter->get_status(), 'The other is held, so the shop sees it did not get on.');
		$this->assertSame('', (string) $butter->get_meta('billing_okoskabet_done', true));
		$this->assertCount(1, $this->notes_saying($butter, 'was being written at the same moment'));

		$this->assertFalse($this->held($parent), 'And the hold is let go of.');
	}

	/** @return array<string,array{0:bool}> */
	public function with_and_without_an_earlier_addition(): array
	{
		return array('the first additions' => array(false), 'after an earlier addition' => array(true));
	}

	/** The held one gets on when the shop sets it to processing, as the note says. */
	public function test_the_held_addition_gets_on_when_tried_again(): void
	{
		$parent = $this->a_delivery();
		$bread  = $this->an_addition_to($parent, 'Rugbrød');
		$butter = $this->an_addition_to($parent, 'Smør');
		$this->meanwhile = static function () use ($butter, $parent) {
			oko_send_addition_to_its_order(wc_get_order($butter->get_id()), $parent->get_id(), array());
		};
		oko_send_addition_to_its_order(wc_get_order($bread->get_id()), $parent->get_id(), array());

		wc_get_order($butter->get_id())->update_status('processing');

		$this->assertSame(array('Økologiske æg', 'ADDITION — Rugbrød', 'ADDITION — Smør'), $this->holds);
		$this->assertSame('yes', (string) wc_get_order($butter->get_id())->get_meta('billing_okoskabet_done', true));
	}

	/** A hold nobody let go of is taken over once it is old enough. */
	public function test_a_hold_nobody_let_go_of_is_taken_over(): void
	{
		$parent = $this->a_delivery();
		$this->a_hold_from(time() - OKO_ADDITION_LOCK_SECONDS - 5, $parent);
		$bread = $this->an_addition_to($parent, 'Rugbrød');

		wc_get_order($bread->get_id())->update_status('processing');

		$this->assertSame(array('Økologiske æg', 'ADDITION — Rugbrød'), $this->holds);
		$this->assertFalse($this->held($parent));
	}

	/** But a fresh one is honoured: that write may still be waiting on Økoskabet. */
	public function test_a_fresh_hold_is_honoured(): void
	{
		$parent = $this->a_delivery();
		$this->a_hold_from(time() - 5, $parent);
		$bread = $this->an_addition_to($parent, 'Rugbrød');

		wc_get_order($bread->get_id())->update_status('processing');

		$this->assertNull($this->holds, 'Nothing was written.');
		$this->assertSame('on-hold', wc_get_order($bread->get_id())->get_status());
		$this->assertTrue($this->held($parent), 'The other request still has it.');
	}

	/** A write Økoskabet refused lets go of the hold too. */
	public function test_a_refused_write_lets_go_of_the_hold(): void
	{
		$parent         = $this->a_delivery();
		$bread          = $this->an_addition_to($parent, 'Rugbrød');
		$this->put_code = 500;

		wc_get_order($bread->get_id())->update_status('processing');

		$this->assertSame('on-hold', wc_get_order($bread->get_id())->get_status());
		$this->assertFalse($this->held($parent));
	}

	/**
	 * A cancellation arriving while an addition is written cannot be held; it
	 * says on the cancelled order that its goods may still be on the delivery.
	 */
	public function test_a_cancellation_while_an_addition_is_written_says_so(): void
	{
		$parent = $this->a_delivery();
		$bread  = $this->an_addition_to($parent, 'Rugbrød');
		wc_get_order($bread->get_id())->update_status('processing');
		$butter = $this->an_addition_to($parent, 'Smør');

		$this->meanwhile = static function () use ($bread) {
			wc_get_order($bread->get_id())->update_status('cancelled');
		};
		oko_send_addition_to_its_order(wc_get_order($butter->get_id()), $parent->get_id(), array());

		$this->assertCount(1, $this->notes_saying(wc_get_order($bread->get_id()), 'was being written at the same moment'));
		$this->assertSame(array($bread->get_id(), $butter->get_id()), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/** An earlier order at Økoskabet, its day still open, with the record of what was sent. */
	private function a_delivery(): \WC_Order
	{
		$parent = wc_create_order();
		$parent->add_product($this->a_product('Økologiske æg'), 2);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$parent->add_item($shipping);
		$parent->set_billing_email('pernille@example.org');
		$parent->set_shipping_first_name('Pernille');
		$parent->set_shipping_last_name('Hansen');
		$parent->set_shipping_address_1('Eksempelvej 12');
		$parent->set_shipping_city('Beder');
		$parent->set_shipping_postcode('8330');
		$parent->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+6 days')));
		$parent->update_meta_data('billing_okoskabet_done', true);
		$parent->set_status('processing');
		$parent->calculate_totals(false);
		$parent->save();
		oko_keep_booked_reference($parent, (string) $parent->get_order_number());
		$parent->save_meta_data();

		$parent = wc_get_order($parent->get_id());
		oko_remember_what_was_sent($parent, oko_shipment_payload($parent, oko_merchant_for_order($parent)));
		$parent->save_meta_data();

		return wc_get_order($parent->get_id());
	}

	/** An addition paid by bank transfer, waiting for the shop to say the money is in. */
	private function an_addition_to(\WC_Order $parent, string $product): \WC_Order
	{
		$addition = wc_create_order();
		$addition->add_product($this->a_product($product), 1);
		$addition->set_payment_method('bacs');
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());
		$addition->calculate_totals(false);
		$addition->set_status('on-hold');
		$addition->save();

		return wc_get_order($addition->get_id());
	}

	/** A hold on the order's additions, taken at this time by some other request. */
	private function a_hold_from(int $when, \WC_Order $parent): void
	{
		global $wpdb;
		$wpdb->insert($wpdb->options, array(
			'option_name'  => OKO_ADDITION_LOCK_PREFIX . $parent->get_id(),
			'option_value' => (string) $when,
			'autoload'     => 'no',
		));
	}

	private function held(\WC_Order $parent): bool
	{
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
			OKO_ADDITION_LOCK_PREFIX . $parent->get_id()
		)) === '1';
	}

	/** @return array<int,string> */
	private function notes_saying(\WC_Order $order, string $needle): array
	{
		$notes = array();
		foreach (wc_get_order_notes(array('order_id' => $order->get_id())) as $note) {
			if (strpos((string) $note->content, $needle) !== false) {
				$notes[] = (string) $note->content;
			}
		}

		return $notes;
	}

	private function a_product(string $name): \WC_Product
	{
		$product = new \WC_Product_Simple();
		$product->set_name($name);
		$product->set_regular_price('10');
		$product->save();

		return $product;
	}
}
