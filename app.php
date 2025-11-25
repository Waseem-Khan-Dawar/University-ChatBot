<?php
// app.php
// Usage: run under Apache/nginx+php-fpm or built-in PHP server:
// php -S 0.0.0.0:8080 app.php

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ------------------------------
// CONFIG
// ------------------------------
$DB_FILE = __DIR__ . '/merit_list.db';
$CSV_FILE = __DIR__ . '/merit_list.csv';
$STATIC_DIR = __DIR__ . '/static';
$GEMINI_KEY = getenv('GEMINI_API_KEY') ?: null;
$LLM_MODEL = 'gemini-1.5-flash'; // used only as hint when calling REST API

// In-memory context per PHP session (ephemeral)
session_start();

// ------------------------------
// HELPERS: Logging (simple)
// ------------------------------
function log_debug($msg) {
    // uncomment to log
    // error_log("[DEBUG] " . $msg);
}

// ------------------------------
// DB INIT / LOAD
// ------------------------------
function get_pdo() {
    global $DB_FILE;
    $needInit = !file_exists($DB_FILE);
    $pdo = new PDO("sqlite:" . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($needInit) {
        init_database($pdo);
    }
    return $pdo;
}

function init_database(PDO $pdo) {
    global $CSV_FILE;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merit_data (
            University TEXT,
            Campus TEXT,
            Department TEXT,
            Program TEXT,
            Year INTEGER,
            MinimumMerit REAL,
            MaximumMerit REAL
        )
    ");
    $count = (int)$pdo->query("SELECT COUNT(*) FROM merit_data")->fetchColumn();
    if ($count === 0 && file_exists($CSV_FILE)) {
        $fh = fopen($CSV_FILE, 'r');
        if ($fh !== false) {
            // read header
            $headers = [];
            if (($row = fgetcsv($fh)) !== false) {
                $headers = array_map('trim', $row);
            }
            while (($row = fgetcsv($fh)) !== false) {
                $rowAssoc = [];
                foreach ($row as $i => $val) {
                    $h = $headers[$i] ?? "col{$i}";
                    $rowAssoc[$h] = $val;
                }
                // tolerant header fetch
                $uni  = $rowAssoc['University'] ?? $rowAssoc['university'] ?? '';
                $camp = $rowAssoc['Campus'] ?? $rowAssoc['campus'] ?? '';
                $dept = $rowAssoc['Department'] ?? $rowAssoc['department'] ?? '';
                $prog = $rowAssoc['Program'] ?? $rowAssoc['program'] ?? '';
                $year = $rowAssoc['Year'] ?? $rowAssoc['year'] ?? '0';
                $minm = $rowAssoc['Minimum Merit'] ?? $rowAssoc['MinimumMerit'] ?? $rowAssoc['minimum_merit'] ?? '0';
                $maxm = $rowAssoc['Maximum Merit'] ?? $rowAssoc['MaximumMerit'] ?? $rowAssoc['maximum_merit'] ?? '0';
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO merit_data
                        (University, Campus, Department, Program, Year, MinimumMerit, MaximumMerit)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        trim($uni), trim($camp), trim($dept), trim($prog),
                        (int)$year, (float)$minm, (float)$maxm
                    ]);
                } catch (Exception $e) {
                    // skip malformed rows
                    continue;
                }
            }
            fclose($fh);
        }
    }
}

// Grab all merit data into memory (cache)
function grab_merit_data() {
    $pdo = get_pdo();
    $rows = $pdo->query("SELECT * FROM merit_data")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            "University" => trim($r['University'] ?? ''),
            "Campus"     => trim($r['Campus'] ?? ''),
            "Department" => trim($r['Department'] ?? ''),
            "Program"    => trim($r['Program'] ?? ''),
            "Year"       => (int)($r['Year'] ?? 0),
            "Minimum Merit" => (float)($r['MinimumMerit'] ?? 0.0),
            "Maximum Merit" => (float)($r['MaximumMerit'] ?? 0.0)
        ];
    }
    return $out;
}

// Initialize DB & cache on first request
$MERIT_RECORDS = grab_merit_data();

// ------------------------------
// CACHED LOOKUPS: UNIS/DEPTS/PROGS/CAMPS
// ------------------------------
function unique_sorted($arr) {
    $s = array_unique($arr);
    sort($s, SORT_STRING | SORT_FLAG_CASE);
    return array_values($s);
}

