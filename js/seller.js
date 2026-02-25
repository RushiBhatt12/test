const API = "../api";
const seller = JSON.parse(localStorage.getItem("seller") || "null");

if (!seller && !location.pathname.includes("register")) {
  location.href = "register.html";
}

if (seller) {
  const el = document.getElementById("sellerName");
  if (el) el.textContent = seller.name;
}

document.getElementById("logoutBtn")?.addEventListener("click", () => {
  localStorage.removeItem("seller");
  location.href = "../index.html";
});

function showTab(name) {
  document.querySelectorAll("[id^='tab-']").forEach(t => t.style.display = "none");
  document.querySelectorAll(".sidebar-item").forEach(b => b.classList.remove("active"));
  document.getElementById("tab-" + name).style.display = "block";
  event.currentTarget.classList.add("active");
}

function toast(msg, dur = 3000) {
  const t = document.getElementById("toast");
  t.textContent = msg;
  t.classList.add("show");
  setTimeout(() => t.classList.remove("show"), dur);
}

async function runResearch() {
  const btn = document.getElementById("runResearchBtn");
  btn.disabled = true;
  btn.textContent = "⏳ Analysing your business...";

  document.getElementById("researchCard").innerHTML = `
    <div class="loader-wrap">
      <div class="spinner"></div>
      <p style="color:var(--muted)">Searching the internet, scraping competitors, generating strategy...</p>
      <p style="color:var(--muted);font-size:13px">This takes ~30 seconds</p>
    </div>`;

  try {
    const res  = await fetch(`${API}/seller_research.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ seller_id: seller.id })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

    const s = data.strategy;

    // Update KPIs
    document.getElementById("kpiComp").textContent     = data.competitors?.length || 0;
    document.getElementById("kpiKeywords").textContent = s.keyword_targets?.length || 0;
    document.getElementById("kpiFixes").textContent    = s.seo_fixes?.length || 0;
    document.getElementById("kpiActions").textContent  = s.weekly_actions?.length || 0;

    // Save report to localStorage for quick access
    localStorage.setItem("lastReport", JSON.stringify(data));

    // Render report
    renderReport(s);
    renderCompetitors(data.competitors);
    toast("✅ Analysis complete! Check your Strategy Report tab.");

    document.getElementById("researchCard").innerHTML = `
      <p style="color:var(--success);font-weight:600">✅ Research complete! Switch to the Strategy Report tab.</p>`;

  } catch (err) {
    toast("❌ Error: " + err.message, 5000);
    document.getElementById("researchCard").innerHTML = `
      <p style="color:var(--danger)">❌ ${err.message}</p>
      <button class="btn btn-primary btn-sm" style="margin-top:12px" onclick="location.reload()">Try again</button>`;
  }
}

function renderReport(s) {
  const el = document.getElementById("reportContent");
  el.innerHTML = `
    <div class="report-section">
      <h3>🚧 What's Bottlenecking Your Sales</h3>
      ${(s.bottlenecks || []).map(b => `<div class="action-item"><span>⚠️</span><p>${b}</p></div>`).join("")}
    </div>
    <div class="report-section">
      <h3>🔑 Target Keywords</h3>
      <div>${(s.keyword_targets || []).map(k => `<span class="tag">${k}</span>`).join("")}</div>
    </div>
    <div class="report-section">
      <h3>🔧 SEO Fixes</h3>
      ${(s.seo_fixes || []).map((f, i) => `
        <div class="action-item">
          <div class="action-num">${i + 1}</div>
          <p>${f}</p>
        </div>`).join("")}
    </div>
    <div class="report-section">
      <h3>📝 Content Gaps vs Competitors</h3>
      ${(s.content_gaps || []).map(c => `<div class="action-item"><span>📌</span><p>${c}</p></div>`).join("")}
    </div>
    <div class="report-section">
      <h3>📋 This Week's Priority Actions</h3>
      ${(s.weekly_actions || []).map((a, i) => `
        <div class="action-item">
          <div class="action-num">${i + 1}</div>
          <p>${a}</p>
        </div>`).join("")}
    </div>
    <div class="report-section">
      <h3>📣 Local Listings to Join</h3>
      <div>${(s.local_listings || []).map(l => `<span class="tag">📍 ${l}</span>`).join("")}</div>
    </div>
    <div class="report-section">
      <h3>💡 Ad Strategy</h3>
      ${(s.ad_strategy || []).map(a => `<div class="action-item"><span>💰</span><p>${a}</p></div>`).join("")}
    </div>`;
}

function renderCompetitors(comps) {
  const el = document.getElementById("competitorList");
  if (!comps?.length) { el.innerHTML = "<p style='color:var(--muted)'>No competitors found.</p>"; return; }
  el.innerHTML = comps.map(c => `
    <div class="seller-card" style="margin-bottom:12px;flex-direction:column;align-items:flex-start">
      <div style="display:flex;justify-content:space-between;width:100%;align-items:center">
        <strong>${c.meta_title || c.url}</strong>
        <a href="${c.url}" target="_blank" class="btn btn-secondary btn-sm">Visit →</a>
      </div>
      <p style="font-size:13px;color:var(--muted);margin-top:6px">${c.meta_desc || "No description"}</p>
      ${c.keywords ? `<div style="margin-top:8px">${c.keywords.split(",").slice(0,6).map(k => `<span class="tag">${k.trim()}</span>`).join("")}</div>` : ""}
    </div>`).join("");
}

// Auto-load cached report if available
window.addEventListener("DOMContentLoaded", () => {
  const cached = JSON.parse(localStorage.getItem("lastReport") || "null");
  if (cached?.strategy) {
    renderReport(cached.strategy);
    renderCompetitors(cached.competitors);
    document.getElementById("kpiComp").textContent     = cached.competitors?.length || 0;
    document.getElementById("kpiKeywords").textContent = cached.strategy.keyword_targets?.length || 0;
    document.getElementById("kpiFixes").textContent    = cached.strategy.seo_fixes?.length || 0;
    document.getElementById("kpiActions").textContent  = cached.strategy.weekly_actions?.length || 0;
  }
});
