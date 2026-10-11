import React from 'react';
import { WidgetProps } from './types';
import { NearbyClinics, PromoBanner, StatWidget, TopDoctors, WeeklyChart } from './shared';
import {
  ActivePrescriptions,
  ClinicServices,
  EmergencyQuickDial,
  FeaturedProducts,
  HealthArticles,
  HealthSummary,
  LabResultsWidget,
  LatestTriage,
  MedicalAidCard,
  NextAppointment,
  OutstandingInvoices,
  RecentEncounters,
  RecentOrders,
  SearchShortcuts,
  ShopCategories,
  Specialties,
  UpcomingRide,
  VisitStats,
} from './patient';
import {
  DoctorAvailability,
  DoctorMonthCalendar,
  DoctorPendingRequests,
  DoctorTodayAppointments,
  DriverNextPickup,
} from './provider';

type WidgetComponent = React.ComponentType<WidgetProps>;

/**
 * Widget key (from Site → App Widgets) → component. Keys the app doesn't
 * know are skipped by WidgetHost, so the backend can ship new widgets
 * ahead of an app update. Any key ending in `_banner` renders PromoBanner.
 */
export const WIDGET_REGISTRY: Record<string, WidgetComponent> = {
  // patient · home
  patient_search_shortcuts: SearchShortcuts,
  patient_next_appointment: NextAppointment,
  patient_latest_triage: LatestTriage,
  patient_visit_stats: VisitStats,
  patient_upcoming_ride: UpcomingRide,
  patient_clinic_services: ClinicServices,
  patient_top_doctors: TopDoctors,
  patient_nearby_clinics: NearbyClinics,
  patient_active_prescriptions: ActivePrescriptions,
  patient_pending_lab_results: LabResultsWidget,
  patient_recent_orders: RecentOrders,
  patient_outstanding_invoices: OutstandingInvoices,
  patient_medical_aid_card: MedicalAidCard,
  patient_emergency_quickdial: EmergencyQuickDial,
  patient_health_articles: HealthArticles,
  // patient · find care / shop / record
  care_specialties: Specialties,
  care_top_doctors: TopDoctors,
  care_nearby_clinics: NearbyClinics,
  shop_categories: ShopCategories,
  shop_featured_products: FeaturedProducts,
  record_health_summary: HealthSummary,
  record_recent_encounters: RecentEncounters,
  // doctor
  doctor_total_patients: StatWidget,
  doctor_total_appointments: StatWidget,
  doctor_weekly_chart: WeeklyChart,
  doctor_availability: DoctorAvailability,
  doctor_pending_requests: DoctorPendingRequests,
  doctor_today_appointments: DoctorTodayAppointments,
  doctor_rating: StatWidget,
  doctor_completed_month: StatWidget,
  doctor_month_calendar: DoctorMonthCalendar,
  // driver
  driver_next_pickup: DriverNextPickup,
  driver_today_trips: StatWidget,
  driver_week_earnings: StatWidget,
  driver_weekly_chart: WeeklyChart,
  driver_rating: StatWidget,
  // pharmacy
  pharmacy_pending_orders: StatWidget,
  pharmacy_pending_scripts: StatWidget,
  pharmacy_revenue_month: StatWidget,
  pharmacy_low_stock: StatWidget,
  // third-party partner
  tp_new_requests: StatWidget,
  tp_active_requests: StatWidget,
  tp_completed_month: StatWidget,
  tp_rating: StatWidget,
};

export function resolveWidget(key: string): WidgetComponent | null {
  if (WIDGET_REGISTRY[key]) return WIDGET_REGISTRY[key];
  if (key.endsWith('_banner')) return PromoBanner;
  return null;
}
