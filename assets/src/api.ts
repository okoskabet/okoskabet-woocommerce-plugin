import type { ApiResponse, HomeDeliveryResponse, ShedsResponse, ShippingMethod } from "./types";

function getUrl(shippingMethod: ShippingMethod): string {
  switch (shippingMethod) {
    case 'shed-delivery':
      return "/wp-json/wp/v2/okoskabet/sheds?"
    case 'home-delivery':
      return "/wp-json/wp/v2/okoskabet/home_delivery?"
  }
}

/**
 * Read product IDs from the WooCommerce checkout page if we're on it.
 * The plugin emits a hidden field `<input id="okoskabet-cart-product-ids">`
 * containing a comma-separated list of product IDs server-side.
 *
 * Falling back to an empty list (no IDs) means the server-side filter will
 * skip exception filtering entirely — same behaviour as before this change.
 */
function readCartProductIds(): string {
  if (typeof document === 'undefined') return '';
  const el = document.getElementById('okoskabet-cart-product-ids') as HTMLInputElement | null;
  return el?.value ?? '';
}

export async function callApi(shippingMethod: 'shed-delivery', address: string, postalCode: string): Promise<ShedsResponse>
export async function callApi(shippingMethod: 'home-delivery', address: string, postalCode: string): Promise<HomeDeliveryResponse>
export async function callApi(shippingMethod: ShippingMethod, address: string, postalCode: string): Promise<ApiResponse>

export async function callApi(shippingMethod: ShippingMethod, address: string, postalCode: string): Promise<ApiResponse> {
  const params: Record<string, string> = {
    zip: postalCode,
    address: encodeURIComponent(address),
  };
  const productIds = readCartProductIds();
  if (productIds) {
    params.product_ids = productIds;
  }
  const queryParams = new URLSearchParams(params).toString();

  const myHeaders = new Headers();
  myHeaders.append("Accept", "application/json");
  myHeaders.append("Content-Type", "application/json");

  const requestOptions: RequestInit = {
    method: "GET",
    headers: myHeaders,
    redirect: "follow",
    // Send WordPress / WooCommerce session cookies so the request is
    // authenticated as the same browser session.
    credentials: "same-origin",
  };

  const url = getUrl(shippingMethod) + queryParams;
  const response = await fetch(url, requestOptions);
  const { results: results } = await response.json();
  return { ...results, type: shippingMethod };
}
