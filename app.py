from flask import Flask, request, jsonify
import os, csv, sqlite3, json, re, difflib, threading
from datetime import datetime
import google.generativeai as genai

# ==============================
# ---- API / LLM CONFIG ----
# ==============================
GEMINI_KEY = os.getenv("GEMINI_API_KEY")
if not GEMINI_KEY:
    raise RuntimeError("Uh-oh: GEMINI_API_KEY not set. Try: export GEMINI_API_KEY='...'")

genai.configure(api_key=GEMINI_KEY)
llm_model = genai.GenerativeModel("gemini-1.5-flash")

# ==============================
# ---- FLASK APP ----
# ==============================
app = Flask(__name__, static_url_path="", static_folder="static")

# ==============================
# ---- STORAGE PATHS ----
# ==============================
DB_FILE = "merit_list.db"
CSV_FILE = "merit_list.csv"   # put your CSV here

# ==============================
# ---- IN-MEMORY CONTEXT ----
# ==============================
# NOTE: ephemeral only; swap with persistent store for prod
user_context = {}
ctx_lock = threading.Lock()

# ==============================
# ---- DB INIT / LOAD ----
# ==============================
def init_database():
    """Creates the table if it's missing and loads CSV if empty."""
    conn = sqlite3.connect(DB_FILE)
    cur = conn.cursor()

    cur.execute("""
    CREATE TABLE IF NOT EXISTS merit_data (
        University TEXT,
        Campus TEXT,
        Department TEXT,
        Program TEXT,
        Year INTEGER,
        MinimumMerit REAL,
        MaximumMerit REAL
    )
    """)
    conn.commit()

    cur.execute("SELECT COUNT(*) FROM merit_data")
    count = cur.fetchone()[0]
    if count == 0 and os.path.exists(CSV_FILE):
        with open(CSV_FILE, newline="", encoding="utf-8") as fh:
            csv_reader = csv.DictReader(fh)
            for row in csv_reader:
                # tolerate slight header differences
                uni  = row.get("University") or row.get("university") or ""
                camp = row.get("Campus") or row.get("campus") or ""
                dept = row.get("Department") or row.get("department") or ""
                prog = row.get("Program") or row.get("program") or ""
                year = row.get("Year") or row.get("year") or "0"
                minm = row.get("Minimum Merit") or row.get("MinimumMerit") or row.get("minimum_merit") or "0"
                maxm = row.get("Maximum Merit") or row.get("MaximumMerit") or row.get("maximum_merit") or "0"
                try:
                    cur.execute("""
                    INSERT INTO merit_data 
                    (University, Campus, Department, Program, Year, MinimumMerit, MaximumMerit)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    """, (
                        uni.strip(), camp.strip(), dept.strip(), prog.strip(),
                        int(year), float(minm), float(maxm)
                    ))
                except Exception:
                    # skip malformed rows
                    continue
        conn.commit()
    conn.close()

def grab_merit_data():
    """Just loads all merit data rows into a list of dicts."""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    cur.execute("SELECT * FROM merit_data")
    records = []
    for r in cur.fetchall():
        records.append({
            "University": (r["University"] or "").strip(),
            "Campus": (r["Campus"] or "").strip(),
            "Department": (r["Department"] or "").strip(),
            "Program": (r["Program"] or "").strip(),
            "Year": int(r["Year"]),
            "Minimum Merit": float(r["MinimumMerit"]),
            "Maximum Merit": float(r["MaximumMerit"])
        })
    conn.close()
    return records

# Boot DB & cache
init_database()
merit_records = grab_merit_data()

# ==============================
# ---- CACHED LOOKUPS ----
# ==============================
UNIS  = sorted({r["University"]  for r in merit_records if r["University"]})
DEPTS = sorted({r["Department"]  for r in merit_records if r["Department"]})
PROGS = sorted({r["Program"]     for r in merit_records if r["Program"]})
CAMPS = sorted({r["Campus"]      for r in merit_records if r["Campus"]})

# Map uni -> campuses
UNI_TO_CAMP = {}
for rec in merit_records:
    UNI_TO_CAMP.setdefault(rec["University"], set()).add(rec["Campus"])
