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
        echo '  <a class="btn ghost" href="logout.php">Log out</a>' . "\n";
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
