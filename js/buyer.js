const API = "../api";

async function searchSellers(e) {
  e.preventDefault();
  const category = document.getElementById("category").value;
  const city     = document.getElementById("city").value;
  const state    = document.getElementById("state").value;
  const results  = document.getElementById("results");

  results.innerHTML = `<div class="loader-wrap"><div class="spinner"></div><p style="color:var(--muted)">Finding local sellers...</p></div>`;

  const res  = await fetch(`${API}/buyer_match.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ category, city, state })
  });
  const data = await res.json();

  const all = [
    ...(data.db_sellers || []).map(s => ({ ...s, _source: "local" })),
    ...(data.external   || []).map(s => ({ ...s, _source: "justdial" }))
  ];

  if (!all.length) {
    results.innerHTML = `<div class="card"><p style="color:var(--muted)">No sellers found in your area yet. Try a broader category.</p></div>`;
    return;
  }

  results.innerHTML = `<p style="color:var(--muted);margin-bottom:16px">Found ${all.length} sellers near you</p>` +
    all.map(s => `
      <div class="seller-card" style="margin-bottom:12px">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <strong>${s.name || s.business_name}</strong>
            ${s._source === "justdial" ? `<span class="badge-ext">JustDial</span>` : `<span class="badge-ext" style="background:#E8F5E9;color:#2E7D32">Verified</span>`}
          </div>
          <p style="font-size:13px;color:var(--muted)">${s.city || ""} ${s.state ? "· " + s.state : ""} ${s.category ? "· " + s.category : ""}</p>
        </div>
        <div style="display:flex;gap:8px">
          ${s.contact ? `<a href="tel:${s.contact}" class="btn btn-secondary btn-sm">📞 Call</a>` : ""}
          ${s.id       ? `<button class="btn btn-primary btn-sm" onclick="connectSeller(${s.id})">Connect</button>` : ""}
        </div>
      </div>`).join("");
}

async function connectSeller(sellerId) {
  const buyer   = JSON.parse(localStorage.getItem("buyer") || "null");
  if (!buyer) { location.href = "register.html"; return; }

  const message = prompt("Send a message to this seller:");
  if (!message) return;

  const res  = await fetch(`${API}/connect.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ buyer_id: buyer.id, seller_id: sellerId, message })
  });
  const data = await res.json();
  alert(data.success ? "✅ Request sent! The seller will contact you shortly." : "Error: " + data.error);
}

document.getElementById("searchForm")?.addEventListener("submit", searchSellers);
