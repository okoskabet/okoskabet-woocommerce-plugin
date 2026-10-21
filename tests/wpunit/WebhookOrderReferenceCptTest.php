<?php

require_once __DIR__ . '/WebhookOrderReferenceCase.php';

/** The posts table, where orders lived before HPOS. */
class WebhookOrderReferenceCptTest extends WebhookOrderReferenceCase {

	protected function hpos(): bool {
		return false;
	}
}
