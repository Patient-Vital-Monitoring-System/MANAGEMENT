<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>VitalWear — Management Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/react@18/umd/react.development.js" crossorigin></script>
  <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js" crossorigin></script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body, #root { height: 100%; width: 100%; }
    body {
      background: #020c18;
      font-family: 'Inter', sans-serif;
      font-size: 15px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: optimizeLegibility;
      color: #c8dff0;
    }
    input, select, button, textarea { font-family: 'Inter', sans-serif; }
    input::placeholder { color: #4a6f8a; }
    select option { background: #0d1929; color: #f0f8ff; }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #020c18; }
    ::-webkit-scrollbar-thumb { background: #1e3a5f; border-radius: 3px; }
    @keyframes modalIn   { from { opacity:0; transform:scale(0.96); } to { opacity:1; transform:scale(1); } }
    @keyframes pulseRing { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
    @keyframes fadeIn    { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
    @keyframes spin      { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    .page-enter { animation: fadeIn 0.2s ease; }
  </style>
</head>
<body>
  <div id="root"></div>
  <script type="text/babel">
    const { useState, useEffect, useCallback, useRef } = React;

    // =========================================================================
    // API CONFIG
    // =========================================================================
    const API_BASE = "http://localhost/vitalwear-api";

    // DB_KEYS maps JS state keys to PHP API endpoints
    const DB_KEYS = {
      devices:    "vw_devices",
      responders: "vw_responders",
      rescuers:   "vw_rescuers",
      deviceLog:  "vw_device_log",
      incidents:  "vw_incidents",
      vitalStats: "vw_vitalstats",
    };

    const API_MAP = {
      vw_devices:    `${API_BASE}/devices.php`,
      vw_responders: `${API_BASE}/responders.php`,
      vw_rescuers:   `${API_BASE}/rescuers.php`,
      vw_device_log: `${API_BASE}/device_log.php`,
      vw_incidents:  `${API_BASE}/incidents.php`,
      vw_vitalstats: `${API_BASE}/vitalstats.php`,
    };

    // =========================================================================
    // FIELD MAP
    // "jsKey" → "exactColumnNameReturnedByPHP_GET"
    // The PHP GET responses use these exact key names — must match 1:1.
    // =========================================================================
    const FIELD_MAP = {
      vw_devices: {
        id:     "dev_id",
        serial: "dev_serial",
        name:   "dev_name",
        type:   "dev_type",
        status: "dev_status",
      },
      vw_responders: {
        id:             "resp_id",
        name:           "resp_name",
        email:          "resp_email",
        phone:          "resp_phone",       // PHP returns resp_phone (mapped from resp_contact)
        active:         "active",
        assignedDevice: "assigned_device",
      },
      vw_rescuers: {
        id:     "resc_id",
        name:   "resc_name",
        email:  "resc_email",
        phone:  "resc_phone",              // PHP returns resc_phone (mapped from resc_contact)
        active: "active",
      },
      vw_device_log: {
        id:             "log_id",
        deviceId:       "device_id",
        responderId:    "responder_id",
        dateAssigned:   "date_assigned",
        dateReturned:   "date_returned",
        verifiedReturn: "verified_return",
      },
      vw_incidents: {
        id:          "inc_id",
        responderId: "responder_id",
        type:        "type",
        severity:    "severity",
        status:      "status",
        date:        "date",
        location:    "location",
      },
      vw_vitalstats: {
        logId:     "log_id",
        heartRate: "heart_rate",
        spo2:      "spo2",
        bp:        "bp",
        temp:      "temp",
        timestamp: "timestamp",
      },
    };

    // Convert a PHP GET row → JS object the dashboard uses internally
    function dbRowToJs(key, row) {
      const map = FIELD_MAP[key];
      if (!map) return row;
      const out = {};
      for (const [jsKey, dbCol] of Object.entries(map)) {
        let val = row[dbCol];
        if (val === undefined) val = row[jsKey];   // fallback: already-mapped key
        if (val === undefined) val = null;
        // Coerce booleans
        if (val === "1" || val === 1)      val = true;
        if (val === "0" || val === 0)      val = false;
        if (val === "true")                val = true;
        if (val === "false")               val = false;
        // Coerce nulls
        if (val === "null" || val === "")  val = null;
        out[jsKey] = val;
      }
      return out;
    }

    // Convert JS array → array of objects using DB column names for POST body
    function jsArrayToDb(key, arr) {
      const map = FIELD_MAP[key];
      if (!map) return arr;
      return arr.map(obj => {
        const row = {};
        for (const [jsKey, dbCol] of Object.entries(map)) {
          // Send BOTH the db column name AND the js key so PHP can find it either way
          row[dbCol] = obj[jsKey] !== undefined ? obj[jsKey] : null;
          row[jsKey] = obj[jsKey] !== undefined ? obj[jsKey] : null;
        }
        return row;
      });
    }

    // Read-only tables — dashboard never writes to these (IoT devices write them)
    const READ_ONLY_KEYS = new Set(["vw_vitalstats"]);

    // Load all records from the API; fall back to seed only on error
    async function dbLoad(key, seed) {
      try {
        const url = API_MAP[key];
        if (!url) return seed;
        const res = await fetch(url, { cache: "no-cache" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const text = await res.text();
        if (!text || text.trim() === "") return seed;
        let rows;
        try { rows = JSON.parse(text); } catch { return seed; }
        if (!Array.isArray(rows)) return seed;
        if (rows.length === 0) return seed;
        return rows.map(row => dbRowToJs(key, row));
      } catch (e) {
        console.warn(`[dbLoad] ${key} failed, using seed:`, e.message);
        return seed;
      }
    }

    // Save the full current JS array -> POST to PHP (upsert + delete orphans)
    async function dbSave(key, data) {
      if (READ_ONLY_KEYS.has(key)) return;
      const url = API_MAP[key];
      if (!url) return;
      try {
        const body = jsArrayToDb(key, Array.isArray(data) ? data : [data]);
        const res  = await fetch(url, {
          method:  "POST",
          headers: { "Content-Type": "application/json" },
          body:    JSON.stringify(body),
        });
        if (!res.ok) {
          const t = await res.text();
          console.warn(`[dbSave] ${key} HTTP ${res.status}:`, t);
        }
      } catch (e) {
        console.warn(`[dbSave] ${key} fetch error:`, e.message);
      }
    }

    // =========================================================================
    // usePersistentState — loads from API on mount, saves on change
    // =========================================================================
    function usePersistentState(dbKey, seed) {
      const [value, setRaw]     = useState(seed);
      const [loaded, setLoaded] = useState(false);
      const isFirstLoad = useRef(true);

      useEffect(() => {
        dbLoad(dbKey, seed).then(data => {
          setRaw(data);
          setLoaded(true);
          isFirstLoad.current = false;
        });
      }, [dbKey]);

      const setValue = useCallback((updater) => {
        setRaw(prev => {
          const next = typeof updater === "function" ? updater(prev) : updater;
          dbSave(dbKey, next);
          return next;
        });
      }, [dbKey]);

      return [value, setValue, loaded];
    }

    // =========================================================================
    // SEED DATA
    // =========================================================================
    const SEED_DEVICES = [
      { id:"DEV001", serial:"VW-2024-001", name:"VitalBand Pro",  status:"available",   type:"Wristband"   },
      { id:"DEV002", serial:"VW-2024-002", name:"VitalBand Pro",  status:"assigned",    type:"Wristband"   },
      { id:"DEV003", serial:"VW-2024-003", name:"VitalPatch X1",  status:"assigned",    type:"Chest Patch" },
      { id:"DEV004", serial:"VW-2024-004", name:"VitalBand Lite", status:"maintenance", type:"Wristband"   },
      { id:"DEV005", serial:"VW-2024-005", name:"VitalPatch X1",  status:"available",   type:"Chest Patch" },
      { id:"DEV006", serial:"VW-2024-006", name:"VitalRing S2",   status:"available",   type:"Ring Sensor" },
      { id:"DEV007", serial:"VW-2024-007", name:"VitalRing S2",   status:"assigned",    type:"Ring Sensor" },
    ];
    const SEED_RESPONDERS = [
      { id:"R001", name:"Auggie Manginsay", email:"a.manginsay@vitawear.ph", phone:"09171234567", active:true,  assignedDevice:"DEV002" },
      { id:"R002", name:"Kristine Lopez",   email:"k.lopez@vitawear.ph",     phone:"09281234567", active:true,  assignedDevice:"DEV003" },
      { id:"R003", name:"Clint Tambacan",   email:"c.tambacan@vitawear.ph",  phone:"09391234567", active:false, assignedDevice:null     },
      { id:"R004", name:"Roland Magdura",   email:"r.magdura@vitawear.ph",   phone:"09451234567", active:true,  assignedDevice:"DEV007" },
      { id:"R005", name:"Cliff Amadeus",    email:"c.amadeus@vitawear.ph",   phone:"09561234567", active:true,  assignedDevice:null     },
    ];
    const SEED_RESCUERS = [
      { id:"RC001", name:"Jino Manginsay",   email:"j.manginsay@rescue.ph", phone:"09561234567", active:true  },
      { id:"RC002", name:"Clint Lopez",      email:"c.lopez@rescue.ph",     phone:"09671234567", active:true  },
      { id:"RC003", name:"Kristine Magdura", email:"k.magdura@rescue.ph",   phone:"09781234567", active:false },
      { id:"RC004", name:"Auggie Tambacan",  email:"a.tambacan@rescue.ph",  phone:"09891234567", active:true  },
    ];
    const SEED_DEVICE_LOG = [
      { id:"LOG001", deviceId:"DEV002", responderId:"R001", dateAssigned:"2025-06-01", dateReturned:"2025-06-08", verifiedReturn:true  },
      { id:"LOG002", deviceId:"DEV003", responderId:"R002", dateAssigned:"2025-06-10", dateReturned:null,         verifiedReturn:false },
      { id:"LOG003", deviceId:"DEV007", responderId:"R004", dateAssigned:"2025-06-12", dateReturned:null,         verifiedReturn:false },
      { id:"LOG004", deviceId:"DEV001", responderId:"R003", dateAssigned:"2025-05-20", dateReturned:"2025-05-28", verifiedReturn:true  },
      { id:"LOG005", deviceId:"DEV006", responderId:"R001", dateAssigned:"2025-05-10", dateReturned:"2025-05-20", verifiedReturn:false },
    ];
    const SEED_INCIDENTS = [
      { id:"INC001", responderId:"R001", type:"Cardiac Alert",      severity:"Critical", status:"completed", date:"2025-06-05", location:"Makati City"  },
      { id:"INC002", responderId:"R002", type:"Fall Detection",      severity:"High",     status:"active",    date:"2025-06-14", location:"BGC, Taguig"  },
      { id:"INC003", responderId:"R004", type:"Oxygen Drop",         severity:"Medium",   status:"active",    date:"2025-06-13", location:"Pasig City"   },
      { id:"INC004", responderId:"R001", type:"Irregular Heartbeat", severity:"High",     status:"completed", date:"2025-05-30", location:"Quezon City"  },
      { id:"INC005", responderId:"R003", type:"High BP Alert",       severity:"Medium",   status:"completed", date:"2025-05-22", location:"Mandaluyong"  },
    ];
    const SEED_VITALSTATS = [
      { logId:"LOG002", heartRate:98, spo2:94, bp:"140/90", temp:37.2, timestamp:"2025-06-14 10:32" },
      { logId:"LOG003", heartRate:72, spo2:98, bp:"120/80", temp:36.8, timestamp:"2025-06-14 10:45" },
    ];

    // =========================================================================
    // ICONS
    // =========================================================================
    const Icon = ({ name, size=18 }) => {
      const s = { width:size, height:size, fill:"none", viewBox:"0 0 24 24", stroke:"currentColor" };
      const icons = {
        dashboard:    <svg {...s} strokeWidth={1.8}><rect x="3"  y="3"  width="7" height="7" rx="1"/><rect x="14" y="3"  width="7" height="7" rx="1"/><rect x="3"  y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>,
        users:        <svg {...s} strokeWidth={1.8}><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>,
        device:       <svg {...s} strokeWidth={1.8}><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>,
        assign:       <svg {...s} strokeWidth={1.8}><path d="M12 5v14M5 12l7 7 7-7"/></svg>,
        verify:       <svg {...s} strokeWidth={1.8}><path d="M20 6L9 17l-5-5"/></svg>,
        reports:      <svg {...s} strokeWidth={1.8}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>,
        plus:         <svg {...s} strokeWidth={2}><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>,
        edit:         <svg {...s} strokeWidth={1.8}><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>,
        close:        <svg {...s} strokeWidth={2}><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>,
        heart:        <svg {...s} strokeWidth={1.8}><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>,
        alert:        <svg {...s} strokeWidth={1.8}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>,
        chevronRight: <svg {...s} strokeWidth={2}><polyline points="9 18 15 12 9 6"/></svg>,
        check:        <svg {...s} strokeWidth={2.5}><polyline points="20 6 9 17 4 12"/></svg>,
        pulse:        <svg {...s} strokeWidth={1.8}><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>,
        menu:         <svg {...s} strokeWidth={2}><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>,
        db:           <svg {...s} strokeWidth={1.8}><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>,
        reset:        <svg {...s} strokeWidth={1.8}><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.95"/></svg>,
        trash:        <svg {...s} strokeWidth={1.8}><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>,
        activity:     <svg {...s} strokeWidth={1.8}><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>,
        shield:       <svg {...s} strokeWidth={1.8}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>,
        eye:          <svg {...s} strokeWidth={1.8}><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>,
        info:         <svg {...s} strokeWidth={1.8}><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>,
        refresh:      <svg {...s} strokeWidth={1.8}><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.08-8.83"/></svg>,
        download:     <svg {...s} strokeWidth={1.8}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>,
      };
      return icons[name] || null;
    };

    // =========================================================================
    // SHARED UI COMPONENTS
    // =========================================================================
    const Modal = ({ title, onClose, children, wide=false }) => (
      <div style={{position:"fixed",inset:0,zIndex:1000,display:"flex",alignItems:"center",justifyContent:"center"}}>
        <div style={{position:"absolute",inset:0,background:"rgba(2,8,20,0.88)",backdropFilter:"blur(4px)"}} onClick={onClose}/>
        <div style={{position:"relative",background:"#0d1929",border:"1px solid #1e3a5f",borderRadius:16,padding:32,
          minWidth:wide?600:460,maxWidth:wide?720:560,width:"92%",maxHeight:"90vh",overflowY:"auto",
          boxShadow:"0 24px 64px rgba(0,0,0,0.7)",animation:"modalIn 0.2s ease"}}>
          <div style={{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:24}}>
            <h2 style={{color:"#f0f8ff",fontSize:18,fontWeight:700,fontFamily:"'Syne',sans-serif",letterSpacing:"-0.01em"}}>{title}</h2>
            <button onClick={onClose} style={{background:"#1a3050",border:"1px solid #2a4f72",borderRadius:8,color:"#9ecde8",cursor:"pointer",padding:"6px 8px",display:"flex",alignItems:"center"}}>
              <Icon name="close" size={16}/>
            </button>
          </div>
          {children}
        </div>
      </div>
    );

    const Field = ({ label, children, hint }) => (
      <div style={{marginBottom:18}}>
        <label style={{display:"block",color:"#9ecde8",fontSize:12,fontWeight:600,marginBottom:7,letterSpacing:"0.07em",textTransform:"uppercase"}}>{label}</label>
        {children}
        {hint && <div style={{color:"#5d8aac",fontSize:12,marginTop:5}}>{hint}</div>}
      </div>
    );

    const Input = ({ value, onChange, placeholder, type="text", disabled=false }) => (
      <input type={type} value={value||""} onChange={e=>onChange(e.target.value)} placeholder={placeholder} disabled={disabled}
        style={{width:"100%",background:disabled?"#060f1c":"#091525",border:"1px solid #1e3a5f",borderRadius:8,color:disabled?"#4a6f8a":"#f0f8ff",padding:"11px 14px",fontSize:15,outline:"none",boxSizing:"border-box",fontFamily:"inherit",transition:"border-color 0.15s",cursor:disabled?"not-allowed":"text"}}
        onFocus={e=>!disabled && (e.target.style.borderColor="#0ea5e9")} onBlur={e=>e.target.style.borderColor="#1e3a5f"}/>
    );

    const Select = ({ value, onChange, children, disabled=false }) => (
      <select value={value||""} onChange={e=>onChange(e.target.value)} disabled={disabled}
        style={{width:"100%",background:disabled?"#060f1c":"#091525",border:"1px solid #1e3a5f",borderRadius:8,color:disabled?"#4a6f8a":"#f0f8ff",padding:"11px 14px",fontSize:15,outline:"none",boxSizing:"border-box",fontFamily:"inherit",cursor:disabled?"not-allowed":"pointer"}}>
        {children}
      </select>
    );

    const Btn = ({ onClick, children, variant="primary", size="md", disabled=false, title }) => {
      const vs = {
        primary:   {background:"linear-gradient(135deg,#0ea5e9,#0369a1)",color:"#fff",   border:"none"},
        secondary: {background:"#1a3050",                                color:"#9ecde8",border:"1px solid #2a4f72"},
        success:   {background:"linear-gradient(135deg,#10b981,#059669)",color:"#fff",   border:"none"},
        danger:    {background:"linear-gradient(135deg,#ef4444,#dc2626)",color:"#fff",   border:"none"},
        ghost:     {background:"transparent",                            color:"#9ecde8",border:"1px solid #1e3a5f"},
        warning:   {background:"linear-gradient(135deg,#f59e0b,#d97706)",color:"#fff",   border:"none"},
      };
      const ss = {
        sm:{padding:"6px 13px", fontSize:13},
        md:{padding:"10px 20px",fontSize:15},
        lg:{padding:"13px 28px",fontSize:16},
      };
      return (
        <button onClick={onClick} disabled={disabled} title={title}
          style={{...vs[variant],...ss[size],borderRadius:8,cursor:disabled?"not-allowed":"pointer",fontWeight:600,
            display:"inline-flex",alignItems:"center",gap:6,fontFamily:"'Syne',sans-serif",opacity:disabled?0.5:1,
            transition:"all 0.15s",whiteSpace:"nowrap"}}>
          {children}
        </button>
      );
    };

    const Badge = ({ label }) => {
      const map = {
        available:   {bg:"rgba(16,185,129,0.15)", text:"#34d399", border:"rgba(16,185,129,0.35)"},
        assigned:    {bg:"rgba(14,165,233,0.15)", text:"#38bdf8", border:"rgba(14,165,233,0.35)"},
        maintenance: {bg:"rgba(245,158,11,0.15)", text:"#fbbf24", border:"rgba(245,158,11,0.35)"},
        active:      {bg:"rgba(239,68,68,0.15)",  text:"#f87171", border:"rgba(239,68,68,0.35)" },
        completed:   {bg:"rgba(16,185,129,0.15)", text:"#34d399", border:"rgba(16,185,129,0.35)"},
        Critical:    {bg:"rgba(239,68,68,0.2)",   text:"#fca5a5", border:"rgba(239,68,68,0.45)" },
        High:        {bg:"rgba(245,158,11,0.2)",  text:"#fcd34d", border:"rgba(245,158,11,0.45)"},
        Medium:      {bg:"rgba(99,102,241,0.2)",  text:"#c4b5fd", border:"rgba(99,102,241,0.45)"},
        Low:         {bg:"rgba(16,185,129,0.15)", text:"#34d399", border:"rgba(16,185,129,0.35)"},
        true:        {bg:"rgba(16,185,129,0.15)", text:"#34d399", border:"rgba(16,185,129,0.35)"},
        false:       {bg:"rgba(239,68,68,0.15)",  text:"#f87171", border:"rgba(239,68,68,0.35)" },
        inactive:    {bg:"rgba(100,116,139,0.15)",text:"#94a3b8", border:"rgba(100,116,139,0.35)"},
      };
      const key = String(label);
      const c = map[key] || {bg:"rgba(255,255,255,0.08)",text:"#94a3b8",border:"rgba(255,255,255,0.15)"};
      return (
        <span style={{background:c.bg,color:c.text,border:`1px solid ${c.border}`,borderRadius:20,
          padding:"3px 11px",fontSize:12,fontWeight:700,letterSpacing:"0.05em",textTransform:"capitalize",whiteSpace:"nowrap"}}>
          {key === "true" ? "✓ Verified" : key === "false" ? "Unverified" : label}
        </span>
      );
    };

    const PageHeader = ({ title, subtitle, action }) => (
      <div style={{display:"flex",justifyContent:"space-between",alignItems:"flex-start",marginBottom:28}}>
        <div style={{flex:1,marginRight:16}}>
          <h1 style={{color:"#f0f8ff",fontSize:22,fontWeight:800,fontFamily:"'Syne',sans-serif",letterSpacing:"-0.02em",marginBottom:6}}>{title}</h1>
          <p style={{color:"#7aa8c9",fontSize:14,lineHeight:1.6}}>{subtitle}</p>
        </div>
        {action && <div style={{flexShrink:0,marginTop:2}}>{action}</div>}
      </div>
    );

    const TH = ({children, center=false}) => (
      <th style={{color:"#7aa8c9",fontSize:12,fontWeight:700,textTransform:"uppercase",letterSpacing:"0.07em",padding:"13px 18px",textAlign:center?"center":"left",whiteSpace:"nowrap",background:"#091525"}}>{children}</th>
    );

    const EmptyState = ({ message }) => (
      <div style={{color:"#7aa8c9",fontSize:14,background:"#0d1929",borderRadius:10,padding:"24px 20px",border:"1px solid #1a3354",textAlign:"center",display:"flex",alignItems:"center",justifyContent:"center",gap:8}}>
        <Icon name="info" size={15}/> {message}
      </div>
    );

    const LoadingSpinner = () => (
      <div style={{display:"flex",alignItems:"center",justifyContent:"center",padding:"40px 0",gap:12,color:"#4a6f8a"}}>
        <div style={{width:20,height:20,border:"2px solid #1a3354",borderTopColor:"#0ea5e9",borderRadius:"50%",animation:"spin 0.7s linear infinite"}}/>
        <span style={{fontSize:14}}>Loading from database…</span>
      </div>
    );

    // =========================================================================
    // DASHBOARD
    // =========================================================================
    const Dashboard = ({ devices, deviceLog, incidents, responders, rescuers, vitalStats, onNavigate }) => {
      const total        = devices.length;
      const available    = devices.filter(d=>d.status==="available").length;
      const assigned     = devices.filter(d=>d.status==="assigned").length;
      const maintenance  = devices.filter(d=>d.status==="maintenance").length;
      const notReturned  = deviceLog.filter(l=>!l.dateReturned).length;
      const activeInc    = incidents.filter(i=>i.status==="active").length;
      const completedInc = incidents.filter(i=>i.status==="completed").length;
      const activeResp   = responders.filter(r=>r.active).length;
      const activeResc   = rescuers.filter(r=>r.active).length;

      const recentLogs   = [...deviceLog].sort((a,b)=>new Date(b.dateAssigned)-new Date(a.dateAssigned)).slice(0,5);
      const activeIncidents = incidents.filter(i=>i.status==="active");

      const cards = [
        {label:"Total Devices",       val:total,        icon:"device",   color:"#0ea5e9", bg:"rgba(14,165,233,0.1)",  nav:"deviceList" },
        {label:"Available",           val:available,    icon:"check",    color:"#10b981", bg:"rgba(16,185,129,0.1)",  nav:"deviceList" },
        {label:"Assigned",            val:assigned,     icon:"assign",   color:"#f59e0b", bg:"rgba(245,158,11,0.1)",  nav:"assign"     },
        {label:"Not Yet Returned",    val:notReturned,  icon:"alert",    color:"#ef4444", bg:"rgba(239,68,68,0.1)",   nav:"verify"     },
        {label:"Active Incidents",    val:activeInc,    icon:"heart",    color:"#ec4899", bg:"rgba(236,72,153,0.1)",  nav:"reports"    },
        {label:"Completed Incidents", val:completedInc, icon:"verify",   color:"#8b5cf6", bg:"rgba(139,92,246,0.1)",  nav:"reports"    },
        {label:"Active Responders",   val:activeResp,   icon:"users",    color:"#06b6d4", bg:"rgba(6,182,212,0.1)",   nav:"responders" },
        {label:"Active Rescuers",     val:activeResc,   icon:"shield",   color:"#84cc16", bg:"rgba(132,204,22,0.1)",  nav:"rescuers"   },
        {label:"In Maintenance",      val:maintenance,  icon:"refresh",  color:"#f97316", bg:"rgba(249,115,22,0.1)",  nav:"deviceList" },
      ];

      return (
        <div className="page-enter">
          <div style={{marginBottom:28}}>
            <h1 style={{color:"#f0f8ff",fontSize:26,fontWeight:800,fontFamily:"'Syne',sans-serif",letterSpacing:"-0.02em",marginBottom:8}}>Command Overview</h1>
            <p style={{color:"#7aa8c9",fontSize:15,lineHeight:1.6,maxWidth:700}}>
              A management dashboard for the <strong style={{color:"#9ecde8"}}>VitalWear IoT Health Monitoring System</strong> — overseeing device assignments, field responder coordination, incident tracking, and real-time wearable deployment status across all sites.
            </p>
          </div>

          <div style={{display:"grid",gridTemplateColumns:"repeat(3,1fr)",gap:14,marginBottom:24}}>
            {cards.map(c=>(
              <div key={c.label} onClick={()=>onNavigate && onNavigate(c.nav)}
                style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:"18px 20px",position:"relative",overflow:"hidden",cursor:"pointer",transition:"border-color 0.15s,transform 0.1s"}}
                onMouseEnter={e=>{e.currentTarget.style.borderColor=c.color+"60";e.currentTarget.style.transform="translateY(-1px)";}}
                onMouseLeave={e=>{e.currentTarget.style.borderColor="#1a3354";e.currentTarget.style.transform="none";}}>
                <div style={{position:"absolute",top:0,right:0,width:72,height:72,background:c.bg,borderRadius:"0 14px 0 72px"}}/>
                <div style={{display:"flex",alignItems:"center",gap:9,marginBottom:12}}>
                  <div style={{background:c.bg,border:`1px solid ${c.color}50`,borderRadius:9,padding:7,color:c.color,display:"flex",zIndex:1}}>
                    <Icon name={c.icon} size={15}/>
                  </div>
                  <span style={{color:"#7aa8c9",fontSize:11,fontWeight:600,textTransform:"uppercase",letterSpacing:"0.06em"}}>{c.label}</span>
                </div>
                <div style={{color:c.color,fontSize:36,fontWeight:800,fontFamily:"'Syne',sans-serif",lineHeight:1}}>{c.val}</div>
              </div>
            ))}
          </div>

          <div style={{display:"grid",gridTemplateColumns:"1fr 1fr",gap:16,marginBottom:16}}>
            {/* Recent Assignments */}
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <div style={{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16}}>
                <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,fontFamily:"'Syne',sans-serif"}}>Recent Assignments</h3>
                <button onClick={()=>onNavigate("reports")} style={{background:"none",border:"none",color:"#0ea5e9",cursor:"pointer",fontSize:13,fontWeight:600}}>View all →</button>
              </div>
              {recentLogs.length===0
                ? <EmptyState message="No assignment records found."/>
                : recentLogs.map(log=>{
                  const dev = devices.find(d=>d.id===log.deviceId);
                  const res = responders.find(r=>r.id===log.responderId);
                  return (
                    <div key={log.id} style={{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"10px 0",borderBottom:"1px solid #0f2035"}}>
                      <div>
                        <div style={{color:"#f0f8ff",fontSize:14,fontWeight:600,marginBottom:1}}>
                          {dev?.name||log.deviceId} <span style={{color:"#5d8aac",fontWeight:400,fontSize:12}}>#{dev?.serial||"—"}</span>
                        </div>
                        <div style={{color:"#7aa8c9",fontSize:12}}>{res?.name||"Unknown"} · {log.dateAssigned}</div>
                      </div>
                      <Badge label={log.dateReturned?"completed":"active"}/>
                    </div>
                  );
                })}
            </div>

            {/* Active Incidents */}
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <div style={{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16}}>
                <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,fontFamily:"'Syne',sans-serif"}}>Live Incidents</h3>
                <button onClick={()=>onNavigate("reports")} style={{background:"none",border:"none",color:"#0ea5e9",cursor:"pointer",fontSize:13,fontWeight:600}}>View all →</button>
              </div>
              {incidents.length===0
                ? <EmptyState message="No incidents recorded."/>
                : incidents.slice(0,5).map(inc=>{
                  const res = responders.find(r=>r.id===inc.responderId);
                  return (
                    <div key={inc.id} style={{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"10px 0",borderBottom:"1px solid #0f2035"}}>
                      <div>
                        <div style={{color:"#f0f8ff",fontSize:14,fontWeight:600,marginBottom:1}}>{inc.type}</div>
                        <div style={{color:"#7aa8c9",fontSize:12}}>{inc.location} · {res?.name||"Unknown"} · {inc.date}</div>
                      </div>
                      <div style={{display:"flex",gap:5,alignItems:"center",flexShrink:0}}>
                        <Badge label={inc.severity}/>
                        <Badge label={inc.status}/>
                      </div>
                    </div>
                  );
                })}
            </div>
          </div>

          {/* Vital Stats */}
          {vitalStats.length > 0 && (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,fontFamily:"'Syne',sans-serif",marginBottom:16}}>Latest Vital Readings</h3>
              <div style={{display:"grid",gridTemplateColumns:"repeat(auto-fill,minmax(220px,1fr))",gap:12}}>
                {vitalStats.map((vs,i)=>{
                  const log = deviceLog.find(l=>l.id===vs.logId);
                  const res = responders.find(r=>r.id===log?.responderId);
                  return (
                    <div key={i} style={{background:"#091525",borderRadius:10,padding:16,border:"1px solid #1a3354"}}>
                      <div style={{color:"#9ecde8",fontSize:12,fontWeight:600,marginBottom:10}}>{res?.name||"Unknown"} · {vs.timestamp}</div>
                      <div style={{display:"grid",gridTemplateColumns:"1fr 1fr",gap:8}}>
                        {[["❤️ HR",`${vs.heartRate} bpm`,"#ef4444"],[`🫁 SpO₂`,`${vs.spo2}%`,"#0ea5e9"],["🩸 BP",vs.bp,"#8b5cf6"],["🌡️ Temp",`${vs.temp}°C`,"#f59e0b"]].map(([k,v,c])=>(
                          <div key={k} style={{textAlign:"center",background:"#0d1929",borderRadius:7,padding:"8px 6px",border:"1px solid #1a3354"}}>
                            <div style={{color:c,fontWeight:700,fontSize:14,fontFamily:"'Syne',sans-serif"}}>{v}</div>
                            <div style={{color:"#5d8aac",fontSize:11,marginTop:2}}>{k}</div>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      );
    };

    // =========================================================================
    // MANAGE RESPONDERS
    // =========================================================================
    const ManageResponders = ({ responders, setResponders, devices, loaded }) => {
      const [modal,   setModal]   = useState(null);
      const [form,    setForm]    = useState({name:"",email:"",phone:""});
      const [editing, setEditing] = useState(null);
      const [viewDev, setViewDev] = useState(null);
      const [confirm, setConfirm] = useState(null);
      const [search,  setSearch]  = useState("");

      const openAdd  = () => { setForm({name:"",email:"",phone:""}); setEditing(null); setModal("form"); };
      const openEdit = r  => { setForm({name:r.name,email:r.email,phone:r.phone}); setEditing(r); setModal("form"); };

      const save = () => {
        if(!form.name.trim()||!form.email.trim()) return;
        if(editing) {
          setResponders(prev=>prev.map(r=>r.id===editing.id?{...r,...form}:r));
        } else {
          const newId = `R${String(Date.now()).slice(-6)}`;
          setResponders(prev=>[...prev,{id:newId,active:true,assignedDevice:null,...form}]);
        }
        setModal(null);
      };

      const toggle = id => setResponders(prev=>prev.map(r=>r.id===id?{...r,active:!r.active}:r));
      const deleteResp = id => { setResponders(prev=>prev.filter(r=>r.id!==id)); setConfirm(null); };

      const filtered = responders.filter(r =>
        !search || r.name.toLowerCase().includes(search.toLowerCase()) || r.email.toLowerCase().includes(search.toLowerCase())
      );

      return (
        <div className="page-enter">
          <PageHeader title="Manage Responders"
            subtitle="Field responders registered in the VitalWear system — assign devices, track activity, and manage access status."
            action={<Btn onClick={openAdd}><Icon name="plus" size={14}/>Add Responder</Btn>}/>

          <div style={{marginBottom:16}}>
            <Input value={search} onChange={setSearch} placeholder="Search by name or email…"/>
          </div>

          {!loaded ? <LoadingSpinner/> : (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
              <table style={{width:"100%",borderCollapse:"collapse"}}>
                <thead><tr><TH>Responder</TH><TH>Contact</TH><TH>Status</TH><TH>Assigned Device</TH><TH>Actions</TH></tr></thead>
                <tbody>
                  {filtered.length===0 ? (
                    <tr><td colSpan={5} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No responders found.</td></tr>
                  ) : filtered.map(r=>{
                    const dev = devices.find(d=>d.id===r.assignedDevice);
                    return (
                      <tr key={r.id} style={{borderTop:"1px solid #0f2035",transition:"background 0.12s"}}
                        onMouseEnter={e=>e.currentTarget.style.background="#0f2238"}
                        onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                        <td style={{padding:"13px 18px"}}>
                          <div style={{display:"flex",alignItems:"center",gap:11}}>
                            <div style={{width:36,height:36,borderRadius:"50%",background:"linear-gradient(135deg,#0ea5e9,#0369a1)",
                              display:"flex",alignItems:"center",justifyContent:"center",color:"#fff",fontWeight:700,fontSize:14,flexShrink:0}}>
                              {r.name.charAt(0)}
                            </div>
                            <div>
                              <div style={{color:"#f0f8ff",fontWeight:600,fontSize:14,marginBottom:1}}>{r.name}</div>
                              <div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace"}}>{r.id}</div>
                            </div>
                          </div>
                        </td>
                        <td style={{padding:"13px 18px"}}>
                          <div style={{color:"#9ecde8",fontSize:14,marginBottom:1}}>{r.email}</div>
                          <div style={{color:"#7aa8c9",fontSize:13}}>{r.phone}</div>
                        </td>
                        <td style={{padding:"13px 18px"}}><Badge label={r.active?"available":"inactive"}/></td>
                        <td style={{padding:"13px 18px"}}>
                          {dev
                            ? <button onClick={()=>setViewDev(dev)} style={{background:"rgba(14,165,233,0.1)",border:"1px solid rgba(14,165,233,0.3)",borderRadius:6,color:"#38bdf8",fontSize:13,padding:"4px 11px",cursor:"pointer",fontWeight:600,fontFamily:"monospace"}}>{dev.serial}</button>
                            : <span style={{color:"#3d5f7a",fontSize:13}}>— None —</span>}
                        </td>
                        <td style={{padding:"13px 18px"}}>
                          <div style={{display:"flex",gap:6}}>
                            <Btn onClick={()=>openEdit(r)} variant="ghost" size="sm"><Icon name="edit" size={13}/>Edit</Btn>
                            <Btn onClick={()=>toggle(r.id)} variant={r.active?"warning":"success"} size="sm">{r.active?"Deactivate":"Activate"}</Btn>
                            <Btn onClick={()=>setConfirm(r)} variant="danger" size="sm" title="Delete"><Icon name="trash" size={13}/></Btn>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* Form Modal */}
          {modal==="form" && (
            <Modal title={editing?"Edit Responder":"Add New Responder"} onClose={()=>setModal(null)}>
              <Field label="Full Name"><Input value={form.name}  onChange={v=>setForm(p=>({...p,name:v}))}  placeholder="Full name"/></Field>
              <Field label="Email Address"><Input value={form.email} onChange={v=>setForm(p=>({...p,email:v}))} placeholder="email@vitawear.ph" type="email"/></Field>
              <Field label="Phone Number"><Input value={form.phone} onChange={v=>setForm(p=>({...p,phone:v}))} placeholder="09XXXXXXXXX"/></Field>
              {!form.name.trim() && <div style={{color:"#f87171",fontSize:12,marginBottom:8}}>⚠ Full name is required.</div>}
              <div style={{display:"flex",gap:10,marginTop:22,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setModal(null)} variant="ghost">Cancel</Btn>
                <Btn onClick={save} variant="primary" disabled={!form.name.trim()||!form.email.trim()}>{editing?"Save Changes":"Add Responder"}</Btn>
              </div>
            </Modal>
          )}

          {/* Delete Confirm */}
          {confirm && (
            <Modal title="Delete Responder" onClose={()=>setConfirm(null)}>
              <p style={{color:"#9ecde8",fontSize:15,marginBottom:20}}>Are you sure you want to delete <strong style={{color:"#f0f8ff"}}>{confirm.name}</strong>? This cannot be undone.</p>
              <div style={{display:"flex",gap:10,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setConfirm(null)} variant="ghost">Cancel</Btn>
                <Btn onClick={()=>deleteResp(confirm.id)} variant="danger"><Icon name="trash" size={14}/>Delete</Btn>
              </div>
            </Modal>
          )}

          {/* Device Detail */}
          {viewDev && (
            <Modal title="Assigned Device Details" onClose={()=>setViewDev(null)}>
              <div style={{background:"#091525",borderRadius:10,padding:18,border:"1px solid #1a3354"}}>
                {[["Device ID",viewDev.id],["Serial",viewDev.serial],["Name",viewDev.name],["Type",viewDev.type],["Status",viewDev.status]].map(([k,v])=>(
                  <div key={k} style={{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"10px 0",borderBottom:"1px solid #0f2035"}}>
                    <span style={{color:"#7aa8c9",fontSize:14}}>{k}</span>
                    {k==="Status"?<Badge label={v}/>:<span style={{color:"#f0f8ff",fontSize:14,fontWeight:600}}>{v}</span>}
                  </div>
                ))}
              </div>
              <div style={{marginTop:18,textAlign:"right"}}><Btn onClick={()=>setViewDev(null)} variant="ghost">Close</Btn></div>
            </Modal>
          )}
        </div>
      );
    };

    // =========================================================================
    // MANAGE RESCUERS
    // =========================================================================
    const ManageRescuers = ({ rescuers, setRescuers, loaded }) => {
      const [modal,   setModal]   = useState(false);
      const [form,    setForm]    = useState({name:"",email:"",phone:""});
      const [editing, setEditing] = useState(null);
      const [confirm, setConfirm] = useState(null);
      const [search,  setSearch]  = useState("");

      const openAdd  = () => { setForm({name:"",email:"",phone:""}); setEditing(null); setModal(true); };
      const openEdit = r  => { setForm({name:r.name,email:r.email,phone:r.phone}); setEditing(r); setModal(true); };
      const save = () => {
        if(!form.name.trim()) return;
        if(editing) setRescuers(prev=>prev.map(r=>r.id===editing.id?{...r,...form}:r));
        else        setRescuers(prev=>[...prev,{id:`RC${String(Date.now()).slice(-6)}`,active:true,...form}]);
        setModal(false);
      };
      const toggle = id => setRescuers(prev=>prev.map(r=>r.id===id?{...r,active:!r.active}:r));
      const deleteResc = id => { setRescuers(prev=>prev.filter(r=>r.id!==id)); setConfirm(null); };

      const filtered = rescuers.filter(r=>
        !search || r.name.toLowerCase().includes(search.toLowerCase()) || r.email.toLowerCase().includes(search.toLowerCase())
      );

      return (
        <div className="page-enter">
          <PageHeader title="Manage Rescuers"
            subtitle="Emergency rescue personnel directory — maintain contact details and control field deployment availability."
            action={<Btn onClick={openAdd}><Icon name="plus" size={14}/>Add Rescuer</Btn>}/>

          <div style={{marginBottom:16}}>
            <Input value={search} onChange={setSearch} placeholder="Search by name or email…"/>
          </div>

          {!loaded ? <LoadingSpinner/> : (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
              <table style={{width:"100%",borderCollapse:"collapse"}}>
                <thead><tr><TH>Rescuer</TH><TH>Contact</TH><TH>Status</TH><TH>Actions</TH></tr></thead>
                <tbody>
                  {filtered.length===0 ? (
                    <tr><td colSpan={4} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No rescuers found.</td></tr>
                  ) : filtered.map(r=>(
                    <tr key={r.id} style={{borderTop:"1px solid #0f2035",transition:"background 0.12s"}}
                      onMouseEnter={e=>e.currentTarget.style.background="#0f2238"}
                      onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                      <td style={{padding:"13px 18px"}}>
                        <div style={{display:"flex",alignItems:"center",gap:11}}>
                          <div style={{width:36,height:36,borderRadius:"50%",background:"linear-gradient(135deg,#8b5cf6,#6d28d9)",
                            display:"flex",alignItems:"center",justifyContent:"center",color:"#fff",fontWeight:700,fontSize:14,flexShrink:0}}>
                            {r.name.charAt(0)}
                          </div>
                          <div>
                            <div style={{color:"#f0f8ff",fontWeight:600,fontSize:14,marginBottom:1}}>{r.name}</div>
                            <div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace"}}>{r.id}</div>
                          </div>
                        </div>
                      </td>
                      <td style={{padding:"13px 18px"}}>
                        <div style={{color:"#9ecde8",fontSize:14,marginBottom:1}}>{r.email}</div>
                        <div style={{color:"#7aa8c9",fontSize:13}}>{r.phone}</div>
                      </td>
                      <td style={{padding:"13px 18px"}}><Badge label={r.active?"available":"inactive"}/></td>
                      <td style={{padding:"13px 18px"}}>
                        <div style={{display:"flex",gap:6}}>
                          <Btn onClick={()=>openEdit(r)} variant="ghost" size="sm"><Icon name="edit" size={13}/>Edit</Btn>
                          <Btn onClick={()=>toggle(r.id)} variant={r.active?"warning":"success"} size="sm">{r.active?"Deactivate":"Activate"}</Btn>
                          <Btn onClick={()=>setConfirm(r)} variant="danger" size="sm" title="Delete"><Icon name="trash" size={13}/></Btn>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {modal && (
            <Modal title={editing?"Edit Rescuer":"Add New Rescuer"} onClose={()=>setModal(false)}>
              <Field label="Full Name"><Input value={form.name}  onChange={v=>setForm(p=>({...p,name:v}))}  placeholder="Full name"/></Field>
              <Field label="Email Address"><Input value={form.email} onChange={v=>setForm(p=>({...p,email:v}))} placeholder="email@rescue.ph" type="email"/></Field>
              <Field label="Phone Number"><Input value={form.phone} onChange={v=>setForm(p=>({...p,phone:v}))} placeholder="09XXXXXXXXX"/></Field>
              <div style={{display:"flex",gap:10,marginTop:22,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setModal(false)} variant="ghost">Cancel</Btn>
                <Btn onClick={save} variant="primary" disabled={!form.name.trim()}>{editing?"Save Changes":"Add Rescuer"}</Btn>
              </div>
            </Modal>
          )}
          {confirm && (
            <Modal title="Delete Rescuer" onClose={()=>setConfirm(null)}>
              <p style={{color:"#9ecde8",fontSize:15,marginBottom:20}}>Delete <strong style={{color:"#f0f8ff"}}>{confirm.name}</strong>? This cannot be undone.</p>
              <div style={{display:"flex",gap:10,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setConfirm(null)} variant="ghost">Cancel</Btn>
                <Btn onClick={()=>deleteResc(confirm.id)} variant="danger"><Icon name="trash" size={14}/>Delete</Btn>
              </div>
            </Modal>
          )}
        </div>
      );
    };

    // =========================================================================
    // DEVICE LIST
    // =========================================================================
    const DeviceList = ({ devices, setDevices, loaded }) => {
      const [filter, setFilter] = useState("all");
      const [modal,  setModal]  = useState(null);
      const [editTarget, setEditTarget] = useState(null);
      const [form,   setForm]   = useState({name:"",serial:"",type:"Wristband",status:"available"});
      const [search, setSearch] = useState("");

      const counts = {
        all:         devices.length,
        available:   devices.filter(d=>d.status==="available").length,
        assigned:    devices.filter(d=>d.status==="assigned").length,
        maintenance: devices.filter(d=>d.status==="maintenance").length,
      };

      const filtered = devices
        .filter(d=>filter==="all"||d.status===filter)
        .filter(d=>!search||d.name.toLowerCase().includes(search.toLowerCase())||d.serial.toLowerCase().includes(search.toLowerCase()));

      const openEdit = d => { setForm({name:d.name||"",serial:d.serial||"",type:d.type||"Wristband",status:d.status||"available"}); setEditTarget(d); setModal("edit"); };
      const saveNew  = () => {
        if(!form.serial.trim()||!form.name.trim()) return;
        setDevices(prev=>[...prev,{id:`DEV${String(Date.now()).slice(-6)}`,...form}]);
        setModal(null); setForm({name:"",serial:"",type:"Wristband",status:"available"});
      };
      const saveEdit = () => {
        if(!form.serial.trim()||!form.name.trim()) return;
        setDevices(prev=>prev.map(d=>d.id===editTarget.id?{...d,...form}:d));
        setModal(null); setEditTarget(null);
      };
      const statusColors = {available:"#10b981",assigned:"#0ea5e9",maintenance:"#f59e0b"};

      return (
        <div className="page-enter">
          <PageHeader title="Device Registry"
            subtitle="All registered VitalWear IoT wearable devices — filter by availability status and register new units into the system."
            action={<Btn onClick={()=>setModal("new")}><Icon name="plus" size={14}/>Register Device</Btn>}/>

          <div style={{display:"flex",gap:12,marginBottom:18,flexWrap:"wrap",alignItems:"center"}}>
            <div style={{flex:1,minWidth:200}}>
              <Input value={search} onChange={setSearch} placeholder="Search by name or serial…"/>
            </div>
            <div style={{display:"flex",gap:6}}>
              {["all","available","assigned","maintenance"].map(f=>(
                <button key={f} onClick={()=>setFilter(f)}
                  style={{padding:"7px 14px",borderRadius:20,border:"1px solid",cursor:"pointer",fontWeight:600,fontSize:13,transition:"all 0.15s",
                    borderColor:filter===f?"#0ea5e9":"#1a3354",background:filter===f?"rgba(14,165,233,0.15)":"transparent",color:filter===f?"#38bdf8":"#7aa8c9"}}>
                  {f.charAt(0).toUpperCase()+f.slice(1)} <span style={{opacity:0.7,fontWeight:400}}>({counts[f]})</span>
                </button>
              ))}
            </div>
          </div>

          {!loaded ? <LoadingSpinner/> : (
            <div style={{display:"grid",gridTemplateColumns:"repeat(auto-fill,minmax(240px,1fr))",gap:14}}>
              {filtered.length===0
                ? <div style={{gridColumn:"1/-1"}}><EmptyState message="No devices match your filter."/></div>
                : filtered.map(d=>(
                  <div key={d.id} style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:13,padding:18,position:"relative",overflow:"hidden",transition:"border-color 0.15s,transform 0.1s"}}
                    onMouseEnter={e=>{e.currentTarget.style.borderColor="#0ea5e9";e.currentTarget.style.transform="translateY(-1px)";}}
                    onMouseLeave={e=>{e.currentTarget.style.borderColor="#1a3354";e.currentTarget.style.transform="none";}}>
                    <div style={{position:"absolute",top:-20,right:-20,width:80,height:80,borderRadius:"50%",background:"rgba(14,165,233,0.05)",border:"1px solid rgba(14,165,233,0.1)"}}/>
                    <div style={{display:"flex",justifyContent:"space-between",alignItems:"flex-start",marginBottom:14}}>
                      <div style={{background:"rgba(14,165,233,0.1)",border:"1px solid rgba(14,165,233,0.2)",borderRadius:10,padding:10,color:"#0ea5e9"}}>
                        <Icon name="device" size={20}/>
                      </div>
                      <Badge label={d.status}/>
                    </div>
                    <div style={{color:"#f0f8ff",fontWeight:700,fontSize:15,marginBottom:3}}>{d.name||"—"}</div>
                    <div style={{color:"#38bdf8",fontSize:13,fontWeight:600,fontFamily:"monospace",marginBottom:3}}>{d.serial}</div>
                    <div style={{color:"#7aa8c9",fontSize:12,marginBottom:12}}>{d.type||"Unknown"} · {d.id}</div>
                    <button onClick={()=>openEdit(d)} style={{background:"rgba(14,165,233,0.08)",border:"1px solid rgba(14,165,233,0.2)",borderRadius:6,color:"#38bdf8",cursor:"pointer",padding:"5px 12px",fontSize:12,fontWeight:600,display:"flex",alignItems:"center",gap:5}}>
                      <Icon name="edit" size={12}/>Edit
                    </button>
                  </div>
                ))}
            </div>
          )}

          {(modal==="new"||modal==="edit") && (
            <Modal title={modal==="edit"?"Edit Device":"Register New Device"} onClose={()=>{setModal(null);setEditTarget(null);}}>
              <Field label="Device Name"><Input value={form.name}   onChange={v=>setForm(p=>({...p,name:v}))}   placeholder="e.g. VitalBand Pro"/></Field>
              <Field label="Serial Number"><Input value={form.serial} onChange={v=>setForm(p=>({...p,serial:v}))} placeholder="VW-2024-XXX" disabled={modal==="edit"}/></Field>
              <Field label="Device Type">
                <Select value={form.type} onChange={v=>setForm(p=>({...p,type:v}))}>
                  <option>Wristband</option><option>Chest Patch</option><option>Ring Sensor</option>
                </Select>
              </Field>
              <Field label="Status">
                <Select value={form.status} onChange={v=>setForm(p=>({...p,status:v}))}>
                  <option value="available">Available</option>
                  <option value="maintenance">Maintenance</option>
                  {modal==="edit" && <option value="assigned">Assigned</option>}
                </Select>
              </Field>
              <div style={{display:"flex",gap:10,marginTop:22,justifyContent:"flex-end"}}>
                <Btn onClick={()=>{setModal(null);setEditTarget(null);}} variant="ghost">Cancel</Btn>
                <Btn onClick={modal==="edit"?saveEdit:saveNew} variant="primary" disabled={!form.name.trim()||!form.serial.trim()}>
                  {modal==="edit"?"Save Changes":"Register Device"}
                </Btn>
              </div>
            </Modal>
          )}
        </div>
      );
    };

    // =========================================================================
    // ASSIGN DEVICE
    // =========================================================================
    const AssignDevice = ({ devices, setDevices, responders, setResponders, deviceLog, setDeviceLog }) => {
      const [selDev,  setSelDev]  = useState("");
      const [selRes,  setSelRes]  = useState("");
      const [success, setSuccess] = useState(false);
      const [date,    setDate]    = useState(new Date().toISOString().slice(0,10));

      const available        = devices.filter(d=>d.status==="available");
      const activeResponders = responders.filter(r=>r.active && !r.assignedDevice);

      const confirm = () => {
        if(!selDev||!selRes) return;
        const logId = `LOG${String(Date.now()).slice(-8)}`;
        setDeviceLog(prev=>[...prev,{id:logId,deviceId:selDev,responderId:selRes,dateAssigned:date,dateReturned:null,verifiedReturn:false}]);
        setDevices(prev=>prev.map(d=>d.id===selDev?{...d,status:"assigned"}:d));
        setResponders(prev=>prev.map(r=>r.id===selRes?{...r,assignedDevice:selDev}:r));
        setSuccess(true);
        setTimeout(()=>{ setSuccess(false); setSelDev(""); setSelRes(""); }, 3500);
      };

      const dev = devices.find(d=>d.id===selDev);
      const res = responders.find(r=>r.id===selRes);

      return (
        <div className="page-enter">
          <PageHeader title="Assign Device"
            subtitle="Link an available VitalWear wearable to a field responder. A new row will be inserted into device_log and device status will update to assigned."/>

          {success && (
            <div style={{background:"rgba(16,185,129,0.12)",border:"1px solid rgba(16,185,129,0.4)",borderRadius:10,padding:"14px 20px",marginBottom:22,color:"#34d399",fontWeight:600,display:"flex",alignItems:"center",gap:10,fontSize:15}}>
              <Icon name="check" size={18}/> Assignment saved successfully — device status updated to <strong>assigned</strong>.
            </div>
          )}

          <div style={{display:"grid",gridTemplateColumns:"1fr 1fr",gap:20,marginBottom:20}}>
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,marginBottom:4,fontFamily:"'Syne',sans-serif"}}>Step 1 — Select Device</h3>
              <p style={{color:"#5d8aac",fontSize:13,marginBottom:14}}>{available.length} device(s) available</p>
              {available.length===0
                ? <EmptyState message="No available devices."/>
                : <div style={{display:"flex",flexDirection:"column",gap:8,maxHeight:320,overflowY:"auto"}}>
                  {available.map(d=>(
                    <div key={d.id} onClick={()=>setSelDev(d.id)}
                      style={{padding:"12px 15px",borderRadius:10,cursor:"pointer",transition:"all 0.12s",
                        border:`1px solid ${selDev===d.id?"#0ea5e9":"#1a3354"}`,background:selDev===d.id?"rgba(14,165,233,0.1)":"#091525"}}>
                      <div style={{display:"flex",justifyContent:"space-between",alignItems:"center"}}>
                        <div>
                          <div style={{color:"#f0f8ff",fontWeight:600,fontSize:14,marginBottom:2}}>{d.name||d.id}</div>
                          <div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace"}}>{d.serial} · {d.type||"—"}</div>
                        </div>
                        {selDev===d.id && <div style={{color:"#0ea5e9"}}><Icon name="check" size={16}/></div>}
                      </div>
                    </div>
                  ))}
                </div>}
            </div>

            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,marginBottom:4,fontFamily:"'Syne',sans-serif"}}>Step 2 — Select Responder</h3>
              <p style={{color:"#5d8aac",fontSize:13,marginBottom:14}}>{activeResponders.length} responder(s) without a device</p>
              {activeResponders.length===0
                ? <EmptyState message="No available responders."/>
                : <div style={{display:"flex",flexDirection:"column",gap:8,maxHeight:320,overflowY:"auto"}}>
                  {activeResponders.map(r=>(
                    <div key={r.id} onClick={()=>setSelRes(r.id)}
                      style={{padding:"12px 15px",borderRadius:10,cursor:"pointer",transition:"all 0.12s",
                        border:`1px solid ${selRes===r.id?"#0ea5e9":"#1a3354"}`,background:selRes===r.id?"rgba(14,165,233,0.1)":"#091525"}}>
                      <div style={{display:"flex",justifyContent:"space-between",alignItems:"center"}}>
                        <div>
                          <div style={{color:"#f0f8ff",fontWeight:600,fontSize:14,marginBottom:2}}>{r.name}</div>
                          <div style={{color:"#7aa8c9",fontSize:12}}>{r.email}</div>
                        </div>
                        {selRes===r.id && <div style={{color:"#0ea5e9"}}><Icon name="check" size={16}/></div>}
                      </div>
                    </div>
                  ))}
                </div>}
            </div>
          </div>

          {(selDev||selRes) && (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,marginBottom:18,fontFamily:"'Syne',sans-serif"}}>Step 3 — Confirm Assignment</h3>
              <div style={{display:"grid",gridTemplateColumns:"1fr 1fr 1fr",gap:14,marginBottom:20}}>
                <div style={{background:"#091525",borderRadius:10,padding:15,border:"1px solid #1a3354"}}>
                  <div style={{color:"#7aa8c9",fontSize:11,fontWeight:600,textTransform:"uppercase",letterSpacing:"0.06em",marginBottom:9}}>Device</div>
                  {dev ? <><div style={{color:"#f0f8ff",fontWeight:700,fontSize:15,marginBottom:3}}>{dev.name}</div><div style={{color:"#38bdf8",fontSize:12,fontFamily:"monospace"}}>{dev.serial}</div></>
                       : <div style={{color:"#3d5f7a",fontSize:13}}>Not selected</div>}
                </div>
                <div style={{background:"#091525",borderRadius:10,padding:15,border:"1px solid #1a3354"}}>
                  <div style={{color:"#7aa8c9",fontSize:11,fontWeight:600,textTransform:"uppercase",letterSpacing:"0.06em",marginBottom:9}}>Responder</div>
                  {res ? <><div style={{color:"#f0f8ff",fontWeight:700,fontSize:15,marginBottom:3}}>{res.name}</div><div style={{color:"#7aa8c9",fontSize:12}}>{res.email}</div></>
                       : <div style={{color:"#3d5f7a",fontSize:13}}>Not selected</div>}
                </div>
                <div style={{background:"#091525",borderRadius:10,padding:15,border:"1px solid #1a3354"}}>
                  <div style={{color:"#7aa8c9",fontSize:11,fontWeight:600,textTransform:"uppercase",letterSpacing:"0.06em",marginBottom:9}}>Date Assigned</div>
                  <input type="date" value={date} onChange={e=>setDate(e.target.value)}
                    style={{background:"transparent",border:"none",color:"#f0f8ff",fontSize:14,fontWeight:600,outline:"none",fontFamily:"monospace",cursor:"pointer"}}/>
                </div>
              </div>
              <Btn onClick={confirm} variant="success" disabled={!selDev||!selRes}><Icon name="check" size={15}/>Confirm Assignment</Btn>
            </div>
          )}
        </div>
      );
    };

    // =========================================================================
    // VERIFY RETURN
    // =========================================================================
    const VerifyReturn = ({ deviceLog, setDeviceLog, devices, setDevices, responders, setResponders }) => {
      const pending        = deviceLog.filter(l=>l.dateReturned && !l.verifiedReturn);
      const unreturnedLogs = deviceLog.filter(l=>!l.dateReturned);

      const markReturned = logId => {
        const today = new Date().toISOString().slice(0,10);
        const log   = deviceLog.find(l=>l.id===logId);
        if (!log) return;
        setDeviceLog(prev=>prev.map(l=>l.id===logId?{...l,dateReturned:today}:l));
        setDevices(prev=>prev.map(d=>d.id===log.deviceId?{...d,status:"available"}:d));
        setResponders(prev=>prev.map(r=>r.id===log.responderId?{...r,assignedDevice:null}:r));
      };

      const verify = logId => setDeviceLog(prev=>prev.map(l=>l.id===logId?{...l,verifiedReturn:true}:l));

      const LogCard = ({log, variant}) => {
        const dev = devices.find(d=>d.id===log.deviceId);
        const res = responders.find(r=>r.id===log.responderId);
        const isU = variant==="unreturned";
        const borderColor = isU ? "rgba(239,68,68,0.3)" : "rgba(245,158,11,0.3)";
        const iconBg      = isU ? "rgba(239,68,68,0.1)"  : "rgba(245,158,11,0.1)";
        const iconColor   = isU ? "#ef4444" : "#f59e0b";
        const days = !isU && log.dateReturned ? Math.round((new Date(log.dateReturned)-new Date(log.dateAssigned))/(1000*60*60*24)) : null;
        return (
          <div style={{background:"#0d1929",border:`1px solid ${borderColor}`,borderRadius:12,padding:"15px 20px",
            display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:10,transition:"background 0.12s"}}
            onMouseEnter={e=>e.currentTarget.style.background="#0f2238"}
            onMouseLeave={e=>e.currentTarget.style.background="#0d1929"}>
            <div style={{display:"flex",gap:14,alignItems:"center"}}>
              <div style={{background:iconBg,border:`1px solid ${borderColor}`,borderRadius:9,padding:10,color:iconColor,flexShrink:0}}>
                <Icon name="device" size={18}/>
              </div>
              <div>
                <div style={{color:"#f0f8ff",fontWeight:700,fontSize:14,marginBottom:3}}>
                  {dev?.name||log.deviceId} <span style={{color:"#38bdf8",fontWeight:400,fontFamily:"monospace",fontSize:12}}>({dev?.serial||"—"})</span>
                </div>
                <div style={{color:"#7aa8c9",fontSize:13}}>
                  {isU
                    ? `Assigned to: ${res?.name||"Unknown"} · Since ${log.dateAssigned}`
                    : `Returned by: ${res?.name||"Unknown"} · ${log.dateReturned} (${days}d out)`}
                </div>
                <div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace",marginTop:2}}>Log: {log.id}</div>
              </div>
            </div>
            {isU
              ? <Btn onClick={()=>markReturned(log.id)} variant="secondary" size="sm"><Icon name="verify" size={13}/>Mark Returned</Btn>
              : <Btn onClick={()=>verify(log.id)} variant="success" size="sm"><Icon name="check" size={13}/>Verify Return</Btn>}
          </div>
        );
      };

      return (
        <div className="page-enter">
          <PageHeader title="Verify Device Return"
            subtitle="Process device returns from field responders — mark as returned to free up availability, then confirm physical receipt to finalize the record."/>

          <div style={{display:"grid",gridTemplateColumns:"1fr 1fr",gap:14,marginBottom:28}}>
            {[["Not Yet Returned",unreturnedLogs.length,"rgba(239,68,68,0.15)","#f87171"],
              ["Awaiting Verification",pending.length,"rgba(245,158,11,0.15)","#fbbf24"],
              ["Total Log Entries",deviceLog.length,"rgba(14,165,233,0.15)","#38bdf8"],
              ["Verified Returns",deviceLog.filter(l=>l.verifiedReturn).length,"rgba(16,185,129,0.15)","#34d399"],
            ].map(([label,val,bg,color])=>(
              <div key={label} style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:12,padding:"14px 20px",display:"flex",alignItems:"center",gap:14}}>
                <div style={{background:bg,borderRadius:9,padding:"8px 14px",color,fontWeight:800,fontSize:22,fontFamily:"'Syne',sans-serif",minWidth:48,textAlign:"center"}}>{val}</div>
                <div style={{color:"#9ecde8",fontSize:14,fontWeight:600}}>{label}</div>
              </div>
            ))}
          </div>

          <div style={{marginBottom:28}}>
            <div style={{display:"flex",alignItems:"center",gap:10,marginBottom:14}}>
              <span style={{background:"rgba(239,68,68,0.15)",color:"#f87171",borderRadius:20,padding:"3px 12px",fontSize:13,fontWeight:700}}>{unreturnedLogs.length}</span>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,fontFamily:"'Syne',sans-serif"}}>Not Yet Returned</h3>
            </div>
            {unreturnedLogs.length===0
              ? <EmptyState message="✓ All assigned devices have been returned."/>
              : unreturnedLogs.map(l=><LogCard key={l.id} log={l} variant="unreturned"/>)}
          </div>

          <div>
            <div style={{display:"flex",alignItems:"center",gap:10,marginBottom:14}}>
              <span style={{background:"rgba(245,158,11,0.15)",color:"#fbbf24",borderRadius:20,padding:"3px 12px",fontSize:13,fontWeight:700}}>{pending.length}</span>
              <h3 style={{color:"#f0f8ff",fontSize:15,fontWeight:700,fontFamily:"'Syne',sans-serif"}}>Returned — Awaiting Verification</h3>
            </div>
            {pending.length===0
              ? <EmptyState message="✓ No returns pending verification."/>
              : pending.map(l=><LogCard key={l.id} log={l} variant="pending"/>)}
          </div>
        </div>
      );
    };

    // =========================================================================
    // MANAGE INCIDENTS
    // =========================================================================
    const ManageIncidents = ({ incidents, setIncidents, responders, loaded }) => {
      const [modal,   setModal]   = useState(false);
      const [editing, setEditing] = useState(null);
      const [confirm, setConfirm] = useState(null);
      const [filterStatus, setFilterStatus] = useState("all");
      const [form, setForm] = useState({responderId:"",type:"Cardiac Alert",severity:"Medium",status:"active",date:new Date().toISOString().slice(0,10),location:""});

      const openAdd  = () => { setForm({responderId:"",type:"Cardiac Alert",severity:"Medium",status:"active",date:new Date().toISOString().slice(0,10),location:""}); setEditing(null); setModal(true); };
      const openEdit = i  => { setForm({responderId:i.responderId,type:i.type,severity:i.severity,status:i.status,date:i.date,location:i.location}); setEditing(i); setModal(true); };
      const save = () => {
        if(!form.type.trim()||!form.location.trim()) return;
        if(editing) setIncidents(prev=>prev.map(i=>i.id===editing.id?{...i,...form}:i));
        else        setIncidents(prev=>[...prev,{id:`INC${String(Date.now()).slice(-6)}`,...form}]);
        setModal(false);
      };
      const deleteInc = id => { setIncidents(prev=>prev.filter(i=>i.id!==id)); setConfirm(null); };
      const resolve   = id => setIncidents(prev=>prev.map(i=>i.id===id?{...i,status:"completed"}:i));

      const filtered = filterStatus==="all" ? incidents : incidents.filter(i=>i.status===filterStatus);

      return (
        <div className="page-enter">
          <PageHeader title="Incident Management"
            subtitle="Log, track, and resolve health incidents detected by VitalWear devices in the field."
            action={<Btn onClick={openAdd}><Icon name="plus" size={14}/>Log Incident</Btn>}/>

          <div style={{display:"flex",gap:8,marginBottom:18}}>
            {["all","active","completed"].map(s=>(
              <button key={s} onClick={()=>setFilterStatus(s)}
                style={{padding:"7px 14px",borderRadius:20,border:"1px solid",cursor:"pointer",fontWeight:600,fontSize:13,transition:"all 0.15s",
                  borderColor:filterStatus===s?"#0ea5e9":"#1a3354",background:filterStatus===s?"rgba(14,165,233,0.15)":"transparent",color:filterStatus===s?"#38bdf8":"#7aa8c9"}}>
                {s.charAt(0).toUpperCase()+s.slice(1)} ({s==="all"?incidents.length:incidents.filter(i=>i.status===s).length})
              </button>
            ))}
          </div>

          {!loaded ? <LoadingSpinner/> : (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
              <table style={{width:"100%",borderCollapse:"collapse"}}>
                <thead><tr><TH>ID</TH><TH>Type</TH><TH>Responder</TH><TH>Severity</TH><TH>Location</TH><TH>Date</TH><TH>Status</TH><TH>Actions</TH></tr></thead>
                <tbody>
                  {filtered.length===0 ? (
                    <tr><td colSpan={8} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No incidents found.</td></tr>
                  ) : filtered.map(inc=>{
                    const res = responders.find(r=>r.id===inc.responderId);
                    return (
                      <tr key={inc.id} style={{borderTop:"1px solid #0f2035",transition:"background 0.12s"}}
                        onMouseEnter={e=>e.currentTarget.style.background="#0f2238"}
                        onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                        <td style={{padding:"12px 18px",color:"#38bdf8",fontSize:13,fontFamily:"monospace",fontWeight:600}}>{inc.id}</td>
                        <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14,fontWeight:600}}>{inc.type}</td>
                        <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:14}}>{res?.name||"—"}</td>
                        <td style={{padding:"12px 18px"}}><Badge label={inc.severity}/></td>
                        <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:13}}>{inc.location}</td>
                        <td style={{padding:"12px 18px",color:"#7aa8c9",fontSize:13,fontFamily:"monospace"}}>{inc.date}</td>
                        <td style={{padding:"12px 18px"}}><Badge label={inc.status}/></td>
                        <td style={{padding:"12px 18px"}}>
                          <div style={{display:"flex",gap:5}}>
                            {inc.status==="active" && <Btn onClick={()=>resolve(inc.id)} variant="success" size="sm"><Icon name="check" size={12}/>Resolve</Btn>}
                            <Btn onClick={()=>openEdit(inc)} variant="ghost" size="sm"><Icon name="edit" size={12}/></Btn>
                            <Btn onClick={()=>setConfirm(inc)} variant="danger" size="sm"><Icon name="trash" size={12}/></Btn>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {modal && (
            <Modal title={editing?"Edit Incident":"Log New Incident"} onClose={()=>setModal(false)}>
              <Field label="Incident Type">
                <Select value={form.type} onChange={v=>setForm(p=>({...p,type:v}))}>
                  {["Cardiac Alert","Fall Detection","Oxygen Drop","Irregular Heartbeat","High BP Alert","High Temperature","Unresponsive Alert","Manual SOS"].map(t=><option key={t}>{t}</option>)}
                </Select>
              </Field>
              <Field label="Responder">
                <Select value={form.responderId} onChange={v=>setForm(p=>({...p,responderId:v}))}>
                  <option value="">— Select Responder —</option>
                  {responders.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}
                </Select>
              </Field>
              <div style={{display:"grid",gridTemplateColumns:"1fr 1fr",gap:14}}>
                <Field label="Severity">
                  <Select value={form.severity} onChange={v=>setForm(p=>({...p,severity:v}))}>
                    {["Low","Medium","High","Critical"].map(s=><option key={s}>{s}</option>)}
                  </Select>
                </Field>
                <Field label="Status">
                  <Select value={form.status} onChange={v=>setForm(p=>({...p,status:v}))}>
                    <option value="active">Active</option><option value="completed">Completed</option>
                  </Select>
                </Field>
              </div>
              <Field label="Location"><Input value={form.location} onChange={v=>setForm(p=>({...p,location:v}))} placeholder="e.g. Makati City"/></Field>
              <Field label="Date"><Input type="date" value={form.date} onChange={v=>setForm(p=>({...p,date:v}))}/></Field>
              <div style={{display:"flex",gap:10,marginTop:22,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setModal(false)} variant="ghost">Cancel</Btn>
                <Btn onClick={save} variant="primary" disabled={!form.type.trim()||!form.location.trim()}>{editing?"Save Changes":"Log Incident"}</Btn>
              </div>
            </Modal>
          )}
          {confirm && (
            <Modal title="Delete Incident" onClose={()=>setConfirm(null)}>
              <p style={{color:"#9ecde8",fontSize:15,marginBottom:20}}>Delete incident <strong style={{color:"#f0f8ff"}}>{confirm.id}</strong> ({confirm.type})? This cannot be undone.</p>
              <div style={{display:"flex",gap:10,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setConfirm(null)} variant="ghost">Cancel</Btn>
                <Btn onClick={()=>deleteInc(confirm.id)} variant="danger"><Icon name="trash" size={14}/>Delete</Btn>
              </div>
            </Modal>
          )}
        </div>
      );
    };

    // =========================================================================
    // REPORTS
    // =========================================================================
    const Reports = ({ deviceLog, incidents, devices, responders, vitalStats, loaded }) => {
      const [active, setActive] = useState("assignment");

      const tabs = [
        {id:"assignment", label:"Assignment History"},
        {id:"return",     label:"Return History"},
        {id:"responder",  label:"Responder Activity"},
        {id:"incident",   label:"Incident Summary"},
        {id:"vitals",     label:"Vital Stats"},
      ];

      const exportCSV = (rows, filename) => {
        if(!rows.length) return;
        const keys = Object.keys(rows[0]);
        const csv  = [keys.join(","), ...rows.map(r=>keys.map(k=>JSON.stringify(r[k]??"")||"").join(","))].join("\n");
        const a = document.createElement("a");
        a.href = "data:text/csv;charset=utf-8," + encodeURIComponent(csv);
        a.download = filename;
        a.click();
      };

      return (
        <div className="page-enter">
          <PageHeader title="Reports & Analytics"
            subtitle="Operational analytics and historical records from the device_log, incident, and vitalstat tables."
            action={<Btn onClick={()=>exportCSV(active==="assignment"?deviceLog:active==="incident"?incidents:active==="vitals"?vitalStats:deviceLog.filter(l=>l.dateReturned), `${active}-report.csv`)} variant="secondary"><Icon name="download" size={14}/>Export CSV</Btn>}/>

          <div style={{display:"flex",gap:7,marginBottom:24,flexWrap:"wrap"}}>
            {tabs.map(t=>(
              <button key={t.id} onClick={()=>setActive(t.id)}
                style={{padding:"8px 16px",borderRadius:20,border:"1px solid",cursor:"pointer",fontWeight:600,fontSize:13,transition:"all 0.15s",
                  borderColor:active===t.id?"#0ea5e9":"#1a3354",background:active===t.id?"rgba(14,165,233,0.15)":"transparent",color:active===t.id?"#38bdf8":"#7aa8c9"}}>
                {t.label}
              </button>
            ))}
          </div>

          {!loaded ? <LoadingSpinner/> : (<>

          {active==="assignment" && (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
              <table style={{width:"100%",borderCollapse:"collapse"}}>
                <thead><tr><TH>Log ID</TH><TH>Device</TH><TH>Responder</TH><TH>Date Assigned</TH><TH>Date Returned</TH><TH>Verified</TH></tr></thead>
                <tbody>
                  {deviceLog.length===0 ? <tr><td colSpan={6} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No records.</td></tr>
                  : deviceLog.map(log=>{
                    const dev=devices.find(d=>d.id===log.deviceId), res=responders.find(r=>r.id===log.responderId);
                    return (
                      <tr key={log.id} style={{borderTop:"1px solid #0f2035"}} onMouseEnter={e=>e.currentTarget.style.background="#0f2238"} onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                        <td style={{padding:"12px 18px",color:"#38bdf8",fontSize:13,fontWeight:600,fontFamily:"monospace"}}>{log.id}</td>
                        <td style={{padding:"12px 18px"}}><div style={{color:"#f0f8ff",fontSize:14,fontWeight:600}}>{dev?.name||log.deviceId}</div><div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace"}}>{dev?.serial||"—"}</div></td>
                        <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14}}>{res?.name||"—"}</td>
                        <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:13,fontFamily:"monospace"}}>{log.dateAssigned}</td>
                        <td style={{padding:"12px 18px"}}>{log.dateReturned?<span style={{color:"#9ecde8",fontFamily:"monospace",fontSize:13}}>{log.dateReturned}</span>:<span style={{color:"#3d5f7a"}}>—</span>}</td>
                        <td style={{padding:"12px 18px"}}><Badge label={String(log.verifiedReturn)}/></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {active==="return" && (
            <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
              <table style={{width:"100%",borderCollapse:"collapse"}}>
                <thead><tr><TH>Device</TH><TH>Responder</TH><TH>Assigned</TH><TH>Returned</TH><TH>Days Out</TH><TH>Verified</TH></tr></thead>
                <tbody>
                  {deviceLog.filter(l=>l.dateReturned).length===0 ? <tr><td colSpan={6} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No returned devices yet.</td></tr>
                  : deviceLog.filter(l=>l.dateReturned).map(log=>{
                    const dev=devices.find(d=>d.id===log.deviceId), res=responders.find(r=>r.id===log.responderId);
                    const days=Math.round((new Date(log.dateReturned)-new Date(log.dateAssigned))/(1000*60*60*24));
                    return (
                      <tr key={log.id} style={{borderTop:"1px solid #0f2035"}} onMouseEnter={e=>e.currentTarget.style.background="#0f2238"} onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                        <td style={{padding:"12px 18px"}}><div style={{color:"#f0f8ff",fontSize:14,fontWeight:600}}>{dev?.name||log.deviceId}</div><div style={{color:"#5d8aac",fontSize:12,fontFamily:"monospace"}}>{dev?.serial||"—"}</div></td>
                        <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14}}>{res?.name||"—"}</td>
                        <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:13,fontFamily:"monospace"}}>{log.dateAssigned}</td>
                        <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:13,fontFamily:"monospace"}}>{log.dateReturned}</td>
                        <td style={{padding:"12px 18px",color:"#38bdf8",fontWeight:700,fontSize:14,fontFamily:"monospace"}}>{days}d</td>
                        <td style={{padding:"12px 18px"}}><Badge label={String(log.verifiedReturn)}/></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {active==="responder" && (
            <div style={{display:"grid",gridTemplateColumns:"repeat(2,1fr)",gap:14}}>
              {responders.map(r=>{
                const logs=deviceLog.filter(l=>l.responderId===r.id);
                const incs=incidents.filter(i=>i.responderId===r.id);
                const activeIncs=incs.filter(i=>i.status==="active").length;
                const vs=vitalStats.filter(s=>logs.some(l=>l.id===s.logId));
                return (
                  <div key={r.id} style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,padding:22}}>
                    <div style={{display:"flex",alignItems:"center",gap:12,marginBottom:16}}>
                      <div style={{width:42,height:42,borderRadius:"50%",background:"linear-gradient(135deg,#0ea5e9,#0369a1)",display:"flex",alignItems:"center",justifyContent:"center",color:"#fff",fontWeight:700,fontSize:16,flexShrink:0}}>{r.name.charAt(0)}</div>
                      <div>
                        <div style={{color:"#f0f8ff",fontWeight:700,fontSize:15,marginBottom:1}}>{r.name}</div>
                        <div style={{color:"#7aa8c9",fontSize:12}}>{r.id} · <span style={{color:r.active?"#34d399":"#f87171"}}>{r.active?"Active":"Inactive"}</span></div>
                      </div>
                    </div>
                    <div style={{display:"grid",gridTemplateColumns:"repeat(4,1fr)",gap:8}}>
                      {[["Assign",logs.length,"#0ea5e9"],["Incidents",incs.length,"#ef4444"],["Active",activeIncs,"#f59e0b"],["VS Reads",vs.length,"#10b981"]].map(([k,v,c])=>(
                        <div key={k} style={{background:"#091525",borderRadius:8,padding:"10px 8px",textAlign:"center",border:"1px solid #1a3354"}}>
                          <div style={{color:c,fontWeight:800,fontSize:20,fontFamily:"'Syne',sans-serif",marginBottom:2}}>{v}</div>
                          <div style={{color:"#7aa8c9",fontSize:11}}>{k}</div>
                        </div>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {active==="incident" && (
            <div>
              <div style={{display:"grid",gridTemplateColumns:"repeat(4,1fr)",gap:12,marginBottom:22}}>
                {[{label:"Total",val:incidents.length,color:"#0ea5e9"},{label:"Active",val:incidents.filter(i=>i.status==="active").length,color:"#ef4444"},{label:"Completed",val:incidents.filter(i=>i.status==="completed").length,color:"#10b981"},{label:"Critical",val:incidents.filter(i=>i.severity==="Critical").length,color:"#f87171"}].map(s=>(
                  <div key={s.label} style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:12,padding:"16px 20px",textAlign:"center"}}>
                    <div style={{color:s.color,fontSize:30,fontWeight:800,fontFamily:"'Syne',sans-serif",marginBottom:4}}>{s.val}</div>
                    <div style={{color:"#7aa8c9",fontSize:13}}>{s.label} Incidents</div>
                  </div>
                ))}
              </div>
              <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
                <table style={{width:"100%",borderCollapse:"collapse"}}>
                  <thead><tr><TH>ID</TH><TH>Type</TH><TH>Responder</TH><TH>Severity</TH><TH>Location</TH><TH>Date</TH><TH>Status</TH></tr></thead>
                  <tbody>
                    {incidents.length===0 ? <tr><td colSpan={7} style={{padding:24,textAlign:"center",color:"#4a6f8a"}}>No incidents recorded.</td></tr>
                    : incidents.map(inc=>{
                      const res=responders.find(r=>r.id===inc.responderId);
                      return (
                        <tr key={inc.id} style={{borderTop:"1px solid #0f2035"}} onMouseEnter={e=>e.currentTarget.style.background="#0f2238"} onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                          <td style={{padding:"12px 18px",color:"#38bdf8",fontSize:13,fontWeight:600,fontFamily:"monospace"}}>{inc.id}</td>
                          <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14}}>{inc.type}</td>
                          <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14}}>{res?.name||"—"}</td>
                          <td style={{padding:"12px 18px"}}><Badge label={inc.severity}/></td>
                          <td style={{padding:"12px 18px",color:"#9ecde8",fontSize:13}}>{inc.location}</td>
                          <td style={{padding:"12px 18px",color:"#7aa8c9",fontSize:13,fontFamily:"monospace"}}>{inc.date}</td>
                          <td style={{padding:"12px 18px"}}><Badge label={inc.status}/></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {active==="vitals" && (
            <div>
              {vitalStats.length===0 ? <EmptyState message="No vital stats recorded yet."/> : (
                <div style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:14,overflow:"hidden"}}>
                  <table style={{width:"100%",borderCollapse:"collapse"}}>
                    <thead><tr><TH>Log ID</TH><TH>Responder</TH><TH center>❤️ HR</TH><TH center>🫁 SpO₂</TH><TH center>🩸 BP</TH><TH center>🌡️ Temp</TH><TH>Timestamp</TH></tr></thead>
                    <tbody>
                      {vitalStats.map((vs,i)=>{
                        const log=deviceLog.find(l=>l.id===vs.logId);
                        const res=responders.find(r=>r.id===log?.responderId);
                        const hrAlert=vs.heartRate>100||vs.heartRate<50;
                        const spo2Alert=vs.spo2<95;
                        const tempAlert=vs.temp>38;
                        return (
                          <tr key={i} style={{borderTop:"1px solid #0f2035"}} onMouseEnter={e=>e.currentTarget.style.background="#0f2238"} onMouseLeave={e=>e.currentTarget.style.background="transparent"}>
                            <td style={{padding:"12px 18px",color:"#38bdf8",fontSize:13,fontFamily:"monospace",fontWeight:600}}>{vs.logId}</td>
                            <td style={{padding:"12px 18px",color:"#f0f8ff",fontSize:14}}>{res?.name||"—"}</td>
                            <td style={{padding:"12px 18px",textAlign:"center",color:hrAlert?"#f87171":"#34d399",fontWeight:700,fontFamily:"monospace"}}>{vs.heartRate} bpm{hrAlert&&" ⚠"}</td>
                            <td style={{padding:"12px 18px",textAlign:"center",color:spo2Alert?"#f87171":"#34d399",fontWeight:700,fontFamily:"monospace"}}>{vs.spo2}%{spo2Alert&&" ⚠"}</td>
                            <td style={{padding:"12px 18px",textAlign:"center",color:"#c4b5fd",fontWeight:600,fontFamily:"monospace"}}>{vs.bp}</td>
                            <td style={{padding:"12px 18px",textAlign:"center",color:tempAlert?"#f87171":"#fbbf24",fontWeight:700,fontFamily:"monospace"}}>{vs.temp}°C{tempAlert&&" ⚠"}</td>
                            <td style={{padding:"12px 18px",color:"#7aa8c9",fontSize:13,fontFamily:"monospace"}}>{vs.timestamp}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
          </>)}
        </div>
      );
    };

    // =========================================================================
    // APP ROOT
    // =========================================================================
    function App() {
      const [page,       setPage]     = useState("dashboard");
      const [sideOpen,   setSideOpen] = useState(true);
      const [expanded,   setExpanded] = useState({users:true,devices:true,manage:true});
      const [resetModal, setResetModal] = useState(false);
      const [savedFlash, setSavedFlash] = useState(false);

      // All state loaded from DB (via API)
      const [devices,    setDevices,    devLoaded]  = usePersistentState(DB_KEYS.devices,    SEED_DEVICES);
      const [responders, setResponders, respLoaded] = usePersistentState(DB_KEYS.responders, SEED_RESPONDERS);
      const [rescuers,   setRescuers,   rescLoaded] = usePersistentState(DB_KEYS.rescuers,   SEED_RESCUERS);
      const [deviceLog,  setDeviceLog,  logLoaded]  = usePersistentState(DB_KEYS.deviceLog,  SEED_DEVICE_LOG);
      const [incidents,  setIncidents,  incLoaded]  = usePersistentState(DB_KEYS.incidents,  SEED_INCIDENTS);
      const [vitalStats, setVitalStats, vsLoaded]   = usePersistentState(DB_KEYS.vitalStats, SEED_VITALSTATS);

      const allLoaded = devLoaded && respLoaded && rescLoaded && logLoaded && incLoaded && vsLoaded;

      // Saved flash on any state change
      const saveTimer = useRef(null);
      useEffect(()=>{
        if(!allLoaded) return;
        setSavedFlash(true);
        if(saveTimer.current) clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(()=>setSavedFlash(false), 2200);
      }, [devices, responders, rescuers, deviceLog, incidents]);

      const resetAll = () => {
        setDevices(SEED_DEVICES);
        setResponders(SEED_RESPONDERS);
        setRescuers(SEED_RESCUERS);
        setDeviceLog(SEED_DEVICE_LOG);
        setIncidents(SEED_INCIDENTS);
        setResetModal(false);
        setPage("dashboard");
      };

      const nav = [
        {id:"dashboard", icon:"dashboard", label:"Dashboard"},
        {id:"users",     icon:"users",     label:"User Management", children:[
          {id:"responders", label:"Manage Responders"},
          {id:"rescuers",   label:"Manage Rescuers"},
        ]},
        {id:"devices", icon:"device", label:"Device Management", children:[
          {id:"deviceList",     label:"Device List"},
          {id:"registerDevice", label:"Register Device"},
        ]},
        {id:"manage", icon:"activity", label:"Operations", children:[
          {id:"incidents", label:"Manage Incidents"},
          {id:"assign",    label:"Assign Device"},
          {id:"verify",    label:"Verify Return"},
        ]},
        {id:"reports", icon:"reports", label:"Reports"},
      ];

      const navigate = (page) => setPage(page);

      const renderContent = () => {
        switch(page) {
          case "responders":    return <ManageResponders responders={responders} setResponders={setResponders} devices={devices} loaded={respLoaded}/>;
          case "rescuers":      return <ManageRescuers   rescuers={rescuers}     setRescuers={setRescuers}     loaded={rescLoaded}/>;
          case "deviceList":
          case "registerDevice": return <DeviceList      devices={devices}       setDevices={setDevices}       loaded={devLoaded}/>;
          case "incidents":     return <ManageIncidents  incidents={incidents}   setIncidents={setIncidents}   responders={responders} loaded={incLoaded}/>;
          case "assign":        return <AssignDevice     devices={devices} setDevices={setDevices} responders={responders} setResponders={setResponders} deviceLog={deviceLog} setDeviceLog={setDeviceLog}/>;
          case "verify":        return <VerifyReturn     deviceLog={deviceLog}   setDeviceLog={setDeviceLog}   devices={devices} setDevices={setDevices} responders={responders} setResponders={setResponders}/>;
          case "reports":       return <Reports          deviceLog={deviceLog}   incidents={incidents} devices={devices} responders={responders} vitalStats={vitalStats} loaded={vsLoaded}/>;
          default:              return <Dashboard        devices={devices} deviceLog={deviceLog} incidents={incidents} responders={responders} rescuers={rescuers} vitalStats={vitalStats} onNavigate={navigate}/>;
        }
      };

      return (
        <div style={{display:"flex",height:"100vh",background:"#020c18",fontFamily:"'Inter',sans-serif",overflow:"hidden"}}>

          {/* SIDEBAR */}
          <div style={{width:sideOpen?252:0,minWidth:sideOpen?252:0,background:"#050e1c",borderRight:"1px solid #0d1e35",display:"flex",flexDirection:"column",transition:"all 0.25s",overflow:"hidden",flexShrink:0}}>
            {/* Logo */}
            <div style={{padding:"20px 18px 16px",borderBottom:"1px solid #0d1e35"}}>
              <div style={{display:"flex",alignItems:"center",gap:11}}>
                <div style={{width:36,height:36,borderRadius:11,background:"linear-gradient(135deg,#0ea5e9,#0284c7)",display:"flex",alignItems:"center",justifyContent:"center",color:"#fff",flexShrink:0}}>
                  <Icon name="pulse" size={18}/>
                </div>
                <div>
                  <div style={{color:"#f0f8ff",fontWeight:800,fontSize:16,fontFamily:"'Syne',sans-serif",lineHeight:1.2}}>VitalWear</div>
                  <div style={{color:"#0ea5e9",fontSize:11,fontWeight:600,letterSpacing:"0.1em",textTransform:"uppercase"}}>Management</div>
                </div>
              </div>
            </div>

            {/* Nav */}
            <nav style={{flex:1,overflowY:"auto",padding:"12px 8px"}}>
              {nav.map(item=>{
                const isActive = page===item.id || (item.children && item.children.some(c=>c.id===page));
                return (
                  <div key={item.id}>
                    <button onClick={()=>{ if(item.children) setExpanded(e=>({...e,[item.id]:!e[item.id]})); else setPage(item.id); }}
                      style={{width:"100%",display:"flex",alignItems:"center",justifyContent:"space-between",padding:"9px 12px",borderRadius:9,border:"none",cursor:"pointer",marginBottom:2,transition:"all 0.15s",textAlign:"left",
                        background:isActive&&!item.children?"rgba(14,165,233,0.13)":"transparent",
                        color:isActive?"#38bdf8":"#8cb8d4"}}>
                      <div style={{display:"flex",alignItems:"center",gap:10}}>
                        <span style={{opacity:isActive?1:0.6,display:"flex"}}><Icon name={item.icon} size={15}/></span>
                        <span style={{fontSize:13,fontWeight:600,whiteSpace:"nowrap"}}>{item.label}</span>
                      </div>
                      {item.children && (
                        <span style={{transform:expanded[item.id]?"rotate(90deg)":"none",transition:"transform 0.2s",display:"flex",color:"#5d8aac"}}>
                          <Icon name="chevronRight" size={12}/>
                        </span>
                      )}
                    </button>
                    {item.children && expanded[item.id] && (
                      <div style={{marginLeft:16,marginBottom:4}}>
                        {item.children.map(c=>(
                          <button key={c.id} onClick={()=>setPage(c.id)}
                            style={{width:"100%",display:"flex",alignItems:"center",gap:9,padding:"7px 11px",borderRadius:7,border:"none",cursor:"pointer",marginBottom:1,fontSize:13,fontWeight:600,textAlign:"left",transition:"all 0.12s",
                              background:page===c.id?"rgba(14,165,233,0.1)":"transparent",color:page===c.id?"#38bdf8":"#6b9fc4"}}>
                            <span style={{width:5,height:5,borderRadius:"50%",flexShrink:0,background:page===c.id?"#0ea5e9":"#1e3a5f"}}/>
                            {c.label}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>

            {/* Sidebar Footer */}
            <div style={{padding:"12px 14px",borderTop:"1px solid #0d1e35"}}>
              <div style={{display:"flex",alignItems:"center",justifyContent:"space-between"}}>
                <div style={{display:"flex",alignItems:"center",gap:7}}>
                  <div style={{width:6,height:6,borderRadius:"50%",background:"#10b981",animation:"pulseRing 2s infinite"}}/>
                  <span style={{color:"#6b9fc4",fontSize:12,fontWeight:600}}>System Online</span>
                </div>
                <div style={{display:"flex",alignItems:"center",gap:5,color:"#38bdf8",fontSize:12,fontWeight:600,background:"rgba(14,165,233,0.08)",border:"1px solid rgba(14,165,233,0.2)",borderRadius:6,padding:"2px 7px",
                  opacity:savedFlash?1:0,transition:"opacity 0.5s"}}>
                  <Icon name="db" size={10}/>Saved
                </div>
              </div>
              {!allLoaded && (
                <div style={{marginTop:8,display:"flex",alignItems:"center",gap:6,color:"#4a6f8a",fontSize:12}}>
                  <div style={{width:12,height:12,border:"2px solid #1a3354",borderTopColor:"#0ea5e9",borderRadius:"50%",animation:"spin 0.7s linear infinite"}}/>
                  Loading from API…
                </div>
              )}
            </div>
          </div>

          {/* MAIN */}
          <div style={{flex:1,display:"flex",flexDirection:"column",overflow:"hidden"}}>
            {/* Topbar */}
            <div style={{height:54,background:"#050e1c",borderBottom:"1px solid #0d1e35",display:"flex",alignItems:"center",justifyContent:"space-between",padding:"0 22px",flexShrink:0}}>
              <div style={{display:"flex",alignItems:"center",gap:11}}>
                <button onClick={()=>setSideOpen(o=>!o)}
                  style={{background:"#0d1929",border:"1px solid #1a3354",borderRadius:7,color:"#9ecde8",cursor:"pointer",padding:"6px 8px",display:"flex",alignItems:"center"}}>
                  <Icon name="menu" size={15}/>
                </button>
                <span style={{color:"#4a6f8a",fontSize:13}}>VitalWear IoT Health Monitoring <span style={{color:"#2a4f72"}}>— Management Portal</span></span>
              </div>
              <div style={{display:"flex",alignItems:"center",gap:8}}>
                <div style={{display:"flex",alignItems:"center",gap:5,background:"#0d1929",border:"1px solid #1a3354",borderRadius:7,padding:"5px 11px"}}>
                  <span style={{color:"#7aa8c9",display:"flex"}}><Icon name="db" size={12}/></span>
                  <span style={{color:"#7aa8c9",fontSize:12,fontWeight:500}}>MySQL API</span>
                  <span style={{width:5,height:5,borderRadius:"50%",background:allLoaded?"#10b981":"#f59e0b",animation:"pulseRing 2s infinite"}}/>
                </div>
                <div style={{display:"flex",alignItems:"center",gap:5,background:"#0d1929",border:"1px solid #1a3354",borderRadius:7,padding:"5px 11px"}}>
                  <span style={{color:"#38bdf8",display:"flex"}}><Icon name="pulse" size={12}/></span>
                  <span style={{color:"#38bdf8",fontSize:12,fontWeight:600}}>IoT Live</span>
                  <span style={{width:5,height:5,borderRadius:"50%",background:"#10b981",animation:"pulseRing 1.5s infinite"}}/>
                </div>
                <button onClick={()=>setResetModal(true)} title="Reset all data to defaults"
                  style={{background:"rgba(239,68,68,0.08)",border:"1px solid rgba(239,68,68,0.2)",borderRadius:7,color:"#f87171",cursor:"pointer",padding:"6px 8px",display:"flex",alignItems:"center"}}>
                  <Icon name="reset" size={14}/>
                </button>
                <div style={{width:32,height:32,borderRadius:"50%",background:"linear-gradient(135deg,#0ea5e9,#0284c7)",display:"flex",alignItems:"center",justifyContent:"center",color:"#fff",fontWeight:700,fontSize:13,flexShrink:0}}>M</div>
              </div>
            </div>

            {/* Page content */}
            <div style={{flex:1,overflowY:"auto",padding:26}}>
              {renderContent()}
            </div>
          </div>

          {/* RESET MODAL */}
          {resetModal && (
            <Modal title="Reset All Data" onClose={()=>setResetModal(false)}>
              <p style={{color:"#9ecde8",fontSize:15,lineHeight:1.6,marginBottom:8}}>
                This will restore all data to the original seed records and re-save them to the API.
              </p>
              <p style={{color:"#7aa8c9",fontSize:13,lineHeight:1.6,marginBottom:24}}>
                All CRUD changes — added responders, registered devices, assignment logs, and return verifications — will be permanently overwritten.
              </p>
              <div style={{display:"flex",gap:10,justifyContent:"flex-end"}}>
                <Btn onClick={()=>setResetModal(false)} variant="ghost">Cancel</Btn>
                <Btn onClick={resetAll} variant="danger"><Icon name="reset" size={14}/>Reset All Data</Btn>
              </div>
            </Modal>
          )}
        </div>
      );
    }

    ReactDOM.createRoot(document.getElementById("root")).render(<App/>);
  </script>
</body>
</html>