$UNIS  = unique_sorted(array_map(fn($r) => $r['University'], array_filter($MERIT_RECORDS, fn($x) => $x['University'] !== '')));
$DEPTS = unique_sorted(array_map(fn($r) => $r['Department'], array_filter($MERIT_RECORDS, fn($x) => $x['Department'] !== '')));
$PROGS = unique_sorted(array_map(fn($r) => $r['Program'], array_filter($MERIT_RECORDS, fn($x) => $x['Program'] !== '')));
$CAMPS = unique_sorted(array_map(fn($r) => $r['Campus'], array_filter($MERIT_RECORDS, fn($x) => $x['Campus'] !== '')));

// UNI -> campuses map
$UNI_TO_CAMP = [];
foreach ($MERIT_RECORDS as $rec) {
    $u = $rec['University'];
    $c = $rec['Campus'];
    if (!isset($UNI_TO_CAMP[$u])) $UNI_TO_CAMP[$u] = [];
    $UNI_TO_CAMP[$u][$c] = true;
}
foreach ($UNI_TO_CAMP as $k => $set) {
    $UNI_TO_CAMP[$k] = array_values(array_keys($set));
    sort($UNI_TO_CAMP[$k], SORT_STRING | SORT_FLAG_CASE);
}

// ------------------------------
// NORMALIZATION / FUZZY
// ------------------------------
function ratio($a, $b) {
    // compute similarity ratio in [0,1] using similar_text normalized by max length
    $a_l = mb_strtolower((string)$a);
    $b_l = mb_strtolower((string)$b);
    $len = max(mb_strlen($a_l), mb_strlen($b_l));
    if ($len === 0) return 1.0;
    similar_text($a_l, $b_l, $percent); // percent 0..100
    return $percent / 100.0;
}

function fuzzy_pick($candidate, $options, $cutoff=0.80) {
    if (!$candidate || !$options) return null;
    $best = null;
    $best_r = 0.0;
    foreach ($options as $o) {
        $r = ratio($candidate, $o);
        if ($r > $best_r) {
            $best_r = $r; $best = $o;
        }
    }
    return $best_r >= $cutoff ? $best : null;
}

// Aliases (ported)
$dept_aliases = [
    "cs"=>"Computer Science","c.s"=>"Computer Science","comp science"=>"Computer Science",
    "computer science"=>"Computer Science","computerscience"=>"Computer Science",
    "se"=>"Software Engineering","software engineering"=>"Software Engineering",
    "cyber"=>"Cyber Security","cyber security"=>"Cyber Security",
    "computing"=>"Computing",
    "ee"=>"Electrical","electrical"=>"Electrical","electrical engineering"=>"Electrical",
    "me"=>"Mechanical","mechanical"=>"Mechanical","mechanical engineering"=>"Mechanical",
    "physics"=>"Physics","applied physics"=>"Applied Physics","math"=>"Mathematics"
];

$prog_aliases = [
    "bs"=>"BS","b.s"=>"BS","bsc"=>"BS","b.sc"=>"BS","bachelors"=>"BS",
    "bachelor"=>"BS","undergrad"=>"BS","ug"=>"BS",
    "ms"=>"MS","m.s"=>"MS","msc"=>"MS","m.sc"=>"MS",
    "mphil"=>"MPhil","m.phil"=>"MPhil","postgrad"=>"MS","pg"=>"MS",
    "phd"=>"PhD","ph.d"=>"PhD","doctorate"=>"PhD"
];

$campus_aliases = [
    "isb"=>"Islamabad","islamabad"=>"Islamabad",
    "rwp"=>"Rawalpindi","rawalpindi"=>"Rawalpindi",
    "lhr"=>"Lahore","lahore"=>"Lahore",
    "khi"=>"Karachi","karachi"=>"Karachi","kt"=>"Karachi",
    "pesh"=>"Peshawar","peshawar"=>"Peshawar",
    "qta"=>"Quetta","quetta"=>"Quetta",
    "abbott"=>"Abbottabad","abbottabad"=>"Abbottabad",
    "skt"=>"Sialkot","sialkot"=>"Sialkot",
    "mul"=>"Multan","multan"=>"Multan",
    "faisalabad"=>"Faisalabad","fsd"=>"Faisalabad",
    "suk"=>"Sukkur","sukkur"=>"Sukkur"
];

