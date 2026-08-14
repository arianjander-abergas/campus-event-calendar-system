const SUPABASE_URL = "https://fgwaeugfkrljgbbgvaox.supabase.co";
const SUPABASE_ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc";

let supabaseClient = null;

function getSupabaseClient() {
  if (supabaseClient) return supabaseClient;
  if (typeof window.supabase === "undefined") {
    console.warn("[Supabase] JS library not loaded — add the CDN script tag. Falling back to mock mode.");
    return null;
  }
  supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  return supabaseClient;
}

const isSupabaseConfigured = () => SUPABASE_URL.startsWith("https://YOUR-PROJECT-REF") === false;

const db = {
  async getEvents() {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("events")
        .select("*, categories(name)")
        .eq("is_published", true)
        .order("start_date", { ascending: true });
      if (!error) return data;
      console.error("[Supabase] getEvents error:", error);
    }
    return window.MOCK_EVENTS || [];
  },

  async getNotifications(userId) {
    if (isSupabaseConfigured() && userId) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("notifications")
        .select("*")
        .eq("user_id", userId)
        .order("created_at", { ascending: false });
      if (!error) return data;
      console.error("[Supabase] getNotifications error:", error);
    }
    return window.MOCK_ANNOUNCEMENTS || [];
  },

  async registerForEvent(eventId, userId) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("event_registrations")
        .insert([{ event_id: eventId, user_id: userId, status: "registered" }]);
      if (!error) return data;
      console.error("[Supabase] registerForEvent error:", error);
    }
    console.info("[Mock mode] Would register user", userId, "for event", eventId);
    return { mock: true };
  },

  async getAttendeeCount(eventId) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { count, error } = await client
        .from("event_registrations")
        .select("*", { count: "exact", head: true })
        .eq("event_id", eventId)
        .eq("status", "registered");
      if (!error) return count;
      console.error("[Supabase] getAttendeeCount error:", error);
    }
    return 0;
  },

  async signIn(email, password) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      return client.auth.signInWithPassword({ email, password });
    }
    console.info("[Mock mode] Would sign in as", email);
    return { data: null, error: { message: "Supabase not configured (mock mode)." } };
  },
};

window.db = db;