for k in UNI_TO_CAMP:
    UNI_TO_CAMP[k] = sorted(UNI_TO_CAMP[k])

# ==============================
# ---- NORMALIZATION / FUZZY ----
# ==============================
def ratio(a, b):
    return difflib.SequenceMatcher(None, (a or "").lower(), (b or "").lower()).ratio()

def fuzzy_pick(candidate, options, cutoff=0.80):
    """Safer fuzzy matching: only returns a value if >= cutoff."""
    if not candidate or not options:
        return None
    best = None
    best_r = 0.0
    for o in options:
        r = ratio(candidate, o)
        if r > best_r:
            best_r, best = r, o
    return best if best_r >= cutoff else None

# Aliases — extended and safer (Physics will not map to Computing)
dept_aliases = {
    # NOTE: Keep "Computer Science" literal for those unis that truly have it (IBA, QAU, etc.)
    # We'll uni-adjust later if a uni uses the umbrella "Computing".
    "cs": "Computer Science", "c.s": "Computer Science", "comp science": "Computer Science",
    "computer science": "Computer Science", "computerscience": "Computer Science",
    "se": "Software Engineering", "software engineering": "Software Engineering",
    "cyber": "Cyber Security", "cyber security": "Cyber Security",
    "computing": "Computing",  # umbrella where applicable
    # Electrical/Mechanical
    "ee": "Electrical", "electrical": "Electrical", "electrical engineering": "Electrical",
    "me": "Mechanical", "mechanical": "Mechanical", "mechanical engineering": "Mechanical",
    # Physics & others
    "physics": "Physics", "applied physics": "Applied Physics", "math": "Mathematics",
}

# Program aliases (BS/MS/MPhil/PhD)
prog_aliases = {
    "bs": "BS", "b.s": "BS", "bsc": "BS", "b.sc": "BS", "bachelors": "BS",
    "bachelor": "BS", "undergrad": "BS", "ug": "BS",
    "ms": "MS", "m.s": "MS", "msc": "MS", "m.sc": "MS",
    "mphil": "MPhil", "m.phil": "MPhil", "postgrad": "MS", "pg": "MS",
    "phd": "PhD", "ph.d": "PhD", "doctorate": "PhD"
}

# Common campus abbreviations (expanded)
campus_aliases = {
    "isb": "Islamabad", "islamabad": "Islamabad",
    "rwp": "Rawalpindi", "rawalpindi": "Rawalpindi",
    "lhr": "Lahore", "lahore": "Lahore",
    "khi": "Karachi", "karachi": "Karachi", "kt": "Karachi",
    "pesh": "Peshawar", "peshawar": "Peshawar",
    "qta": "Quetta", "quetta": "Quetta",
    "abbott": "Abbottabad", "abbottabad": "Abbottabad",
    "skt": "Sialkot", "sialkot": "Sialkot",
    "mul": "Multan", "multan": "Multan",
    "faisalabad": "Faisalabad", "fsd": "Faisalabad",
    "suk": "Sukkur", "sukkur": "Sukkur",
    # add more as needed
}

def norm_dept(txt):
    if not txt: return None
    t = txt.strip().lower()
    if t in dept_aliases:
        return dept_aliases[t]
    # try exact list hit
    for d in DEPTS:
        if d.lower() == t:
            return d
    # safe fuzzy
    fm = fuzzy_pick(txt, DEPTS, cutoff=0.83)
    return fm or txt.strip()

def norm_prog(txt):
    if not txt: return None
    t = txt.strip().lower().replace("-", "").replace("_", "").replace(" ", "")
    if t in prog_aliases:
        return prog_aliases[t]
    fm = fuzzy_pick(txt, PROGS, cutoff=0.90)  # program set is small; be strict
    return fm or txt.strip()

def norm_uni(txt):
    if not txt: return None
    # exact
    for u in UNIS:
        if u.lower() == txt.strip().lower():
            return u
    # fuzzy (slightly looser for unis)
    fm = fuzzy_pick(txt, UNIS, cutoff=0.78)
    return fm or txt.strip()

