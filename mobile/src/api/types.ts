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
  bio?: string | null;
  qualification?: string | null;
  experience_years?: number | null;
  consultation_fee?: number;
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
  email?: string | null;
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
  avatar_url?: string | null;
  /** Active services — only on GET /doctors/{id}. */
  services?: ServiceItem[];
  offers_video?: boolean;
  /** Patient-facing traffic light set by the doctor: green / orange / red. */
  live_status?: 'green' | 'orange' | 'red';
  live_status_label?: string;
  live_status_description?: string;
  /** e.g. { Mon: '08:00–17:00' } — days without hours are omitted. */
  operating_hours?: Record<string, string>;
}

/** Radiology / laboratory / allied provider (GET /partners). */
export interface Partner {
  id: number;
  company_name: string;
  service_type: string | null;
  category: string;
  description: string | null;
  rating_avg: number;
  rating_count: number;
  accepts_walk_ins: boolean;
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
  prescription?: {
    id: number;
    notes: string | null;
    items?: { id: number; medicine_name: string; dosage: string | null; frequency: string | null; duration_days: number | null; instructions: string | null }[];
  } | null;
}

export interface Appointment {
  id: number;
  booking_ref: string;
  date: string;
  start_time: string;
  end_time: string;
  visit_type: 'clinic' | 'telemed' | 'home' | 'phone';
  status: 'pending' | 'confirmed' | 'checked_in' | 'completed' | 'cancelled' | 'no_show' | 'matching';
  reason: string | null;
  requested_specialty?: string | null;
  // NOTE: `mode`/`meet_url` columns exist on the appointments table (see
  // backend/database/migrations/2026_09_23_100011_add_video_fields_to_appointments_table.php)
  // but AppointmentResource does not serialize them yet — optional here so the
  // UI degrades gracefully until the backend exposes them.
  mode?: 'in_person' | 'video' | 'callback';
  meet_url?: string | null;
  /** Call-back consult (orange triage call back / booked "call back"); tel/wa.me links only for the doctor. */
  is_callback?: boolean;
  callback?: import('../services/consults').CallbackInfo | null;
  // In-app video (join URL itself comes from GET /appointments/{id}/video).
  is_video?: boolean;
  can_join_video?: boolean;
  video_ended_at?: string | null;
  doctor?: Doctor;
  clinic?: Clinic;
  patient?: PatientProfile;
  service?: ServiceItem | null;
  // Only present when the backend eager-loads the `encounter` relation —
  // GET /doctor/appointments/{id}/encounter does (used by EncounterScreen).
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
  patient?: { id: number; name?: string; phone?: string | null; user?: { name: string; phone: string | null } };
  // Lifecycle contract (server): is_active = not terminal AND not stale.
  is_active?: boolean;
  is_terminal?: boolean;
  is_expired?: boolean;
  status_label?: string;
  dispatch_state?: string | null;
  radius_km?: number | null;
  pickup_notes?: string | null;
  /** Patient owner only, while the ride is live. */
  trip_pin?: string | null;
  /** Drivers only. */
  pin_required?: boolean;
  rider?: RiderProfile;
}

/** "Who am I picking up" card for drivers (App\Services\Dispatch\RiderProfile). */
export interface RiderProfile {
  first_name: string;
  initials: string;
  rating: number | null;
  rating_count: number;
  rating_label: string;
  trips_completed: number;
  member_since: string | null;
  verified: { phone: boolean; id: boolean; medical_aid: boolean };
  needs: string[];
  pickup_notes: string | null;
  full_name: string | null;
  photo_url: string | null;
  phone: string | null;
  booked_by: { name: string; phone: string | null } | null;
  emergency_contact: { name: string | null; phone: string } | null;
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
  discount_amount?: number | null;
  promo_code?: string | null;
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

export interface MedicalScheme {
  id: number;
  name: string;
}

export interface EmergencyContact {
  id: number;
  label: string;
  phone: string;
  tel_url: string;
  is_primary: boolean;
  sort_order: number;
}

export interface LatestTriage {
  reference: string;
  level: 'red' | 'orange' | 'yellow' | 'green';
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  links?: unknown;
  meta?: unknown;
}

// --- Search, booking sessions & doctor settings ---------------------------

export interface ServiceItem {
  id: number;
  clinic_id: number;
  doctor_profile_id: number | null;
  name: string;
  category: string | null;
  description: string | null;
  price: number;
  duration_minutes: number;
  is_active: boolean;
}

export type SearchResultType = 'speciality' | 'service_category' | 'condition' | 'symptom' | 'doctor' | 'clinic' | 'product';

export interface SearchResult {
  type: SearchResultType;
  id?: number | string | null;
  name: string;
  subtitle?: string | null;
  specialization?: string | null;
  urgent?: boolean;
  avatar_url?: string | null;
  logo_url?: string | null;
  rating_avg?: number;
  rating_count?: number;
  product?: Product;
}

export interface SearchGroups {
  specialities: SearchResult[];
  conditions: SearchResult[];
  doctors: SearchResult[];
  clinics: SearchResult[];
  products: SearchResult[];
}

export interface SlotCell {
  time: string;
  available: boolean;
  booked: boolean;
  past: boolean;
  blocked: boolean;
}

export interface SlotSession {
  label: string;
  start: string;
  end: string;
  slot_minutes: number;
  slots: SlotCell[];
}

export interface SlotSchedule {
  slots: string[];
  sessions?: SlotSession[];
  time_off?: string | null;
}

export interface DoctorTimeOffItem {
  id: number;
  date: string;
  end_date: string | null;
  start_time: string | null;
  end_time: string | null;
  reason: string | null;
}

export interface DoctorSessionItem {
  id?: number;
  clinic_id: number;
  clinic_name?: string | null;
  weekday: number;
  start_time: string;
  end_time: string;
  slot_minutes: number;
  is_active: boolean;
}

export interface AppPageSection {
  type: 'heading' | 'paragraph' | 'bullet';
  text: string;
  title?: string;
}

export interface AppPage {
  key: string;
  title: string;
  slug: string;
  url: string;
  updated_at: string | null;
  content: AppPageSection[];
}