function norm_dept($txt) {
    global $dept_aliases, $DEPTS;
    if (!$txt) return null;
    $t = mb_strtolower(trim($txt));
    if (isset($dept_aliases[$t])) return $dept_aliases[$t];
    foreach ($DEPTS as $d) {
        if (mb_strtolower($d) === $t) return $d;
    }
    $fm = fuzzy_pick($txt, $DEPTS, 0.83);
    return $fm ?: trim($txt);
}

function norm_prog($txt) {
    global $prog_aliases, $PROGS;
    if (!$txt) return null;
    $t = str_replace(['-','_',' '], '', mb_strtolower(trim($txt)));
    if (isset($prog_aliases[$t])) return $prog_aliases[$t];
    $fm = fuzzy_pick($txt, $PROGS, 0.90);
    return $fm ?: trim($txt);
}

function norm_uni($txt) {
    global $UNIS;
    if (!$txt) return null;
    foreach ($UNIS as $u) {
        if (mb_strtolower($u) === mb_strtolower(trim($txt))) return $u;
    }
    $fm = fuzzy_pick($txt, $UNIS, 0.78);
    return $fm ?: trim($txt);
}

function norm_campus($txt) {
    global $campus_aliases, $CAMPS;
    if (!$txt) return "";
    $parts = preg_split('/\s*(?:,|and|&)\s*/i', $txt);
    $normalized = [];
    foreach ($parts as $p) {
        $p_s = trim($p);
        if ($p_s === '') continue;
        $low = mb_strtolower($p_s);
        if (isset($campus_aliases[$low])) {
            $normalized[] = $campus_aliases[$low];
            continue;
        }
        $fm = fuzzy_pick($p_s, $CAMPS, 0.80);
        $normalized[] = $fm ?: $p_s;
    }
    $out = [];
    $seen = [];
    foreach ($normalized as $c) {
        $kl = mb_strtolower($c);
        if (!in_array($kl, $seen)) {
            $out[] = $c;
            $seen[] = $kl;
        }
    }
    return implode(', ', $out);
}

function campus_like($c1, $c2) {
    if (!$c2) return true;
    $c1_l = mb_strtolower($c1 ?? '');
    $parts = array_filter(array_map('trim', explode(',', $c2)));
    foreach ($parts as $p) {
        $pl = mb_strtolower($p);
        if ($pl === $c1_l || strpos($c1_l, $pl) !== false) return true;
    }
    return false;
}

function departments_at_uni($uni) {
    global $MERIT_RECORDS;
    $out = [];
    foreach ($MERIT_RECORDS as $r) {
        if (mb_strtolower($r['University']) === mb_strtolower($uni ?? '')) {
            $out[] = $r['Department'];
        }
    }
    return unique_sorted($out);
}

function programs_for($uni, $dept) {
    global $MERIT_RECORDS;
    $out = [];
    foreach ($MERIT_RECORDS as $r) {
        if (mb_strtolower($r['University']) === mb_strtolower($uni ?? '') &&
            mb_strtolower($r['Department']) === mb_strtolower($dept ?? '')) {
            $out[] = $r['Program'];
        }
    }
    return unique_sorted($out);
}

// adjust_dept_for_uni
function adjust_dept_for_uni($uni, $dept) {
    if (!$uni || !$dept) return $dept;
    $uni_deps = array_map('mb_strtolower', departments_at_uni($uni));
    $check = mb_strtolower($dept);
    if (in_array($check, ['computer science','software engineering','cyber security'])) {
        if (!in_array('computer science', $uni_deps) && in_array('computing', $uni_deps)) {
            return 'Computing';
        }
    }
    return $dept;
}

// ------------------------------
// DATA HELPERS
// ------------------------------
function lookup_rows($uni, $camp, $dept, $prog, $yr) {
    global $MERIT_RECORDS;
    $hits = [];
    foreach ($MERIT_RECORDS as $rec) {
        if (mb_strtolower($rec['University']) !== mb_strtolower($uni ?? '')) continue;
        if (!campus_like($rec['Campus'], $camp)) continue;
        if (mb_strtolower($rec['Department']) !== mb_strtolower($dept ?? '')) continue;
        if (mb_strtolower($rec['Program']) !== mb_strtolower($prog ?? '')) continue;
        if ((int)$rec['Year'] !== (int)$yr) continue;
        $hits[] = $rec;
    }
    return $hits;
}