def norm_campus(txt):
    if not txt: return ""
    # support comma, 'and'
    parts = re.split(r"\s*(?:,|and|&)\s*", txt, flags=re.IGNORECASE)
    normalized = []
    for p in parts:
        p_s = p.strip()
        if not p_s:
            continue
        low = p_s.lower()
        if low in campus_aliases:
            normalized.append(campus_aliases[low])
            continue
        fm = fuzzy_pick(p_s, CAMPS, cutoff=0.80)
        normalized.append(fm or p_s)
    # dedupe while preserving order
    out, seen = [], set()
    for c in normalized:
        if c.lower() not in seen:
            out.append(c)
            seen.add(c.lower())
    return ", ".join(out)

def campus_like(c1, c2):
    """Return True if campus c1 matches filter c2. c2 may be empty or multi-campus comma list."""
    if not c2:
        return True
    c1_l = (c1 or "").lower()
    c2_list = [x.strip().lower() for x in re.split(r',\s*', c2) if x.strip()]
    return any(part == c1_l or part in c1_l for part in c2_list)

# ---------- NEW: uni-aware department adjustment ----------
def adjust_dept_for_uni(uni, dept):
    """
    If a user asks for 'Computer Science' (or SE/Cyber) at a uni that stores the umbrella 'Computing'
    in the CSV (like FAST), remap to 'Computing'. Otherwise leave as-is.
    """
    if not uni or not dept:
        return dept
    uni_deps = {d.lower() for d in departments_at_uni(uni)}
    # If 'computer science' (or se/cyber) is not present but 'computing' is, map to 'Computing'
    if dept.lower() in {"computer science", "software engineering", "cyber security"}:
        if "computer science" not in uni_deps and "computing" in uni_deps:
            return "Computing"
    return dept
# ----------------------------------------------------------

# ==============================
# ---- DATA HELPERS ----
# ==============================
def lookup_rows(uni, camp, dept, prog, yr):
    hits = []
    for rec in merit_records:
        if rec["University"].lower() != (uni or "").lower(): continue
        if not campus_like(rec["Campus"], camp): continue
        if rec["Department"].lower() != (dept or "").lower(): continue
        if rec["Program"].lower() != (prog or "").lower(): continue
        if int(rec["Year"]) != int(yr): continue
        hits.append(rec)
    return hits

def available_years(uni, dept, prog, camp=None):
    ys = sorted({r["Year"] for r in merit_records
                 if r["University"].lower()==(uni or "").lower()
                 and r["Department"].lower()==(dept or "").lower()
                 and r["Program"].lower()==(prog or "").lower()
                 and (camp is None or campus_like(r["Campus"], camp))})
    return ys

def closest_year(uni, dept, prog, yr, camp=None):
    ys = available_years(uni, dept, prog, camp)
    if not ys: return None
    return min(ys, key=lambda y: abs(y - int(yr)))

def campuses_offering(uni, dept, prog, yr=None):
    """Return campuses at uni that offer (dept, prog), optionally for specific year."""
    camps = sorted({r["Campus"] for r in merit_records
                    if r["University"].lower()==(uni or "").lower()
                    and r["Department"].lower()==(dept or "").lower()
                    and r["Program"].lower()==(prog or "").lower()
                    and (yr is None or int(r["Year"])==int(yr))})
    return camps

def departments_at_uni(uni):
    return sorted({r["Department"] for r in merit_records if r["University"].lower()==(uni or "").lower()})

def programs_for(uni, dept):
    return sorted({r["Program"] for r in merit_records
                   if r["University"].lower()==(uni or "").lower()
                   and r["Department"].lower()==(dept or "").lower()})

# ==============================
# ---- EXTRACTION / INTENT ----
# ==============================
def detect_year_from_text(msg):
    # "last year" support + explicit four-digit
    msg_l = (msg or "").lower()
    now_y = datetime.now().year
    if re.search(r"\blast\s+year\b", msg_l):
        return now_y - 1
    m = re.search(r"\b(20\d{2}|19\d{2})\b", msg_l)
    if m:
        return int(m.group(1))
    return now_y  # default to current year

