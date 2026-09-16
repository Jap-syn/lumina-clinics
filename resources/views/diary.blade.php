<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lumina &mdash; reception diary</title>
<style>
  :root { --line:#e2e2e2; --ink:#1a1a1a; --muted:#666; }
  body { font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif; color:var(--ink); margin:0 auto;
         max-width:1000px; padding:24px; }
  h1 { font-weight:600; letter-spacing:-.02em; margin-bottom:4px; }
  .controls { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin:16px 0; }
  label { display:block; font-size:13px; color:var(--muted); margin-bottom:3px; }
  select,input { padding:8px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; }
  table { border-collapse:collapse; width:100%; margin-top:12px; }
  th,td { text-align:left; padding:9px 10px; border-bottom:1px solid var(--line); }
  th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
  .tag { font-size:12px; padding:2px 7px; border-radius:10px; border:1px solid var(--line); }
  .confirmed { background:#eef7f0; border-color:#bfe0c8; }
  .pending_payment { background:#fdf6e6; border-color:#eddcaf; }
  .cancelled,.expired { background:#f6f6f6; color:var(--muted); }
  .via { font-size:12px; color:var(--muted); }
  .muted { color:var(--muted); }
</style>
</head>
<body>
<h1>Reception diary</h1>
<p class="muted">One live view of the day, shared by everyone on the desk. Phone and WhatsApp
bookings are entered here and follow exactly the same rules as the website.</p>

<div class="controls">
  <div><label for="token">Staff token</label><input id="token" value="lumina-reception"></div>
  <div><label for="branch">Branch</label><select id="branch"></select></div>
  <div><label for="date">Date</label><input type="date" id="date"></div>
</div>

<table>
  <thead>
    <tr><th>Time</th><th>Room free</th><th>Client</th><th>Treatment</th>
        <th>Therapist</th><th>Room</th><th>Status</th><th>Ref</th></tr>
  </thead>
  <tbody id="rows"><tr><td colspan="8" class="muted">Loading&hellip;</td></tr></tbody>
</table>

<script>
const $ = id => document.getElementById(id);

async function load() {
  const res = await fetch(`/api/staff/diary?branch_id=${$('branch').value}&date=${$('date').value}`, {
    headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + $('token').value },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    $('rows').innerHTML = `<tr><td colspan="8" class="muted">${body.error || 'Could not load the diary.'}</td></tr>`;
    return;
  }
  if (!body.bookings.length) {
    $('rows').innerHTML = '<tr><td colspan="8" class="muted">Nothing booked yet.</td></tr>';
    return;
  }
  $('rows').innerHTML = body.bookings.map(b => `
    <tr>
      <td><strong>${b.starts_at}</strong>&ndash;${b.ends_at}</td>
      <td class="muted">${b.room_free_at}</td>
      <td>${b.client}<br><span class="via">${b.phone}${b.is_member ? ' &middot; member' : ''}</span></td>
      <td>${b.treatment}</td>
      <td>${b.therapist}</td>
      <td>${b.room}</td>
      <td><span class="tag ${b.status}">${b.status.replace('_',' ')}</span>
          <div class="via">${b.created_via}${b.deposit_paid ? ' &middot; deposit paid' : ''}</div></td>
      <td class="via">${b.reference}</td>
    </tr>`).join('');
}

fetch('/api/branches').then(r => r.json()).then(branches => {
  $('branch').innerHTML = branches.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
  $('date').valueAsDate = new Date();
  load();
});

['token','branch','date'].forEach(id => $(id).onchange = load);
setInterval(load, 20000);   // the desk keeps this open all day
</script>
</body>
</html>
