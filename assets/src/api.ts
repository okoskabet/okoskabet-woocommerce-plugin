import type { ApiResponse, HomeDeliveryResponse, ShedsResponse, ShippingMethod } from "./types";

function getUrl(shippingMethod: ShippingMethod): string {
  switch (shippingMethod) {
    case 'shed-delivery':
      return "/wp-json/wp/v2/okoskabet/sheds?"
    case 'home-delivery':
      return "/wp-json/wp/v2/okoskabet/home_delivery?"
  }
}

export async function callApi(shippingMethod: 'shed-delivery', address: string, postalCode: string): Promise<ShedsResponse>
export async function callApi(shippingMethod: 'home-delivery', address: string, postalCode: string): Promise<HomeDeliveryResponse>
export async function callApi(shippingMethod: ShippingMethod, address: string, postalCode: string): Promise<ApiResponse>

export async function callApi(shippingMethod: ShippingMethod, address: string, postalCode: string): Promise<ApiResponse> {
  const queryParams = new URLSearchParams({
    zip: postalCode,
    address: encodeURIComponent(address),
  }).toString()

  const myHeaders = new Headers();
  myHeaders.append("Accept", "application/json");
  myHeaders.append("Content-Type", "application/json");

  const requestOptions: RequestInit = {
    method: "GET",
    headers: myHeaders,
    redirect: "follow"
  };

  const url = getUrl(shippingMethod) + queryParams;
  const response = await fetch(url, requestOptions);
  const { results: results } = await response.json();
  return { ...results, type: shippingMethod };
}
