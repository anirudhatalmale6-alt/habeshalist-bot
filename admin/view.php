<?php
/**
 * view.php - shared page chrome (header + footer + inline styles).
 * Everything is inline so the panel works on any shared host with no external
 * CSS/font/CDN dependencies.
 */

function hl_head($title, $loggedIn = false) {
    $t = h($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t} - HabeshaList Admin</title>
<style>
  :root{
    --bg:#0f1419; --card:#1a2029; --line:#2a333f; --text:#e7edf3; --muted:#93a1b3;
    --accent:#2ea043; --accent2:#238636; --danger:#da3633; --input:#0d1117; --chip:#22303f;
  }
  @media (prefers-color-scheme: light){
    :root{ --bg:#f4f6f9; --card:#ffffff; --line:#e2e8f0; --text:#1a2430; --muted:#5a6b7d;
      --accent:#2ea043; --accent2:#238636; --danger:#c62828; --input:#ffffff; --chip:#eef2f6; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
    font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:860px;margin:0 auto;padding:20px 16px 60px}
  header.top{display:flex;align-items:center;justify-content:space-between;
    padding:14px 0 18px;border-bottom:1px solid var(--line);margin-bottom:22px;flex-wrap:wrap;gap:10px}
  .brand{font-weight:700;font-size:18px;letter-spacing:.2px}
  .brand span{color:var(--accent)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;
    padding:18px 18px;margin-bottom:18px}
  .card h2{margin:0 0 4px;font-size:16px}
  .card p.sub{margin:0 0 16px;color:var(--muted);font-size:13px}
  label{display:block;font-size:13px;color:var(--muted);margin:0 0 6px}
  .row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}
  .row .field{flex:1;min-width:200px}
  input[type=text],input[type=password],input[type=number]{
    width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;
    background:var(--input);color:var(--text);font-size:15px}
  input:focus{outline:none;border-color:var(--accent)}
  .prefix{position:relative}
  .prefix input{padding-left:26px}
  .prefix .sym{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)}
  button,.btn{cursor:pointer;border:0;border-radius:8px;padding:10px 18px;font-size:14px;
    font-weight:600;background:var(--accent2);color:#fff}
  button:hover,.btn:hover{background:var(--accent)}
  .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}
  .btn.ghost:hover{background:var(--chip)}
  .nav{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .navlink{padding:8px 12px;border-radius:8px;font-size:14px;font-weight:600;
    color:var(--text);text-decoration:none}
  .navlink:hover{background:var(--chip)}
  .navlink[aria-current]{background:var(--chip);color:var(--accent)}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:6px}
  .stat{background:var(--chip);border:1px solid var(--line);border-radius:10px;padding:14px}
  .stat .n{font-size:24px;font-weight:700}
  .stat .l{font-size:12px;color:var(--muted);margin-top:2px}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line)}
  th{color:var(--muted);font-weight:600}
  .pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11.5px;font-weight:600}
  .pill.ok{background:rgba(46,160,67,.16);color:#3fb950}
  .pill.pend{background:rgba(210,153,34,.16);color:#d29922}
  .pill.rej{background:rgba(218,54,51,.16);color:#f85149}
  .pill.mut{background:var(--chip);color:var(--muted)}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:18px;font-size:14px}
  .flash.ok{background:rgba(46,160,67,.14);border:1px solid rgba(46,160,67,.4);color:#3fb950}
  .flash.err{background:rgba(218,54,51,.14);border:1px solid rgba(218,54,51,.4);color:#f85149}
  .tblwrap{overflow-x:auto}
  .muted{color:var(--muted)}
  .small{font-size:12.5px}
  a{color:var(--accent)}
</style>
</head>
<body>
<div class="wrap">
<header class="top">
  <div class="brand">Habesha<span>List</span> &nbsp;Admin</div>

HTML;
    if ($loggedIn) {
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $dashCur = ($cur === 'index.php') ? ' aria-current="page"' : '';
        $keysCur = ($cur === 'secrets.php') ? ' aria-current="page"' : '';
        echo '  <nav class="nav">';
        echo '<a class="navlink"' . $dashCur . ' href="index.php">Dashboard</a>';
        echo '<a class="navlink"' . $keysCur . ' href="secrets.php">Keys</a>';
        echo '<a class="btn ghost" href="logout.php">Log out</a>';
        echo "</nav>\n";
    }
    echo "</header>\n";
}

function hl_foot() {
    echo "</div></body></html>\n";
}

function hl_flash($msg, $type = 'ok') {
    $c = $type === 'err' ? 'err' : 'ok';
    echo '<div class="flash ' . $c . '">' . h($msg) . "</div>\n";
}

// ---------------------------------------------------------------------------
// App shell (sidebar + top bar) used by the dashboard-style pages.
// ---------------------------------------------------------------------------
function hl_nav_items() {
    return [
        ['sec' => 'Main', 'items' => [
            ['slug' => 'dashboard',  'label' => 'Dashboard',       'href' => 'index.php',      'icon' => "\xF0\x9F\x93\x8A"],
            ['slug' => 'pending',    'label' => 'Pending Ads',     'href' => 'pending.php',    'icon' => "\xE2\x8F\xB3", 'badge' => true],
            ['slug' => 'payments',   'label' => 'Payments',        'href' => 'payments.php',   'icon' => "\xF0\x9F\x92\xB3"],
            ['slug' => 'businesses', 'label' => 'Businesses',      'href' => 'businesses.php', 'icon' => "\xF0\x9F\x8F\xA2"],
            ['slug' => 'users',      'label' => 'Users',           'href' => 'users.php',      'icon' => "\xF0\x9F\x91\xA5"],
        ]],
        ['sec' => 'Settings', 'items' => [
            ['slug' => 'pricing',    'label' => 'Plan & Pricing',  'href' => 'pricing.php',    'icon' => "\xF0\x9F\x8F\xB7\xEF\xB8\x8F"],
            ['slug' => 'methods',    'label' => 'Payment Methods', 'href' => 'methods.php',    'icon' => "\xF0\x9F\x92\xB0"],
            ['slug' => 'keys',       'label' => 'Keys',            'href' => 'secrets.php',    'icon' => "\xF0\x9F\x94\x91"],
        ]],
        ['sec' => 'Coming soon', 'items' => [
            ['slug' => 'scheduled', 'label' => 'Scheduled Posts',  'href' => 'soon.php',       'icon' => "\xF0\x9F\x97\x93\xEF\xB8\x8F", 'soon' => true],
            ['slug' => 'calendar',  'label' => 'Calendar & Slots', 'href' => 'soon.php',       'icon' => "\xF0\x9F\x93\x85", 'soon' => true],
            ['slug' => 'subs',      'label' => 'Subscriptions',    'href' => 'soon.php',       'icon' => "\xF0\x9F\x94\x84", 'soon' => true],
            ['slug' => 'reports',   'label' => 'Reports',          'href' => 'soon.php',       'icon' => "\xF0\x9F\x93\x88", 'soon' => true],
        ]],
    ];
}

function hl_shell_head($title, $active = '', $pending = 0) {
    $t = h($title);
    $user = h($_SESSION['hl_admin'] ?? 'Admin');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t} - HabeshaList Admin</title>
<style>
  :root{
    --bg:#f4f6f9; --card:#ffffff; --line:#e6ebf1; --text:#1a2430; --muted:#64748b;
    --accent:#2ea043; --accent2:#238636; --danger:#c62828; --dangerbg:#fdecea;
    --input:#ffffff; --chip:#eef2f6; --side:#ffffff; --sidetext:#334155;
    --brand:#111827; --okbg:#e7f6ec; --okfg:#1a7f37; --pendbg:#fff4e2; --pendfg:#b26a00;
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#0f1419; --card:#161b22; --line:#252d38; --text:#e7edf3; --muted:#93a1b3;
      --accent:#3fb950; --accent2:#238636; --danger:#f85149; --dangerbg:#3a1d1d;
      --input:#0d1117; --chip:#1d2530; --side:#12171e; --sidetext:#c3ced9;
      --brand:#e7edf3; --okbg:#132b1c; --okfg:#3fb950; --pendbg:#2e2410; --pendfg:#d29922; }
  }
  :root[data-theme=light]{ --bg:#f4f6f9; --card:#fff; --side:#fff; --text:#1a2430; --sidetext:#334155; --brand:#111827; --line:#e6ebf1; --input:#fff; --chip:#eef2f6; --muted:#64748b; --okbg:#e7f6ec; --okfg:#1a7f37; --pendbg:#fff4e2; --pendfg:#b26a00; --dangerbg:#fdecea;}
  :root[data-theme=dark]{ --bg:#0f1419; --card:#161b22; --side:#12171e; --text:#e7edf3; --sidetext:#c3ced9; --brand:#e7edf3; --line:#252d38; --input:#0d1117; --chip:#1d2530; --muted:#93a1b3; --okbg:#132b1c; --okfg:#3fb950; --pendbg:#2e2410; --pendfg:#d29922; --dangerbg:#3a1d1d;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
    font:14.5px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  a{color:inherit;text-decoration:none}
  .layout{display:flex;min-height:100vh}
  aside{width:230px;flex:0 0 230px;background:var(--side);border-right:1px solid var(--line);
    padding:0;position:sticky;top:0;height:100vh;overflow-y:auto}
  .brand{padding:18px 20px 14px;font-weight:800;font-size:20px;color:var(--brand);letter-spacing:.2px}
  .brand span{color:var(--accent)}
  .brand small{display:block;font-weight:500;font-size:11px;color:var(--muted);letter-spacing:.3px;margin-top:2px}
  .navsec{padding:8px 12px 2px;font-size:10.5px;font-weight:700;letter-spacing:.8px;
    text-transform:uppercase;color:var(--muted);margin-top:8px}
  .navi{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:9px;
    color:var(--sidetext);font-weight:500;font-size:14px}
  .navi:hover{background:var(--chip)}
  .navi.active{background:var(--accent);color:#fff}
  .navi .ic{width:18px;text-align:center;font-size:14px}
  .navi .badge{margin-left:auto;background:var(--danger);color:#fff;font-size:11px;font-weight:700;
    border-radius:20px;padding:1px 8px;min-width:20px;text-align:center}
  .navi.active .badge{background:rgba(255,255,255,.28)}
  .navi .soon{margin-left:auto;font-size:9.5px;font-weight:700;color:var(--muted);
    border:1px solid var(--line);border-radius:20px;padding:1px 7px;text-transform:uppercase;letter-spacing:.4px}
  main{flex:1;min-width:0;display:flex;flex-direction:column}
  .topbar{display:flex;align-items:center;gap:14px;padding:14px 22px;background:var(--card);
    border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}
  .topbar .title{font-weight:700;font-size:17px}
  .topbar .spacer{flex:1}
  .topbar .who{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--muted)}
  .avatar{width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;
    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px}
  .content{padding:22px;max-width:1200px;width:100%}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 16px;
    display:flex;gap:13px;align-items:center}
  .stat .ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;
    justify-content:center;font-size:20px;flex:0 0 46px}
  .stat .n{font-size:23px;font-weight:800;line-height:1.1}
  .stat .l{font-size:12.5px;color:var(--muted);margin-top:1px}
  .grid2{display:grid;grid-template-columns:1fr;gap:18px}
  @media(min-width:1000px){.grid2{grid-template-columns:1fr 1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:18px}
  .card h2{margin:0 0 3px;font-size:15.5px}
  .card .sub{margin:0 0 14px;color:var(--muted);font-size:12.5px}
  .card .hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:10px}
  .card .hd h2{margin:0}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
  th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
  tr:last-child td{border-bottom:0}
  .tblwrap{overflow-x:auto}
  .pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11.5px;font-weight:700}
  .pill.ok{background:var(--okbg);color:var(--okfg)}
  .pill.pend{background:var(--pendbg);color:var(--pendfg)}
  .pill.rej{background:var(--dangerbg);color:var(--danger)}
  .pill.mut{background:var(--chip);color:var(--muted)}
  .plan{display:inline-block;background:var(--chip);border-radius:7px;padding:2px 8px;font-size:12px;font-weight:600}
  label{display:block;font-size:13px;color:var(--muted);margin:0 0 6px}
  input[type=text],input[type=password],input[type=number]{width:100%;padding:10px 12px;
    border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:15px}
  input:focus{outline:none;border-color:var(--accent)}
  .row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}
  .row .field{flex:1;min-width:200px}
  .prefix{position:relative}.prefix input{padding-left:26px}
  .prefix .sym{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)}
  button,.btn{cursor:pointer;border:0;border-radius:9px;padding:9px 16px;font-size:13.5px;
    font-weight:600;background:var(--accent2);color:#fff}
  button:hover,.btn:hover{background:var(--accent)}
  .btn.sm{padding:6px 13px;font-size:12.5px}
  .btn.red{background:var(--danger)}.btn.red:hover{filter:brightness(1.08)}
  .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}
  .btn.ghost:hover{background:var(--chip)}
  .actions{display:flex;gap:7px}
  .flash{padding:11px 14px;border-radius:9px;margin-bottom:16px;font-size:13.5px}
  .flash.ok{background:var(--okbg);border:1px solid var(--accent);color:var(--okfg)}
  .flash.err{background:var(--dangerbg);border:1px solid var(--danger);color:var(--danger)}
  .muted{color:var(--muted)}.small{font-size:12px}.mono{font-family:ui-monospace,Menlo,Consolas,monospace}
  .empty{padding:26px 10px;text-align:center;color:var(--muted)}
  .thumb{width:40px;height:40px;border-radius:8px;background:var(--chip);display:flex;align-items:center;
    justify-content:center;font-size:16px;color:var(--muted)}
  @media(max-width:820px){
    .layout{flex-direction:column}
    aside{width:100%;flex:none;height:auto;position:static;border-right:0;border-bottom:1px solid var(--line)}
    aside .navwrap{display:flex;flex-wrap:wrap;padding-bottom:8px}
    .navsec{width:100%}
    .navi{margin:1px 6px}
  }
