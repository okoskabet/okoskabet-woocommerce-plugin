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

// Answers already fetched on this page, by request. The pickers are torn down
// and rebuilt every time WooCommerce recalculates the checkout, and each
// rebuild asked again — a full round trip to the shop for dates it already
// had. A failed lookup is forgotten, so the next rebuild tries again.
const answers = new Map<string, Promise<ApiResponse>>();

export async function callApi(shippingMethod: ShippingMethod, address: string, postalCode: string): Promise<ApiResponse> {
  const params: Record<string, string> = {
    zip: postalCode,
    address: encodeURIComponent(address),
  };
  const productIds = readCartProductIds();
  if (productIds) {
    params.product_ids = productIds;
  }
  // A pre-order is offered its own days, a normal order the normal ones.
  const preOrder = document.getElementById('billing_okoskabet_pre_order') as HTMLInputElement | null;
  if (preOrder?.value === '1') {
    params.pre_order = '1';
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
  const known = answers.get(url);
  if (known) {
    return known;
  }

  const answer = fetch(url, requestOptions)
    .then((response) => response.json())
    .then(({ results: results }) => ({ ...results, type: shippingMethod }));
  answers.set(url, answer);
  answer.catch(() => answers.delete(url));
  return answer;
}