def cheap_extract(msg):
    """Extracts (uni, camp, dept, prog, year) with safer fuzzy & aliases."""
    msg_l = (msg or "").lower()

    # university (substring, then fuzzy)
    uni_found = None
    for u in UNIS:
        if u.lower() in msg_l:
            uni_found = u; break
    if not uni_found:
        tokens = re.findall(r"[A-Za-z0-9']{3,}", msg_l)
        for token in tokens:
            fm = fuzzy_pick(token, UNIS, cutoff=0.80)
            if fm: uni_found = fm; break

    # department (aliases, substrings, safe fuzzy)
    dept_found = None
    for k, v in dept_aliases.items():
        if re.search(rf"\b{k}\b", msg_l):
            dept_found = v; break
    if not dept_found:
        for d in DEPTS:
            if d.lower() in msg_l:
                dept_found = d; break
    if not dept_found:
        for token in re.findall(r"[A-Za-z0-9']{2,}", msg_l):
            fm = fuzzy_pick(token, DEPTS, cutoff=0.83)
            if fm: dept_found = fm; break

    # program (aliases, substrings, safe fuzzy)
    prog_found = None
    for k, v in prog_aliases.items():
        if re.search(rf"\b{k}\b", msg_l):
            prog_found = v; break
    if not prog_found:
        for p in PROGS:
            if p.lower() in msg_l:
                prog_found = p; break
    if not prog_found:
        # default to BS if user wrote "CS", "Physics" etc. without program
        prog_found = "BS"

    # campuses (short forms, known names; multi-campus via comma/and/&)
    camp_found = None
    camps_detected = []
    for short, full in campus_aliases.items():
        if re.search(rf"\b{re.escape(short)}\b", msg_l):
            camps_detected.append(full)
    for c in CAMPS:
        if c.lower() in msg_l:
            camps_detected.append(c)
    mc = re.split(r"\s*(?:,|and|&)\s*", msg_l)
    # if user wrote "... Islamabad and Lahore ..."
    # the earlier detection already captured texts
    if camps_detected:
        # dedupe in order
        seen = set()
        ordered = []
        for x in camps_detected:
            if x.lower() not in seen:
                seen.add(x.lower()); ordered.append(x)
        camp_found = ", ".join(ordered)

    # year
    year_found = detect_year_from_text(msg)

    return uni_found, camp_found, dept_found, prog_found, year_found

def is_policy_question(msg):
    msg_l = (msg or "").lower()
    # Expanded policy detection (avoid confusing with "merit score")
    if re.search(r"\b(vacant seats?|vacancies|merit\s*list(?:s)?|policy|how many lists?)\b", msg_l):
        return True
    return False

# ==============================
# ---- REPLY BUILDERS ----
# ==============================
def build_merit_line(r):
    return f"min {r['Minimum Merit']}% / max {r['Maximum Merit']}%"

def reply_for_multi_campus(uni, dept, prog, camp, yr):
    # camp may already be "A, B, C"
    camp_list = [c.strip() for c in re.split(r',\s*', camp) if c.strip()]
    replies = []
    for c in camp_list:
        rows = lookup_rows(uni, c, dept, prog, yr)
        if not rows:
            # Automatic closest-year fallback for that campus
            cy = closest_year(uni, dept, prog, yr, c)
            if cy is not None:
                fallback = lookup_rows(uni, c, dept, prog, cy)
                if fallback:
                    r = fallback[0]
                    replies.append(f"{c} (showing {cy}): {build_merit_line(r)}")
                    continue
            # If program not at this campus at all (any year), suggest other campuses
            offered_any = campuses_offering(uni, dept, prog, yr=None)
            if offered_any and c not in offered_any:
                replies.append(f"{c}: {prog} {dept} is not offered here. Try: {', '.join(offered_any)}.")
            else:
                replies.append(f"{c}: No data for {yr}.")
        else:
            r = rows[0]
            replies.append(f"{c}: {build_merit_line(r)}")
    return f"Merits for {prog} {dept} at {uni} in {yr}:\n" + "\n".join(replies)

