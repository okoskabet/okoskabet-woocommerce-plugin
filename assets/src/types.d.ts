/// <reference types="@wordpress/blocks" />
import { BlockAttributes } from '@wordpress/blocks';

interface Shed {
  id: string;
  name: string;
  address: {
    address: string;
    city: string;
    latitude: number;
    longitude: number;
    postal_code: string;
  };
  delivery_dates: string[];
}

type Origin = { latitude: number, longitude: number } | null;

interface ShedsResponse {
  type: 'shed-delivery';
  origin: Origin;
  sheds: Shed[];
}

interface HomeDeliveryResponse {
  type: 'home-delivery';
  origin: Origin;
  delivery_dates: string[];
}

type ApiResponse = ShedsResponse | HomeDeliveryResponse

/**
 * Admin script types
 */
interface ExampleDemo {
  nonce: string;
  wp_rest: string;
  alert: string;
}

declare global {
  interface Window {
    exampleDemo: ExampleDemo;
  }
}
