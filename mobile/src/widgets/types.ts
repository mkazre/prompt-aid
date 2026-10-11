/**
 * Config-driven widgets — shapes returned by `GET /app-widgets?include=data`
 * (see backend App\Support\Widgets\WidgetDataService for every payload).
 */
export type WidgetSettings = Record<string, string | undefined>;

export interface WidgetConfig<D = any> {
  key: string;
  title: string;
  subtitle: string | null;
  settings: WidgetSettings;
  data?: D | null;
}

/** Screen-level hooks a host screen can hand to its widgets. */
export interface WidgetContext {
  /** Re-fetch every widget on the screen (e.g. after an action inside one). */
  refresh: () => void;
  /** Doctor calendar: the date whose appointments the screen is listing. */
  selectedDate?: Date;
  onSelectDate?: (d: Date) => void;
  /** Screens with their own search/filter state (Find care, Shop). */
  onSearch?: (q: string) => void;
  onCategory?: (category: { id: number; name: string } | null) => void;
  onSpecialization?: (name: string | null) => void;
  onClinic?: (clinic: { id: number; name: string } | null) => void;
}

export interface WidgetProps<D = any> {
  config: WidgetConfig<D>;
  data: D | null | undefined;
  ctx: WidgetContext;
  navigation: any;
}

export interface StatPayload {
  value: string;
  caption: string | null;
  trend?: number[];
  rating?: number;
}

export interface BarsPayload {
  bars: { label: string; date: string; value: number }[];
  total: number;
}