def reply_for_single_or_uncamped(uni, dept, prog, camp, yr):
    rows = lookup_rows(uni, camp, dept, prog, yr)
    if rows:
        # single campus or already filtered
        if len(rows) == 1:
            r = rows[0]
            camp_txt = f" ({r['Campus']})" if r["Campus"] else ""
            return f"The merit for {r['Program']} {r['Department']} at {r['University']}{camp_txt} in {r['Year']} is: {build_merit_line(r)}."
        # multiple campuses and no campus specified -> summarize
        lines = [f"- {r['Campus']}: {build_merit_line(r)}" for r in rows]
        return f"Multiple campuses found for {prog} {dept} at {uni} in {yr}:\n" + "\n".join(lines) + "\nIf you want one campus, say e.g. 'FAST Islamabad'."

    # No exact rows -> automatic closest-year fallback (any campus)
    cy = closest_year(uni, dept, prog, yr, camp if camp else None)
    if cy is not None:
        fb = lookup_rows(uni, camp, dept, prog, cy)
        if fb:
            r = fb[0]
            camp_txt = f" ({r['Campus']})" if r["Campus"] else ""
            return f"No data for {yr}. Showing closest available year ({cy}) for {prog} {dept} at {uni}{camp_txt}: {build_merit_line(r)}."

    # If a campus was requested but doesn’t offer that program, explain and suggest alternatives
    if camp:
        offered_here_years = available_years(uni, dept, prog, camp)
        if not offered_here_years:
            offered_other_camps = campuses_offering(uni, dept, prog, yr=None)
            if offered_other_camps:
                return f"{prog} {dept} is not offered at {uni} ({camp}). It is available at: {', '.join(offered_other_camps)}."
            # Not offered at this uni at all
            pr_avail = programs_for(uni, dept)
            if pr_avail:
                return f"{prog} is not offered for {dept} at {uni} ({camp}). Available programs here: {', '.join(pr_avail)}."
            deps = departments_at_uni(uni)
            return f"{prog} {dept} is not available at {uni}. Departments at {uni}: {', '.join(deps)}."

    # If no campus specified, explain what’s available at the uni/department
    pr_avail = programs_for(uni, dept)
    if pr_avail:
        return f"No {prog} data found for {dept} at {uni} in {yr}. Available programs for this department: {', '.join(pr_avail)}."
    if uni in UNI_TO_CAMP:
        deps = departments_at_uni(uni)
        return f"No match found. {uni} campuses: {', '.join(UNI_TO_CAMP[uni])}. Departments: {', '.join(deps)}"
    return "Sorry, nothing matched."