function available_years($uni, $dept, $prog, $camp = null) {
    global $MERIT_RECORDS;
    $ys = [];
    foreach ($MERIT_RECORDS as $r) {
        if (mb_strtolower($r['University']) === mb_strtolower($uni ?? '') &&
            mb_strtolower($r['Department']) === mb_strtolower($dept ?? '') &&
            mb_strtolower($r['Program']) === mb_strtolower($prog ?? '') &&
            ($camp === null || campus_like($r['Campus'], $camp))) {
            $ys[] = (int)$r['Year'];
        }
    }
    return unique_sorted($ys);
}

function closest_year($uni, $dept, $prog, $yr, $camp = null) {
    $ys = available_years($uni, $dept, $prog, $camp);
    if (empty($ys)) return null;
    $closest = null; $bestDiff = null;
    foreach ($ys as $y) {
        $diff = abs($y - (int)$yr);
        if ($bestDiff === null || $diff < $bestDiff) {
            $bestDiff = $diff; $closest = $y;
        }
    }
    return $closest;
}

function campuses_offering($uni, $dept, $prog, $yr = null) {
    global $MERIT_RECORDS;
    $camps = [];
    foreach ($MERIT_RECORDS as $r) {
        if (mb_strtolower($r['University']) === mb_strtolower($uni ?? '') &&
            mb_strtolower($r['Department']) === mb_strtolower($dept ?? '') &&
            mb_strtolower($r['Program']) === mb_strtolower($prog ?? '') &&
            ($yr === null || (int)$r['Year'] === (int)$yr)) {
            $camps[] = $r['Campus'];
        }
    }
    return unique_sorted($camps);
}

// ------------------------------
// EXTRACTION / INTENT
// ------------------------------
function detect_year_from_text($msg) {
    $now = (int)date('Y');
    $msg_l = mb_strtolower($msg ?? '');
    if (preg_match('/\blast\s+year\b/', $msg_l)) {
        return $now - 1;
    }
    if (preg_match('/\b(19\d{2}|20\d{2})\b/', $msg_l, $m)) {
        return (int)$m[1];
    }
    return $now;
}

function cheap_extract($msg) {
    global $UNIS, $DEPTS, $PROGS, $campus_aliases, $CAMPS;
    $msg_l = mb_strtolower($msg ?? '');
    $uni_found = null;
    foreach ($UNIS as $u) {
        if (mb_stripos($msg_l, mb_strtolower($u)) !== false) { $uni_found = $u; break; }
    }
    if (!$uni_found) {
        preg_match_all("/[A-Za-z0-9']{3,}/", $msg_l, $tokens);
        foreach ($tokens[0] as $token) {
            $fm = fuzzy_pick($token, $UNIS, 0.80);
            if ($fm) { $uni_found = $fm; break; }
        }
    }

    $dept_found = null;
    foreach ($GLOBALS['dept_aliases'] as $k => $v) {
        if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $msg_l)) { $dept_found = $v; break; }
    }
    if (!$dept_found) {
        foreach ($DEPTS as $d) {
            if (mb_stripos($msg_l, mb_strtolower($d)) !== false) { $dept_found = $d; break; }
        }
    }
    if (!$dept_found) {
        preg_match_all("/[A-Za-z0-9']{2,}/", $msg_l, $tokens2);
        foreach ($tokens2[0] as $token) {
            $fm = fuzzy_pick($token, $DEPTS, 0.83);
            if ($fm) { $dept_found = $fm; break; }
        }
    }

    $prog_found = null;
    foreach ($GLOBALS['prog_aliases'] as $k => $v) {
        if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $msg_l)) { $prog_found = $v; break; }
    }
    if (!$prog_found) {
        foreach ($PROGS as $p) {
            if (mb_stripos($msg_l, mb_strtolower($p)) !== false) { $prog_found = $p; break; }
        }
    }
    if (!$prog_found) $prog_found = "BS";

    // campuses
    $camp_found = null;
    $camps_detected = [];
    foreach ($campus_aliases as $short => $full) {
        if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $msg_l)) $camps_detected[] = $full;
    }
    foreach ($CAMPS as $c) {
        if (mb_stripos($msg_l, mb_strtolower($c)) !== false) $camps_detected[] = $c;
    }
    if (!empty($camps_detected)) {
        // dedupe
        $seen = [];
        $ordered = [];
        foreach ($camps_detected as $x) {
            $kl = mb_strtolower($x);
            if (!in_array($kl, $seen)) { $seen[] = $kl; $ordered[] = $x; }
        }
        $camp_found = implode(', ', $ordered);
    }

    $year_found = detect_year_from_text($msg);

    return [$uni_found, $camp_found, $dept_found, $prog_found, $year_found];
}

