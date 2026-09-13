export type Role = 'super_admin' | 'clinic_admin' | 'doctor' | 'driver' | 'patient' | 'third_party' | 'pharmacy_admin';

export interface PatientProfile {
  id: number;
  dob: string | null;
  gender: string | null;
  blood_group: string | null;
  address: string | null;
  lat: number | null;
  lng: number | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
}

export interface DriverProfile {
  id: number;
  name: string;
  phone: string;
  vehicle_make: string | null;
  vehicle_model: string | null;
  vehicle_plate_no: string | null;
  vehicle_type: string;
  availability: 'offline' | 'available' | 'busy';
  rating_avg: number;
  status: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: Role;
  avatar: string | null;
  status: string;
  patient_profile?: PatientProfile;
  driver_profile?: DriverProfile;
}

export interface Clinic {
  id: number;
  name: string;
  slug: string;
  address: string;
  city: string;
  lat: number;
  lng: number;
  phone: string | null;
  specialties: string[];
}

export interface Doctor {
  id: number;
  name: string;
  specialization: string;
  qualification: string | null;
  experience_years: number;
  bio: string | null;
  consultation_fee: number;
  rating_avg: number;
  rating_count: number;
  clinics: Clinic[];
}

export interface Appointment {
  id: number;
  booking_ref: string;
  date: string;
  start_time: string;
  end_time: string;
  visit_type: 'clinic' | 'telemed' | 'home';
  status: 'pending' | 'confirmed' | 'checked_in' | 'completed' | 'cancelled' | 'no_show';
  reason: string | null;
  doctor?: Doctor;
  clinic?: Clinic;
}

export type VehicleType = 'sedan' | 'suv' | 'van' | 'wheelchair_accessible';

export interface Ride {
  id: number;
  ride_ref: string;
  vehicle_type: VehicleType;
  eta_minutes: number | null;
  pickup_address: string;
  pickup_lat: number;
  pickup_lng: number;
  dropoff_address: string;
  dropoff_lat: number;
  dropoff_lng: number;
  status: 'requested' | 'accepted' | 'driver_enroute' | 'arrived' | 'in_progress' | 'completed' | 'cancelled';
  distance_km: number | null;
  fare_estimate: number | null;
  fare_final: number | null;
  driver?: DriverProfile;
  patient?: { id: number; user?: { name: string; phone: string } };
}

export interface RideQuote {
  vehicle_type: VehicleType;
  distance_km: number;
  eta_minutes: number;
  fare: number;
}

export interface LabRequestItem {
  id: number;
  test_name: string;
  sample_type: string | null;
  notes: string | null;
}

export interface LabResult {
  id: number;
  label: string;
  file_path: string;
  summary: string | null;
  uploaded_at: string;
}

export interface LabRequest {
  id: number;
  request_ref: string;
  priority: 'routine' | 'urgent';
  clinical_notes: string | null;
  collection_address: string | null;
  status: 'requested' | 'accepted' | 'sample_collected' | 'processing' | 'completed' | 'cancelled';
  requested_at: string;
  doctor?: Doctor;
  patient?: { id: number; user?: { name: string; phone: string }; dob?: string };
  third_party?: { id: number; company_name: string; service_type: string } | null;
  items?: LabRequestItem[];
  results?: LabResult[];
}

export interface Pharmacy {
  id: number;
  name: string;
  slug: string;
  logo: string | null;
  description: string | null;
  address: string;
  city: string;
  lat: number;
  lng: number;
  delivery_fee: number;
  rating_avg: number;
  rating_count: number;
  products?: Product[];
}

export interface Product {
  id: number;
  pharmacy_id: number;
  pharmacy_name?: string;
  name: string;
  category: string | null;
  description: string | null;
  image: string | null;
  price: number;
  stock: number;
  requires_prescription: boolean;
  is_active: boolean;
}

export interface OrderItem {
  id: number;
  product_name: string;
  qty: number;
  unit_price: number;
  amount: number;
}

export interface Order {
  id: number;
  order_no: string;
  subtotal: number;
  delivery_fee: number;
  total: number;
  status: string;
  payment_status: string;
  delivery_address: string;
  pharmacy?: Pharmacy;
  items?: OrderItem[];
  created_at: string;
}

export interface PrescriptionUpload {
  id: number;
  file_path: string;
  status: 'pending_review' | 'approved' | 'rejected';
  notes: string | null;
  created_at: string;
}

export interface Invoice {
  id: number;
  invoice_no: string;
  total: number;
  status: string;
  due_date: string | null;
  clinic?: Clinic;
}

export interface Paginated<T> {
  data: T[];
  links?: unknown;
  meta?: unknown;
}