</style>
</head>
<body>
<div class="layout">
<aside>
  <div class="brand">Habesha<span>List</span><small>Community Marketplace</small></div>
  <div class="navwrap">
HTML;
    foreach (hl_nav_items() as $group) {
        echo '<div class="navsec">' . h($group['sec']) . "</div>\n";
        foreach ($group['items'] as $it) {
            $cls = 'navi' . ($it['slug'] === $active ? ' active' : '');
            echo '<a class="' . $cls . '" href="' . h($it['href']) . '">';
            echo '<span class="ic">' . $it['icon'] . '</span>';
            echo '<span>' . h($it['label']) . '</span>';
            if (!empty($it['badge']) && $pending > 0) {
                echo '<span class="badge">' . (int) $pending . '</span>';
            } elseif (!empty($it['soon'])) {
                echo '<span class="soon">soon</span>';
            }
            echo "</a>\n";
        }
    }
    echo <<<HTML
  </div>
</aside>
<main>
  <div class="topbar">
    <div class="title">{$t}</div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="logout.php">Log out</a>
    <div class="who"><span>{$user}</span><span class="avatar">A</span></div>
  </div>
  <div class="content">
HTML;
}

function hl_shell_foot() {
    echo "  </div>\n</main>\n</div>\n</body></html>\n";
}