function is_policy_question($msg) {
    $msg_l = mb_strtolower($msg ?? '');
    return preg_match('/\b(vacant seats?|vacancies|merit\s*list(?:s)?|policy|how many lists?)\b/', $msg_l) ? true : false;
}

// ------------------------------
// REPLY BUILDERS
// ------------------------------
function build_merit_line($r) {
    return "min " . $r['Minimum Merit'] . "% / max " . $r['Maximum Merit'] . "%";
}

function reply_for_multi_campus($uni, $dept, $prog, $camp, $yr) {
    $camp_list = array_filter(array_map('trim', preg_split('/,\s*/', $camp)));
    $replies = [];
    foreach ($camp_list as $c) {
        $rows = lookup_rows($uni, $c, $dept, $prog, $yr);
        if (empty($rows)) {
            $cy = closest_year($uni, $dept, $prog, $yr, $c);
            if ($cy !== null) {
                $fallback = lookup_rows($uni, $c, $dept, $prog, $cy);
                if (!empty($fallback)) {
                    $r = $fallback[0];
                    $replies[] = "$c (showing $cy): " . build_merit_line($r);
                    continue;
                }
            }
            $offered_any = campuses_offering($uni, $dept, $prog, null);
            if (!empty($offered_any) && !in_array($c, $offered_any)) {
                $replies[] = "$c: $prog $dept is not offered here. Try: " . implode(', ', $offered_any) . ".";
            } else {
                $replies[] = "$c: No data for $yr.";
            }
        } else {
            $r = $rows[0];
            $replies[] = "$c: " . build_merit_line($r);
        }
    }
    return "Merits for $prog $dept at $uni in $yr:\n" . implode("\n", $replies);
}

function reply_for_single_or_uncamped($uni, $dept, $prog, $camp, $yr) {
    $rows = lookup_rows($uni, $camp, $dept, $prog, $yr);
    if (!empty($rows)) {
        if (count($rows) === 1) {
            $r = $rows[0];
            $camp_txt = $r['Campus'] ? " ({$r['Campus']})" : "";
            return "The merit for {$r['Program']} {$r['Department']} at {$r['University']}{$camp_txt} in {$r['Year']} is: " . build_merit_line($r) . ".";
        }
        $lines = [];
        foreach ($rows as $r) $lines[] = "- {$r['Campus']}: " . build_merit_line($r);
        return "Multiple campuses found for $prog $dept at $uni in $yr:\n" . implode("\n", $lines) . "\nIf you want one campus, say e.g. 'FAST Islamabad'.";
    }

    $cy = closest_year($uni, $dept, $prog, $yr, $camp ?: null);
    if ($cy !== null) {
        $fb = lookup_rows($uni, $camp, $dept, $prog, $cy);
        if (!empty($fb)) {
            $r = $fb[0];
            $camp_txt = $r['Campus'] ? " ({$r['Campus']})" : "";
            return "No data for $yr. Showing closest available year ($cy) for $prog $dept at $uni{$camp_txt}: " . build_merit_line($r) . ".";
        }
    }

    if ($camp) {
        $offered_here_years = available_years($uni, $dept, $prog, $camp);
        if (empty($offered_here_years)) {
            $offered_other_camps = campuses_offering($uni, $dept, $prog, null);
            if (!empty($offered_other_camps)) {
                return "$prog $dept is not offered at $uni ($camp). It is available at: " . implode(', ', $offered_other_camps) . ".";
            }
            $pr_avail = programs_for($uni, $dept);
            if (!empty($pr_avail)) {
                return "$prog is not offered for $dept at $uni ($camp). Available programs here: " . implode(', ', $pr_avail) . ".";
            }
            $deps = departments_at_uni($uni);
            return "$prog $dept is not available at $uni. Departments at $uni: " . implode(', ', $deps);
        }
    }

    $pr_avail = programs_for($uni, $dept);
    if (!empty($pr_avail)) {
        return "No $prog data found for $dept at $uni in $yr. Available programs for this department: " . implode(', ', $pr_avail) . ".";
    }
    if (isset($GLOBALS['UNI_TO_CAMP'][$uni])) {
        $deps = departments_at_uni($uni);
        return "No match found. $uni campuses: " . implode(', ', $GLOBALS['UNI_TO_CAMP'][$uni]) . ". Departments: " . implode(', ', $deps);
    }
    return "Sorry, nothing matched.";
}

