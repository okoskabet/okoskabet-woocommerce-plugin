<?php

require_once __DIR__ . '/WebhookOrderReferenceCase.php';

/** WooCommerce's own order tables (HPOS). */
class WebhookOrderReferenceHposTest extends WebhookOrderReferenceCase {

	protected function hpos(): bool {
		return true;
	}
}