# ==============================
# ---- CHAT ENDPOINT ----
# ==============================
@app.route("/chat", methods=["POST"])
def chat():
    payload = request.json or {}
    user_msg = payload.get("message", "")
    session_id = payload.get("session") or payload.get("session_id") or request.remote_addr or "default"

    # load/ensure context
    with ctx_lock:
        ctx = user_context.get(session_id, {})

    # 1) Merit-list policy questions (handle first)
    if is_policy_question(user_msg):
        # You can wire this to a real policy KB if available
        return jsonify({"reply": ("Policy question detected.\n"
                                  "Typically, universities issue 2–3 merit lists and may extend if seats remain vacant. "
                                  "For a specific campus, ask e.g. 'Vacant-seats policy at FAST Islamabad'.")})

    # 2) Try LLM extraction
    uni, camp, dept, prog, yr = None, None, None, None, None
    try:
        prompt = f"""
        From the question, pull:
        - university (one of: {UNIS})
        - campus (string; "" if none; support multi-campus via comma or 'and')
        - department (canonical to: {DEPTS})
        - program (canonical to: {PROGS}, default "BS")
        - year (int, default current year; 'last year' = current year-1)

        Return ONLY JSON with keys: university, campus, department, program, year.
        User said:
        \"\"\"{user_msg}\"\"\""""
        res = llm_model.generate_content(prompt)
        text_out = (res.text or "").strip()
        json_match = re.search(r"\{.*\}", text_out, re.DOTALL)
        info = json.loads(json_match.group()) if json_match else None
        if info:
            uni = info.get("university")
            camp = info.get("campus", "")
            dept = info.get("department")
            prog = info.get("program", "BS")
            yr = int(info.get("year", detect_year_from_text(user_msg)))
    except Exception:
        info = None

    # 3) Fallback extractor if LLM didn’t give all fields
    if not (uni and dept and prog and yr):
        f_uni, f_camp, f_dep, f_prog, f_yr = cheap_extract(user_msg)
        uni = uni or f_uni
        camp = camp or f_camp
        dept = dept or f_dep
        prog = prog or f_prog
        yr = yr or f_yr

    # 4) Normalize with safe fuzzy & aliases
    uni = norm_uni(uni) if uni else None
    dept = norm_dept(dept) if dept else None
    prog = norm_prog(prog) if prog else None
    camp = norm_campus(camp) if camp else ""

    # ---------- NEW: adjust dept if the uni uses umbrella 'Computing' ----------
    if uni and dept:
        dept = adjust_dept_for_uni(uni, dept)
    # --------------------------------------------------------------------------

    # 5) Conversational follow-ups (collect missing pieces)
    #    We ask only for missing items; once filled, proceed.
    missing = []
    if not uni:  missing.append("university")
    if not dept: missing.append("department")
    if not prog: missing.append("program")
    # Campus is optional; Year we have default from detector
    if missing:
        # store what we already know; ask only the next missing piece
        ask_next = missing[0]
        with ctx_lock:
            user_context[session_id] = {
                "awaiting": ask_next,
                "known_university": uni,
                "known_department": dept,
                "known_program": prog,
                "known_campus": camp,
                "known_year": yr
            }
        if ask_next == "university":
            return jsonify({"reply": f"Which university? For example: {', '.join(UNIS[:8])}."})
        if ask_next == "department":
            if uni:
                return jsonify({"reply": f"Which department at {uni}? Examples: {', '.join(departments_at_uni(uni)[:8])}."})
            return jsonify({"reply": f"Which department? Examples: {', '.join(DEPTS[:8])}."})
        if ask_next == "program":
            if uni and dept:
                return jsonify({"reply": f"Which program for {dept} at {uni}? Options: {', '.join(programs_for(uni, dept)) or 'BS/MS/MPhil/PhD'}."})
            return jsonify({"reply": "Which program (BS/MS/MPhil/PhD)?"})

    # If we were awaiting a specific field previously, try to plug it in now
    if ctx.get("awaiting"):
        awaiting = ctx["awaiting"]
        # use the latest user_msg to fill the awaited field
        if awaiting == "university" and not uni:
            uni_try = norm_uni(user_msg)
            if uni_try: uni = uni_try
        elif awaiting == "department" and not dept:
            dept_try = norm_dept(user_msg)
            if dept_try: dept = dept_try
        elif awaiting == "program" and not prog:
            prog_try = norm_prog(user_msg)
            if prog_try: prog = prog_try
        # Once anything is filled, clear awaiting
        with ctx_lock:
            user_context.pop(session_id, None)

    # 6) Multi-campus support
    if camp and ("," in camp or re.search(r"\band\b|\&", camp, flags=re.IGNORECASE)):
        reply = reply_for_multi_campus(uni, dept, prog, camp, yr)
        return jsonify({"reply": reply})

    # 7) Single-campus / no-campus reply with automatic closest-year fallback
    reply = reply_for_single_or_uncamped(uni, dept, prog, camp, yr)
    return jsonify({"reply": reply})

# ==============================
# ---- BASIC ROUTES ----
# ==============================
@app.route("/")
def home():
    return app.send_static_file("index.html")

@app.route("/health")
def health():
    return jsonify({"ok": True})

# ==============================
# ---- MAIN ----
# ==============================
if __name__ == "__main__":
    # run in debug for development; disable in production
    app.run(debug=True, host="0.0.0.0", port=5000)