// ------------------------------
// LLM integration (optional)
// ------------------------------
function call_llm_extract($user_msg) {
    global $GEMINI_KEY, $LLM_MODEL, $UNIS, $DEPTS, $PROGS;
    if (!$GEMINI_KEY) return null;

    // Build prompt (mirrors Python code)
    $prompt = "From the question, pull:\n- university (one of: " . implode(", ", $UNIS) . ")\n- campus (string; \"\" if none; support multi-campus via comma or 'and')\n- department (canonical to: " . implode(", ", $DEPTS) . ")\n- program (canonical to: " . implode(", ", $PROGS) . ", default \"BS\")\n- year (int, default current year; 'last year' = current year-1)\n\nReturn ONLY JSON with keys: university, campus, department, program, year.\nUser said:\n\"\"\"" . $user_msg . "\"\"\"";

    // Google Generative REST - NOTE: endpoint & auth method may need update depending on account.
    // This attempt uses a generic Google Generative endpoint; you may need to adapt if your environment uses a different path.
    $url = "https://generativelanguage.googleapis.com/v1beta2/models/" . urlencode($LLM_MODEL) . ":generate";
    $payload = [
        "prompt" => [
            "text" => $prompt
        ],
        "temperature" => 0.0,
        "max_output_tokens" => 512
    ];
    $ch = curl_init($url . "?key=" . urlencode($GEMINI_KEY));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        log_debug("LLM call failed: $err");
        return null;
    }
    $j = json_decode($res, true);
    if (!$j) return null;

    // Attempt to extract text from response (varies by API shape)
    // Commonly Google returns "candidates"/"output" fields; fallback to scanning for JSON.
    $text_out = null;
    if (isset($j['candidates'][0]['content'])) $text_out = $j['candidates'][0]['content'];
    if (!$text_out && isset($j['output'][0]['content'])) $text_out = $j['output'][0]['content'];
    if (!$text_out) {
        // try top-level text
        $text_out = json_encode($j);
    }

    // find JSON object inside text_out
    if (preg_match('/\{.*\}/s', $text_out, $m)) {
        $obj = json_decode($m[0], true);
        if ($obj) return $obj;
    }
    return null;
}

// ------------------------------
// ROUTING (simple single-file router)
// ------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Serve static files if they exist under /static path or index root
if ($uri === '/' || $uri === '') {
    // serve static/index.html if present
    $index = $STATIC_DIR . '/index.html';
    if (file_exists($index)) {
        header('Content-Type: text/html; charset=utf-8');
        echo file_get_contents($index);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'msg' => 'No index.html found.']);
        exit;
    }
}

