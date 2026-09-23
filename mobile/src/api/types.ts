export type Role = 'super_admin' | 'clinic_admin' | 'doctor' | 'driver' | 'patient' | 'third_party' | 'pharmacy_admin';

export interface PatientProfile {
  id: number;
  name?: string;
  phone?: string | null;
  avatar?: string | null;
  dob: string | null;
  gender: string | null;
  blood_group: string | null;
  address: string | null;
  lat: number | null;
  lng: number | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  allergies?: string | null;
  chronic_conditions?: string | null;
}

export interface DriverProfile {
  id: number;
  name: string;
  phone: string;
  license_no?: string | null;
  license_expiry?: string | null;
  vehicle_make: string | null;
  vehicle_model: string | null;
  vehicle_color?: string | null;
  vehicle_plate_no: string | null;
  vehicle_type: string;
  vehicle_photo?: string | null;
  availability: 'offline' | 'available' | 'busy';
  current_lat?: number | null;
  current_lng?: number | null;
  rating_avg: number;
  rating_count?: number;
  status: string;
}

export interface DoctorMiniProfile {
  id: number;
  name: string;
  specialization: string;
  status: string;
  is_accepting_appointments: boolean;
}

export type ThirdPartyCategory =
  | 'lab'
  | 'imaging'
  | 'physio'
  | 'optometry'
  | 'dental'
  | 'dietetics'
  | 'audiology'
  | 'home_nursing'
  | 'other';

export interface ThirdPartyProfile {
  id: number;
  company_name: string;
  service_type: string | null;
  category: ThirdPartyCategory;
  license_no: string | null;
  description: string | null;
  logo: string | null;
  rating_avg: number;
  rating_count: number;
  status: string;
  service_area: string[] | null;
  accepts_walk_ins: boolean;
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
  doctor_profile?: DoctorMiniProfile;
  third_party_profile?: ThirdPartyProfile;
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
  is_accepting_appointments: boolean;
  availability_summary: string | null;
  clinics: Clinic[];
}

export interface EncounterVitals {
  bp?: string;
  pulse?: string;
  temp?: string;
  spo2?: string;
  [key: string]: string | undefined;
}

export interface Encounter {
  id: number;
  vitals: EncounterVitals | null;
  chief_complaint: string | null;
  diagnosis: string | null;
  notes: string | null;
  follow_up_date: string | null;
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
  // NOTE: `mode`/`meet_url` columns exist on the appointments table (see
  // backend/database/migrations/2026_09_23_100011_add_video_fields_to_appointments_table.php)
  // but AppointmentResource does not serialize them yet — optional here so the
  // UI degrades gracefully until the backend exposes them.
  mode?: 'in_person' | 'video';
  meet_url?: string | null;
  doctor?: Doctor;
  clinic?: Clinic;
  patient?: PatientProfile;
  // Only present when the backend eager-loads the `encounter` relation, which
  // DoctorController::appointments() does not currently do — see doctor
  // screens for the TODOs tracking this gap.
  encounter?: Encounter | null;
}

export interface DoctorStats {
  total_patients: number;
  total_appointments: number;
  weekly_appointments: { day: string; date: string; count: number }[];
}

export interface DoctorPatient {
  id: number;
  name: string;
  phone: string | null;
  gender: string | null;
  dob: string | null;
  visits: number;
  last_visit: string | null;
}

export type VehicleType = 'sedan' | 'suv' | 'van' | 'wheelchair_accessible' | 'stretcher';

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
  priority?: 'normal' | 'emergency';
  distance_km: number | null;
  fare_estimate: number | null;
  fare_final: number | null;
  is_return: boolean;
  return_of_ride_id: number | null;
  wait_and_return: boolean;
  ride_series_id: number | null;
  scheduled_for: string | null;
  requested_at?: string | null;
  accepted_at?: string | null;
  started_at?: string | null;
  completed_at?: string | null;
  driver?: DriverProfile;
  patient?: { id: number; user?: { name: string; phone: string } };
}

export interface RideSeries {
  id: number;
  pattern: { days: number[]; time: string; until: string };
  pickup: { address: string; lat: number; lng: number };
  dropoff: { address: string; lat: number; lng: number };
  vehicle_type: VehicleType;
  active: boolean;
  created_at: string;
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
  slug?: string;
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
  patient?: PatientProfile;
  items?: OrderItem[];
  prescription_upload?: PrescriptionUpload | null;
  created_at: string;
}

export interface PrescriptionUpload {
  id: number;
  file_path: string;
  /** Absolute, viewable URL for the uploaded file (added on the vendor order endpoints). */
  file_url?: string | null;
  status: 'pending_review' | 'approved' | 'rejected';
  notes: string | null;
  created_at: string;
}

export interface InvoiceItem {
  id: number;
  description: string;
  qty: number;
  unit_price: number;
  amount: number;
}

export interface InvoicePayment {
  id: number;
  method: string;
  amount: number;
  status: string;
  gateway_ref: string | null;
  created_at: string;
}

export interface Invoice {
  id: number;
  invoice_no: string;
  subtotal?: number;
  tax?: number;
  discount?: number;
  total: number;
  status: string;
  due_date: string | null;
  clinic?: Clinic;
  items?: InvoiceItem[];
  payments?: InvoicePayment[];
}

export interface Paginated<T> {
  data: T[];
  links?: unknown;
  meta?: unknown;
}