// /health
if ($uri === '/health' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// /chat (POST)
if ($uri === '/chat' && $method === 'POST') {
    // read JSON body
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];
    $user_msg = $payload['message'] ?? ($payload['msg'] ?? '');
    $session_id = $payload['session'] ?? $payload['session_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'default');

    // load session-based context (ephemeral)
    $ctx = $_SESSION['user_context'][$session_id] ?? [];

    // 1) policy check
    if (is_policy_question($user_msg)) {
        header('Content-Type: application/json');
        echo json_encode(['reply' => "Policy question detected.\nTypically, universities issue 2–3 merit lists and may extend if seats remain vacant. For a specific campus, ask e.g. 'Vacant-seats policy at FAST Islamabad'."]);
        exit;
    }

    // 2) Try LLM extraction (optional)
    $uni = $camp = $dept = $prog = $yr = null;
    $info = null;
    try {
        $info = call_llm_extract($user_msg);
        if ($info) {
            $uni = $info['university'] ?? null;
            $camp = $info['campus'] ?? '';
            $dept = $info['department'] ?? null;
            $prog = $info['program'] ?? 'BS';
            $yr = isset($info['year']) ? (int)$info['year'] : detect_year_from_text($user_msg);
        }
    } catch (Exception $e) {
        // ignore - fallback will handle
        $info = null;
    }

    // 3) fallback extractor if LLM didn't fill everything
    if (!($uni && $dept && $prog && $yr)) {
        list($f_uni, $f_camp, $f_dep, $f_prog, $f_yr) = cheap_extract($user_msg);
        $uni = $uni ?: $f_uni;
        $camp = $camp ?: $f_camp;
        $dept = $dept ?: $f_dep;
        $prog = $prog ?: $f_prog;
        $yr = $yr ?: $f_yr;
    }

    // 4) Normalize
    $uni = $uni ? norm_uni($uni) : null;
    $dept = $dept ? norm_dept($dept) : null;
    $prog = $prog ? norm_prog($prog) : null;
    $camp = $camp ? norm_campus($camp) : "";

    if ($uni && $dept) {
        $dept = adjust_dept_for_uni($uni, $dept);
    }

    // 5) conversational follow-ups: store missing
    $missing = [];
    if (!$uni) $missing[] = 'university';
    if (!$dept) $missing[] = 'department';
    if (!$prog) $missing[] = 'program';
    if (!empty($missing)) {
        $ask_next = $missing[0];
        $_SESSION['user_context'][$session_id] = [
            'awaiting' => $ask_next,
            'known_university' => $uni,
            'known_department' => $dept,
            'known_program' => $prog,
            'known_campus' => $camp,
            'known_year' => $yr
        ];
        if ($ask_next === 'university') {
            header('Content-Type: application/json');
            echo json_encode(['reply' => "Which university? For example: " . implode(', ', array_slice($GLOBALS['UNIS'], 0, 8)) . "."]);
            exit;
        }
        if ($ask_next === 'department') {
            if ($uni) {
                header('Content-Type: application/json');
                echo json_encode(['reply' => "Which department at $uni? Examples: " . implode(', ', array_slice(departments_at_uni($uni), 0, 8)) . "."]);
                exit;
            }
            header('Content-Type: application/json');
            echo json_encode(['reply' => "Which department? Examples: " . implode(', ', array_slice($GLOBALS['DEPTS'], 0, 8)) . "."]);
            exit;
        }
        if ($ask_next === 'program') {
            if ($uni && $dept) {
                $opts = programs_for($uni, $dept);
                header('Content-Type: application/json');
                echo json_encode(['reply' => "Which program for $dept at $uni? Options: " . ($opts ? implode(', ', $opts) : 'BS/MS/MPhil/PhD') . "."]);
                exit;
            }
            header('Content-Type: application/json');
            echo json_encode(['reply' => "Which program (BS/MS/MPhil/PhD)?"]);
            exit;
        }
    }

    // fill awaited if present
    if (!empty($ctx['awaiting'])) {
        $awaiting = $ctx['awaiting'];
        if ($awaiting === 'university' && !$uni) {
            $try = norm_uni($user_msg);
            if ($try) $uni = $try;
        } elseif ($awaiting === 'department' && !$dept) {
            $try = norm_dept($user_msg);
            if ($try) $dept = $try;
        } elseif ($awaiting === 'program' && !$prog) {
            $try = norm_prog($user_msg);
            if ($try) $prog = $try;
        }
        unset($_SESSION['user_context'][$session_id]);
    }

    // 6) multi-campus support
    if ($camp && (strpos($camp, ',') !== false || preg_match('/\band\b|\&/i', $camp))) {
        $reply = reply_for_multi_campus($uni, $dept, $prog, $camp, $yr);
        header('Content-Type: application/json');
        echo json_encode(['reply' => $reply]);
        exit;
    }

    // 7) final
    $reply = reply_for_single_or_uncamped($uni, $dept, $prog, $camp, $yr);
    header('Content-Type: application/json');
    echo json_encode(['reply' => $reply]);
    exit;
}

// fallback: try to serve static file if exists under static/
$maybe = realpath(__DIR__ . $uri);
if ($maybe && is_file($maybe) && strpos($maybe, realpath($STATIC_DIR)) === 0) {
    // let web server normally serve static, but support PHP built-in server
    $ext = pathinfo($maybe, PATHINFO_EXTENSION);
    $mime = match(strtolower($ext)) {
        'js' => 'application/javascript',
        'css' => 'text/css',
        'html' => 'text/html',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg','jpeg' => 'image/jpeg',
        default => 'application/octet-stream'
    };
    header("Content-Type: $mime");
    readfile($maybe);
    exit;
}

// Not found
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);
exit;
